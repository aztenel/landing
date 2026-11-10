<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Domain\Warehouse\Drafts\StockOpDraft;
use App\Domain\Warehouse\Services\Warranty;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

function secondStore($w): Store
{
    $store = Store::query()->create(['name' => 'ТРЦ «Хан Шатыр»']);
    app(OrganizationProvisioner::class)->installStarterAccounts($store);
    app(\App\Domain\Ledger\Services\Shifts::class)->open($store, $w->owner);

    return $store;
}

it('moves phones and accessories to another store: in transit, accepted, one refused goes back (№24–26)', function () {
    $w = world();
    $b = secondStore($w);
    $u1 = $w->phone();
    $u2 = $w->phone();
    $w->accessories($w->case, 5, Money::kzt(2000));

    $out = StockOpDraft::transferOut($w->store, $b)->unit($u1)->unit($u2)->accessory($w->case->id, 3)->post($w->owner);
    expect($out->number)->toStartWith('ПРМ-')->and($u1->refresh()->status)->toBe(UnitStatus::InTransit);
    expect(fn () => StockOpDraft::transferIn($out)->unit($u1)->accessory($w->case->id, 3)->post($w->owner))->toThrow(PostingException::class, 'каждый телефон');

    StockOpDraft::transferIn($out)->unit($u1)->refuseUnit($u2, 'не тот цвет')->accessory($w->case->id, 2, 1)->post($w->owner);
    expect($u1->refresh()->isInStore($b->id))->toBeTrue()->and($u1->status)->toBe(UnitStatus::InStock)
        ->and($u2->refresh()->isInStore($w->store->id))->toBeTrue()
        ->and(Reconciliation::problems())->toBe([]);
    expect(fn () => app(Reverser::class)->reverse($out->refresh(), ReversalReason::InputError, null, $w->owner))->toThrow(ReversalBlocked::class, 'принято');
});

it('writes off within the limit and asks the owner above it; reversal brings the phone back (№27, L15)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    expect(fn () => StockOpDraft::writeOff($w->store, 'разбит')->unit($unit)->post($w->seller))->toThrow(ApprovalRequired::class);
    $doc = StockOpDraft::writeOff($w->store, 'разбит при доставке')->unit($unit)->post($w->owner);
    expect($unit->refresh()->status)->toBe(UnitStatus::WrittenOff);
    app(Reverser::class)->reverse($doc, ReversalReason::InputError, null, $w->owner);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock)->and(Reconciliation::problems())->toBe([]);
});

it('returns a phone to the supplier: our debt gets smaller; or money comes to the cash (№28–29)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    \App\Domain\Ledger\Drafts\OpeningBalanceDraft::make($w->store)->debt(\App\Domain\Counterparties\Enums\CounterpartyType::Supplier, $w->supplier->id, Money::kzt(-500000), now()->addDays(10))->post($w->owner);
    StockOpDraft::supplierReturn($w->store, $w->supplier->id, 'debt', Money::kzt(400000))->unit($unit)->post($w->admin);
    expect($unit->refresh()->status)->toBe(UnitStatus::ReturnedToSupplier)
        ->and((int) $w->debt('supplier', $w->supplier->id)->balance)->toBe(Money::kzt(-100000)->amount);
    $u2 = $w->phone(cost: Money::kzt(400000));
    StockOpDraft::supplierReturn($w->store, $w->supplier->id, 'money', Money::kzt(390000), $w->account('cash_kzt')->id)->unit($u2)->post($w->admin);
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(390000)->amount)->and(Reconciliation::problems())->toBe([]);
    expect(fn () => StockOpDraft::supplierReturn($w->store, $w->supplier->id, 'debt', Money::kzt(1))->unit($w->phone())->post($w->seller))->toThrow(AccessDenied::class);
});

it('posts a revision: a missing phone is written off, a found one comes back with its cost, accessories ± (№30–31)', function () {
    $w = world();
    $missing = $w->phone(cost: Money::kzt(400000));
    $lost = $w->phone(cost: Money::kzt(300000));
    StockOpDraft::writeOff($w->store, 'потерян')->unit($lost)->post($w->owner);
    $w->accessories($w->case, 5, Money::kzt(2000));

    StockOpDraft::revision($w->store)->shortage($missing)->found($lost)->accessory($w->case->id, -2)->post($w->admin);
    expect($missing->refresh()->status)->toBe(UnitStatus::WrittenOff)
        ->and($lost->refresh()->status)->toBe(UnitStatus::InStock)->and((int) $lost->cost_kzt)->toBe(Money::kzt(300000)->amount)
        ->and(Reconciliation::problems())->toBe([]);
    expect(fn () => StockOpDraft::revision($w->store)->accessory($w->case->id, 1)->post($w->seller))->toThrow(AccessDenied::class);
});

it('corrects a card (IMEI typo) and the owner adjusts the cost (№48–49)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $new = Imei::withCheckDigit('35693812999999');
    $doc = StockOpDraft::unitEdit($unit, ['imei1' => $new], 'опечатка при приходе')->post($w->admin);
    expect($unit->refresh()->imei1)->toBe($new)->and(StockUnit::findByIdentifier($new)?->id)->toBe($unit->id);
    app(Reverser::class)->reverse($doc, ReversalReason::InputError, null, $w->owner);
    expect($unit->refresh()->imei1)->not->toBe($new);

    expect(fn () => StockOpDraft::costAdjustment($unit, Money::kzt(-5000), 'скидка поставщика')->post($w->admin))->toThrow(AccessDenied::class);
    StockOpDraft::costAdjustment($unit, Money::kzt(-5000), 'скидка поставщика')->post($w->owner);
    expect((int) $unit->refresh()->cost_kzt)->toBe(Money::kzt(395000)->amount)->and(Reconciliation::problems())->toBe([]);
});

it('takes a sold phone into warranty without changing its status (№46, Э17)', function () {
    $w = world();
    $unit = $w->phone();
    SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    $case = app(Warranty::class)->accept($unit->refresh(), $w->seller, $w->store->id, 'не заряжается');
    expect($case->number)->toStartWith('ГРО-')->and($unit->refresh()->status)->toBe(UnitStatus::Sold);
    app(Warranty::class)->move($case, 'at_service', $w->admin, null, $w->supplier->id);
    app(Warranty::class)->move($case->refresh(), 'ready', $w->admin, 'заменён разъём');
    app(Warranty::class)->move($case->refresh(), 'issued', $w->seller);
    expect($case->refresh()->status)->toBe('issued')->and($unit->refresh()->status)->toBe(UnitStatus::Sold);
    expect(fn () => app(Warranty::class)->accept($w->phone(), $w->seller, $w->store->id, 'x'))->toThrow(PostingException::class, 'проданного');
});
