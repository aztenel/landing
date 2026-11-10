<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Filament\Stock\Pages\Accessories;
use App\Filament\Stock\Pages\Phones;
use App\Livewire\Stock\UnitPassport;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('lists the phones; the seller sees no cost and no supplier in HTML, Livewire state and Excel (Э8)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(412345)); // 412 345 ₸ — a number that appears nowhere else

    $this->actingAs($w->owner);
    Livewire::test(Phones::class)->assertSee($unit->public_code)->assertSee("412\u{00A0}345")->assertSee('Dubai Mobile');

    $this->actingAs($w->seller);
    $page = Livewire::test(Phones::class)->assertSee($unit->public_code)->assertDontSee("412\u{00A0}345")->assertDontSee('Dubai Mobile');
    expect(json_encode($page->snapshot))->not->toContain('41234500')->not->toContain('Dubai');

    $xlsx = $this->get(route('erp.stock.export'))->assertOk()->streamedContent();
    $zip = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($zip, $xlsx);
    $sheet = (new ZipArchive)->open($zip) === true ? (function () use ($zip) { $z = new ZipArchive; $z->open($zip); return $z->getFromName('xl/sharedStrings.xml').$z->getFromName('xl/worksheets/sheet1.xml'); })() : '';
    expect($sheet)->toContain((string) $unit->imei1)->not->toContain('Dubai')->not->toContain('412345')->not->toContain('Себестоимость');
});

it('filters by госбаза and finds a phone by the last digits of IMEI', function () {
    $w = world();
    $fresh = $w->phone();
    $none = $w->phone(imeiCheck: false);
    $this->actingAs($w->owner);

    Livewire::test(Phones::class)->set('imei', 'none')->assertSee($none->public_code)->assertDontSee($fresh->public_code)
        ->set('imei', '')->set('q', substr($fresh->imei1, -6))->assertSee($fresh->public_code)->assertDontSee($none->public_code);
});

it('opens the passport by PM-code and IMEI, marks госбаза, changes the price; status only by the table (Э10)', function () {
    $w = world();
    $unit = $w->phone(imeiCheck: false);
    $this->actingAs($w->seller);

    $this->get(route('erp.stock.units.show', $unit->public_code))->assertOk()->assertSee($unit->imei1)->assertSee('нет отметки госбазы');
    $this->get(route('erp.stock.units.show', $unit->imei1))->assertOk();
    $this->get(route('erp.stock.units.show', 'PM-99999'))->assertNotFound();

    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->call('imei', 'registered')->assertSet('error', null)->assertSee('Можно продавать');
    expect(ImeiCheck::query()->where('stock_unit_id', $unit->id)->count())->toBe(1);

    // a seller has no right to change the price
    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->assertDontSee('Сменить цену');
    $this->actingAs($w->owner);
    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->set('newPrice', '520 000')->call('price')->assertSet('error', null);
    expect($unit->refresh()->sale_price)->toBe(Money::kzt(520000)->amount);

    // «Готов к продаже» is not allowed from «в наличии»
    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->call('ready')->assertSet('error', fn ($e) => $e !== null);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock);
});

it('does not open a phone of another firm', function () {
    $w = world();
    $unit = $w->phone();
    $other = world();
    $this->actingAs($other->owner);
    $this->get(route('erp.stock.units.show', $unit->public_code))->assertNotFound();
});

it('lists accessories with the stock and hides the average cost from the seller (Э9)', function () {
    $w = world();
    $w->accessories($w->case, 7, Money::kzt(2345));
    $this->actingAs($w->seller);
    Livewire::test(Accessories::class)->assertSee('Чехол iPhone 15 Pro')->assertSee('7 шт.')->assertDontSee("2\u{00A0}345");
    $this->actingAs($w->owner);
    Livewire::test(Accessories::class)->assertSee("2\u{00A0}345");
});
