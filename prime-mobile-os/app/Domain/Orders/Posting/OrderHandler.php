<?php

namespace App\Domain\Orders\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Inventory\UnitTransitions;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\ShiftRequirement;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Orders\Models\Order;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rounding;

/**
 * «Заказ (бронь) с предоплатой» ЗАК (TZ 01 П10.2, 02 №22):
 *  - a concrete phone in stock (or in this cart) → «Забронирован» (ordered), held by the order;
 *  - a variant that is not in stock («под заказ») — no phone, attached later;
 *  - prepayment (optional): + the account of the payment method (− bank commission), − customer debt (our advance);
 *  - hold: the firm's «Бронь заказа, дней»; price fixed at the order.
 * Cancel = reversal of the order: the prepayment goes back the same way, the phone is free again. A seller cancels own
 * orders (order.create), others' — order.cancel_any. A sold order cannot be cancelled: reverse the sale first.
 */
class OrderHandler extends AbstractDocumentHandler
{
    public function __construct(private readonly MoneyLedger $money, private readonly DebtLedger $debts) {}

    public function type(): DocumentType
    {
        return DocumentType::Order;
    }

    public function shiftRequirement(Document $document): ShiftRequirement
    {
        // money at the till needs the shift (audit №18); a bare hold does not
        return $this->order($document)->prepayment > 0 ? ShiftRequirement::Always : ShiftRequirement::Never;
    }

    public function validate(Document $document, PostingContext $ctx): void
    {
        $o = $this->order($document);
        if ($o->price <= 0 || $o->price % Money::TENGE !== 0 || $o->prepayment % Money::TENGE !== 0) {
            throw PostingException::because('bad_price', 'Цена и предоплата — целые тенге, цена больше нуля');
        }
        if ($o->prepayment > $o->price) {
            throw PostingException::because('prepayment_over_price', 'Предоплата больше цены');
        }
        if ($o->kind === 'unit' && ! $o->stock_unit_id || $o->kind === 'variant' && ! $o->variant_id) {
            throw PostingException::because('subject_required', 'Выберите телефон или вариант');
        }
        if (! $document->store_id) {
            throw PostingException::because('store_required', 'Выберите магазин');
        }
    }

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $o = $this->order($document);
        $plan = (new LockPlan)->unit($o->stock_unit_id ? (int) $o->stock_unit_id : null);
        if ($o->prepayment > 0) {
            $plan->account($this->method($o, $document)->account_id)->counterparty(CounterpartyType::Customer, (int) $o->customer_id, Currency::KZT);
        }

        return $plan;
    }

    public function reversalLockPlan(Document $document): LockPlan
    {
        $o = $this->order($document);

        return (new LockPlan)->unit($o->stock_unit_id ? (int) $o->stock_unit_id : null);
    }

    public function post(Document $document, PostingContext $ctx): void
    {
        $o = $this->order($document);
        $ctx->authorize('order.create', message: 'Заказ оформляет продавец этого магазина');

        if ($o->stock_unit_id) {
            $unit = $ctx->locks->unit((int) $o->stock_unit_id);
            $mine = $unit->status === UnitStatus::Reserved && UnitReservation::query()->where('stock_unit_id', $unit->id)
                ->whereNull('released_at')->where('user_id', $ctx->actor->id)->exists();
            if ($unit->status !== UnitStatus::InStock && ! $mine) {
                throw PostingException::because('unit_not_free', "Телефон {$unit->public_code} не свободен: ".$unit->status->label());
            }
            if (! Location::store((int) $document->store_id)->equals($unit->location())) {
                throw PostingException::because('unit_other_store', 'Телефон в другом магазине — сначала переместите его');
            }
            if ($mine) {
                UnitReservation::query()->where('stock_unit_id', $unit->id)->whereNull('released_at')
                    ->update(['released_at' => $ctx->now, 'release_reason' => 'ordered', 'updated_at' => $ctx->now]);
                $unit->forceFill(['status' => UnitStatus::InStock->value])->save();
            }
            UnitTransitions::assert(UnitStatus::InStock, UnitStatus::Ordered, UnitAction::Order);
            $unit->forceFill(['status' => UnitStatus::Ordered->value, 'order_document_id' => $document->id])->save();
            $o->forceFill(['variant_id' => $unit->variant_id])->save();
        }

        if ($o->prepayment > 0) {
            $method = $this->method($o, $document);
            $attrs = ['counterparty_type' => CounterpartyType::Customer->value, 'counterparty_id' => (int) $o->customer_id];
            $this->money->record($ctx, (int) $method->account_id, (int) $o->prepayment, ArticleCode::Prepayment, $attrs);
            $commission = Money::kztMinor((int) $o->prepayment)->percent((string) $method->commission_percent, Rounding::HalfUp, Money::TENGE)->amount;
            if ($commission > 0) {
                $this->money->record($ctx, (int) $method->account_id, -$commission, ArticleCode::BankFee, $attrs);
            }
            $this->debts->record($ctx, CounterpartyType::Customer, (int) $o->customer_id, Currency::KZT, -(int) $o->prepayment, DebtKind::Prepayment);
            $o->forceFill(['prepayment_account_id' => $method->account_id])->save();
        }

        $days = (int) $ctx->settings()->get('sales.order_hold_days');
        $o->forceFill(['status' => 'active', 'hold_until' => $ctx->now->addDays(max(1, $days)), 'seller_id' => $o->seller_id ?: $ctx->actor->id])->save();
    }

    /** Cancel by the seller of the order (order.create) or by anyone with order.cancel_any — not the general doc.reverse. */
    public function authorizeReversal(Document $document, PostingContext $ctx): bool
    {
        $o = $this->order($document);
        $ctx->authorize((int) $o->seller_id === (int) $ctx->actor->id ? 'order.create' : 'order.cancel_any', message: 'Отменить чужой заказ может админ');

        return true;
    }

    public function assertReversible(Document $document, PostingContext $ctx): void
    {
        $o = $this->order($document);
        if ($o->status === 'sold') {
            throw ReversalBlocked::because('order_sold', 'Заказ продан — сначала сторнируйте продажу');
        }
        if (! $o->isOpen() && $o->status !== 'expired') {
            throw ReversalBlocked::because('order_closed', 'Заказ уже закрыт');
        }
    }

    public function afterReverse(Document $original, PostingContext $ctx): void
    {
        $o = $this->order($original);
        if ($o->stock_unit_id) {
            $unit = $ctx->locks->unit((int) $o->stock_unit_id);
            if ($unit->status === UnitStatus::Ordered && (int) $unit->order_document_id === (int) $original->id) {
                UnitTransitions::assert(UnitStatus::Ordered, UnitStatus::InStock, UnitAction::CancelOrder);
                $unit->forceFill(['status' => UnitStatus::InStock->value, 'order_document_id' => null])->save();
            }
        }
        $o->forceFill(['status' => 'cancelled', 'closed_at' => $ctx->now])->save();
    }

    private function method(Order $o, Document $document): PaymentMethod
    {
        $code = PaymentMethodCode::tryFrom((string) $o->prepayment_method);
        if (! $code || in_array($code, [PaymentMethodCode::Debt, PaymentMethodCode::Bonus, PaymentMethodCode::TradeIn, PaymentMethodCode::Prepayment, PaymentMethodCode::ExchangeOffset, PaymentMethodCode::KaspiShop], true)) {
            throw PostingException::because('bad_prepayment_method', 'Предоплата — наличными, Kaspi или картой');
        }

        return PaymentMethod::resolve($code, (int) $document->store_id)
            ?? throw PostingException::because('method_disabled', 'Этот способ оплаты выключен в настройках');
    }

    private function order(Document $document): Order
    {
        return Order::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных заказа');
    }
}
