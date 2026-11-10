<?php

use App\Domain\Debts\Drafts\DebtPaymentDraft;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

it('accrues bonuses on the credit part when the debt is repaid, and cancels them on return (audit №25 p.4)', function () {
    $w = world();
    $unit = $w->phone();
    // 500 000: 400 000 cash (4 000 bonuses at once), 100 000 on credit (1 000 bonuses — later, by repayment)
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($unit, Money::kzt(500000))
        ->cash(Money::kzt(400000))->debt(Money::kzt(100000), now()->addDays(10))->post($w->owner);
    expect((int) $w->bonuses($w->customer)->pending)->toBe(4000);

    $first = DebtPaymentDraft::fromCustomer($w->customer, $w->account('cash_kzt'), Money::kzt(30000), $w->store)->post($w->seller);
    expect((int) $w->bonuses($w->customer)->pending)->toBe(4300); // ⌊1 000 × 30 000 / 100 000⌋

    // a reversed payment takes its bonuses back; the next one counts from what is really repaid
    app(Reverser::class)->reverse($first, ReversalReason::InputError, null, $w->owner);
    expect((int) $w->bonuses($w->customer)->pending)->toBe(4000);
    DebtPaymentDraft::fromCustomer($w->customer, $w->account('cash_kzt'), Money::kzt(33333), $w->store)->post($w->seller);
    DebtPaymentDraft::fromCustomer($w->customer, $w->account('cash_kzt'), Money::kzt(66667), $w->store)->post($w->seller);
    expect((int) $w->bonuses($w->customer)->pending)->toBe(5000) // 333 + 667: Σ = 1 000 exactly
        ->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(0);

    // the phone comes back: all 5 000 bonuses of the line are cancelled
    $line = SaleLine::query()->where('document_id', $sale->id)->first();
    CustomerReturnDraft::make($sale)->line($line, 1)->refund('cash', Money::kzt(500000))->post($w->owner);
    expect((int) $w->bonuses($w->customer)->pending)->toBe(0)
        ->and(Reconciliation::problems())->toBe([]);
});
