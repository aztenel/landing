<?php

use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;
use Tests\Support\World;

function reverser(): Reverser
{
    return app(Reverser::class);
}

it('reverses a sale: unit back in stock, money and bonuses back, line inactive (TZ 02 §14 №2)', function () {
    $w = world();
    PaymentMethod::query()->where('code', 'kaspi_qr')->update(['commission_percent' => '1.00']);
    $unit = $w->phone();
    $w->accessories($w->case, 3, Money::kzt(2400));
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)
        ->unit($unit, Money::kzt(549000))->accessory($w->case, 1, Money::kzt(9000))->discount(Money::kzt(3000))
        ->pay('kaspi_qr', Money::kzt(200000))->cash(Money::kzt(355000))->post($w->seller);
    expect($w->bonuses($w->customer)->pending)->toBeGreaterThan(0);

    $reversal = reverser()->reverse($sale, ReversalReason::InputError, null, $w->admin);

    expect($reversal->number)->toStartWith('СТН-')
        ->and($reversal->reversal_of_document_id)->toBe($sale->id)
        ->and($reversal->occurred_at->equalTo($sale->occurred_at))->toBeTrue()   // same day: report of that day is corrected
        ->and($sale->refresh()->status)->toBe(DocumentStatus::Reversed)
        ->and($sale->reversed_by_document_id)->toBe($reversal->id)
        ->and($unit->refresh()->status)->toBe(UnitStatus::InStock)
        ->and($unit->isInStore($w->store->id))->toBeTrue()
        ->and($w->accessoryQty($w->case))->toBe(3)
        ->and($w->balance('cash_kzt'))->toBe(0)
        ->and($w->balance('kaspi_qr'))->toBe(0)
        ->and((int) $w->bonuses($w->customer)->pending)->toBe(0)
        ->and(SaleLine::query()->where('document_id', $sale->id)->where('is_active', true)->count())->toBe(0);

    // every journal of the pair nets to zero (TZ 02 §13.2 check 10)
    $docs = [$sale->id, $reversal->id];
    expect((int) MoneyEntry::query()->whereIn('document_id', $docs)->sum('amount'))->toBe(0)
        ->and((int) InventoryMovement::query()->whereIn('document_id', $docs)->sum('qty'))->toBe(0)
        ->and((int) BonusEntry::query()->whereIn('document_id', $docs)->sum('amount'))->toBe(0);

    // the phone can be sold again
    $again = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    expect($again->isPosted())->toBeTrue();
});

it('forbids reversing a reversal, a reversed document, and requires a reason', function () {
    $w = world();
    $doc = $w->cash(Money::kzt(1000));

    expect(fn () => reverser()->reverse($doc, ReversalReason::Other, 'abc', $w->owner))->toThrow(PostingException::class, '5 символов');
    $reversal = reverser()->reverse($doc, ReversalReason::Other, 'введено дважды', $w->owner);

    expect(fn () => reverser()->reverse($doc, ReversalReason::Duplicate, null, $w->owner))->toThrow(ReversalBlocked::class, 'уже сторнирован')
        ->and(fn () => reverser()->reverse($reversal, ReversalReason::Duplicate, null, $w->owner))->toThrow(ReversalBlocked::class, 'Сторно сторно');
});

it('blocks reversing a purchase whose phone was sold afterwards (TZ 02 §2.1 p.4)', function () {
    $w = world();
    $imei = World::imei();
    $purchase = PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, $imei, Money::kzt(450000))->onCredit(now()->addDays(5))->post($w->admin);
    $unit = StockUnit::findByIdentifier($imei);
    ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $imei, 'result' => 'registered', 'checked_by' => $w->admin->id, 'checked_at' => now()]);
    $sale = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);

    expect(fn () => reverser()->reverse($purchase, ReversalReason::WrongItem, null, $w->admin))
        ->toThrow(ReversalBlocked::class, "Сначала сторнируйте: Продажа {$sale->number}");

    // after the sale is reversed the purchase can be reversed: the unit leaves stock and its IMEI is free again
    reverser()->reverse($sale, ReversalReason::WrongItem, null, $w->admin);
    reverser()->reverse($purchase, ReversalReason::WrongItem, null, $w->admin);
    expect($unit->refresh()->status)->toBeNull()
        ->and(UnitIdentifier::query()->where('value', $imei)->exists())->toBeFalse()
        ->and((int) $w->debt('supplier', $w->supplier->id)->balance)->toBe(0);
});

it('reverses an accessory purchase with the average cost formula and blocks it when stock was sold', function () {
    $w = world();
    PurchaseDraft::make($w->store, $w->supplier)->accessory($w->case, 10, Money::kzt(2000))->onCredit(now()->addDays(7))->post($w->admin);
    $second = PurchaseDraft::make($w->store, $w->supplier)->accessory($w->case, 20, Money::kzt(2600))->onCredit(now()->addDays(7))->post($w->admin);
    expect($w->accessoryAvg($w->case))->toBe(Money::kzt(2400)->amount);

    reverser()->reverse($second, ReversalReason::WrongAmount, null, $w->admin);
    expect($w->accessoryQty($w->case))->toBe(10)->and($w->accessoryAvg($w->case))->toBe(Money::kzt(2000)->amount);

    $third = PurchaseDraft::make($w->store, $w->supplier)->accessory($w->glass, 2, Money::kzt(1000))->onCredit(now()->addDays(7))->post($w->admin);
    SaleDraft::make($w->store, $w->seller)->accessory($w->glass, 1, Money::kzt(5000))->cash(Money::kzt(5000))->post($w->seller);
    expect(fn () => reverser()->reverse($third, ReversalReason::WrongAmount, null, $w->admin))->toThrow(ReversalBlocked::class, 'уже продана');
});

it('lets a seller reverse only with an approval (TZ 03 §3.7 №79)', function () {
    $w = world();
    $doc = $w->cash(Money::kzt(1000));
    expect(fn () => reverser()->reverse($doc, ReversalReason::InputError, null, $w->seller))->toThrow(ApprovalRequired::class)
        ->and($doc->refresh()->isPosted())->toBeTrue();
});

it('returns a sold reserved phone to free stock on reversal (the cart reservation was consumed)', function () {
    $w = world();
    $unit = $w->phone();
    app(ReservationService::class)->reserve($unit, $w->seller);
    $sale = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    expect(InventoryMovement::query()->where('document_id', $sale->id)->first()->status_before)->toBe(UnitStatus::Reserved);

    reverser()->reverse($sale, ReversalReason::InputError, null, $w->admin);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock);
});
