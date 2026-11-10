<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\Document;
use App\Domain\Pos\Services\CartService;
use App\Domain\TradeIn\Models\GridPrice;
use App\Filament\TradeIn\Pages\Grid;
use App\Filament\TradeIn\Pages\NewAct;
use App\Filament\TradeIn\Pages\Preparation;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function validIin(): string
{
    // 12 digits with a valid control digit (the helper decides): try candidates
    foreach (range(0, 9) as $d) {
        $candidate = '90010130012'.$d;
        if (\App\Support\Iin\Iin::isValid($candidate)) {
            return $candidate;
        }
    }

    return '900101300125';
}

function fillAct($page, $w, string $imei, string $amount)
{
    $page->call('pickVariant', $w->usedIphone->id)->set('imei1', $imei)->set('imeiVerified', true)->set('imeiCheck', 'registered')
        ->set('battery', '86')->set('kit', ['box'])->set('grade', 'B')->set('amount', $amount)
        ->set('person', ['phone' => '+7 701 555 44 33', 'name' => 'Асхат Нурланов', 'iin' => validIin(), 'doc' => '045678912', 'consent' => true]);
    foreach (array_keys(NewAct::CHECKS) as $k) {
        $page->set("checklist.$k", 'yes');
    }

    return $page;
}

it('takes a phone in offset from the cash register (F8) and posts the act together with the check (scenario 23)', function () {
    $w = world();
    GridPrice::query()->create(['model_id' => $w->usedIphone->model_id, 'memory_id' => $w->usedIphone->memory_id, 'base_price_kzt' => Money::kzt(210000)->amount]);
    $w->cash(Money::kzt(300000));
    $phone = $w->phone(cost: Money::kzt(400000));
    $this->actingAs($w->owner);
    $svc = app(CartService::class);
    $cart = $svc->cart($w->owner, $w->store);
    $svc->scan($cart, $w->owner, $phone->imei1); // 549 000

    $imei = Imei::withCheckDigit('35288104777777');
    fillAct(Livewire::test(NewAct::class, [])->set('tab', 1)->set('mode', 'offset'), $w, $imei, '200000')
        ->call('submit')->assertSet('error', null)->assertRedirect();
    $cart->refresh();
    expect($svc->totals($cart)['tradein']['amount'])->toBe(Money::kzt(200000)->amount);

    $svc->payAllWith($cart, 'cash');
    expect($svc->checkout($cart->refresh(), $w->owner)['status'])->toBe('posted');
    expect(StockUnit::findByIdentifier($imei)->status)->toBe(UnitStatus::Preparing)
        ->and($w->balance('tradein_offset'))->toBe(0)->and(Reconciliation::problems())->toBe([]);
});

it('buys back for cash from the page and prints the act with a masked IIN for a seller (Ф5 data)', function () {
    $w = world();
    GridPrice::query()->create(['model_id' => $w->usedIphone->model_id, 'memory_id' => $w->usedIphone->memory_id, 'base_price_kzt' => Money::kzt(150000)->amount]);
    $w->cash(Money::kzt(300000));
    $this->actingAs($w->seller);
    $imei = Imei::withCheckDigit('35288104666666');
    $page = fillAct(Livewire::test(NewAct::class), $w, $imei, '140000')->set('mode', 'cash');
    $page->set('checklist.icloud', 'no')->assertSee('С включённым iCloud телефон не принимаем')->set('checklist.icloud', 'yes')
        ->call('submit')->assertSet('error', null);
    $doc = Document::query()->where('type', 'tradein')->where('status', 'posted')->firstOrFail();
    $this->get(route('erp.tradein.print', $doc))->assertOk()->assertSee('Асхат Нурланов')->assertDontSee(validIin())->assertSee(substr(validIin(), -4));
    $this->actingAs($w->owner)->get(route('erp.tradein.print', $doc))->assertSee(validIin());
});

it('prepares a used phone: a cost, then «Готов к продаже» needs a price and госбаза (Э12)', function () {
    $w = world();
    GridPrice::query()->create(['model_id' => $w->usedIphone->model_id, 'memory_id' => $w->usedIphone->memory_id, 'base_price_kzt' => Money::kzt(150000)->amount]);
    $w->cash(Money::kzt(300000));
    $this->actingAs($w->owner);
    $imei = Imei::withCheckDigit('35288104555555');
    fillAct(Livewire::test(NewAct::class), $w, $imei, '140000')->set('mode', 'cash')->call('submit');
    $unit = StockUnit::findByIdentifier($imei);

    Livewire::test(Preparation::class)->assertSee($unit->public_code)
        ->set('costFor', $unit->id)->set('cost.amount', '18000')->set('cost.account_id', $w->account('cash_kzt')->id)->call('saveCost')->assertSet('error', null)
        ->call('ready', $unit->id)->assertSet('error', fn ($e) => str_contains((string) $e, 'цену'))
        ->set("prices.{$unit->id}", '215000')->call('ready', $unit->id)->assertSet('error', fn ($e) => str_contains((string) $e, 'госбазы'));
    \App\Domain\Inventory\Models\ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $imei, 'result' => 'registered', 'checked_by' => $w->owner->id, 'checked_at' => now()]);
    Livewire::test(Preparation::class)->call('ready', $unit->id)->assertSet('error', null);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock)->and((int) $unit->cost_kzt)->toBe(Money::kzt(158000)->amount);
});

it('lets only the owner edit the grid (Э31)', function () {
    $w = world();
    $this->actingAs($w->admin)->get(Grid::getUrl())->assertForbidden();
    $this->actingAs($w->owner);
    Livewire::test(Grid::class)->set('row.model_id', $w->usedIphone->model_id)->set('row.memory_id', $w->usedIphone->memory_id)->set('row.price', '165000')->call('savePrice')
        ->set('deductions.battery_low.value', '15000')->call('saveDeductions');
    expect(GridPrice::query()->count())->toBe(1);
});
