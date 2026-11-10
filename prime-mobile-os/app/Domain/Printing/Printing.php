<?php

namespace App\Domain\Printing;

use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Helpers of the print forms Ф1–Ф12 (TZ 06 §6.5, §8, §9): kk + ru texts by the firm setting (price tags — always
 * kk first), money «489 000 ₸», masked phones, Code 128 / EAN-13 / QR as inline SVG (pure black, scales to any
 * printer), the duplicate mark «ДУБЛИКАТ / КӨШІРМЕ» and the print log.
 */
final class Printing
{
    /** kk + ru | ru | kk (settings.printing.language). */
    public static function lang(): string
    {
        return (string) CurrentOrganization::instance()->require()->settings()->get('printing.language', 'kk+ru');
    }

    /** A bilingual label: «Кепілдік / Гарантия» or one language by the setting; $always — price tags (law: kk first). */
    public static function t(string $kk, string $ru, bool $always = false): string
    {
        return match ($always ? 'kk+ru' : self::lang()) {
            'ru' => $ru,
            'kk' => $kk,
            default => $kk === $ru ? $ru : $kk.' / '.$ru,
        };
    }

    public static function money(?int $tiyn): string
    {
        return $tiyn === null ? '' : Money::kztMinor($tiyn)->format();
    }

    /** +7 701 *** 45 67 (TZ 06 Ф1 п. 7). */
    public static function maskPhone(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone);

        return strlen($d) === 11 ? '+7 '.substr($d, 1, 3).' *** '.substr($d, 7, 2).' '.substr($d, 9, 2) : '';
    }

    public static function code128(string $value, int $height = 34, float $module = 1.4): string
    {
        return self::svg((new BarcodeGeneratorSVG)->getBarcode($value, BarcodeGeneratorSVG::TYPE_CODE_128, $module, $height), true);
    }

    public static function ean13(string $value, int $height = 30): string
    {
        return self::svg((new BarcodeGeneratorSVG)->getBarcode($value, BarcodeGeneratorSVG::TYPE_EAN_13, 1.3, $height), true);
    }

    public static function qr(string $data): string
    {
        $svg = (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'eccLevel' => EccLevel::M, 'outputBase64' => false,
            'addQuietzone' => false, 'svgUseFillAttributes' => true, 'drawLightModules' => false])))->render($data);

        return self::svg($svg);
    }

    /** Clean inline SVG; barcodes stretch to the box given by CSS (class «bc»), QR keeps its square. */
    private static function svg(string $svg, bool $stretch = false): string
    {
        $svg = preg_replace(['/<\?xml[^>]*>\s*/', '/<!DOCTYPE[^>]*>\s*/'], '', $svg);
        if ($stretch) {
            $svg = preg_replace('/<svg([^>]*?)\swidth="[^"]*"\s+height="[^"]*"/', '<svg$1 class="bc" preserveAspectRatio="none"', $svg, 1);
        }

        return $svg;
    }

    /**
     * Writes the print to the activity log (§6.5 «каждая печать и перепечатка») and says whether it is a duplicate:
     * the first print of a document/form is the original, every next one gets «ДУБЛИКАТ / КӨШІРМЕ».
     */
    public static function logAndIsDuplicate(string $form, string $subject, int $id): bool
    {
        $orgId = CurrentOrganization::instance()->requireId();
        $before = DB::table('activity_log')->where('organization_id', $orgId)->where('log_name', 'print')->where('description', $form)
            ->where('subject_type', $subject)->where('subject_id', $id)->exists();
        activity('print')->causedBy(auth()->user())->withProperties(['organization_id' => $orgId, 'form' => $form, 'duplicate' => $before])
            ->tap(fn ($a) => $a->forceFill(['subject_type' => $subject, 'subject_id' => $id]))->log($form);

        return $before;
    }

    /** Secret link of the electronic receipt and warranty (§6.5, §8): /r/<22 random chars>, no IMEI or phone in it. */
    public static function receiptLink(int $documentId): string
    {
        $orgId = CurrentOrganization::instance()->requireId();
        $token = DB::table('receipt_links')->where('organization_id', $orgId)->where('document_id', $documentId)->value('token');
        if (! $token) {
            $token = Str::random(22);
            DB::table('receipt_links')->insertOrIgnore(['organization_id' => $orgId, 'document_id' => $documentId, 'token' => $token, 'created_at' => now()]);
            $token = DB::table('receipt_links')->where('organization_id', $orgId)->where('document_id', $documentId)->value('token');
        }
        $shop = config('prime.shop_domain');
        $port = request()->getPort();

        return (request()->isSecure() ? 'https://' : 'http://').$shop.($port && ! in_array($port, [80, 443], true) ? ':'.$port : '').'/r/'.$token;
    }

    /** «Гарантия до» = date + months; a missing day (31-е) → the last day of the month (Ф3). */
    public static function warrantyUntil(CarbonInterface $from, int $months): CarbonImmutable
    {
        return CarbonImmutable::parse($from)->addMonthsNoOverflow($months);
    }

    /** Russian amount in words for acts and invoices: «сто пятьдесят тысяч тенге». */
    public static function words(int $tenge): string
    {
        if ($tenge === 0) {
            return 'ноль тенге';
        }
        $ones = [['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'], ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять']];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        $units = [['', '', ''], ['тысяча', 'тысячи', 'тысяч'], ['миллион', 'миллиона', 'миллионов'], ['миллиард', 'миллиарда', 'миллиардов']];
        $plural = fn (int $n, array $f) => $n % 100 >= 11 && $n % 100 <= 14 ? $f[2] : ([1 => $f[0], 2 => $f[1], 3 => $f[1], 4 => $f[1]][$n % 10] ?? $f[2]);
        $out = [];
        $n = abs($tenge);
        for ($i = 0; $n > 0; $i++, $n = intdiv($n, 1000)) {
            $chunk = $n % 1000;
            if (! $chunk) {
                continue;
            }
            $w = [$hundreds[intdiv($chunk, 100)]];
            $r = $chunk % 100;
            $w[] = $r >= 10 && $r < 20 ? $teens[$r - 10] : trim($tens[intdiv($r, 10)].' '.$ones[$i === 1 ? 1 : 0][$r % 10]);
            if ($i > 0) {
                $w[] = $plural($chunk, $units[$i]);
            }
            array_unshift($out, trim(implode(' ', array_filter($w))));
        }

        return ($tenge < 0 ? 'минус ' : '').implode(' ', $out).' тенге';
    }
}
