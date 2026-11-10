<?php

namespace App\Domain\Migration\Services;

use App\Domain\Migration\Models\ImportRun;
use App\Models\User;
use App\Support\Phone\Phone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Excel exports of sfon (TZ 07 Ш2): the columns are found by the header names (the exact names are learnt on the
 * reconnaissance — a different file just needs another column map, passed by the screen). Each kind normalises its
 * rows into import_rows; the sales history goes to the read-only archive sfon_archive.
 */
final class ExcelImport
{
    /** kind => [title, fields => header synonyms (lower case, part of the header)] */
    public const KINDS = [
        'customers' => ['Клиенты', ['phone' => ['телефон', 'phone', 'номер тел'], 'name' => ['фио', 'имя', 'клиент', 'name'], 'source' => ['источник'], 'comment' => ['коммент', 'примеч']]],
        'customer_debts' => ['Долги клиентов', ['phone' => ['телефон', 'phone'], 'name' => ['фио', 'имя', 'клиент'], 'amount' => ['долг', 'сумма', 'остаток'], 'due' => ['срок', 'вернуть', 'дата возврата'], 'comment' => ['коммент', 'примеч', 'товар']]],
        'supplier_debts' => ['Долги поставщикам', ['name' => ['поставщик', 'наименование', 'контрагент'], 'amount' => ['долг', 'сумма', 'остаток'], 'currency' => ['валюта'], 'due' => ['срок', 'дата оплаты']]],
        'bonuses' => ['Бонусы клиентов', ['phone' => ['телефон', 'phone'], 'name' => ['фио', 'имя', 'клиент'], 'amount' => ['бонус', 'баланс', 'сумма']]],
        'accessories' => ['Склад аксессуаров', ['name' => ['наименование', 'название', 'товар'], 'barcode' => ['штрихкод', 'штрих-код', 'barcode', 'ean'], 'sku' => ['артикул', 'код'],
            'qty' => ['количество', 'кол-во', 'остаток', 'шт'], 'cost' => ['себестоим', 'закуп', 'цена закупа', 'приход'], 'price' => ['розниц', 'цена продажи', 'цена'], 'category' => ['категор', 'группа', 'тип']]],
        'costs' => ['Себестоимость телефонов', ['imei' => ['imei', 'имей'], 'cost' => ['себестоим', 'закуп', 'цена закупа', 'приход'], 'currency' => ['валюта']]],
        'archive_sales' => ['История продаж (архив)', ['date' => ['дата'], 'number' => ['№', 'номер', 'чек'], 'title' => ['товар', 'наименование', 'модель'], 'imei' => ['imei', 'имей'],
            'amount' => ['сумма', 'цена продажи', 'итого'], 'cost' => ['себестоим', 'закуп'], 'phone' => ['телефон'], 'customer' => ['клиент', 'фио']]],
    ];

    public function __construct(private readonly Staging $staging) {}

    /** @return array{0: list<string>, 1: list<array<int, mixed>>} headers and rows */
    public static function read(string $path, string $name): array
    {
        $reader = str_ends_with(mb_strtolower($name), '.csv') ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : $c, $row->toArray());
            }
            break;
        }
        $reader->close();
        // the header is the first row with at least two texts
        $h = 0;
        foreach ($rows as $i => $r) {
            if (count(array_filter($r, fn ($v) => is_string($v) && trim($v) !== '')) >= 2) {
                $h = $i;
                break;
            }
        }

        return [array_map(fn ($v) => trim((string) $v), $rows[$h] ?? []), array_slice($rows, $h + 1)];
    }

    /** Guess our field → column index by the header names. */
    public static function guess(string $kind, array $headers): array
    {
        $map = [];
        foreach (self::KINDS[$kind][1] as $field => $syn) {
            foreach ($headers as $i => $h) {
                $lh = mb_strtolower($h);
                if (! in_array($i, $map, true) && collect($syn)->contains(fn ($s) => str_contains($lh, $s))) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return $map;
    }

    /** @return array{run: ImportRun, rows: int, ok: int, errors: int} */
    public function import(string $kind, string $path, string $name, User $user, ?array $columns = null): array
    {
        abort_unless(isset(self::KINDS[$kind]), 422);
        [$headers, $rows] = self::read($path, $name);
        $columns ??= self::guess($kind, $headers);
        $run = $this->staging->run('excel', $kind, $user, $name, $columns);
        $ok = $err = 0;
        foreach ($rows as $n => $r) {
            $v = fn (string $f) => isset($columns[$f]) ? trim((string) ($r[$columns[$f]] ?? '')) : '';
            if (implode('', array_map('strval', $r)) === '') {
                continue;
            }
            [$key, $fields, $error] = $this->row($kind, $v, $n, $run);
            if ($key === null) {
                continue;
            }
            $this->staging->put($run, $kind, $key, array_combine(array_map(fn ($i) => $headers[$i] ?? 'c'.$i, array_keys($r)), array_values($r)) ?: $r, $fields, $error);
            $error ? $err++ : $ok++;
        }
        $this->staging->finish($run, ['rows' => $ok + $err, 'ok' => $ok, 'error' => $err]);
        activity('migration')->causedBy($user)->withProperties(['kind' => $kind, 'file' => $name, 'ok' => $ok, 'error' => $err])->log('excel.imported');

        return ['run' => $run, 'rows' => $ok + $err, 'ok' => $ok, 'errors' => $err];
    }

    public static function money(string $v): ?int
    {
        $v = str_replace([' ', "\u{00A0}", '₸', '$', 'тг'], '', $v);
        if ($v === '' || ! is_numeric(str_replace(',', '.', $v))) {
            return null;
        }

        return (int) round((float) str_replace(',', '.', $v) * 100);
    }

    private static function phone(string $v): ?string
    {
        try {
            return $v === '' ? null : Phone::normalize($v);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function date(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse(preg_match('/^\d{2}\.\d{2}\.\d{4}/', $v) ? Carbon::createFromFormat('d.m.Y', substr($v, 0, 10))->toDateString() : $v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: ?string, 1: array, 2: ?string} key, normalised fields, error */
    private function row(string $kind, \Closure $v, int $n, ImportRun $run): array
    {
        switch ($kind) {
            case 'customers':
                $phone = self::phone($v('phone'));

                return [$phone ?? 'row'.$n, ['phone' => $phone, 'name' => $v('name') ?: __('Клиент'), 'source' => $v('source') ?: null, 'comment' => $v('comment') ?: null],
                    $phone ? null : __('нет телефона — останется только в архиве')];
            case 'customer_debts':
            case 'bonuses':
                $phone = self::phone($v('phone'));
                $amount = $kind === 'bonuses' ? (int) round(((float) str_replace([' ', ','], ['', '.'], $v('amount'))) * 100) : self::money($v('amount'));

                return [$phone ?? 'row'.$n, ['phone' => $phone, 'name' => $v('name') ?: null, 'amount' => $amount, 'due' => self::date($v('due')), 'comment' => $v('comment') ?: null],
                    ! $phone ? __('нет телефона') : (! $amount ? __('нет суммы') : null)];
            case 'supplier_debts':
                $cur = str_contains(mb_strtolower($v('currency')), 'usd') || str_contains($v('currency'), '$') || str_contains($v('amount'), '$') ? 'USD' : 'KZT';
                $amount = self::money($v('amount'));

                return [mb_strtolower($v('name')).'|'.$cur, ['name' => $v('name'), 'currency' => $cur, 'amount' => $amount, 'due' => self::date($v('due'))],
                    $v('name') === '' ? __('нет поставщика') : (! $amount ? __('нет суммы') : null)];
            case 'accessories':
                $key = $v('barcode') ?: ($v('sku') ?: mb_strtolower($v('name')));

                return [$key ?: 'row'.$n, ['name' => $v('name'), 'sku' => $v('sku') ?: null, 'barcode' => $v('barcode') ?: null, 'qty' => (int) $v('qty'),
                    'cost' => self::money($v('cost')), 'price' => self::money($v('price')), 'category' => $v('category') ?: __('Аксессуары')],
                    $v('name') === '' ? __('нет названия') : (self::money($v('cost')) === null ? __('нет себестоимости') : null)];
            case 'costs':
                $imei = preg_replace('/\D/', '', $v('imei'));
                $usd = str_contains(mb_strtolower($v('currency')), 'usd') || str_contains($v('cost'), '$');

                return [$imei ?: 'row'.$n, ['imei' => $imei, 'cost' => self::money($v('cost')), 'currency' => $usd ? 'USD' : 'KZT'],
                    strlen($imei) !== 15 ? __('нет IMEI') : (self::money($v('cost')) === null ? __('нет себестоимости') : null)];
            case 'archive_sales':
                $date = self::date($v('date'));
                $same = DB::table('sfon_archive')->where('organization_id', $run->organization_id)->where('kind', 'sale')->where('occurred_at', $date)
                    ->where('number', $v('number') ?: null)->where('imei', preg_replace('/\D/', '', $v('imei')) ?: null)->where('amount', self::money($v('amount')))->exists();
                $same || DB::table('sfon_archive')->insert(['organization_id' => $run->organization_id, 'run_id' => $run->id, 'kind' => 'sale', 'month' => $date ? substr($date, 0, 7) : null,
                    'occurred_at' => $date, 'number' => $v('number') ?: null, 'imei' => preg_replace('/\D/', '', $v('imei')) ?: null, 'customer_phone' => self::phone($v('phone')),
                    'title' => mb_substr($v('title'), 0, 200) ?: null, 'amount' => self::money($v('amount')), 'cost' => self::money($v('cost')),
                    'data' => json_encode(['customer' => $v('customer')], JSON_UNESCAPED_UNICODE)]);

                return [null, [], null];
        }

        return [null, [], null];
    }
}
