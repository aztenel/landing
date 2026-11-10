<?php

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Migration\Models\ImportRow;
use App\Domain\Migration\Services\DayX;
use App\Domain\Migration\Services\ExcelImport;
use App\Domain\Migration\Services\SfonPull;
use App\Domain\Migration\Services\SfonReconcile;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Filament\Migration\Pages\Migration;
use App\Support\Exceptions\UserFacingException;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeSfon(int $newCount = 2): void
{
    // a second call changes the stock that sfon answers (the first fake callback stays registered and reads it)
    config(['services.sfon.key' => 'test-key', 'services.sfon.pause_ms' => 0, 'services.sfon.test_new_count' => $newCount]);
    Http::fake(function ($req) {
        $newCount = config('services.sfon.test_new_count');
        expect($req->header('X-API-Key')[0] ?? null)->toBe('test-key');
        $p = parse_url($req->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($p, '/v1/company') => Http::response(['companyDto' => ['companyName' => 'Prime Mobile'], 'siteSettingsDto' => ['Текст гарантийного талона' => 'Гарантия 12 месяцев'],
                'stores' => [['id' => 77, 'storeName' => 'ТРЦ «Керуен»', 'address' => 'Достык 9', 'addressLink' => 'https://2gis.kz/x', 'telephone' => '+77010000000', 'telephoneWhatsapp' => '+77015550505', 'isHideImNew' => false, 'isHideImOld' => false]]]),
            str_ends_with($p, '/dictionaries/memories') => Http::response([['id' => 1, 'nameRu' => '256GB'], ['id' => 2, 'nameRu' => '128GB']]),
            str_ends_with($p, '/dictionaries/colors') => Http::response([['id' => 11, 'nameRu' => 'Black Titanium', 'nameKz' => 'Қара титан'], ['id' => 12, 'nameRu' => 'Midnight']]),
            str_ends_with($p, '/dictionaries/rams') => Http::response([]),
            str_ends_with($p, '/dictionaries/product-types') => Http::response([['id' => 5, 'code' => 'smartphone', 'nameRu' => 'Смартфон', 'nameKz' => 'Смартфон']]),
            str_ends_with($p, '/product-types/5/families') => Http::response([['id' => 50, 'nameRu' => 'iPhone']]),
            str_ends_with($p, '/families/50/models') => Http::response([['id' => 500, 'nameRu' => 'iPhone 15 Pro', 'nameEn' => 'iPhone 15 Pro'], ['id' => 501, 'nameRu' => 'iPhone 13']]),
            str_ends_with($p, '/products/search') => Http::response(['content' => [
                ['state' => 'NEW', 'modelId' => 500, 'modelName' => 'iPhone 15 Pro', 'memoryName' => '256GB', 'colorName' => 'Black Titanium', 'count' => $newCount, 'averagePrice' => 549000],
                ['state' => 'OLD', 'productId' => 9001, 'modelId' => 501, 'modelName' => 'iPhone 13', 'memoryName' => '128GB', 'colorName' => 'Midnight', 'count' => 1, 'averagePrice' => 219000, 'batteryState' => 87, 'year' => 2021],
            ], 'last' => true]),
            str_contains($p, '/products/old/9001') => Http::response(['productInfo' => ['description' => 'Царапина на рамке', 'photos' => [['url' => 'https://shop.sfon.kz/storage/1.jpg']], 'batteryState' => 87, 'year' => 2021]]),
            default => Http::response(['message' => 'not found'], 404),
        };
    });
}

function csvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
    file_put_contents($path, $content);

    return $path;
}

it('pulls sfon: stores, dictionary and stock into raw rows, without touching the live journals', function () {
    $w = world();
    fakeSfon();
    $t = app(SfonPull::class)->all($w->owner);

    expect($t['stores'])->toBe(1)->and($t['new_units'])->toBe(2)->and($t['used'])->toBe(1)->and($t['errors'])->toBe(0)
        ->and($w->store->refresh()->sfon_id)->toBe(77)->and($w->store->meta['whatsapp'])->toBe('+77015550505')
        ->and(ImportRow::query()->where('kind', 'stock_used')->first()->fields['description'])->toBe('Царапина на рамке')
        ->and(StockUnit::query()->count())->toBe(0);

    app(SfonPull::class)->all($w->owner);   // repeatable: no duplicates
    expect(ImportRow::query()->whereIn('kind', ['stock_new', 'stock_used'])->count())->toBe(2);
});

it('imports Excel exports by the header names: customers, debts, bonuses, accessories, archive', function () {
    $w = world();
    $x = app(ExcelImport::class);
    $r = $x->import('customers', csvFile("ФИО,Телефон,Источник\nАсхат,8 701 111 22 33,Instagram\nБез телефона,,\n"), 'clients.csv', $w->owner);
    expect($r['rows'])->toBe(2)->and($r['errors'])->toBe(1);
    $x->import('customer_debts', csvFile("Клиент,Телефон,Сумма долга,Срок\nАсхат,+77011112233,70 000,20.11.2026\n"), 'debts.csv', $w->owner);
    $x->import('bonuses', csvFile("Клиент,Телефон,Бонусы\nАсхат,+77011112233,5000\n"), 'bonus.csv', $w->owner);
    $x->import('accessories', csvFile("Наименование,Штрихкод,Остаток,Цена закупа,Цена продажи,Категория\nЧехол 15 Pro,4601234567893,10,2000,6000,Чехлы\n"), 'acc.csv', $w->owner);
    $x->import('archive_sales', csvFile("Дата,№ чека,Товар,IMEI,Сумма\n12.03.2025,1001,iPhone 12,356938035643809,300000\n"), 'sales-2025-03.csv', $w->owner);
    $x->import('archive_sales', csvFile("Дата,№ чека,Товар,IMEI,Сумма\n12.03.2025,1001,iPhone 12,356938035643809,300000\n"), 'sales-2025-03.csv', $w->owner);

    $debt = ImportRow::query()->where('kind', 'customer_debts')->first()->fields;
    expect($debt['phone'])->toBe('+77011112233')->and($debt['amount'])->toBe(7000000)->and($debt['due'])->toBe('2026-11-20')
        ->and(ImportRow::query()->where('kind', 'accessories')->first()->fields['cost'])->toBe(200000)
        ->and(DB::table('sfon_archive')->count())->toBe(1);
});

it('runs day X: scan takes sfon rows, extras are recorded, the ВХО posts units, accessories, money, debts and bonuses', function () {
    $w = world();
    fakeSfon();
    app(SfonPull::class)->all($w->owner);
    $x = app(ExcelImport::class);
    $x->import('customer_debts', csvFile("Клиент,Телефон,Сумма\nАсхат,+77011112233,70000\n"), 'd.csv', $w->owner);
    $x->import('bonuses', csvFile("Клиент,Телефон,Бонусы\nАсхат,+77011112233,5000\n"), 'b.csv', $w->owner);
    $x->import('accessories', csvFile("Наименование,Штрихкод,Остаток,Цена закупа,Цена продажи\nЧехол 15 Pro,4601234567893,10,2000,6000\n"), 'a.csv', $w->owner);
    $x->import('supplier_debts', csvFile("Поставщик,Сумма,Валюта\nDubai Mobile,1000,USD\n"), 's.csv', $w->owner);

    $dx = app(DayX::class);
    $group = ImportRow::query()->where('kind', 'stock_new')->first();
    $used = ImportRow::query()->where('kind', 'stock_used')->first();
    $group->update(['fields' => $group->fields + ['cost' => Money::kzt(489250)->amount]]);
    $used->update(['fields' => $used->fields + ['cost' => Money::kzt(150000)->amount]]);
    $i = fn ($n) => Imei::withCheckDigit('35693923447'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));

    expect($dx->scan($w->store, '123', $w->owner)['result'])->toBe('bad')
        ->and($dx->scan($w->store, $i(1), $w->owner)['result'])->toBe('choose');
    expect($dx->takeNew($w->store, $i(1), $group->id, $w->owner)['result'])->toBe('ok')
        ->and($dx->takeNew($w->store, $i(2), $group->id, $w->owner)['result'])->toBe('ok')
        ->and($dx->takeNew($w->store, $i(3), $group->id, $w->owner)['result'])->toBe('full')
        ->and($dx->scan($w->store, $i(1), $w->owner)['result'])->toBe('dup')
        ->and($dx->scan($w->store, $i(4), $w->owner, $used->id)['result'])->toBe('ok');
    $dx->extra($w->store, $i(5), $w->iphone->id, 'new', Money::kzt(549000)->amount, null, $w->owner);

    $q = $dx->quality();
    expect($q['scanned'])->toBe(4)->and($q['extra'])->toBe(1)->and($q['not_found'])->toBe(0)->and($q['no_cost'])->toBe(1);

    expect(fn () => $dx->post($w->store, [], $w->owner))->toThrow(UserFacingException::class, 'Ш6');
    DB::table('migration_checklist')->insert(['organization_id' => $w->org->id, 'step' => 'Ш6', 'status' => 'done', 'done_by' => $w->owner->id, 'done_at' => now()]);
    expect(fn () => $dx->post($w->store, [], $w->admin))->toThrow(UserFacingException::class, 'владелец');
    $w->rate('515');
    $doc = $dx->post($w->store, [$w->account('cash_kzt')->id => Money::kzt(1240000)->amount], $w->owner);

    expect($doc->status->value)->toBe('posted')
        ->and(StockUnit::query()->count())->toBe(4)
        ->and(StockUnit::findByIdentifier($i(4))->condition->value)->toBe('used')
        ->and(StockUnit::findByIdentifier($i(4))->sfon_id)->toBe(9001)
        ->and(StockUnit::findByIdentifier($i(5))->cost_unknown)->toBeTrue()
        ->and((int) $w->accessoryQty(Accessory::query()->where('barcode', '4601234567893')->first()))->toBe(10)
        ->and($w->debt('customer', Customer::query()->where('phone', '+77011112233')->value('id'))->balance)->toBe(Money::kzt(70000)->amount)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(1240000)->amount)
        ->and((int) $w->bonuses(Customer::query()->where('phone', '+77011112233')->first())->available)->toBe(Money::kzt(5000)->amount)
        ->and(ImportRow::query()->where('status', 'in_vxo')->count())->toBeGreaterThan(3);
    expect(fn () => $dx->post($w->store, [], $w->owner))->toThrow(UserFacingException::class, 'уже проведены');
});

it('checks nightly with sfon: equal stock — 0, a sale not repeated in sfon — a difference with our document', function () {
    $w = world();
    fakeSfon(1);
    app(SfonPull::class)->all($w->owner);
    $group = ImportRow::query()->where('kind', 'stock_new')->first();
    $group->update(['fields' => $group->fields + ['cost' => Money::kzt(489250)->amount]]);
    $used = ImportRow::query()->where('kind', 'stock_used')->first();
    $imei = Imei::withCheckDigit('35693923447001');
    $dx = app(DayX::class);
    $dx->takeNew($w->store, $imei, $group->id, $w->owner);
    $dx->scan($w->store, Imei::withCheckDigit('35693923447002'), $w->owner, $used->id);
    DB::table('migration_checklist')->insert(['organization_id' => $w->org->id, 'step' => 'Ш6', 'status' => 'done', 'done_at' => now()]);
    $dx->post($w->store, [], $w->owner);

    expect(app(SfonReconcile::class)->run()['diffs'])->toBe([]);
    $unit = StockUnit::findByIdentifier($imei);
    ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $imei, 'result' => 'registered', 'checked_by' => $w->owner->id, 'checked_at' => now()]);
    $sale = SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $r = app(SfonReconcile::class)->run();
    expect($r['diffs'])->toHaveCount(1)->and($r['diffs'][0]['text'])->toContain('sfon 1, у нас 0')->and($r['diffs'][0]['cause'])->toContain($sale->number)
        ->and($r['streak'])->toBe(0);
});

it('the screen «Перенос» is for the owner only and shows the quality report', function () {
    $w = world();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($w->admin)->get(Migration::getUrl())->assertForbidden();
    $this->actingAs($w->owner);
    Livewire::test(Migration::class)->assertSee('Ш6')->call('toggleStep', 'Ш0')
        ->set('tab', 'files')->assertSee('Ключ API sfon не задан')
        ->set('tab', 'fix')->assertSee('Без себестоимости')
        ->set('tab', 'scan')->set('imei', '111')->call('scan')->assertSee('IMEI с ошибкой')
        ->set('tab', 'off')->call('switchOff')->assertSee('все 7 условий');
    expect(DB::table('migration_checklist')->where('step', 'Ш0')->value('status'))->toBe('done');
});

// ТЗ 07 §6, раздел J (приёмка).

it('57: day X — sfon has 8, scanned 7 + 1 unknown IMEI: 7 taken, 1 «Не найдено», 1 «Лишний» asks «Какой это телефон?»; the ВХО waits for it', function () {
    $w = world();
    fakeSfon(8);
    app(SfonPull::class)->all($w->owner);
    $group = ImportRow::query()->where('kind', 'stock_new')->first();
    $group->update(['fields' => $group->fields + ['cost' => Money::kzt(489250)->amount]]);
    ImportRow::query()->where('kind', 'stock_used')->update(['status' => 'skipped']);
    DB::table('migration_checklist')->insert(['organization_id' => $w->org->id, 'step' => 'Ш6', 'status' => 'done', 'done_at' => now()]);
    $dx = app(DayX::class);
    $i = fn ($n) => Imei::withCheckDigit('35693923448'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
    foreach (range(1, 7) as $n) {
        expect($dx->scan($w->store, $i($n), $w->owner)['result'])->toBe('choose');
        expect($dx->takeNew($w->store, $i($n), $group->id, $w->owner)['result'])->toBe('ok');
    }
    $unknown = $dx->scan($w->store, $i(99), $w->owner);
    expect($unknown['result'])->toBe('choose')->and($unknown['message'])->toBe('Какой это телефон?');

    $q = $dx->quality();
    expect($q['not_found'])->toBe(1)->and($q['unresolved'])->toBe(1)->and($q['scanned'])->toBe(8);
    $w->rate('515');
    expect(fn () => $dx->post($w->store, [], $w->owner))->toThrow(UserFacingException::class, 'Сначала разберите «Лишние»');

    // the owner answers «Какой это телефон?» — then the ВХО posts: 7 sfon phones + 1 extra
    expect($dx->scan($w->store, $i(99), $w->owner)['result'])->toBe('choose'); // asked again, not «уже отсканирован»
    $dx->extra($w->store, $i(99), $w->usedIphone->id, 'used', Money::kzt(219000)->amount, Money::kzt(150000)->amount, $w->owner);
    expect($dx->quality()['unresolved'])->toBe(0);
    $dx->post($w->store, [], $w->owner);
    expect(StockUnit::query()->count())->toBe(8);
});

it('58: the nightly check — sfon has a sale we do not: «sfon 3, у нас 4», «вероятная причина не найдена», streak 0', function () {
    $w = world();
    fakeSfon(4);
    app(SfonPull::class)->all($w->owner);
    $group = ImportRow::query()->where('kind', 'stock_new')->first();
    $group->update(['fields' => $group->fields + ['cost' => Money::kzt(489250)->amount]]);
    ImportRow::query()->where('kind', 'stock_used')->update(['status' => 'skipped']);
    DB::table('migration_checklist')->insert(['organization_id' => $w->org->id, 'step' => 'Ш6', 'status' => 'done', 'done_at' => now()]);
    $dx = app(DayX::class);
    foreach (range(1, 4) as $n) {
        $dx->takeNew($w->store, Imei::withCheckDigit('35693923449'.str_pad((string) $n, 3, '0', STR_PAD_LEFT)), $group->id, $w->owner);
    }
    $dx->post($w->store, [], $w->owner);

    fakeSfon(3); // a sale in sfon that was not repeated here
    $r = app(SfonReconcile::class)->run();
    expect(collect($r['diffs'])->pluck('text')->implode(' | '))->toContain('sfon 3, у нас 4')
        ->and(collect($r['diffs'])->firstWhere('kind', 'count')['cause'])->toContain('вероятная причина не найдена')
        ->and($r['streak'])->toBe(0);
});
