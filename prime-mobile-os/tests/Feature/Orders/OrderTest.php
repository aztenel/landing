<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Notifications\Models\AppNotification;
use App\Domain\Orders\Drafts\OrderDraft;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\Orders;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

function orderOf($doc): Order
{
    return Order::query()->where('document_id', $doc->id)->firstOrFail();
}

it('holds a phone with a prepayment and sells it by the order: the advance pays, the price is the order price (TZ 01 П10.2)', function () {
    $w = world();
    $unit = $w->phone(attributes: ['sale_price' => Money::kzt(699000)->amount]);

    $doc = OrderDraft::unit($w->store, $w->seller, $w->customer, $unit, Money::kzt(699000))->prepay('cash', Money::kzt(50000))->post($w->seller);
    expect($doc->number)->toStartWith('ЗАК-')
        ->and($unit->refresh()->status)->toBe(UnitStatus::Ordered)
        ->and((int) $unit->order_document_id)->toBe($doc->id)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(50000)->amount)
        ->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(-50000)->amount) // our advance
        ->and(orderOf($doc)->status)->toBe('active')
        ->and(orderOf($doc)->hold_until->isAfter(now()->addDays(2)))->toBeTrue();

    // another sale cannot take the held phone
    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(699000))->cash(Money::kzt(699000))->post($w->seller))
        ->toThrow(PostingException::class, 'забронирован');

    // the price went up to 720 000 — the order still sells at 699 000 without a discount
    $unit->forceFill(['sale_price' => Money::kzt(720000)->amount])->save();
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->fromOrder($doc->id)->unit($unit, Money::kzt(699000))
        ->pay('prepayment', Money::kzt(50000))->cash(Money::kzt(649000))->post($w->seller);
    expect($unit->refresh()->status)->toBe(UnitStatus::Sold)
        ->and(SaleLine::query()->where('document_id', $sale->id)->value('list_price_kzt'))->toBe(Money::kzt(699000)->amount)
        ->and(orderOf($doc)->status)->toBe('sold')
        ->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(0);

    // a sold order is not cancelled; reversing the sale opens the order again with its phone
    expect(fn () => app(Reverser::class)->reverse($doc, ReversalReason::Other, 'клиент передумал', $w->owner))->toThrow(ReversalBlocked::class);
    app(Reverser::class)->reverse($sale, ReversalReason::InputError, null, $w->owner);
    expect(orderOf($doc)->status)->toBe('active')
        ->and($unit->refresh()->status)->toBe(UnitStatus::Ordered)
        ->and((int) $unit->order_document_id)->toBe($doc->id)
        ->and(Reconciliation::problems())->toBe([]);
});

it('cancels an order: the prepayment goes back, the phone is free; only the seller of the order or an admin', function () {
    $w = world();
    $unit = $w->phone();
    $doc = OrderDraft::unit($w->store, $w->seller, $w->customer, $unit, Money::kzt(500000))->prepay('cash', Money::kzt(30000))->post($w->seller);

    expect(fn () => app(Reverser::class)->reverse($doc, ReversalReason::Other, 'клиент передумал', $w->seller2))->toThrow(ApprovalRequired::class); // чужой заказ — заявкой
    app(Reverser::class)->reverse($doc, ReversalReason::Other, 'клиент передумал', $w->seller);

    expect($unit->refresh()->status)->toBe(UnitStatus::InStock)
        ->and($unit->order_document_id)->toBeNull()
        ->and(orderOf($doc)->status)->toBe('cancelled')
        ->and($w->balance('cash_kzt'))->toBe(0)
        ->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);
});

it('expires a hold without prepayment and marks one with prepayment overdue; extending makes it active', function () {
    $w = world();
    $free = $w->phone();
    $paid = $w->phone();
    $a = OrderDraft::unit($w->store, $w->seller, $w->customer, $free, Money::kzt(400000))->post($w->seller);
    $b = OrderDraft::unit($w->store, $w->seller, $w->customer, $paid, Money::kzt(400000))->prepay('cash', Money::kzt(20000))->post($w->seller);

    $this->travel(4)->days();
    $this->artisan('prime:orders-tick')->assertSuccessful();
    expect(orderOf($a)->status)->toBe('expired')->and($free->refresh()->status)->toBe(UnitStatus::InStock)
        ->and(orderOf($b)->status)->toBe('overdue')->and($paid->refresh()->status)->toBe(UnitStatus::Ordered)
        ->and(AppNotification::query()->where('type', 'order_overdue')->where('user_id', $w->seller->id)->count())->toBe(1);

    app(Orders::class)->extend(orderOf($b), 2, $w->seller);
    expect(orderOf($b)->status)->toBe('active')->and(orderOf($b)->extended_count)->toBe(1);
});

it('orders a variant that is not in stock and sells any phone of that variant by it', function () {
    $w = world();
    $doc = OrderDraft::variant($w->store, $w->seller, $w->customer, $w->iphone, Money::kzt(600000))->post($w->seller);
    $unit = $w->phone($w->iphone);
    expect(fn () => app(Orders::class)->attachUnit(orderOf($doc), $w->phone($w->usedIphone), $w->seller))->toThrow(PostingException::class, 'этого варианта');
    app(Orders::class)->attachUnit(orderOf($doc), $unit, $w->seller);
    expect($unit->refresh()->status)->toBe(UnitStatus::Ordered);

    SaleDraft::make($w->store, $w->seller)->customer($w->customer)->fromOrder($doc->id)->unit($unit, Money::kzt(600000))
        ->cash(Money::kzt(600000))->post($w->seller);
    expect(orderOf($doc)->status)->toBe('sold');
});
