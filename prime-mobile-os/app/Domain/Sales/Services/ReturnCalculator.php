<?php

namespace App\Domain\Sales\Services;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\CustomerReturnLine;
use App\Domain\Sales\Models\SaleLine;
use App\Support\Money\Money;
use App\Support\Money\Rounding;

/**
 * Amounts of a customer return. Used by CustomerReturnHandler and by the return screen (quote) to pre-fill
 * «сколько вернуть деньгами».
 */
class ReturnCalculator
{
    public function __construct(private readonly BonusLedger $bonuses) {}

    /**
     * Refund amount of $qty pieces of a sale line: proportional share of the line amount in whole tenge;
     * the last remaining pieces get exactly what is left (no rounding drift).
     */
    public function lineAmount(SaleLine $line, int $qty, ?int $override = null): int
    {
        if ($override !== null) {
            return $override;
        }
        if ($qty === (int) $line->qty - (int) $line->returned_qty) {
            return $this->remainingAmount($line);
        }

        return Rounding::HalfUp->divide((int) $line->amount * $qty, (int) $line->qty * Money::TENGE) * Money::TENGE;
    }

    /** Part of the line amount not refunded yet by posted returns (the most a further return of it may refund). */
    public function remainingAmount(SaleLine $line): int
    {
        $already = (int) CustomerReturnLine::query()
            ->join('documents', 'documents.id', '=', 'customer_return_lines.document_id')
            ->where('documents.status', DocumentStatus::Posted->value)
            ->where('customer_return_lines.sale_line_id', $line->id)
            ->sum('customer_return_lines.amount');

        return (int) $line->amount - $already;
    }

    /**
     * Bonuses paid on the line that come back when $qty more pieces are returned. Cumulative rounding:
     * share(returned + qty) − share(returned), so that partial returns add up EXACTLY to what was spent
     * (per-return rounding could give back 17 + 17 + 17 of 50 and fail on the last return).
     */
    public function bonusesToRestore(SaleLine $line, int $qty): int
    {
        return $this->cumulativeShare((int) $line->bonus_paid_share, (int) $line->qty * Money::TENGE, (int) $line->returned_qty, $qty);
    }

    /** Bonuses accrued on the line to cancel for $qty more returned pieces (cumulative rounding, Σ = accrued). */
    /** $debtBonuses: accrued for this line later, on repayment of its credit part (audit №25). */
    public function bonusesToCancel(SaleLine $line, int $qty, int $debtBonuses = 0): int
    {
        return $this->cumulativeShare((int) $line->bonus_accrued + $debtBonuses, (int) $line->qty, (int) $line->returned_qty, $qty);
    }

    /** Part of the returned pieces that was sold on credit, in tiyn, whole tenge (cumulative rounding, Σ = debt share). */
    public function creditPart(SaleLine $line, int $qty): int
    {
        return $this->cumulativeShare((int) $line->debt_share, (int) $line->qty * Money::TENGE, (int) $line->returned_qty, $qty) * Money::TENGE;
    }

    private function cumulativeShare(int $total, int $denominator, int $alreadyReturned, int $qty): int
    {
        $after = Rounding::HalfUp->divide($total * ($alreadyReturned + $qty), $denominator);
        $before = Rounding::HalfUp->divide($total * $alreadyReturned, $denominator);

        return $after - $before;
    }

    /**
     * Preview for the screen. «withheld» is an estimate: the exact value is computed under locks at posting.
     *
     * @return array{total: int, bonus_returned: int, bonus_cancelled: int, withheld_estimate: int, refund: int, to_debt_min: int, lines: array<int, int>}
     */
    public function quote(Document $returnDraft): array
    {
        $header = CustomerReturn::query()->where('document_id', $returnDraft->id)->firstOrFail();
        $lines = [];
        $restore = 0;
        $cancel = 0;
        $credit = 0;
        foreach (CustomerReturnLine::query()->where('document_id', $returnDraft->id)->get() as $line) {
            $saleLine = SaleLine::query()->findOrFail($line->sale_line_id);
            $lines[$line->id] = $this->lineAmount($saleLine, (int) $line->qty, $line->amount);
            $restore += $this->bonusesToRestore($saleLine, (int) $line->qty);
            $cancel += $this->bonusesToCancel($saleLine, (int) $line->qty);
            $credit += $this->creditPart($saleLine, (int) $line->qty);
        }
        $toDebt = 0;
        if ($header->customer_id && $credit > 0) {
            $owed = (int) CounterpartyBalance::query()->where('counterparty_type', CounterpartyType::Customer->value)
                ->where('counterparty_id', $header->customer_id)->where('currency', 'KZT')->value('balance');
            $toDebt = min($credit, max(0, $owed));
        }
        $withheld = 0;
        if ($header->customer_id && $cancel > 0) {
            $have = (int) $this->bonuses->sources((int) $header->customer_id)->sum('remaining') + $restore;
            $withheld = max(0, $cancel - $have) * Money::TENGE;
        }
        $total = array_sum($lines);

        return [
            'total' => $total,
            'bonus_returned' => $restore,
            'bonus_cancelled' => $cancel,
            'withheld_estimate' => $withheld,
            'refund' => $total - $restore * Money::TENGE - $withheld,
            'to_debt_min' => $toDebt, // unpaid credit part: at least this much of «refund» goes to reducing the debt
            'lines' => $lines,
        ];
    }
}
