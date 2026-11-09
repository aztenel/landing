<?php

use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Services\ReturnCalculator;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;

function saleWithCase(array $settings = []): array
{
    $w = world($settings + ['bonus' => ['phone_percent' => '3.00', 'accessory_percent' => '5.00']]);
    $unit = $w->phone();
    $w->accessories($w->case, 10, Money::kzt(2000));
    PurchaseDraft::make($w->store, $w->supplier)->accessory($w->case, 20, Money::kzt(2600))->onCredit(now()->addDays(7))->post($w->owner);
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)
        ->unit($unit, Money::kzt(549000))->accessory($w->case, 1, Money::kzt(9000))->discount(Money::kzt(3000))
        ->cash(Money::kzt(555000))->post($w->seller);
    $lines = SaleLine::query()->where('document_id', $sale->id)->orderBy('line_no')->get();

    return [$w, $unit, $sale, $lines[0], $lines[1]];
}

it('returns one accessory of a split check: average cost, cash refund, bonus cancel (TZ 02 §6.5, §14 №4)', function () {
    [$w, $unit, $sale, $phoneLine, $caseLine] = saleWithCase();
    expect($w->accessoryAvg($w->case))->toBe(Money::kzt(2400)->amount)
        ->and((int) $w->bonuses($w->customer)->pending)->toBe(16828);

    $draft = CustomerReturnDraft::make($sale)->line($caseLine, 1)->save();
    expect(app(ReturnCalculator::class)->quote($draft)['refund'])->toBe(Money::kzt(8952)->amount);

    $return = CustomerReturnDraft::make($sale)->line($caseLine, 1)->refund('cash', Money::kzt(8952))->reason('не подошёл')->post($w->admin);

    expect($return->number)->toStartWith('ВЗК-')
        ->and($w->accessoryQty($w->case))->toBe(30)
        ->and($w->accessoryAvg($w->case))->toBe(Money::kzt(2400)->amount)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(555000 - 8952)->amount)
        ->and((int) $w->bonuses($w->customer)->pending)->toBe(16381)   // −447
        ->and($caseLine->refresh()->is_active)->toBeFalse()
        ->and($caseLine->returned_qty)->toBe(1)
        ->and($phoneLine->refresh()->is_active)->toBeTrue();
});

it('returns a phone fully; the phone goes to the chosen status and can be sold again', function () {
    [$w, $unit, $sale, $phoneLine] = saleWithCase();

    expect(fn () => CustomerReturnDraft::make($sale)->line($phoneLine, 1)->refund('cash', Money::kzt(1000))->post($w->admin))
        ->toThrow(PostingException::class, 'Сумма возврата должна быть 546');

    CustomerReturnDraft::make($sale)->line($phoneLine, 1, UnitStatus::OnCheck)->refund('cash', Money::kzt(546048))->post($w->admin);
    expect($unit->refresh()->status)->toBe(UnitStatus::OnCheck)
        ->and($unit->isInStore($w->store->id))->toBeTrue()
        ->and($unit->cost_kzt)->toBe(Money::kzt(489250)->amount)
        ->and($phoneLine->refresh()->is_active)->toBeFalse();

    // returning the same phone twice is impossible
    expect(fn () => CustomerReturnDraft::make($sale)->line($phoneLine, 1)->refund('cash', Money::kzt(546048))->post($w->admin))
        ->toThrow(PostingException::class);
});

it('requires an approval for a seller and blocks the sale reversal while a return exists', function () {
    [$w, $unit, $sale, $phoneLine, $caseLine] = saleWithCase();

    expect(fn () => CustomerReturnDraft::make($sale)->line($caseLine, 1)->refund('cash', Money::kzt(8952))->post($w->seller))
        ->toThrow(ApprovalRequired::class);

    $return = CustomerReturnDraft::make($sale)->line($caseLine, 1)->refund('cash', Money::kzt(8952))->post($w->admin);
    expect(fn () => app(Reverser::class)->reverse($sale, ReversalReason::InputError, null, $w->admin))
        ->toThrow(ReversalBlocked::class, $return->number);

    // reversing the return restores the sale line, then the sale itself can be reversed
    app(Reverser::class)->reverse($return, ReversalReason::InputError, null, $w->admin);
    expect($caseLine->refresh()->is_active)->toBeTrue()->and($caseLine->returned_qty)->toBe(0)
        ->and((int) $w->bonuses($w->customer)->pending)->toBe(16828);
    app(Reverser::class)->reverse($sale, ReversalReason::InputError, null, $w->admin);
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock);
});

it('refunds a credit sale by reducing the customer debt', function () {
    $w = world();
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->accessory($w->case, 2, Money::kzt(9000))
        ->debt(Money::kzt(18000), now()->addDays(10))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->first();

    CustomerReturnDraft::make($sale)->line($line, 1)->refundToDebt(Money::kzt(9000))->post($w->owner);
    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(9000)->amount)
        ->and($line->refresh()->is_active)->toBeTrue()->and($line->returned_qty)->toBe(1);
});

it('gives back spent bonuses on return and takes them again when the return is reversed', function () {
    $w = world(['bonus' => ['accessory_percent' => '0.00']]);
    OpeningBalanceDraft::make($w->store)->bonus($w->customer, 1000, now()->subDay(), now()->addDays(60))->post($w->owner);
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->accessory($w->case, 2, Money::kzt(9000))
        ->bonus(Money::kzt(1000))->cash(Money::kzt(17000))->post($w->seller);
    $line = SaleLine::query()->where('document_id', $sale->id)->first();
    expect((int) $w->bonuses($w->customer)->available)->toBe(0)->and($line->bonus_paid_share)->toBe(Money::kzt(1000)->amount);

    $draft = CustomerReturnDraft::make($sale)->line($line, 1)->save();
    expect(app(ReturnCalculator::class)->quote($draft)['refund'])->toBe(Money::kzt(8500)->amount); // 9 000 − 500 bonuses
    $return = CustomerReturnDraft::make($sale)->line($line, 1)->refund('cash', Money::kzt(8500))->post($w->admin);
    expect((int) $w->bonuses($w->customer)->available)->toBe(500);

    app(Reverser::class)->reverse($return, ReversalReason::InputError, null, $w->admin);
    expect((int) $w->bonuses($w->customer)->available)->toBe(0)
        ->and($line->refresh()->returned_qty)->toBe(0);
});

it('withholds already spent accrued bonuses from the refund (TZ 02 §6.5 assumption)', function () {
    $w = world(['bonus' => ['accessory_percent' => '10.00', 'activation_days' => 0]]);
    $w->accessories($w->case, 5, Money::kzt(2000));
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))->cash(Money::kzt(9000))->post($w->seller);
    expect((int) $w->bonuses($w->customer)->available)->toBe(900);
    // the 900 bonuses are spent right away on another purchase
    SaleDraft::make($w->store, $w->seller)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))->bonus(Money::kzt(900))->cash(Money::kzt(8100))->post($w->seller);
    $line = SaleLine::query()->where('document_id', $sale->id)->first();

    $return = CustomerReturnDraft::make($sale)->line($line, 1)->refund('cash', Money::kzt(9000 - 90))->post($w->admin);
    $header = CustomerReturn::query()->where('document_id', $return->id)->first();
    // 810 of the new accrual (10 % of 8 100) cover part of the 900 to cancel; 90 are withheld in money
    expect($header->bonus_cancelled)->toBe(900)->and($header->bonus_withheld)->toBe(Money::kzt(90)->amount)
        ->and((int) $w->bonuses($w->customer)->available)->toBe(0);
});
