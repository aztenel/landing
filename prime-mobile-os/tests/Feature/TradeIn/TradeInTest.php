<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\InsufficientFunds;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\TradeIn\Drafts\PrepCostDraft;
use App\Domain\TradeIn\Drafts\TradeInDraft;
use App\Domain\TradeIn\Models\GridDeduction;
use App\Domain\TradeIn\Models\GridPrice;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

function tiImei(int $n): string
{
    return Imei::withCheckDigit('35288104'.str_pad((string) $n, 6, '0', STR_PAD_LEFT));
}

function grid($w, int $tenge): void
{
    GridPrice::query()->create(['model_id' => $w->usedIphone->model_id, 'memory_id' => $w->usedIphone->memory_id, 'base_price_kzt' => Money::kzt($tenge)->amount]);
    GridDeduction::query()->create(['code' => 'grade_b', 'kind' => 'amount', 'value' => Money::kzt(0)->amount]);
    GridDeduction::query()->create(['code' => 'battery_low', 'kind' => 'amount', 'value' => Money::kzt(15000)->amount]);
}

function act($w, int $imei, int $tenge): TradeInDraft
{
    return TradeInDraft::make($w->store, $w->customer, $w->usedIphone, tiImei($imei), Money::kzt($tenge))
        ->grade('B')->battery(84)->checklist(['icloud' => 'yes', 'screen' => 'yes'])->kit(['box'])->imeiCheck('registered')->idDocument('012345678');
}

it('buys back for 200 000 with a purchase of 7 980: offset into the check, the rest from the cash (scenario 23, audit №40)', function () {
    $w = world();
    grid($w, 210000);
    $w->cash(Money::kzt(300000));
    $w->accessories($w->case, 2, Money::kzt(2000));

    $act = act($w, 1, 200000)->offset(Money::kzt(7980))->payout('cash', Money::kzt(192020))->save();
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->accessory($w->case, 1, Money::kzt(7980))->tradeIn($act, Money::kzt(7980))->save();
    app(Poster::class)->postMany([$act, $sale], $w->owner); // 192 020 cash is above the seller's L12

    $unit = StockUnit::findByIdentifier(tiImei(1));
    expect($unit->status)->toBe(UnitStatus::Preparing)
        ->and((int) $unit->cost_kzt)->toBe(Money::kzt(200000)->amount)
        ->and($w->balance('tradein_offset'))->toBe(0)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(300000 - 192020)->amount)
        ->and(Reconciliation::problems())->toBe([]);

    // the act alone cannot be reversed; the pair can
    expect(fn () => app(Reverser::class)->reverse($act->refresh(), ReversalReason::InputError, null, $w->owner))->toThrow(ReversalBlocked::class);
    app(Reverser::class)->reverseMany([$sale->refresh(), $act->refresh()], ReversalReason::InputError, null, $w->owner);
    expect($w->balance('tradein_offset'))->toBe(0)->and(StockUnit::query()->find($unit->id)?->status)->toBeNull()
        ->and(Reconciliation::problems())->toBe([]);
});

it('refuses a cash payout above the cash in the register, a split with Kaspi passes (scenario 24)', function () {
    $w = world();
    grid($w, 210000);
    $w->cash(Money::kzt(150000));
    $w->cash(Money::kzt(100000), 'kaspi_gold');
    expect(fn () => act($w, 2, 192000)->payout('cash', Money::kzt(192000))->post($w->owner))->toThrow(InsufficientFunds::class, 'не хватает');
    act($w, 3, 192000)->payout('cash', Money::kzt(150000))->payout('kaspi_transfer', Money::kzt(42000), '123456')->post($w->owner);
    expect($w->balance('cash_kzt'))->toBe(0)->and($w->balance('kaspi_gold'))->toBe(Money::kzt(58000)->amount)->and(Reconciliation::problems())->toBe([]);
});

it('never accepts a phone with iCloud on or a blocked IMEI (scenarios 25, 27)', function () {
    $w = world();
    grid($w, 210000);
    $w->cash(Money::kzt(300000));
    expect(fn () => act($w, 4, 150000)->checklist(['icloud' => 'no'])->payout('cash', Money::kzt(150000))->post($w->owner))->toThrow(PostingException::class, 'iCloud');
    expect(fn () => act($w, 5, 150000)->imeiCheck('blocked')->payout('cash', Money::kzt(150000))->post($w->owner))->toThrow(PostingException::class, 'госбазу');
});

it('asks the owner when the sum is above the grid for the role and when there is no grid (scenario 26, audit №29)', function () {
    $w = world();
    $w->cash(Money::kzt(500000), 'kaspi_gold'); // paid out by Kaspi: the cash limit L12 is not in the way
    expect(fn () => act($w, 6, 150000)->payout('kaspi_transfer', Money::kzt(150000))->post($w->seller))->toThrow(ApprovalRequired::class, 'нет сетки');
    grid($w, 165000);
    act($w, 7, 165000)->payout('kaspi_transfer', Money::kzt(165000))->post($w->seller);                 // at the grid
    expect(fn () => act($w, 8, 166000)->payout('kaspi_transfer', Money::kzt(166000))->post($w->seller))->toThrow(ApprovalRequired::class, 'выше сетки');
    act($w, 9, 175000)->payout('kaspi_transfer', Money::kzt(175000))->post($w->owner);                  // the owner — no limit
});

it('takes back a phone we sold: the same unit, history continues (scenario 28)', function () {
    $w = world();
    grid($w, 210000);
    $w->cash(Money::kzt(300000));
    $unit = $w->phone($w->usedIphone);
    SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    TradeInDraft::make($w->store, $w->customer, $w->usedIphone, $unit->imei1, Money::kzt(200000))->grade('A')->battery(90)
        ->checklist(['icloud' => 'yes'])->kit(['box'])->imeiCheck('registered')->payout('cash', Money::kzt(200000))->post($w->owner);
    expect($unit->refresh()->status)->toBe(UnitStatus::Preparing)->and((int) $unit->cost_kzt)->toBe(Money::kzt(200000)->amount)
        ->and(StockUnit::query()->where('imei1', $unit->imei1)->count())->toBe(1)
        ->and(Reconciliation::problems())->toBe([]);
});

it('adds preparation costs to the cost: paid and owed to a master (П6)', function () {
    $w = world();
    grid($w, 210000);
    $w->cash(Money::kzt(300000));
    act($w, 10, 150000)->payout('cash', Money::kzt(150000))->post($w->owner);
    $unit = StockUnit::findByIdentifier(tiImei(10));
    PrepCostDraft::paid($unit, 'battery', Money::kzt(18000), $w->account('cash_kzt'))->post($w->admin);
    PrepCostDraft::owed($unit->refresh(), 'screen', Money::kzt(20000), $w->supplier, now()->addDays(7))->post($w->admin);
    expect((int) $unit->refresh()->cost_kzt)->toBe(Money::kzt(188000)->amount)
        ->and((int) $w->debt('supplier', $w->supplier->id)->balance)->toBe(Money::kzt(-20000)->amount)
        ->and(Reconciliation::problems())->toBe([]);
    expect(fn () => PrepCostDraft::paid($unit, 'other', Money::kzt(1000), $w->account('cash_kzt'))->post($w->seller))
        ->toThrow(\App\Domain\Tenancy\Access\Exceptions\AccessDenied::class);
});
