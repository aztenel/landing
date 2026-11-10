<?php

namespace App\Domain\Sales\Posting;

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Ledger\Enums\BonusKind;
use App\Domain\Ledger\Enums\DebtKind;
use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\AbstractDocumentHandler;
use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Domain\Ledger\Posting\Ledgers\DebtLedger;
use App\Domain\Ledger\Posting\Ledgers\InventoryLedger;
use App\Domain\Ledger\Posting\Ledgers\MoneyLedger;
use App\Domain\Ledger\Posting\LockPlan;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Access\Limit;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rounding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Продажа (TZ 02 §6.2 №10–17, §3.2, §10.4; architecture §2.6–2.7).
 *
 * Lines: a unit (phone, by IMEI) or an accessory × qty. Payments (split): cash (tendered/change), card, Kaspi,
 * installments (full amount + bank commission), debt (due date), bonus (FIFO), trade-in offset (technical account),
 * prepayment. Σ payments = check total, all whole tenge. The check discount is spread over lines by the largest
 * remainder method; every line stores its cost at posting time.
 *
 * IMEI protection (architecture §2.7): 1) unique identifiers; 2) a reserved unit is sellable only by the seller
 * who holds it; 3) the unit row is locked FOR UPDATE and its status re-checked under the lock; 4) the partial
 * unique index sale_lines(stock_unit_id) WHERE is_active rejects a second active sale even if the code is wrong.
 */
class SaleHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly InventoryLedger $inventory,
        private readonly MoneyLedger $money,
        private readonly DebtLedger $debts,
        private readonly BonusLedger $bonuses,
    ) {}

    public function type(): DocumentType
    {
        return DocumentType::Sale;
    }

    // ------------------------------------------------------------------ validate (no locks yet)

    public function validate(Document $document, PostingContext $ctx): void
    {
        $ctx->requireStoreId();
        $sale = $this->sale($document);
        $lines = $this->lines($document);
        $payments = $this->payments($document);

        if ($lines->isEmpty()) {
            throw PostingException::because('empty', 'Чек пустой');
        }
        if ($sale->customer_id && ! Customer::query()->whereKey($sale->customer_id)->exists()) {
            throw PostingException::because('customer_not_found', 'Клиент не найден');
        }
        // the seller earns the sale (reports, % of sales): only an active member of this organization (TZ 03 §18 p.4)
        if (! $sale->seller_id || ! Membership::query()->where('user_id', $sale->seller_id)->where('status', MembershipStatus::Active->value)->exists()) {
            throw PostingException::because('seller_not_member', 'Продавец не работает в этой фирме');
        }

        $units = [];
        $subtotal = 0;
        $base = 0;
        foreach ($lines as $line) {
            if ($line->stock_unit_id) {
                if ($line->qty !== 1 || $line->accessory_id) {
                    throw PostingException::because('bad_line', 'Телефон продаётся поштучно');
                }
                if (isset($units[$line->stock_unit_id])) {
                    throw PostingException::because('duplicate_unit', 'Один телефон дважды в чеке');
                }
                $units[$line->stock_unit_id] = true;
            } elseif (! $line->accessory_id || $line->qty <= 0) {
                throw PostingException::because('bad_line', 'Строка чека: укажите товар и количество');
            }
            $this->assertWholeTenge($line->price, 'Цена');
            $this->assertWholeTenge($line->line_discount, 'Скидка');
            if ($line->price < 0 || $line->line_discount < 0 || $line->line_discount > $line->price * $line->qty) {
                throw PostingException::because('bad_discount', 'Скидка на строку больше её суммы');
            }
            if ($line->is_gift && $line->stock_unit_id) {
                throw PostingException::because('bad_gift', 'Телефон не может быть подарком');
            }
            if ($line->is_gift && ($line->price !== 0 || $line->line_discount !== 0)) {
                throw PostingException::because('bad_gift', 'Подарок к покупке продаётся за 0 ₸');
            }
            $subtotal += $line->price * $line->qty;
            $base += $line->price * $line->qty - $line->line_discount;
        }

        $this->assertWholeTenge($sale->check_discount, 'Скидка на чек');
        if ($sale->check_discount < 0 || $sale->check_discount > $base) {
            throw PostingException::because('bad_discount', 'Скидка на чек больше суммы чека');
        }
        $total = $base - $sale->check_discount;

        $paid = 0;
        foreach ($payments as $p) {
            $this->assertWholeTenge($p->amount, 'Оплата');
            if ($p->amount <= 0) {
                throw PostingException::because('bad_payment', 'Сумма оплаты должна быть больше нуля');
            }
            $method = $p->method;
            // the trade-in offset is accepted only together with the trade-in act of the same operation (audit №6, №40);
            // until the trade-in module plugs in, the method is closed
            if ($method === PaymentMethodCode::TradeIn) {
                throw PostingException::because('tradein_without_act', 'Зачёт trade-in принимается только вместе с актом trade-in');
            }
            if ($method->isMoney()) {
                // account and bank commission come from the firm settings of the payment method, never from the request
                $setup = PaymentMethod::resolve($method, $ctx->requireStoreId());
                if (! $setup || ! $setup->account_id) {
                    throw PostingException::because('no_account', "Способ оплаты «{$method->label()}» выключен или для него не настроен счёт");
                }
                $p->forceFill(['account_id' => $setup->account_id, 'commission_percent' => $setup->commission_percent])->save();
            }
            if ($method->isCash()) {
                if ($p->tendered !== null && ($p->tendered < $p->amount || $p->tendered % Money::TENGE !== 0)) {
                    throw PostingException::because('bad_tendered', 'Внесено меньше суммы оплаты наличными');
                }
            } elseif ($p->tendered !== null) {
                throw PostingException::because('bad_tendered', '«Внесено» и «сдача» — только для наличных');
            }
            if (in_array($method, [PaymentMethodCode::Debt, PaymentMethodCode::Bonus, PaymentMethodCode::Prepayment], true) && ! $sale->customer_id) {
                throw PostingException::because('customer_required', "Для оплаты «{$method->label()}» выберите клиента");
            }
            if ($method === PaymentMethodCode::Debt) {
                if (! $p->debt_due_date) {
                    throw PostingException::because('due_date_required', 'Укажите срок возврата долга');
                }
                if ($p->debt_due_date->lt($ctx->businessDate())) {
                    throw PostingException::because('due_date_past', 'Срок долга не может быть в прошлом');
                }
            }
            $paid += $p->amount;
        }
        if ($paid !== $total) {
            throw PostingException::because('payments_mismatch', sprintf(
                'Сумма оплат %s не равна итогу чека %s', Money::kztMinor($paid)->format(), Money::kztMinor($total)->format(),
            ), ['paid' => $paid, 'total' => $total]);
        }
    }

    // ------------------------------------------------------------------ locks

    public function lockPlan(Document $document, PostingContext $ctx): LockPlan
    {
        $plan = new LockPlan;
        $store = Location::store($ctx->requireStoreId());
        $sale = $this->sale($document);
        foreach ($this->lines($document) as $line) {
            $line->stock_unit_id
                ? $plan->unit((int) $line->stock_unit_id)
                : $plan->accessory((int) $line->accessory_id, $store);
        }
        foreach ($this->payments($document) as $p) {
            $plan->account($p->account_id ? (int) $p->account_id : null);
            if (in_array($p->method, [PaymentMethodCode::Debt, PaymentMethodCode::Prepayment], true)) {
                $plan->counterparty(CounterpartyType::Customer, (int) $sale->customer_id, Currency::KZT);
            }
        }
        $plan->bonus($sale->customer_id ? (int) $sale->customer_id : null);

        return $plan;
    }

    // ------------------------------------------------------------------ post (under locks)

    public function post(Document $document, PostingContext $ctx): void
    {
        $sale = $this->sale($document);
        $lines = $this->lines($document);
        $payments = $this->payments($document);
        $storeId = $ctx->requireStoreId();
        $store = Location::store($storeId);
        $customerId = $sale->customer_id ? (int) $sale->customer_id : null;

        $ctx->authorize('sale.create');
        if ((int) $sale->seller_id !== $ctx->actor->id) {
            $ctx->authorize('sale.set_seller', message: 'Выбрать другого продавца в чеке может старший продавец, админ или владелец');
        }

        // 1. phones: re-check under the row lock (layer 3) + sale rules of TZ 02 §10.4
        foreach ($lines->whereNotNull('stock_unit_id') as $line) {
            $this->assertSellable($ctx, $ctx->locks->unit((int) $line->stock_unit_id), $sale, $storeId);
        }

        // 2. costs at posting time
        $costs = [];
        foreach ($lines as $line) {
            if ($line->stock_unit_id) {
                $costs[$line->id] = (int) $ctx->locks->unit((int) $line->stock_unit_id)->cost_kzt;
            } else {
                $balance = $ctx->locks->accessoryBalance($store, (int) $line->accessory_id);
                if ((int) $balance->qty < $line->qty) {
                    throw InsufficientStock::because('accessory_short', "Нет на остатке: позиция {$line->line_no} (есть {$balance->qty} шт.). Позовите админа: сделать приход или ревизию");
                }
                $costs[$line->id] = (int) $ctx->locks->accessoryCost((int) $line->accessory_id)->avg_cost_kzt * $line->qty;
            }
        }

        // 3. amounts: check discount spread by the largest remainder method in whole tenge (TZ 02 §3.2)
        $bases = [];
        foreach ($lines as $line) {
            $bases[$line->id] = $line->price * $line->qty - $line->line_discount;
        }
        $shares = Money::kztMinor((int) $sale->check_discount)->allocate($bases, Money::TENGE);
        $amounts = [];
        foreach ($bases as $id => $b) {
            $amounts[$id] = $b - $shares[$id]->amount;
        }
        // 4. limits and permissions (TZ 03 §5). The list price comes from the database, never from the request: a
        // price below the list price is a discount for the limits, a gift is a 100 % discount (audit №2).
        $markdown = 0;
        $listSubtotal = 0;
        foreach ($lines as $line) {
            $list = $this->listPrice($ctx, $line);
            $line->forceFill(['list_price_kzt' => $list])->save();
            if ($list === null) {
                $listSubtotal += $line->price * $line->qty;

                continue;
            }
            $listSubtotal += $list * $line->qty;
            $markdown += max(0, $list - $line->price) * $line->qty;
            if ($line->price > $list) {
                $ctx->authorize('sale.price_up');
            }
        }
        $subtotal = (int) $lines->sum(fn ($l) => $l->price * $l->qty);
        $total = array_sum($amounts);
        $discountTotal = $markdown + (int) $lines->sum('line_discount') + (int) $sale->check_discount;

        $this->authorizeDiscount($ctx, $sale, $discountTotal, $listSubtotal);
        foreach ($lines as $line) {
            $unknownCost = $line->stock_unit_id && $ctx->locks->unit((int) $line->stock_unit_id)->cost_unknown;
            if (! $line->is_gift && ! $unknownCost && $amounts[$line->id] < $costs[$line->id]) {
                $ctx->authorize('sale.below_cost', approvalType: 'below_cost', message: 'Нужно разрешение владельца: цена ниже допустимой');
            }
        }

        $byMethod = fn (PaymentMethodCode $m) => (int) $payments->filter(fn ($p) => $p->method === $m)->sum('amount');
        $bonusPaid = $byMethod(PaymentMethodCode::Bonus);
        $debtPaid = $byMethod(PaymentMethodCode::Debt);
        if ($debtPaid > 0) {
            $this->authorizeDebt($ctx, $customerId, $debtPaid, $payments);
        }
        $prepaymentPaid = $byMethod(PaymentMethodCode::Prepayment);
        if ($prepaymentPaid > 0) {
            // «из аванса» only up to the advance we hold (customer balance < 0), re-read under the lock — otherwise
            // the payment would silently become a debt without due date and credit checks (TZ 02 §6.2 №16)
            $advance = max(0, -(int) $ctx->locks->counterparty(CounterpartyType::Customer, $customerId, Currency::KZT)->balance);
            if ($prepaymentPaid > $advance) {
                throw PostingException::because('prepayment_exceeds', 'Оплата из аванса '.Money::kztMinor($prepaymentPaid)->format()
                    .' больше аванса клиента '.Money::kztMinor($advance)->format(), ['advance' => $advance, 'requested' => $prepaymentPaid]);
            }
        }
        if ($bonusPaid > 0) {
            $ctx->authorize('bonus.redeem');
            $max = Money::kztMinor($total)->percent($ctx->settings()->bonusMaxPayPercent(), Rounding::Down, Money::TENGE)->amount;
            if ($bonusPaid > $max) {
                throw PostingException::because('bonus_over_max', 'Бонусами можно оплатить не больше '.Money::kztMinor($max)->format().' ('.$ctx->settings()->bonusMaxPayPercent().' % чека)');
            }
        }
        $bonusShares = Money::kztMinor($bonusPaid)->allocate($amounts, Money::TENGE);
        $debtShares = Money::kztMinor($debtPaid)->allocate($amounts, Money::TENGE);

        // 5. goods out
        foreach ($lines as $line) {
            if ($line->stock_unit_id) {
                $unit = $ctx->locks->unit((int) $line->stock_unit_id);
                $this->inventory->unitOut($ctx, $unit, MovementKind::Sale, UnitAction::Sell, UnitStatus::Sold);
                UnitReservation::query()->where('stock_unit_id', $unit->id)->whereNull('released_at')
                    ->update(['released_at' => $ctx->now, 'release_reason' => 'sold', 'updated_at' => $ctx->now]);
                if ($unit->order_document_id) {
                    $unit->forceFill(['order_document_id' => null])->save();
                }
            } else {
                $this->inventory->accessoryOut($ctx, (int) $line->accessory_id, $store, (int) $line->qty, MovementKind::Sale);
            }
        }

        // 6. money, debt, bonus payments
        $customerAttrs = $customerId ? ['counterparty_type' => CounterpartyType::Customer->value, 'counterparty_id' => $customerId] : [];
        foreach ($payments as $p) {
            $changes = [];
            switch (true) {
                case $p->method->isMoney():
                    $account = $this->money->account((int) $p->account_id);
                    if ($account->currency !== Currency::KZT) {
                        throw PostingException::because('kzt_only', 'Покупатель платит только в тенге');
                    }
                    $this->money->record($ctx, $account->id, (int) $p->amount, ArticleCode::Revenue, $customerAttrs + ['sale_payment_id' => $p->id]);
                    $commission = Money::kztMinor((int) $p->amount)->percent((string) $p->commission_percent, Rounding::HalfUp, Money::TENGE)->amount;
                    if ($commission > 0) {
                        $this->money->record($ctx, $account->id, -$commission, ArticleCode::BankFee, ['sale_payment_id' => $p->id]);
                    }
                    $changes = ['commission_amount' => $commission, 'change' => $p->method->isCash() && $p->tendered !== null ? $p->tendered - $p->amount : 0];
                    break;
                case $p->method === PaymentMethodCode::TradeIn:
                    if ($this->money->account((int) $p->account_id)->currency !== Currency::KZT) {
                        throw PostingException::because('kzt_only', 'Зачёт trade-in — только в тенге');
                    }
                    $this->money->record($ctx, (int) $p->account_id, (int) $p->amount, ArticleCode::Revenue, $customerAttrs + ['sale_payment_id' => $p->id, 'comment' => 'Выручка (зачёт trade-in)']);
                    break;
                case $p->method === PaymentMethodCode::Debt:
                    $this->debts->record($ctx, CounterpartyType::Customer, $customerId, Currency::KZT, (int) $p->amount, DebtKind::SaleCredit, $p->debt_due_date);
                    break;
                case $p->method === PaymentMethodCode::Prepayment:
                    $this->debts->record($ctx, CounterpartyType::Customer, $customerId, Currency::KZT, (int) $p->amount, DebtKind::PrepaymentUse);
                    break;
                case $p->method === PaymentMethodCode::Bonus:
                    $this->bonuses->spend($ctx, $customerId, intdiv((int) $p->amount, Money::TENGE));
                    break;
            }
            if ($changes) {
                $p->forceFill($changes)->save();
            }
        }

        // 7. bonus accrual: base = line amount − part paid with bonuses − part on credit (TZ 02 §7, №13)
        $accrued = [];
        if ($customerId && $ctx->settings()->bonusEnabled()) {
            $availableFrom = $ctx->occurredAt()->addDays($ctx->settings()->bonusActivationDays());
            $expiresAt = $availableFrom->addDays($ctx->settings()->bonusLifetimeDays());
            foreach ($lines as $line) {
                $base = $amounts[$line->id] - $bonusShares[$line->id]->amount - $debtShares[$line->id]->amount;
                $percent = $line->stock_unit_id ? $ctx->settings()->bonusPhonePercent() : $ctx->settings()->bonusAccessoryPercent();
                $bonuses = intdiv(Money::kztMinor($base)->percent($percent, Rounding::Down, Money::TENGE)->amount, Money::TENGE);
                if ($bonuses > 0) {
                    $this->bonuses->accrue($ctx, $customerId, $bonuses, $availableFrom, $expiresAt, BonusKind::Accrual, $line->id);
                    $accrued[$line->id] = $bonuses;
                }
            }
        }

        // 8. detail rows
        foreach ($lines as $line) {
            $line->forceFill([
                'check_discount_share' => $shares[$line->id]->amount,
                'amount' => $amounts[$line->id],
                'cost_kzt' => $costs[$line->id],
                'bonus_paid_share' => $bonusShares[$line->id]->amount,
                'debt_share' => $debtShares[$line->id]->amount,
                'bonus_accrued' => $accrued[$line->id] ?? 0,
                'is_active' => true, // layer 4: the partial unique index fires here for a second active sale
            ])->save();
        }
        $sale->forceFill(['subtotal' => $subtotal, 'discount_total' => $discountTotal, 'total' => $total])->save();
    }

    // ------------------------------------------------------------------ reversal hooks

    public function assertReversible(Document $document, PostingContext $ctx): void
    {
        $return = CustomerReturn::query()
            ->join('documents', 'documents.id', '=', 'customer_returns.document_id')
            ->where('customer_returns.sale_document_id', $document->id)
            ->where('documents.status', DocumentStatus::Posted->value)
            ->first(['documents.number']);
        if ($return) {
            throw ReversalBlocked::because('later_documents', "Сначала сторнируйте: Возврат покупателя {$return->number}");
        }
    }

    public function afterReverse(Document $original, PostingContext $ctx): void
    {
        foreach ($this->lines($original) as $line) {
            $line->forceFill(['is_active' => false])->save();
        }
    }

    // ------------------------------------------------------------------ rules

    private function assertSellable(PostingContext $ctx, StockUnit $unit, Sale $sale, int $storeId): void
    {
        switch ($unit->status) {
            case UnitStatus::InStock:
                break;
            case UnitStatus::Reserved:
                $reservation = UnitReservation::query()->where('stock_unit_id', $unit->id)->whereNull('released_at')->first();
                if (! $reservation || (int) $reservation->user_id !== (int) $sale->seller_id) {
                    $holder = $reservation ? User::query()->find($reservation->user_id)?->name : '—';
                    throw PostingException::because('unit_reserved', "Телефон {$unit->public_code} в корзине у {$holder}");
                }
                break;
            case UnitStatus::Ordered:
                if (! $sale->order_document_id || (int) $unit->order_document_id !== (int) $sale->order_document_id) {
                    $order = Document::query()->find($unit->order_document_id);
                    throw PostingException::because('unit_ordered', "Телефон {$unit->public_code} забронирован по заказу {$order?->number}");
                }
                break;
            case UnitStatus::Sold:
                $active = SaleLine::query()->where('stock_unit_id', $unit->id)->where('is_active', true)->first();
                $doc = $active ? Document::query()->find($active->document_id) : null;
                $seller = $doc ? User::query()->find(Sale::query()->where('document_id', $doc->id)->value('seller_id'))?->name : null;
                $when = $doc?->posted_at?->setTimezone($ctx->organization->timezone)->format('H:i');
                throw PostingException::because('unit_sold', $doc
                    ? "Телефон уже продан ({$doc->number}, {$when}, {$seller})"
                    : 'Телефон уже продан', ['document_id' => $doc?->id]);
            default:
                throw PostingException::because('unit_not_sellable', "Телефон {$unit->public_code} нельзя продать: статус «".UnitStatus::labelOf($unit->status).'»');
        }
        if (! $unit->isInStore($storeId)) {
            throw PostingException::because('unit_other_store', "Телефон {$unit->public_code} числится в другом магазине");
        }

        // госбаза IMEI (TZ 02 §10.4 p.3): registered, not older than N days; owner may switch the rule off
        if ($ctx->settings()->requireImeiCheck() && $unit->imei1) {
            $check = $unit->latestImeiCheck();
            $maxAge = $ctx->settings()->imeiCheckMaxAgeDays();
            $message = match (true) {
                $check === null => 'Нет отметки госбазы IMEI — проверьте IMEI перед продажей',
                $check->result !== ImeiCheckResult::Registered => 'Госбаза: IMEI не зарегистрирован — продажа только с разрешения владельца',
                $check->checked_at->lt($ctx->now->subDays($maxAge)) => "Проверка госбазы старше {$maxAge} дней — проверьте IMEI ещё раз",
                default => null,
            };
            if ($message !== null) {
                $ctx->authorize('sale.no_imei_check', approvalType: 'no_imei_check', message: $message);
            }
        }
    }

    private function authorizeDiscount(PostingContext $ctx, Sale $sale, int $discountTotal, int $subtotal): void
    {
        if ($discountTotal <= 0) {
            return;
        }
        $ctx->authorize('sale.discount', Limit::DiscountPerCheck, $discountTotal, 'discount');
        if ($subtotal > 0) {
            $percentScaled = Rounding::Up->divide($discountTotal * 10000, $subtotal);
            $ctx->authorize('sale.discount', Limit::DiscountPercentPerCheck, $percentScaled, 'discount');
        }
        $tz = $ctx->organization->timezone;
        $dayStart = $ctx->occurredAt()->setTimezone($tz)->startOfDay();
        $today = (int) Sale::query()
            ->join('documents', 'documents.id', '=', 'sales.document_id')
            ->where('documents.status', DocumentStatus::Posted->value)
            ->whereNull('documents.approval_request_id')
            ->where('sales.seller_id', $sale->seller_id)
            ->where('documents.occurred_at', '>=', $dayStart->utc())
            ->where('documents.occurred_at', '<', $dayStart->addDay()->utc())
            ->sum('sales.discount_total');
        $ctx->authorize('sale.discount', Limit::DiscountPerDay, $today + $discountTotal, 'discount');
    }

    /** @param Collection<int, SalePayment> $payments */
    private function authorizeDebt(PostingContext $ctx, int $customerId, int $debtPaid, Collection $payments): void
    {
        $balance = $ctx->locks->counterparty(CounterpartyType::Customer, $customerId, Currency::KZT);
        $after = (int) $balance->balance + $debtPaid;
        $ctx->authorize('sale.credit', Limit::CustomerDebtTotal, $after, 'debt',
            'Долг клиента станет '.Money::kztMinor($after)->format().' — нужно разрешение владельца');

        $due = $payments->firstWhere('method', PaymentMethodCode::Debt)->debt_due_date;
        $days = (int) abs($ctx->businessDate()->diffInDays(CarbonImmutable::parse($due->format('Y-m-d'), $ctx->organization->timezone)));
        $ctx->authorize('sale.credit', Limit::CustomerDebtDays, $days, 'debt');

        $hasHistory = Sale::query()
            ->join('documents', 'documents.id', '=', 'sales.document_id')
            ->where('documents.status', DocumentStatus::Posted->value)
            ->where('sales.customer_id', $customerId)
            ->exists();
        if (! $hasHistory) {
            $ctx->authorize('sale.credit', Limit::DebtWithoutHistory, 1, 'debt');
        }
        if ((int) $balance->balance > 0 && $balance->nearest_due_date && CarbonImmutable::parse($balance->nearest_due_date)->lt($ctx->businessDate())) {
            $ctx->requireApprovalUnlessOwner('sale.credit', 'debt_overdue', 'У клиента просроченный долг — новый долг только с разрешения владельца');
        }
    }

    /** Retail price of the line at posting time: phone — its own price, else the variant price; accessory — the firm price list. */
    private function listPrice(PostingContext $ctx, SaleLine $line): ?int
    {
        if ($line->stock_unit_id) {
            $unit = $ctx->locks->unit((int) $line->stock_unit_id);
            $price = $unit->sale_price ?? VariantPrice::query()->where('variant_id', $unit->variant_id)->value('price_kzt');
        } else {
            $price = AccessoryPrice::query()->where('accessory_id', $line->accessory_id)->value('price_kzt');
        }

        return $price === null ? null : (int) $price;
    }

    private function assertWholeTenge(?int $minor, string $what): void
    {
        if ($minor !== null && $minor % Money::TENGE !== 0) {
            throw PostingException::because('not_whole', "{$what}: только целые тенге");
        }
    }

    private function sale(Document $document): Sale
    {
        return Sale::query()->where('document_id', $document->id)->first()
            ?? throw PostingException::because('no_header', 'Нет данных продажи');
    }

    /** @return Collection<int, SaleLine> */
    private function lines(Document $document): Collection
    {
        return SaleLine::query()->where('document_id', $document->id)->orderBy('line_no')->orderBy('id')->get();
    }

    /** @return Collection<int, SalePayment> */
    private function payments(Document $document): Collection
    {
        return SalePayment::query()->where('document_id', $document->id)->orderBy('id')->get();
    }
}
