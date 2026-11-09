<?php

namespace App\Domain\Ledger\Posting\Ledgers;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\DebtEntry;
use App\Domain\Ledger\Posting\PostingContext;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\Rounding;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The ONLY writer of debt_entries and counterparty_balances. Sign (TZ 02 §6.1): + the counterparty owes us more,
 * − we owe more. Balance per counterparty × currency. USD debts keep balance_kzt and the average rate (§3.5):
 * growth is valued at the transaction rate, settlement at the average rate; the difference against the money
 * actually paid is fx_diff_kzt (+ gain, − loss).
 */
class DebtLedger
{
    /**
     * @param  int  $amount  signed, minor units of $currency
     * @param  Rate|null  $rate  transaction rate for USD (defaults to the document rate)
     * @param  int|null  $settledKzt  ₸ value of the money that settled this movement, same sign as $amount
     *                                (e.g. paying a supplier: −money.amount_kzt). Produces fx_diff_kzt.
     */
    public function record(
        PostingContext $ctx,
        CounterpartyType $type,
        int $counterpartyId,
        Currency $currency,
        int $amount,
        DebtKind $kind,
        ?CarbonInterface $dueDate = null,
        ?Rate $rate = null,
        ?int $settledKzt = null,
        ?string $comment = null,
    ): DebtEntry {
        if ($kind->requiresDueDate() && $dueDate === null) {
            throw PostingException::because('due_date_required', 'Укажите срок оплаты долга');
        }
        $balance = $ctx->locks->counterparty($type, $counterpartyId, $currency);
        [$kzt, $usedRate] = $this->valuate($ctx, $balance, $currency, $amount, $rate);
        $fx = $settledKzt === null ? 0 : $kzt - $settledKzt;

        return $this->write($ctx, $balance, $type, $counterpartyId, $currency, $amount, $kzt, $usedRate, $kind, $dueDate, $fx, $comment, null);
    }

    /** Inverse entry with exactly mirrored ₸ valuation and fx difference. */
    public function reverse(PostingContext $ctx, DebtEntry $original): DebtEntry
    {
        $type = $original->counterparty_type;
        $currency = $original->currency;
        $balance = $ctx->locks->counterparty($type, (int) $original->counterparty_id, $currency);

        return $this->write(
            $ctx, $balance, $type, (int) $original->counterparty_id, $currency,
            -(int) $original->amount, -(int) $original->amount_kzt, Rate::of((string) $original->exchange_rate),
            DebtKind::Reversal, null, -(int) $original->fx_diff_kzt, $original->comment, $original->id,
        );
    }

    /** @return array{0: int, 1: Rate} */
    private function valuate(PostingContext $ctx, CounterpartyBalance $balance, Currency $currency, int $amount, ?Rate $rate): array
    {
        if ($amount === 0) {
            throw new LogicException('Debt entry amount cannot be zero.');
        }
        if ($currency === Currency::KZT) {
            return [$amount, Rate::one()];
        }
        $rate ??= $ctx->requireRate();
        $b = (int) $balance->balance;
        $k = (int) $balance->balance_kzt;

        if ($b === 0 || ($amount > 0) === ($b > 0)) {
            return [Money::of($amount, $currency)->convert(Currency::KZT, $rate)->amount, $rate];
        }

        // settling: the part up to zero at the average rate, the excess (advance) at the transaction rate
        $settle = $amount > 0 ? min($amount, -$b) : max($amount, -$b);
        $avg = $this->averageRate($b, $k);
        $settleKzt = abs($settle) === abs($b) ? -$k : Money::of($settle, $currency)->convert(Currency::KZT, $avg)->amount;
        $excess = $amount - $settle;
        $excessKzt = $excess === 0 ? 0 : Money::of($excess, $currency)->convert(Currency::KZT, $rate)->amount;

        return [$settleKzt + $excessKzt, $excess === 0 ? $avg : $rate];
    }

    private function write(
        PostingContext $ctx, CounterpartyBalance $balance, CounterpartyType $type, int $counterpartyId, Currency $currency,
        int $amount, int $kzt, Rate $rate, DebtKind $kind, ?CarbonInterface $dueDate, int $fx, ?string $comment, ?int $reversalOf,
    ): DebtEntry {
        $entry = new DebtEntry;
        $entry->forceFill($ctx->entryDefaults() + [
            'counterparty_type' => $type->value,
            'counterparty_id' => $counterpartyId,
            'currency' => $currency->value,
            'amount' => $amount,
            'exchange_rate' => $rate->toString(),
            'amount_kzt' => $kzt,
            'kind' => $kind->value,
            'due_date' => $dueDate?->format('Y-m-d'),
            'fx_diff_kzt' => $fx,
            'comment' => $comment,
            'reversal_of_entry_id' => $reversalOf,
        ])->save();

        $newBalance = (int) $balance->balance + $amount;
        $newKzt = (int) $balance->balance_kzt + $kzt;
        $balance->forceFill([
            'balance' => $newBalance,
            'balance_kzt' => $newKzt,
            'avg_rate' => $currency === Currency::KZT ? '1.0000' : ($newBalance === 0 ? '0.0000' : $this->averageRate($newBalance, $newKzt)->toString()),
            'last_entry_id' => $entry->id,
            'updated_at' => $ctx->now,
        ]);
        $balance->nearest_due_date = $this->nearestDueDate($balance);
        $balance->save();

        return $entry;
    }

    /** ₸ per 1 unit = |Σ kzt| / |Σ amount| with 4 decimals. */
    private function averageRate(int $balance, int $balanceKzt): Rate
    {
        $scaled = Rounding::HalfUp->divide(abs($balanceKzt) * Rate::SCALE, abs($balance));

        return Rate::fromScaled(max($scaled, 1));
    }

    /**
     * Nearest due date of the outstanding debt assuming payments cover the oldest credits first:
     * walk credits (with a due date, not reversed) from the latest due date back until they cover the balance.
     */
    private function nearestDueDate(CounterpartyBalance $balance): ?string
    {
        $b = (int) $balance->balance;
        if ($b === 0) {
            return null;
        }
        $credits = DebtEntry::query()
            ->where('counterparty_type', $balance->counterparty_type)
            ->where('counterparty_id', $balance->counterparty_id)
            ->where('currency', $balance->currency)
            ->whereNotNull('due_date')
            ->where('amount', $b > 0 ? '>' : '<', 0)
            ->whereNotExists(fn ($q) => $q->from('debt_entries as r')->whereColumn('r.reversal_of_entry_id', 'debt_entries.id'))
            ->orderByDesc('due_date')->orderByDesc('id')
            ->get(['amount', 'due_date']);

        $remaining = abs($b);
        $due = null;
        foreach ($credits as $credit) {
            $due = $credit->due_date->format('Y-m-d');
            $remaining -= abs((int) $credit->amount);
            if ($remaining <= 0) {
                break;
            }
        }

        return $due;
    }
}
