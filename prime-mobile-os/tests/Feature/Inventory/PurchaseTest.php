<?php

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Exceptions\InsufficientFunds;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Tests\Support\World;

it('receives phones in USD on credit: piece cost by the document rate, supplier debt in USD (TZ 02 §6.2 №2)', function () {
    $w = world();
    $w->rate('515');
    $imei1 = World::imei();
    $imei2 = World::imei();

    $doc = PurchaseDraft::make($w->store, $w->supplier, Currency::USD)
        ->unit($w->iphone, $imei1, Money::usd(950), ['sale_price' => Money::kzt(549000)])
        ->unit($w->usedIphone, $imei2, Money::usd(300), ['condition' => 'used', 'grade' => 'A', 'battery_percent' => 89])
        ->onCredit(now()->addDays(30))
        ->post($w->admin);

    expect($doc->number)->toBe('ПРХ-'.now('Asia/Almaty')->year.'-000001')
        ->and($doc->exchange_rate)->toBe('515.0000');

    $new = StockUnit::findByIdentifier($imei1);
    $used = StockUnit::findByIdentifier($imei2);
    expect($new->status)->toBe(UnitStatus::InStock)
        ->and($new->cost_kzt)->toBe(Money::kzt(489250)->amount)      // $950 × 515
        ->and($new->cost_currency)->toBe('USD')
        ->and((int) $new->cost_amount_orig)->toBe(95000)
        ->and($new->isInStore($w->store->id))->toBeTrue()
        ->and($new->public_code)->toStartWith('PM-')
        ->and($used->status)->toBe(UnitStatus::Preparing)            // used phones go to preparation
        ->and($used->cost_kzt)->toBe(Money::kzt(154500)->amount);

    $debt = $w->debt(CounterpartyType::Supplier->value, $w->supplier->id, 'USD');
    expect((int) $debt->balance)->toBe(-Money::usd(1250)->amount)    // we owe $1 250
        ->and((int) $debt->balance_kzt)->toBe(-Money::kzt(643750)->amount)
        ->and($debt->avg_rate)->toBe('515.0000')
        ->and($debt->nearest_due_date)->not->toBeNull();
});

it('pays a KZT purchase from the cash register and never lets the cash go negative', function () {
    $w = world();
    $w->cash(Money::kzt(500000));

    PurchaseDraft::make($w->store, $w->supplier)
        ->unit($w->iphone, World::imei(), Money::kzt(450000))
        ->payFrom($w->account('cash_kzt'), Money::kzt(450000))
        ->post($w->admin);
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(50000)->amount);

    expect(fn () => PurchaseDraft::make($w->store, $w->supplier)
        ->unit($w->iphone, World::imei(), Money::kzt(100000))
        ->payFrom($w->account('cash_kzt'), Money::kzt(100000))
        ->post($w->admin))->toThrow(InsufficientFunds::class, 'не хватает');
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(50000)->amount);
});

it('recomputes the moving average cost of accessories (TZ 02 §4.2 example)', function () {
    $w = world();
    PurchaseDraft::make($w->store, $w->supplier)->accessory($w->case, 10, Money::kzt(2000))->onCredit(now()->addDays(7))->post($w->admin);
    PurchaseDraft::make($w->store, $w->supplier)->accessory($w->case, 20, Money::kzt(2600))->onCredit(now()->addDays(7))->post($w->admin);

    expect($w->accessoryQty($w->case))->toBe(30)
        ->and($w->accessoryAvg($w->case))->toBe(Money::kzt(2400)->amount);

    // USD accessories: 50 glasses × $1.20 at 515 → 618 ₸ apiece
    $w->rate('515');
    PurchaseDraft::make($w->store, $w->supplier, Currency::USD)->accessory($w->glass, 50, Money::usd('1.20'))->onCredit(now()->addDays(7))->post($w->admin);
    expect($w->accessoryAvg($w->glass))->toBe(Money::kzt(618)->amount);
});

it('rejects invalid IMEIs, duplicates and unpaid purchases without a due date', function () {
    $w = world();
    $imei = World::imei();
    $bad = substr($imei, 0, 14).((int) substr($imei, -1) + 1) % 10;

    expect(fn () => PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, $bad, Money::kzt(1000))->onCredit(now()->addDay())->post($w->admin))
        ->toThrow(PostingException::class, 'Луну')
        ->and(fn () => PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, $imei, Money::kzt(1000))->unit($w->iphone, $imei, Money::kzt(1000))->onCredit(now()->addDay())->post($w->admin))
        ->toThrow(PostingException::class, 'дважды')
        ->and(fn () => PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, $imei, Money::kzt(1000))->post($w->admin))
        ->toThrow(PostingException::class, 'срок оплаты');
    expect(InventoryMovement::query()->count())->toBe(0);
});

it('lets a seller receive phones only without cost (TZ 03 §3.3 №31–32)', function () {
    $w = world();
    expect(fn () => PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, World::imei(), Money::kzt(1000))->onCredit(now()->addDay())->post($w->seller))
        ->toThrow(AccessDenied::class);

    $imei = World::imei();
    PurchaseDraft::make($w->store, $w->supplier)->unit($w->iphone, $imei, null)->post($w->seller);
    $unit = StockUnit::findByIdentifier($imei);
    expect($unit->status)->toBe(UnitStatus::InStock)->and($unit->cost_unknown)->toBeTrue()->and($unit->cost_kzt)->toBe(0);
});
