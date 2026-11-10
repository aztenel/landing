<?php

use App\Domain\Inventory\Drafts\CostEntryDraft;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Models\PurchaseLine;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;
use Tests\Support\World;

/** A seller receives two phones without a purchase price (audit №4, docs/audit/decisions.md). */
function costlessPurchase(): array
{
    $w = world();
    $w->rate('515');
    $doc = PurchaseDraft::make($w->store, $w->supplier, Currency::USD)
        ->unit($w->iphone, World::imei(), null, ['sale_price' => Money::kzt(589000)])
        ->unit($w->iphone, World::imei(), null, ['sale_price' => Money::kzt(589000)])
        ->post($w->seller);
    $units = StockUnit::query()->whereIn('id', PurchaseLine::query()->where('document_id', $doc->id)->pluck('stock_unit_id'))->orderBy('id')->get();

    return [$w, $doc, $units];
}

it('never sells a phone without a purchase price, not even the owner (audit №4)', function () {
    [$w, , $units] = costlessPurchase();
    expect($units->every(fn ($u) => $u->cost_unknown))->toBeTrue();

    expect(fn () => SaleDraft::make($w->store, $w->owner)->unit($units[0], Money::kzt(589000))->cash(Money::kzt(589000))->post($w->owner))
        ->toThrow(PostingException::class, 'без себестоимости');
    expect(Reconciliation::problems())->toBe([]);
});

it('enters the cost of the whole purchase with payment and supplier debt in dollars, then the phone sells (ВСБ)', function () {
    [$w, $purchase, $units] = costlessPurchase();
    $w->cash(Money::usd(1000), 'cash_usd');

    // a seller may not enter the cost; a partial entry is refused
    expect(fn () => CostEntryDraft::forPurchase($w->store, $purchase)->price($units[0], Money::usd(1000))->price($units[1], Money::usd(1000))
        ->payFrom($w->account('cash_usd'), Money::usd(1000))->onCredit(now()->addDays(10))->post($w->seller))->toThrow(AccessDenied::class);
    expect(fn () => CostEntryDraft::forPurchase($w->store, $purchase)->price($units[0], Money::usd(1000))->post($w->admin))
        ->toThrow(PostingException::class, 'по всем телефонам');

    $doc = CostEntryDraft::forPurchase($w->store, $purchase)->price($units[0], Money::usd(1000))->price($units[1], Money::usd(1000))
        ->payFrom($w->account('cash_usd'), Money::usd(1000))->onCredit(now()->addDays(10))->post($w->admin);

    expect($doc->number)->toStartWith('ВСБ-')
        ->and($units[0]->fresh()->cost_unknown)->toBeFalse()
        ->and($units[0]->fresh()->cost_kzt)->toBe(Money::kzt(515000)->amount)
        ->and($w->balance('cash_usd'))->toBe(0)
        ->and((int) $w->debt('supplier', $w->supplier->id, 'USD')->balance)->toBe(-Money::usd(1000)->amount)
        ->and(Reconciliation::problems())->toBe([]);

    SaleDraft::make($w->store, $w->seller)->unit($units[0], Money::kzt(589000))->cash(Money::kzt(589000))->post($w->owner);
    expect(Reconciliation::problems())->toBe([]);
});

it('reverses a cost entry while the phones did not move: the flag, money and debt come back', function () {
    [$w, $purchase, $units] = costlessPurchase();
    $doc = CostEntryDraft::forPurchase($w->store, $purchase)->price($units[0], Money::usd(900))->price($units[1], Money::usd(900))
        ->onCredit(now()->addDays(10))->post($w->owner);

    app(Reverser::class)->reverse($doc, 'wrong_amount', 'не та цена', $w->owner);

    expect($units[0]->fresh()->cost_unknown)->toBeTrue()
        ->and($units[0]->fresh()->cost_kzt)->toBe(0)
        ->and((int) ($w->debt('supplier', $w->supplier->id, 'USD')?->balance ?? 0))->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);
});

it('enters a cost without a purchase only by the owner and only as cost', function () {
    $w = world();
    $unit = $w->phone();
    $unit->forceFill(['cost_unknown' => true, 'cost_kzt' => 0])->save();

    expect(fn () => CostEntryDraft::withoutPurchase($w->store, 'лишний телефон дня X')->price($unit, Money::kzt(385000))->post($w->admin))
        ->toThrow(AccessDenied::class);
    $doc = CostEntryDraft::withoutPurchase($w->store, 'лишний телефон дня X')->price($unit, Money::kzt(385000))->post($w->owner);

    expect($unit->fresh()->cost_kzt)->toBe(Money::kzt(385000)->amount)
        ->and($unit->fresh()->cost_unknown)->toBeFalse()
        ->and($doc->isPosted())->toBeTrue();
});
