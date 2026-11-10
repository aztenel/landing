<?php

namespace App\Domain\Ledger\Posting\Handlers;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Services\UnitIntake;
use App\Domain\Ledger\Enums\BonusKind;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\OpeningBalanceLine;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\MoneyAccount;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use Illuminate\Support\Collection;

/**
 * Входящие остатки, day X (TZ 02 §6.2 №44; architecture §5): phones (in_stock / preparing) with their cost,
 * accessories with cost, money per account, debts per counterparty (signed by §6.1), bonus balances.
 * Owner only (permission doc.opening_balance).
 */
class OpeningBalanceHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly UnitIntake $intake,
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
        private readonly BonusLedger $bonuses,
    ) {}

    public function shiftRequirement(Document $document): \App\Domain\Ledger\Posting\ShiftRequirement
    {
        return \App\Domain\Ledger\Posting\ShiftRequirement::Never;
    }

    public function type(): DocumentType
    {
        return DocumentType::OpeningBalance;
    }

    public function requiresExchangeRate(Document $document): bool
    {
        $lines = $this->lines($document);
        if ($lines->contains(fn ($l) => $l->currency === 'USD' && $l->rate === null) || $lines->contains(fn ($l) => $l->cost_currency === 'USD' && $l->cost_rate === null)) {
            return true;
        }
        $accounts = $lines->where('line_type', 'money')->pluck('account_id');

        return MoneyAccount::query()->whereIn('id', $accounts)->where('currency', 'USD')->exists();
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $ctx->requireStoreId();
        $lines = $this->lines($document);
        if ($lines->isEmpty()) {
            throw PostingException::because('empty', 'Документ входящих остатков пуст');
        }
        foreach ($lines as $line) {
            match ($line->line_type) {
                'unit' => in_array($line->status_after ?? 'in_stock', ['in_stock', 'preparing', 'on_check', 'defective'], true)
                    ?: throw PostingException::because('bad_status', 'Входящий остаток телефона: «В наличии», «На подготовке», «На проверке» или «Брак»'),
                'accessory' => ($line->accessory_id && $line->qty > 0 && $line->unit_cost_kzt !== null && $line->unit_cost_kzt >= 0)
                    ?: throw PostingException::because('bad_line', 'Аксессуар: товар, количество > 0 и себестоимость'),
                'money' => ($line->account_id && (int) $line->amount !== 0)
                    ?: throw PostingException::because('bad_line', 'Деньги: счёт и сумма ≠ 0'),
                'debt' => ($line->counterparty_type && $line->counterparty_id && (int) $line->amount !== 0 && $line->currency)
                    ?: throw PostingException::because('bad_line', 'Долг: контрагент, валюта и сумма ≠ 0'),
                'bonus' => ($line->customer_id && (int) $line->bonus_amount > 0)
                    ?: throw PostingException::because('bad_line', 'Бонусы: клиент и сумма > 0'),
                default => throw PostingException::because('bad_line', "Неизвестный тип строки {$line->line_type}"),
            };
        }
        $this->intake->validate($lines->where('line_type', 'unit'));

        // supplier of a phone is not a locked counterparty: check that it is ours (TZ 03 §18 p.4)
        $suppliers = $lines->where('line_type', 'unit')->pluck('supplier_id')->filter()->map(fn ($id) => (int) $id)->unique();
        if ($suppliers->isNotEmpty() && Supplier::query()->whereIn('id', $suppliers->all())->count() !== $suppliers->count()) {
            throw PostingException::because('supplier_not_found', 'Поставщик не найден в этой фирме');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        $store = Location::store($ctx->requireStoreId());
        foreach ($this->lines($document) as $line) {
            match ($line->line_type) {
                'accessory' => $plan->accessory((int) $line->accessory_id, $store),
                'money' => $plan->account((int) $line->account_id),
                'debt' => $plan->counterparty(CounterpartyType::from($line->counterparty_type), (int) $line->counterparty_id, Currency::from($line->currency)),
                'bonus' => $plan->bonus((int) $line->customer_id),
                default => null,
            };
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $ctx->authorize('doc.opening_balance', message: 'Входящие остатки вводит владелец');
        $store = Location::store($ctx->requireStoreId());

        foreach ($this->lines($document) as $line) {
            switch ($line->line_type) {
                case 'unit':
                    [$cost, $orig] = $this->unitCost($ctx, $line);
                    $unit = $this->intake->create($ctx, $line, $line->supplier_id ? (int) $line->supplier_id : null);
                    $status = UnitStatus::from($line->status_after ?? 'in_stock');
                    $action = UnitAction::OpeningBalance;
                    $this->inventory->unitIn($ctx, $unit, $store, MovementKind::Opening, $action, $status, $cost, $orig);
                    if ($line->cost_unknown) {
                        $unit->forceFill(['cost_unknown' => true])->save();
                    }
                    $line->forceFill(['stock_unit_id' => $unit->id, 'unit_cost_kzt' => $cost])->save();
                    break;

                case 'accessory':
                    $this->inventory->accessoryIn($ctx, (int) $line->accessory_id, $store, (int) $line->qty, (int) $line->unit_cost_kzt, MovementKind::Opening);
                    break;

                case 'money':
                    $this->money->record($ctx, (int) $line->account_id, (int) $line->amount, ArticleCode::Opening);
                    break;

                case 'debt':
                    $currency = Currency::from($line->currency);
                    $rate = $line->rate !== null ? Rate::of((string) $line->rate) : null;
                    $this->debts->record(
                        $ctx, CounterpartyType::from($line->counterparty_type), (int) $line->counterparty_id, $currency,
                        (int) $line->amount, DebtKind::Opening, $line->due_date, $rate,
                    );
                    break;

                case 'bonus':
                    $from = $line->available_from ?? $ctx->occurredAt();
                    $expires = $line->expires_at ?? $from->copy()->addDays($ctx->settings()->bonusLifetimeDays());
                    $this->bonuses->accrue($ctx, (int) $line->customer_id, (int) $line->bonus_amount, $from, $expires, BonusKind::Opening);
                    break;
            }
        }
    }

    /** @return array{0: int, 1: array} */
    private function unitCost(PostingContext $ctx, OpeningBalanceLine $line): array
    {
        if ($line->cost_currency === 'USD') {
            $rate = $line->cost_rate !== null ? Rate::of((string) $line->cost_rate) : $ctx->requireRate();
            $kzt = Money::usdMinor((int) $line->cost_amount_orig)->convert(Currency::KZT, $rate)->amount;

            return [$kzt, ['currency' => 'USD', 'amount' => (int) $line->cost_amount_orig, 'rate' => $rate->toString()]];
        }
        $kzt = (int) ($line->unit_cost_kzt ?? 0);

        return [$kzt, ['currency' => 'KZT', 'amount' => $kzt, 'rate' => null]];
    }

    /** @return Collection<int, OpeningBalanceLine> */
    private function lines(Document $document): Collection
    {
        return OpeningBalanceLine::query()->where('document_id', $document->id)->orderBy('line_no')->orderBy('id')->get();
    }
}
