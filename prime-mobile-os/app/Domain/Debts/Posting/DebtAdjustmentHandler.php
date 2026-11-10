<?php

namespace App\Domain\Debts\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Debts\Models\DebtAdjustment;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\ShiftRequirement;
use App\Domain\Tenancy\Access\Limit;
use App\Support\Money\Currency;
use Carbon\CarbonImmutable;

/**
 * «Корректировка долга» КДЛ (TZ 01 П14.1, 02 §6.2 №35, 03 право 26 и «Перенос срока долга»). Reason required.
 *   adjust   ± the debt by the reason. Customer: debt.customer_adjust (owner; admin — by approval). Supplier: debt.supplier.
 *            A debt never flips to the other side (that would be an advance, not a correction); a growth needs a due date.
 *   postpone the oldest $amount gets a new due date: −amount and +amount with the new date (the balance does not change,
 *            the nearest due date moves). Customer: debt.customer_postpone, its days limit L5 counts to the new date.
 */
class DebtAdjustmentHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly DebtLedger $debts) {}

    public function type(): DocumentType
    {
        return DocumentType::DebtAdjustment;
    }

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        return ShiftRequirement::Never; // no money moves
    }

    public function requiresExchangeRate(Document $document): bool
    {
        return DebtAdjustment::query()->where('document_id', $document->id)->value('currency') === 'USD';
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $a = $this->row($document);
        if (trim((string) $document->comment) === '') {
            throw PostingException::because('reason_required', 'Напишите основание корректировки долга');
        }
        if ($a->due_date && $a->due_date->lt($ctx->businessDate())) {
            throw PostingException::because('due_date_past', 'Срок долга не может быть в прошлом');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $a = $this->row($document);

        return (new LockPlan)->counterparty(CounterpartyType::from($a->counterparty_type), (int) $a->counterparty_id, Currency::from($a->currency));
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $a = $this->row($document);
        $type = CounterpartyType::from($a->counterparty_type);
        $currency = Currency::from($a->currency);
        $customer = $type === CounterpartyType::Customer;
        $balance = $ctx->locks->counterparty($type, (int) $a->counterparty_id, $currency);
        $owed = $customer ? (int) $balance->balance : -(int) $balance->balance; // how much is owed, ≥ 0 normally
        $comment = $document->comment;

        if ($a->operation === 'postpone') {
            if ($customer) {
                $days = (int) $ctx->businessDate()->diffInDays(CarbonImmutable::parse($a->due_date->format('Y-m-d')));
                $ctx->authorize('debt.customer_postpone', Limit::CustomerDebtDays, $days, message: 'Перенести срок долга — по праву роли');
            } else {
                $ctx->authorize('debt.supplier', message: 'Срок долга поставщику меняет админ или владелец');
            }
            if ($a->amount > $owed) {
                throw PostingException::because('postpone_over_debt', 'Переносится больше, чем долг');
            }
            $sign = $customer ? 1 : -1;
            $a->forceFill(['previous_due_date' => $balance->nearest_due_date])->save();
            $this->debts->record($ctx, $type, (int) $a->counterparty_id, $currency, -$sign * (int) $a->amount, DebtKind::Adjustment, comment: $comment);
            $this->debts->record($ctx, $type, (int) $a->counterparty_id, $currency, $sign * (int) $a->amount, DebtKind::Adjustment, $a->due_date, comment: $comment);

            return;
        }

        $ctx->authorize($customer ? 'debt.customer_adjust' : 'debt.supplier', message: $customer
            ? 'Корректировка долга клиента — владелец (админ — заявкой)'
            : 'Корректировка долга поставщику — админ или владелец');
        $growth = $customer ? (int) $a->amount : -(int) $a->amount; // + the debt grows
        if ($growth < 0 && -$growth > $owed) {
            throw PostingException::because('adjust_over_debt', 'Списывается больше, чем долг');
        }
        if ($growth > 0 && ! $a->due_date) {
            throw PostingException::because('due_date_required', 'Укажите срок оплаты долга');
        }
        $this->debts->record($ctx, $type, (int) $a->counterparty_id, $currency, (int) $a->amount, DebtKind::Adjustment, $growth > 0 ? $a->due_date : null, comment: $comment);
    }

    private function row(Document $document): DebtAdjustment
    {
        return DebtAdjustment::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных корректировки долга');
    }
}
