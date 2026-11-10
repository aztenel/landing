<?php

namespace App\Domain\Debts\Drafts;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Debts\Models\DebtAdjustment;
use App\Domain\Ledger\Drafts\DraftBuilder;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Models\Document;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * «Корректировка долга» КДЛ, the reason goes to ->comment():
 *
 *   DebtAdjustmentDraft::forgive($erlan, Money::kzt(4000))->comment('скидка за ожидание')->post($owner);   // −4 000 ₸ долга
 *   DebtAdjustmentDraft::increase($erlan, Money::kzt(10000), $due)->comment('забыли пробить')->post($admin);
 *   DebtAdjustmentDraft::postpone($erlan, Money::kzt(50000), $newDue)->comment('попросил')->post($seller); // перенос срока
 *
 * For a supplier the amount is about OUR debt: forgive = they wrote off part of what we owe, increase = we owe more.
 */
final class DebtAdjustmentDraft extends DraftBuilder
{
    private array $row;

    private static function make(Customer|Supplier $who, string $operation, int $ledgerAmount, string $currency, ?CarbonInterface $due): self
    {
        $d = new self;
        $d->row = [
            'operation' => $operation, 'counterparty_type' => ($who instanceof Customer ? CounterpartyType::Customer : CounterpartyType::Supplier)->value,
            'counterparty_id' => $who->id, 'currency' => $currency, 'amount' => $ledgerAmount, 'due_date' => $due?->format('Y-m-d'),
        ];

        return $d;
    }

    /** The debt gets smaller by $amount (whoever owes). */
    public static function forgive(Customer|Supplier $who, Money $amount): self
    {
        $a = abs($amount->amount);

        return self::make($who, 'adjust', $who instanceof Customer ? -$a : $a, $amount->currency->value, null);
    }

    /** The debt grows by $amount, with a due date. */
    public static function increase(Customer|Supplier $who, Money $amount, CarbonInterface $due): self
    {
        $a = abs($amount->amount);

        return self::make($who, 'adjust', $who instanceof Customer ? $a : -$a, $amount->currency->value, $due);
    }

    /** $amount of the oldest debt gets a new due date. */
    public static function postpone(Customer|Supplier $who, Money $amount, CarbonInterface $newDue): self
    {
        return self::make($who, 'postpone', abs($amount->amount), $amount->currency->value, $newDue);
    }

    protected function type(): DocumentType
    {
        return DocumentType::DebtAdjustment;
    }

    protected function writeDetails(Document $document): void
    {
        DebtAdjustment::query()->create(['document_id' => $document->id] + $this->row);
    }
}
