<?php

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Models\Document;
use App\Domain\Orders\Models\Order;
use App\Domain\Pos\Services\CartService;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Filament\Sales\Pages\Orders;
use App\Filament\Sales\Pages\Returns;
use App\Filament\Sales\Pages\Sales;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function soldPhone($w, $seller = null)
{
    $unit = $w->phone(cost: Money::kzt(400000));
    $doc = SaleDraft::make($w->store, $seller ?? $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($seller ?? $w->seller);

    return [$unit, $doc];
}

it('shows a seller only own checks of 7 days, no profit; a forged id is not opened (Э4)', function () {
    $w = world();
    [, $mine] = soldPhone($w, $w->seller);
    [, $other] = soldPhone($w, $w->seller2);
    $this->actingAs($w->seller);
    Livewire::test(Sales::class)->assertSee($mine->number)->assertDontSee($other->number)
        ->call('open', $mine->id)->assertDontSee('прибыль');
    expect(fn () => Livewire::test(Sales::class)->call('open', $other->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->actingAs($w->owner);
    Livewire::test(Sales::class)->assertSee($other->number)->call('open', $mine->id)->assertSee('прибыль')
        ->set('reverseReason', 'input_error')->call('reverse')->assertSet('error', null);
    expect($mine->refresh()->status->value)->toBe('reversed')->and(Reconciliation::problems())->toBe([]);
});

it('returns a phone: the seller sends a request, the owner allows, the return is posted (scenario 29)', function () {
    $w = world();
    [$unit, $sale] = soldPhone($w);
    $this->actingAs($w->seller);
    $line = SaleLine::query()->where('document_id', $sale->id)->first();

    $page = Livewire::test(Returns::class)->set('find', $sale->number)->call('chooseSale', $sale->id)
        ->set("pick.{$line->id}.on", true)->set("pick.{$line->id}.status", 'in_stock')->call('toMoney')
        ->assertSet('error', fn ($e) => str_contains((string) $e, 'iCloud'))
        ->set("pick.{$line->id}.icloud", true)->call('toMoney')->assertSet('step', 3)
        ->assertSee('Отправить заявку')->call('sendRequest')->assertSet('error', null)->assertSee('Ждём ответа');

    app(ApprovalService::class)->approve(ApprovalRequest::query()->latest('id')->first(), $w->owner, confirmed: true);
    $page->call('pollRequest')->assertSet('step', 5);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock)->and(Reconciliation::problems())->toBe([]);
});

it('exchanges from the wizard: the client takes a dearer phone and pays the difference (scenario 31)', function () {
    $w = world();
    [, $sale] = soldPhone($w);
    $new = $w->phone(cost: Money::kzt(400000));
    $new->forceFill(['sale_price' => Money::kzt(560000)->amount])->save();
    $line = SaleLine::query()->where('document_id', $sale->id)->first();
    $this->actingAs($w->owner);

    Livewire::test(Returns::class)->call('chooseSale', $sale->id)
        ->set("pick.{$line->id}.on", true)->set("pick.{$line->id}.icloud", true)->set('exchange', true)
        ->set('takeCode', $new->imei1)->call('addTake')->call('toMoney')->assertSee('Клиент доплачивает')
        ->set('extraMethod', 'cash')->call('post')->assertSet('error', null)->assertSet('step', 5);

    expect($new->refresh()->status)->toBe(UnitStatus::Sold)->and($w->balance('exchange_offset'))->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);
});

it('creates an order with a prepayment, sends it to the cash register and cancels another one (Э7)', function () {
    $w = world();
    $unit = $w->phone();
    $other = $w->phone();
    $this->actingAs($w->seller);

    $page = Livewire::test(Orders::class)->call('newOrder')->set('form.customer_id', $w->customer->id)
        ->set('form.code', $unit->imei1)->call('findUnit')->set('form.prepay', '50000')->call('save')->assertSet('error', null);
    expect($unit->refresh()->status)->toBe(UnitStatus::Ordered);

    $page->call('newOrder')->set('form.customer_id', $w->customer->id)->set('form.code', $other->imei1)->call('findUnit')->call('save');
    $second = Order::query()->where('stock_unit_id', $other->id)->first();
    $page->call('cancel', $second->id)->assertSet('error', null);
    expect($other->refresh()->status)->toBe(UnitStatus::InStock);

    $order = Order::query()->where('stock_unit_id', $unit->id)->first();
    $page->call('sell', $order->id)->assertRedirect();
    $cart = app(CartService::class)->cart($w->seller, $w->store);
    expect((int) $cart->get('order_document_id'))->toBe($order->document_id)
        ->and(app(CartService::class)->totals($cart)['paid'])->toBe(Money::kzt(50000)->amount);
});
