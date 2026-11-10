<?php

namespace App\Domain\Loyalty\Services;

use App\Domain\Ledger\Enums\BonusKind;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Sales\Models\SaleLine;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Illuminate\Support\Facades\DB;

/**
 * Bonuses on the credit part of a sale, accrued when the debt is repaid (audit №25 p.4, TZ 01 П14.2 p.5):
 *   accrued(line) = ⌊ bonuses of the line's credit part × repaid / credit part ⌋, the difference with what was already
 *   accrued is given by this payment; active 14 days after the payment (bonus.activation_days).
 * A payment covers first the debt that is not tied to a sale line (brought from sfon, manual corrections — it is the
 * oldest), then the lines sold on credit, the oldest sale first. Lines (partly) returned count only what is left.
 */
class DebtBonusAccrual
{
    public function __construct(private readonly BonusLedger $bonuses) {}

    /** Called by the incoming debt payment inside its posting, after the debt entry; $balanceBefore — debt before it. */
    public function onPayment(PostingContext $ctx, Document $payment, int $customerId, int $paid, int $balanceBefore): void
    {
        $lines = SaleLine::query()->select('sale_lines.*')
            ->join('sales', 'sales.document_id', '=', 'sale_lines.document_id')
            ->join('documents', 'documents.id', '=', 'sale_lines.document_id')
            ->where('sales.customer_id', $customerId)->where('documents.status', 'posted')
            ->where('sale_lines.debt_share', '>', 0)->whereColumn('sale_lines.returned_qty', '<', 'sale_lines.qty')
            ->orderBy('documents.occurred_at')->orderBy('sale_lines.id')->get();
        if ($lines->isEmpty()) {
            return;
        }
        $repaid = $this->sums($lines->pluck('id')->all(), 'amount');
        $accrued = $this->sums($lines->pluck('id')->all(), 'bonuses');

        $outstanding = [];
        foreach ($lines as $line) {
            $outstanding[$line->id] = max(0, $this->lineCredit($line) - ($repaid[$line->id] ?? 0));
        }
        $notTied = max(0, $balanceBefore - array_sum($outstanding));
        $left = $paid - min($paid, $notTied);

        $settings = $ctx->settings();
        $give = $settings->bonusEnabled();
        $availableFrom = $ctx->occurredAt()->addDays($settings->bonusActivationDays());
        $expiresAt = $availableFrom->addDays($settings->bonusLifetimeDays());

        foreach ($lines as $line) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $outstanding[$line->id]);
            if ($take <= 0) {
                continue;
            }
            $left -= $take;
            $credit = $this->lineCredit($line);
            $percent = $line->stock_unit_id ? $settings->bonusPhonePercent() : $settings->bonusAccessoryPercent();
            $lineBonuses = intdiv(Money::kztMinor($credit)->percent($percent, Rounding::Down, Money::TENGE)->amount, Money::TENGE);
            $target = intdiv($lineBonuses * (($repaid[$line->id] ?? 0) + $take), $credit);
            $bonuses = $give ? max(0, $target - ($accrued[$line->id] ?? 0)) : 0;
            if ($bonuses > 0) {
                $this->bonuses->accrue($ctx, $customerId, $bonuses, $availableFrom, $expiresAt, BonusKind::Accrual, (int) $line->id);
            }
            DB::table('debt_payment_allocations')->insert([
                'organization_id' => $payment->organization_id, 'document_id' => $payment->id, 'sale_line_id' => $line->id,
                'amount' => $take, 'bonuses' => $bonuses, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Bonuses accrued for a line by debt payments still in force (for the return of the line). */
    public function accruedFor(int $saleLineId): int
    {
        return $this->sums([$saleLineId], 'bonuses')[$saleLineId] ?? 0;
    }

    /** Credit part of what is left of the line (returned pieces leave by quantity). */
    private function lineCredit(SaleLine $line): int
    {
        $left = (int) $line->qty - (int) $line->returned_qty;

        return Rounding::HalfUp->divide((int) $line->debt_share * $left, (int) $line->qty);
    }

    /** @return array<int, int> */
    private function sums(array $lineIds, string $column): array
    {
        return DB::table('debt_payment_allocations as a')->join('documents as d', 'd.id', '=', 'a.document_id')
            ->whereIn('a.sale_line_id', $lineIds)->where('d.status', 'posted')
            ->groupBy('a.sale_line_id')->selectRaw("a.sale_line_id, SUM(a.{$column}) AS s")
            ->pluck('s', 'sale_line_id')->map(fn ($v) => (int) $v)->all();
    }
}
