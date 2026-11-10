<?php

use App\Domain\Storefront\Services\Photos;
use App\Domain\Storefront\Support\SiteChange;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// ТЗ 07 §6, сценарий 56 (= ТЗ 05 п. 15, № 8): no page of the site has 14–15 digits in a row or the word «себестоимость».

beforeEach(function () {
    config(['app.url' => 'http://localhost']);
    URL::forceRootUrl('http://localhost');
    Storage::fake('public');
    Storage::fake('local');
});

function crawlJpeg(): UploadedFile
{
    $im = imagecreatetruecolor(1200, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 210, 210, 220));
    ob_start();
    imagejpeg($im, null, 85);

    return UploadedFile::fake()->createWithContent('p.jpg', ob_get_clean());
}

it('56: crawls every page of the site (ru and kk) — no IMEI-like 14–15 digits, no cost words, no supplier', function () {
    $w = world();
    $w->org->settings = array_replace_recursive($w->org->settings ?? [], ['storefront' => ['enabled' => true]]);
    $w->org->save();
    CurrentOrganization::instance()->set($w->org->refresh());
    $w->store->update(['phone' => '+77010000001', 'meta' => ['whatsapp' => '+77015550505', 'hours' => '10:00–21:00']]);
    $new = $w->phone();
    $used = $w->phone($w->usedIphone, Money::kzt(150000), ['condition' => 'used', 'grade' => 'B', 'battery_percent' => 87, 'sale_price' => Money::kzt(219000)]);
    app(Photos::class)->upload(['stock_unit_id' => $used->id], crawlJpeg(), $w->owner);
    app(Photos::class)->upload(['model_id' => $w->iphone->model_id, 'color_id' => $w->iphone->color_id], crawlJpeg(), $w->owner);
    SiteChange::all('test');
    app(OutboxDispatcher::class)->run(500);

    $queue = ['/', '/kk', '/sitemap.xml', '/search?q=iphone', '/phone/'.$used->public_code, '/phone/'.$new->public_code];
    $seen = [];
    while ($queue && count($seen) < 80) {
        $path = array_shift($queue);
        if (isset($seen[$path])) {
            continue;
        }
        $res = $this->get('http://localhost'.$path);
        $seen[$path] = $res->getStatusCode();
        if ($res->getStatusCode() !== 200) {
            continue;
        }
        $html = (string) $res->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style)[^>]*>.*?</\1>#si', ' ', $html)));
        expect(preg_match('/(?<!\d)\d{14,15}(?!\d)/', $text, $m))->toBe(0, "{$path}: {$text}")
            ->and(preg_match('/(?<![\d.])\d{14,15}(?![\d])/', preg_replace('#(src|href|srcset)="[^"]*"#', '', $html)))->toBe(0, $path)
            ->and(mb_stripos($html, 'себестоим'))->toBeFalse($path)
            ->and(mb_stripos($html, 'өзіндік құн'))->toBeFalse($path)
            ->and(mb_stripos($html, $w->supplier->name))->toBeFalse($path)
            ->and(str_contains($html, $new->imei1) || str_contains($html, $used->imei1))->toBeFalse($path);
        preg_match_all('#(?:href|loc)[=>]"?(?:http://localhost)?(/[^"<\s\#]*)#', $html, $links);
        foreach ($links[1] as $link) {
            if (! preg_match('#^/(storage|build|go/|r/|preview)#', $link) && ! str_ends_with($link, '.webp') && ! str_ends_with($link, '.jpg')) {
                $queue[] = html_entity_decode($link);
            }
        }
    }
    expect(count(array_filter($seen, fn ($s) => $s === 200)))->toBeGreaterThan(8);
    expect(array_keys(array_filter($seen, fn ($s) => $s >= 500)))->toBe([]);
});
