<?php

namespace App\Domain\TradeIn\Posting;

use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\UnitIntake;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\TradeIn\Models\TradeInAct;
use App\Domain\TradeIn\Models\TradeInPayout;
use App\Domain\TradeIn\Services\TradeInGrid;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * «Акт trade-in» ТРД (TZ 01 П12, 02 №18–19, audit №29, №40). The phone comes in as a used one «на подготовке» with the
 * cost = the buy-back sum; a phone we sold before comes back as the same unit (TradeInKnown, its sale line closes).
 * Money: the part «в зачёт» goes to «Зачёт trade-in» (−) and the check of the same operation takes it (+); the rest
 * is paid out from the cash register / Kaspi of the store. Grid (L13 on the whole sum), cash payout (L12).
 * Never accepted: iCloud / «Локатор» on, госбаза «Не верифицирован» / «Заблокирован».
 */
class TradeInHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly UnitIntake $intake,
        private readonly TradeInGrid $grid,
    ) {}

    public function type(): DocumentType
    {
        return DocumentType::TradeIn;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $act = $this->act($document);
        if ($act->amount <= 0 || $act->amount % Money::TENGE !== 0) {
            throw PostingException::because('bad_amount', 'Сумма выкупа — целые тенге больше нуля');
        }
        $payouts = $this->payouts($document);
        if ((int) $act->offset_amount + (int) $payouts->sum('amount') !== (int) $act->amount) {
            throw PostingException::because('payout_mismatch', 'Зачёт и выплата должны давать сумму выкупа '.Money::kztMinor((int) $act->amount)->format());
        }
        if (($act->checklist['icloud'] ?? null) !== 'yes') {
            throw PostingException::because('icloud_on', 'С включённым iCloud / «Локатором» телефон не принимаем');
        }
        if (in_array($act->imei_check, ['not_registered', 'blocked'], true)) {
            throw PostingException::because('imei_registry', 'Принимать нельзя: телефон не прошёл госбазу');
        }
        if ($act->imei1 && ($act->imei_check !== 'registered' || ! $act->imei_verified)) {
            throw PostingException::because('imei_check_required', 'Сверьте IMEI с *#06# и проверьте телефон в госбазе');
        }
        if (! $act->customer_id) {
            throw PostingException::because('customer_required', 'Укажите, кто сдаёт телефон (ФИО, ИИН, телефон)');
        }
        foreach ($payouts as $p) {
            $method = PaymentMethodCode::tryFrom($p->method);
            if (! $method || ! $method->isMoney() || $p->amount <= 0 || $p->amount % Money::TENGE !== 0) {
                throw PostingException::because('bad_payout', 'Выплата — наличными или переводом, целые тенге');
            }
            $setup = PaymentMethod::resolve($method, (int) $document->store_id);
            if (! $setup?->account_id) {
                throw PostingException::because('no_account', "Способ «{$method->label()}» выключен или без счёта");
            }
            $p->forceFill(['account_id' => $setup->account_id])->save();
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $act = $this->act($document);
        $plan = (new LockPlan)->unit($this->existing($act)?->id);
        foreach ($this->payouts($document) as $p) {
            $plan->account((int) $p->account_id);
        }
        if ($act->offset_amount > 0) {
            $plan->account(MoneyAccount::byCode(MoneyAccount::CODE_TRADEIN_OFFSET)->id);
        }

        return $plan;
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $act = $this->act($document);
        $ctx->authorize('tradein.create', message: 'Акт trade-in оформляет сотрудник магазина');
        $store = Location::store($ctx->requireStoreId());

        // grid (audit №29): L13 on the whole sum; no grid row — the owner only, others by request
        $quote = $this->grid->quote((int) $act->variant_id, ['grade' => $act->grade, 'battery' => $act->battery_percent, 'replaced' => $act->replaced ?? [], 'kit' => $act->kit ?? []]);
        if ($quote === null) {
            $ctx->requireApprovalUnlessOwner('tradein.over_grid', 'tradein_grid', 'Для этой модели нет сетки выкупа — нужна заявка владельцу');
        } elseif ($act->amount > $quote['price']) {
            $over = $quote['price'] > 0 ? (int) ceil(($act->amount - $quote['price']) * 10000 / $quote['price']) : PHP_INT_MAX;
            $ctx->authorize('tradein.over_grid', Limit::BuyoutOverGridPercent, $over, 'tradein_grid',
                'Сумма выше сетки на '.Money::kztMinor((int) $act->amount - $quote['price'])->format().' — нужна заявка');
        }
        $cash = (int) $this->payouts($document)->filter(fn ($p) => PaymentMethodCode::from($p->method)->isCash())->sum('amount');
        if ($cash > 0) {
            $ctx->authorize('tradein.buyout', Limit::BuyoutCash, $cash, 'tradein_cash', 'Выкуп за наличные сверх лимита — нужна заявка');
        }

        // the phone
        $unit = $this->existing($act);
        if ($unit) {
            if ($unit->status !== UnitStatus::Sold) {
                throw PostingException::because('unit_in_stock', "Этот IMEI уже у нас: {$unit->public_code}, «".UnitStatus::labelOf($unit->status).'»');
            }
            $this->inventory->unitIn($ctx, $unit, $store, MovementKind::TradeInIn, UnitAction::TradeInKnown, UnitStatus::Preparing, (int) $act->amount);
            $ctx->locks->unit($unit->id)->forceFill(['grade' => $act->grade, 'battery_percent' => $act->battery_percent])->save();
        } else {
            $line = (new TradeInAct)->forceFill([
                'variant_id' => $act->variant_id, 'condition' => 'used', 'grade' => $act->grade, 'battery_percent' => $act->battery_percent,
                'year' => null, 'defects' => null, 'imei1' => $act->imei1, 'imei2' => $act->imei2, 'serial' => $act->serial, 'show_on_site' => null, 'sale_price' => null,
            ]);
            $unit = $this->intake->create($ctx, $line, null);
            $this->inventory->unitIn($ctx, $unit, $store, MovementKind::TradeInIn, UnitAction::ReceiveUsed, UnitStatus::Preparing, (int) $act->amount);
        }
        $act->forceFill(['stock_unit_id' => $unit->id, 'grid_price' => $quote['price'] ?? null])->save();

        // money: the part «в зачёт» and the payouts
        $who = ['counterparty_type' => 'customer', 'counterparty_id' => (int) $act->customer_id];
        if ($act->offset_amount > 0) {
            $this->money->record($ctx, MoneyAccount::byCode(MoneyAccount::CODE_TRADEIN_OFFSET)->id, -(int) $act->offset_amount, ArticleCode::Buyout, $who + ['comment' => 'Выкуп б/у (зачёт)']);
        }
        foreach ($this->payouts($document) as $p) {
            $this->money->record($ctx, (int) $p->account_id, -(int) $p->amount, ArticleCode::Buyout, $who);
        }
    }

    /** An act with a part «в зачёт» is reversed only together with its check (audit №40 p.5). */
    public function assertReversible(Document $document, PostingContext $ctx): void
    {
        $act = $this->act($document);
        if ($act->offset_amount <= 0) {
            return;
        }
        $sale = SalePayment::query()->where('tradein_document_id', $document->id)->value('document_id');
        if ($sale && Document::query()->whereKey($sale)->first()?->status === DocumentStatus::Posted) {
            throw ReversalBlocked::because('tradein_with_sale', 'Акт зачтён в чек — сторнируйте продажу и акт вместе');
        }
    }

    private function existing(TradeInAct $act): ?StockUnit
    {
        foreach ([$act->imei1, $act->imei2, $act->serial] as $id) {
            if ($id && ($unit = StockUnit::findByIdentifier($id))) {
                return $unit;
            }
        }

        return null;
    }

    private function act(Document $document): TradeInAct
    {
        return TradeInAct::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных акта trade-in');
    }

    /** @return Collection<int, TradeInPayout> */
    private function payouts(Document $document): Collection
    {
        return TradeInPayout::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
