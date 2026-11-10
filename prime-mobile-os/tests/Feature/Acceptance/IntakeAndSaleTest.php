<?php

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Filament\Intake\Pages\NewIntake;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

// ТЗ 07 §6, раздел A: сценарии 1–4.

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function boxImei(int $n): string
{
    return Imei::withCheckDigit('35281004'.str_pad((string) $n, 6, '0', STR_PAD_LEFT));
}

it('1: 10 boxes in $ at 515 — «10 из 10», cost 515 000 each, $10 000 / 5 150 000 ₸; margin 74 000 for the owner only', function () {
    $w = world();
    $w->rate('515');
    $this->actingAs($w->owner);
    $page = Livewire::test(NewIntake::class)
        ->set('head.supplier_id', $w->supplier->id)->set('head.currency', 'USD')->set('head.pay', 'credit')->set('head.due', now()->addDays(14)->toDateString())
        ->call('pickVariant', 0, $w->iphone->id)->set('batches.0.price', '1000')->set('batches.0.sale_price', '589000')->set('batches.0.expected', '10');
    foreach (range(1, 10) as $n) {
        $page->call('scan', boxImei($n))->assertSet('scanError', null);
    }
    $page->assertSee('10 из 10')->assertSee("маржа 74 000 ₸ на штуку")
        ->call('askConfirm')->assertSet('error', null)->call('post')->assertSet('error', null);

    $units = StockUnit::query()->whereIn('imei1', array_map('boxImei', range(1, 10)))->get();
    expect($units)->toHaveCount(10)
        ->and($units->every(fn ($u) => (int) $u->cost_kzt === Money::kzt(515000)->amount))->toBeTrue()
        ->and($units->sum('cost_kzt'))->toBe(Money::kzt(5150000)->amount)
        ->and((int) $w->debt('supplier', $w->supplier->id, 'USD')->balance)->toBe(Money::usd(-10000)->amount)
        ->and(Reconciliation::problems())->toBe([]);

    // the seller sees neither the purchase price nor the margin
    $this->actingAs($w->seller);
    Livewire::test(NewIntake::class)->set('head.supplier_id', $w->supplier->id)->call('pickVariant', 0, $w->iphone->id)
        ->set('batches.0.sale_price', '589000')->assertDontSee('маржа')->assertDontSee('Закуп,');
});

it('2–3: a repeated IMEI and a wrong Luhn digit are refused with a message, the counter stays', function () {
    $w = world();
    $w->rate('515');
    $this->actingAs($w->admin);
    $page = Livewire::test(NewIntake::class)->set('head.supplier_id', $w->supplier->id)->set('head.currency', 'USD')
        ->call('pickVariant', 0, $w->iphone->id)->set('batches.0.expected', '10')
        ->call('scan', boxImei(1))->call('scan', boxImei(2))->call('scan', boxImei(3))
        ->call('scan', boxImei(3))->assertSet('scanError', 'Уже отсканирован в этой партии (строка 3)')->assertDispatched('pm-intake-bad')
        ->assertSee('3 из 10')
        ->call('scan', '356789012345671')->assertSet('scanError', fn ($e) => str_starts_with((string) $e, 'IMEI с ошибкой'));
    expect($page->get('batches')[0]['rows'])->toHaveCount(3);
});

it('4: an IMEI sold on 12.10.2026 by Дана — «уже есть … продан 12.10.2026, Дана Продавец», a link to the passport, no new unit', function () {
    $w = world();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-12 12:00', config('app.timezone')));
    app(\App\Domain\Ledger\Services\Shifts::class)->current($w->store) ?? app(\App\Domain\Ledger\Services\Shifts::class)->open($w->store, $w->owner);
    $unit = $w->phone(cost: Money::kzt(400000));
    SaleDraft::make($w->store, $w->seller2)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller2);
    $this->travelBack();

    $this->actingAs($w->admin);
    Livewire::test(NewIntake::class)->set('head.supplier_id', $w->supplier->id)->call('pickVariant', 0, $w->iphone->id)
        ->call('scan', $unit->imei1)
        ->assertSet('scanError', fn ($e) => str_contains((string) $e, $unit->public_code) && str_contains((string) $e, '«Продан» 12.10.2026') && str_contains((string) $e, '12.10.2026, Дана Продавец'))
        ->assertSee('Открыть паспорт?')->assertSee(route('erp.stock.units.show', $unit->public_code), false)
        ->assertSet('batches.0.rows', []);
    expect(StockUnit::query()->where('imei1', $unit->imei1)->count())->toBe(1);
});

it('7: a seller receives 20 glasses without the cost — posted, «себестоимость не задана»; the sale is blocked until the admin re-posts with prices', function () {
    $w = world();
    $intake = \App\Domain\Inventory\Drafts\PurchaseDraft::make($w->store, $w->supplier)->accessory($w->glass, 20, null)->post($w->seller);
    expect($w->accessoryQty($w->glass))->toBe(20);

    expect(fn () => SaleDraft::make($w->store, $w->seller)->accessory($w->glass, 1, Money::kzt(2990))->cash(Money::kzt(2990))->post($w->seller))
        ->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class, 'нужна себестоимость — сообщите админу');

    // the admin cancels that intake and receives it again with the price; now they sell with the right cost
    app(\App\Domain\Ledger\Posting\Reverser::class)->reverse($intake, 'other', 'Нет цены закупа', $w->admin);
    \App\Domain\Inventory\Drafts\PurchaseDraft::make($w->store, $w->supplier)->accessory($w->glass, 20, Money::kzt(900))->onCredit(now()->addDays(7))->post($w->admin);
    SaleDraft::make($w->store, $w->seller)->accessory($w->glass, 1, Money::kzt(2990))->cash(Money::kzt(2990))->post($w->seller);
    expect($w->accessoryAvg($w->glass))->toBe(Money::kzt(900)->amount)->and(Reconciliation::problems())->toBe([]);
});

it('7: the list of intakes marks a seller intake «себестоимость не задана»', function () {
    $w = world();
    \App\Domain\Inventory\Drafts\PurchaseDraft::make($w->store, $w->supplier)->accessory($w->glass, 20, null)->post($w->seller);
    $this->actingAs($w->admin);
    Livewire::test(\App\Filament\Intake\Pages\Intakes::class)->assertSee('себестоимость не задана');
});

it('6: Excel of 50 rows, unknown model in rows 7 and 31, a repeated IMEI in row 12 — 47 ok, 3 errors with the row text; adding waits for a fix', function () {
    $w = world();
    $this->actingAs($w->owner);
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new OpenSpout\Writer\XLSX\Writer;
    $writer->openToFile($path);
    $writer->addRow(OpenSpout\Common\Entity\Row::fromValues(['Модель', 'Память', 'Цвет', 'IMEI 1', 'IMEI 2', 'Состояние', 'Цена', 'Розница']));
    foreach (range(1, 50) as $n) {
        $model = in_array($n, [7, 31], true) ? 'Nokia 3310' : 'iPhone 15 Pro';
        $imei = $n === 12 ? boxImei(500 + 3) : boxImei(500 + $n);
        $writer->addRow(OpenSpout\Common\Entity\Row::fromValues([$model, '256 ГБ', 'Black Titanium', $imei, '', 'новый', 400000, 549000]));
    }
    $writer->close();

    $page = Livewire::test(NewIntake::class)->set('file', \Illuminate\Http\UploadedFile::fake()->createWithContent('p.xlsx', file_get_contents($path)))->call('importFile');
    $preview = collect($page->get('preview'));
    $errors = $preview->whereNotNull('error');
    expect($preview)->toHaveCount(50)->and($errors)->toHaveCount(3)
        ->and($errors->pluck('error')->implode(' | '))->toContain('Неизвестная модель')->toContain('Дубль IMEI (строка 4)'); // Excel row numbers: the header is row 1
    $page->assertSee('47 ок')->assertSee('3 с ошибкой')
        ->call('usePreview')->assertSet('error', fn ($e) => str_contains((string) $e, 'Ошибок: 3'));

    foreach ($errors->keys()->sortDesc() as $i) {
        $page->call('dropPreviewRow', $i);
    }
    $page->call('usePreview')->assertSet('error', null);
    expect(collect($page->get('batches'))->sum(fn ($b) => count($b['rows'])))->toBe(47);
});
