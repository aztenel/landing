<?php

use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Services\Shifts;
use App\Domain\Pos\Services\CartService;
use App\Domain\Sales\Models\SaleLine;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

function posCart(): CartService
{
    return app(CartService::class);
}

function posWorld(): array
{
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000)); // list price 549 000 (World), above cost
    $w->accessories($w->case, 3, Money::kzt(2000));
    AccessoryPrice::query()->create(['accessory_id' => $w->case->id, 'price_kzt' => Money::kzt(4990)->amount]);
    $cart = posCart()->cart($w->seller, $w->store);

    return [$w, $unit, $cart];
}

it('adds a phone by IMEI scan with a reservation and an accessory by barcode; prices come from the price list (Э3, scenario 8)', function () {
    [$w, $unit, $cart] = posWorld();

    posCart()->scan($cart, $w->seller, $unit->imei1);
    posCart()->scan($cart, $w->seller, $w->case->barcode);
    posCart()->scan($cart, $w->seller, $w->case->barcode);
    expect($unit->refresh()->status)->toBe(UnitStatus::Reserved);

    $t = posCart()->totals($cart->refresh());
    expect($t['total'])->toBe(Money::kzt(549000 + 2 * 4990)->amount)
        ->and($t['lines'][1]['qty'])->toBe(2)
        ->and(array_key_exists('cost', $t['lines'][0]))->toBeFalse();

    // more than in stock
    expect(fn () => posCart()->setQty($cart, $w->seller, 1, 4))->toThrow(PostingException::class, 'только 3 шт.');

    // another seller cannot take the phone from this cart
    $other = posCart()->cart($w->seller2, $w->store);
    expect(fn () => posCart()->scan($other, $w->seller2, $unit->imei1))->toThrow(PostingException::class, 'Уже в корзине у');

    posCart()->payAllWith($cart->refresh(), 'cash');
    $r = posCart()->checkout($cart, $w->seller);
    expect($r['status'])->toBe('posted')
        ->and($r['document']->number)->toStartWith('ПРД-')
        ->and($unit->refresh()->status)->toBe(UnitStatus::Sold)
        ->and($cart->refresh()->isEmpty())->toBeTrue()
        ->and((int) $cart->last_document_id)->toBe($r['document']->id)
        ->and(Reconciliation::problems())->toBe([]);
});

it('posts one check on a double click or a repeat after a lost answer', function () {
    [$w, $unit, $cart] = posWorld();
    posCart()->scan($cart, $w->seller, $unit->imei1);
    posCart()->payAllWith($cart->refresh(), 'cash');

    $before = $cart->refresh()->only(['key', 'version', 'data']);
    $first = posCart()->checkout($cart, $w->seller);
    // the second click arrives with the very same cart (the answer to the first was lost)
    $cart->forceFill($before)->save();
    $second = posCart()->checkout($cart->refresh(), $w->seller);
    expect($second['document']->id)->toBe($first['document']->id)
        ->and(Document::query()->where('type', 'sale')->where('status', 'posted')->count())->toBe(1);
});

it('sends an over-limit discount to the owner and posts after «Разрешить»; a changed check needs a new request (scenario 18)', function () {
    [$w, $unit, $cart] = posWorld();
    posCart()->scan($cart, $w->seller, $unit->imei1);
    posCart()->setCheckDiscount($cart->refresh(), Money::kzt(15000)->amount, 'постоянный клиент');
    posCart()->payAllWith($cart->refresh(), 'cash');

    $r = posCart()->checkout($cart, $w->seller);
    expect($r['status'])->toBe('approval');

    $request = posCart()->requestApproval($cart, $w->seller);
    expect(posCart()->checkout($cart->refresh(), $w->seller)['status'])->toBe('waiting');

    app(ApprovalService::class)->approve($request, $w->owner);
    $r = posCart()->checkout($cart->refresh(), $w->seller);
    expect($r['status'])->toBe('posted')
        ->and((int) \App\Domain\Sales\Models\Sale::query()->where('document_id', $r['document']->id)->value('total'))->toBe(Money::kzt(534000)->amount);

    // the next check: approval for 15 000, then the seller raises the discount — the approval does not cover it
    $unit2 = $w->phone(cost: Money::kzt(400000));
    posCart()->scan($cart->refresh(), $w->seller, $unit2->imei1);
    posCart()->setCheckDiscount($cart->refresh(), Money::kzt(15000)->amount, 'торг');
    posCart()->payAllWith($cart->refresh(), 'cash');
    posCart()->checkout($cart->refresh(), $w->seller);
    app(ApprovalService::class)->approve(posCart()->requestApproval($cart->refresh(), $w->seller), $w->owner);
    posCart()->setCheckDiscount($cart->refresh(), Money::kzt(25000)->amount, 'торг');
    posCart()->payAllWith($cart->refresh(), 'cash');
    expect($cart->refresh()->approval_request_id)->toBeNull()
        ->and(posCart()->checkout($cart, $w->seller)['status'])->toBe('approval');
});

it('refuses an unbalanced payment, debt without a customer and a closed shift', function () {
    [$w, $unit, $cart] = posWorld();
    posCart()->scan($cart, $w->seller, $unit->imei1);
    posCart()->setPayments($cart->refresh(), [['method' => 'cash', 'amount' => Money::kzt(500000)->amount]]);
    expect(fn () => posCart()->checkout($cart->refresh(), $w->seller))->toThrow(PostingException::class, 'не хватает 49');
    expect(fn () => posCart()->setPayments($cart->refresh(), [['method' => 'debt', 'amount' => Money::kzt(549000)->amount]]))
        ->toThrow(PostingException::class, 'выберите клиента');

    // split: cash with change + Kaspi QR
    posCart()->setPayments($cart->refresh(), [
        ['method' => 'cash', 'amount' => Money::kzt(249000)->amount, 'tendered' => Money::kzt(250000)->amount],
        ['method' => 'kaspi_qr', 'amount' => Money::kzt(300000)->amount],
    ]);
    expect(posCart()->totals($cart->refresh())['change'])->toBe(Money::kzt(1000)->amount);

    app(Shifts::class)->close(app(Shifts::class)->current($w->store), $w->owner);
    expect(fn () => posCart()->checkout($cart->refresh(), $w->seller))->toThrow(PostingException::class, 'Смена не открыта');
});

it('sells an order from the cash register: its phone, its customer, the prepayment as a payment (П10.2)', function () {
    [$w, $unit, $cart] = posWorld();
    $doc = \App\Domain\Orders\Drafts\OrderDraft::unit($w->store, $w->seller, $w->customer, $unit, Money::kzt(520000))->prepay('cash', Money::kzt(20000))->post($w->seller);
    $order = \App\Domain\Orders\Models\Order::query()->where('document_id', $doc->id)->first();

    posCart()->loadOrder($cart, $w->seller, $order);
    $t = posCart()->totals($cart->refresh());
    expect($t['total'])->toBe(Money::kzt(520000)->amount) // the order price, not today's 549 000
        ->and($t['paid'])->toBe(Money::kzt(20000)->amount);
    posCart()->setPayments($cart, [['method' => 'prepayment', 'amount' => Money::kzt(20000)->amount], ['method' => 'cash', 'amount' => Money::kzt(500000)->amount]]);
    expect(posCart()->checkout($cart->refresh(), $w->seller)['status'])->toBe('posted')
        ->and($order->refresh()->status)->toBe('sold');
});
