<?php

namespace App\Domain\Money\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\AccountType;
use App\Domain\Money\Models\Expense;
use App\Domain\Money\Models\ExpensePayment;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Tenancy\Access\Limit;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use Illuminate\Support\Collection;

/**
 * Расход (TZ 02 §6.2 №36): from one or several accounts (part cash, part bank) and/or on credit to a
 * counterparty. Cash expenses check limits L10 (per operation) and L11 (per day); a cash account never goes negative.
 */
class ExpenseHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly MoneyLedger $money, private readonly DebtLedger $debts) {}

    public function type(): DocumentType
    {
        return DocumentType::Expense;
    }

    public function requiresExchangeRate(Document $document): bool
    {
        $accounts = ExpensePayment::query()->where('document_id', $document->id)->pluck('account_id');

        return MoneyAccount::query()->whereIn('id', $accounts)->where('currency', Currency::USD->value)->exists();
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $expense = $this->expense($document);
        $article = MoneyArticle::query()->find($expense->article_id);
        if (! $article || ! $article->isExpense()) {
            throw PostingException::because('bad_article', 'Выберите статью расхода');
        }
        $payments = $this->payments($document);
        if ($payments->isEmpty() && (int) $expense->credit_amount <= 0) {
            throw PostingException::because('empty', 'Укажите, откуда оплачен расход');
        }
        foreach ($payments as $p) {
            if ($p->amount <= 0) {
                throw PostingException::because('bad_amount', 'Сумма расхода должна быть больше нуля');
            }
        }
        if ((int) $expense->credit_amount > 0 && (! $expense->counterparty_id || ! $expense->due_date)) {
            throw PostingException::because('credit_requires', 'Расход в долг: укажите контрагента и срок оплаты');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        foreach ($this->payments($document) as $p) {
            $plan->account((int) $p->account_id);
        }
        $expense = $this->expense($document);
        if ((int) $expense->credit_amount > 0) {
            $plan->counterparty(CounterpartyType::from($expense->counterparty_type ?? 'supplier'), (int) $expense->counterparty_id, Currency::KZT);
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $expense = $this->expense($document);
        $payments = $this->payments($document);

        $cashKzt = 0;
        $bankKzt = 0;
        foreach ($payments as $p) {
            $account = $this->money->account((int) $p->account_id);
            $kzt = Money::of((int) $p->amount, $account->currency)->convert(Currency::KZT, $ctx->rate() ?? Rate::one())->amount;
            $account->type->isCash() ? $cashKzt += $kzt : $bankKzt += $kzt;
        }
        if ($cashKzt > 0) {
            $ctx->authorize('expense.create', Limit::ExpenseCashPerOperation, $cashKzt, 'expense');
            $ctx->authorize('expense.create', Limit::ExpenseCashPerDay, $this->todayCashExpenses($ctx) + $cashKzt, 'expense');
        }
        if ($bankKzt > 0 || (int) $expense->credit_amount > 0) {
            $ctx->authorize('expense.bank', Limit::ExpenseCashPerOperation, $bankKzt + (int) $expense->credit_amount, 'expense');
        }

        $attrs = $expense->counterparty_id ? ['counterparty_type' => $expense->counterparty_type, 'counterparty_id' => (int) $expense->counterparty_id] : [];
        $total = 0;
        foreach ($payments as $p) {
            $entry = $this->money->record($ctx, (int) $p->account_id, -(int) $p->amount, (int) $expense->article_id, $attrs + ['expense_payment_id' => $p->id]);
            $total += -$entry->amount_kzt;
        }
        if ((int) $expense->credit_amount > 0) {
            $this->debts->record($ctx, CounterpartyType::from($expense->counterparty_type ?? 'supplier'), (int) $expense->counterparty_id, Currency::KZT,
                -(int) $expense->credit_amount, DebtKind::PurchaseCredit, $expense->due_date, comment: $expense->description);
            $total += (int) $expense->credit_amount;
        }
        $expense->forceFill(['total_kzt' => $total])->save();
    }

    /** Σ of today's cash expenses of the acting user (limit L11). */
    private function todayCashExpenses(PostingContext $ctx): int
    {
        $tz = $ctx->organization->timezone;
        $start = $ctx->occurredAt()->setTimezone($tz)->startOfDay();
        $cashAccounts = MoneyAccount::query()->whereIn('type', [AccountType::Cash->value, AccountType::Safe->value])->pluck('id');

        return -(int) MoneyEntry::query()
            ->join('documents', 'documents.id', '=', 'money_entries.document_id')
            ->where('documents.type', DocumentType::Expense->value)
            ->where('documents.status', DocumentStatus::Posted->value)
            ->whereNull('documents.approval_request_id')
            ->where('money_entries.user_id', $ctx->actor->id)
            ->whereIn('money_entries.account_id', $cashAccounts)
            ->where('money_entries.occurred_at', '>=', $start->utc())
            ->where('money_entries.occurred_at', '<', $start->addDay()->utc())
            ->sum('money_entries.amount_kzt');
    }

    private function expense(Document $document): Expense
    {
        return Expense::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных расхода');
    }

    /** @return Collection<int, ExpensePayment> */
    private function payments(Document $document): Collection
    {
        return ExpensePayment::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
