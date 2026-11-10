<?php

namespace App\Domain\TradeIn\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\TradeIn\Models\PrepCost;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * «Затраты на подготовку» ЗПД: + cost of a used phone on hand (preparing / on check / in stock), paid from an account
 * (article «Выкуп б/у» — it is part of the purchase of the phone) or owed to the master (supplier debt with a due date).
 * Right stock.prepare_cost. A sold phone gets no preparation costs — that is an expense.
 */
class PrepCostHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly InventoryLedger $inventory, private readonly MoneyLedger $money, private readonly DebtLedger $debts) {}

    public function type(): DocumentType
    {
        return DocumentType::PrepCost;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $c = $this->row($document);
        if ($c->amount <= 0 || $c->amount % Money::TENGE !== 0) {
            throw PostingException::because('bad_amount', 'Сумма затраты — целые тенге');
        }
        if ($c->supplier_id && ! $c->due_date) {
            throw PostingException::because('due_date_required', 'Укажите, когда заплатить мастеру');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $c = $this->row($document);
        $plan = (new LockPlan)->unit((int) $c->stock_unit_id)->account($c->account_id ? (int) $c->account_id : null);

        return $c->supplier_id ? $plan->counterparty(CounterpartyType::Supplier, (int) $c->supplier_id, Currency::KZT) : $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $c = $this->row($document);
        $ctx->authorize('stock.prepare_cost', message: 'Затраты на подготовку вносит админ или владелец');
        $unit = $ctx->locks->unit((int) $c->stock_unit_id);
        if (! in_array($unit->status, [UnitStatus::Preparing, UnitStatus::OnCheck, UnitStatus::InStock, UnitStatus::Defective], true)) {
            throw PostingException::because('unit_not_on_hand', 'Затраты добавляются только к телефону на складе');
        }
        $this->inventory->unitCost($ctx, $unit, (int) $c->amount, MovementKind::PrepCost);
        if ($c->account_id) {
            $this->money->record($ctx, (int) $c->account_id, -(int) $c->amount, ArticleCode::Buyout, ['comment' => 'Подготовка '.$unit->public_code]);
        } else {
            $this->debts->record($ctx, CounterpartyType::Supplier, (int) $c->supplier_id, Currency::KZT, -(int) $c->amount, DebtKind::PurchaseCredit,
                CarbonImmutable::parse($c->due_date), comment: 'Подготовка '.$unit->public_code);
        }
    }

    private function row(Document $document): PrepCost
    {
        return PrepCost::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных затраты');
    }
}
