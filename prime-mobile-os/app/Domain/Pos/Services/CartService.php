<?php

namespace App\Domain\Pos\Services;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\AccessoryBalance;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Orders\Models\Order;
use App\Domain\Pos\Models\Cart;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Idempotency\Idempotency;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server side of the cash register (TZ 04 Э3, 01 П8). The screen only sends intentions («add this code», «discount
 * 5 000 on line 2», «pay 200 000 cash»); every price comes from the price list here, every limit is checked by the
 * posting core. Phones in the cart are reservations of the core (ReservationService).
 *
 * checkout() returns one of: ['status' => 'posted', 'document' => Document], ['status' => 'approval', 'message' => …]
 * (the seller may ask the owner), ['status' => 'waiting'] (a request is pending), or throws a user-facing exception.
 */
class CartService
{
    public const MAX_TABS = 5;

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly Poster $poster,
        private readonly ApprovalService $approvals,
    ) {}

    // ------------------------------------------------------------------ carts

    public function cart(User $user, Store $store, int $tab = 1): Cart
    {
        $tab = max(1, min(self::MAX_TABS, $tab));

        return Cart::query()->firstOrCreate(
            ['user_id' => $user->id, 'store_id' => $store->id, 'tab' => $tab],
            ['key' => (string) Str::uuid(), 'data' => Cart::EMPTY],
        );
    }

    /** @return list<int> tabs of this seller in this store (at least [1]) */
    public function tabs(User $user, Store $store): array
    {
        $tabs = Cart::query()->where('user_id', $user->id)->where('store_id', $store->id)->orderBy('tab')->pluck('tab')->map(fn ($t) => (int) $t)->all();

        return $tabs ?: [1];
    }

    public function newTab(User $user, Store $store): int
    {
        $tabs = $this->tabs($user, $store);
        $free = collect(range(1, self::MAX_TABS))->first(fn ($t) => ! in_array($t, $tabs, true));
        if (! $free) {
            throw PostingException::because('too_many_tabs', 'Открыто 5 чеков — закончите один из них');
        }
        $this->cart($user, $store, $free);

        return $free;
    }

    /** An empty tab other than the first closes itself (TZ 04 Э3.1). */
    public function dropEmptyTabs(User $user, Store $store, int $keep): void
    {
        Cart::query()->where('user_id', $user->id)->where('store_id', $store->id)->where('tab', '>', 1)->where('tab', '<>', $keep)
            ->get()->filter->isEmpty()->each->delete();
    }

    // ------------------------------------------------------------------ scan and lines

    /** IMEI / serial, public code «PM-10482», accessory barcode or SKU. Returns a short text of what was added. */
    public function scan(Cart $cart, User $user, string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            throw PostingException::because('empty_code', 'Отсканируйте IMEI или штрихкод');
        }
        $unit = preg_match('/^[A-Za-z]{1,4}-\d+$/', $code)
            ? StockUnit::query()->where('public_code', mb_strtoupper($code))->first()
            : (preg_match('/^[\d\s\-]{14,20}$/', $code) || Imei::isSerial($code) ? StockUnit::findByIdentifier($code) : null);
        if ($unit) {
            $this->addUnit($cart, $user, $unit->id);

            return $unit->variant?->title() ?? (string) $unit->public_code;
        }
        $accessory = Accessory::query()->where('is_active', true)->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))->first();
        if ($accessory) {
            $this->addAccessory($cart, $user, $accessory->id);

            return $accessory->name_ru;
        }
        if (preg_match('/^\d{15}$/', $code) && ! Imei::luhnValid($code)) {
            throw PostingException::because('imei_invalid', 'IMEI с ошибкой (не сходится контрольная цифра). Отсканируйте ещё раз');
        }

        throw PostingException::because('not_found', "Не нашли «{$code}» на складе. Проверьте код или сделайте приход");
    }

    public function addUnit(Cart $cart, User $user, int $unitId): void
    {
        $unit = StockUnit::query()->find($unitId) ?? throw PostingException::because('not_found', 'Телефон не найден');
        if (collect($cart->get('lines'))->contains(fn ($l) => $l['kind'] === 'unit' && (int) $l['unit_id'] === $unit->id)) {
            throw PostingException::because('already_in_cart', 'Этот телефон уже в чеке');
        }
        if (! $unit->isInStore((int) $cart->store_id)) {
            throw PostingException::because('unit_other_store', $unit->status === UnitStatus::Sold
                ? 'Этот телефон уже продан'
                : "Телефон {$unit->public_code} числится в другом магазине");
        }
        if ($unit->status === UnitStatus::Ordered) {
            $order = Order::query()->where('document_id', $unit->order_document_id)->first();
            if (! $order || (int) $cart->get('order_document_id') !== (int) $order->document_id) {
                $number = Document::query()->whereKey($unit->order_document_id)->value('number');
                throw PostingException::because('unit_ordered', "Телефон забронирован по заказу {$number}. Продайте его через «Заказы»");
            }
        } elseif (! in_array($unit->status, [UnitStatus::InStock, UnitStatus::Reserved], true)) {
            throw PostingException::because('unit_not_sellable', "Телефон {$unit->public_code} нельзя продать: «".UnitStatus::labelOf($unit->status).'»');
        } else {
            $this->reservations->reserve($unit, $user, $cart->key); // «Уже в корзине у Айдара до 15:20»
        }
        if ($this->unitPrice($unit) === null) {
            $this->release($unit->id, $user);
            throw PostingException::because('no_price', "У телефона {$unit->public_code} нет цены. Сообщите администратору");
        }
        $this->change($cart, function (array $d) use ($unit) {
            $d['lines'][] = ['kind' => 'unit', 'unit_id' => $unit->id, 'discount' => 0];

            return $d;
        });
    }

    public function addAccessory(Cart $cart, User $user, int $accessoryId, int $qty = 1): void
    {
        $accessory = Accessory::query()->whereKey($accessoryId)->where('is_active', true)->first()
            ?? throw PostingException::because('not_found', 'Аксессуар не найден');
        if ($this->accessoryPrice($accessory->id) === null) {
            throw PostingException::because('no_price', "У «{$accessory->name_ru}» нет цены. Сообщите администратору");
        }
        $lines = $cart->get('lines');
        $index = collect($lines)->search(fn ($l) => $l['kind'] === 'accessory' && (int) $l['accessory_id'] === $accessory->id && empty($l['gift']));
        $want = ($index === false ? 0 : (int) $lines[$index]['qty']) + $qty;
        $this->assertStock($cart, $accessory, $want);
        $this->change($cart, function (array $d) use ($index, $accessory, $want) {
            if ($index === false) {
                $d['lines'][] = ['kind' => 'accessory', 'accessory_id' => $accessory->id, 'qty' => $want, 'discount' => 0, 'gift' => false];
            } else {
                $d['lines'][$index]['qty'] = $want;
            }

            return $d;
        });
    }

    public function setQty(Cart $cart, User $user, int $index, int $qty): void
    {
        $line = $this->line($cart, $index);
        if ($line['kind'] !== 'accessory') {
            return;
        }
        if ($qty < 1) {
            $this->removeLine($cart, $user, $index);

            return;
        }
        $this->assertStock($cart, Accessory::query()->findOrFail($line['accessory_id']), $qty);
        $this->change($cart, function (array $d) use ($index, $qty) {
            $d['lines'][$index]['qty'] = $qty;
            $d['lines'][$index]['discount'] = min((int) $d['lines'][$index]['discount'], $this->lineGross($d['lines'][$index]));

            return $d;
        });
    }

    public function removeLine(Cart $cart, User $user, int $index): void
    {
        $line = $this->line($cart, $index);
        if ($line['kind'] === 'unit') {
            $this->release((int) $line['unit_id'], $user);
        }
        $this->change($cart, function (array $d) use ($index) {
            array_splice($d['lines'], $index, 1);
            if ($d['lines'] === []) {
                $d['check_discount'] = 0;
                $d['payments'] = [];
            }

            return $d;
        });
    }

    /** Line discount in tiyn (whole tenge). The limits are the core's job at payment; here only «not above the line». */
    public function setLineDiscount(Cart $cart, int $index, int $discount): void
    {
        $line = $this->line($cart, $index);
        $discount = $this->wholeTenge(max(0, $discount));
        if ($discount > $this->lineGross($line)) {
            throw PostingException::because('discount_over_line', 'Скидка больше цены строки');
        }
        $this->change($cart, function (array $d) use ($index, $discount) {
            $d['lines'][$index]['discount'] = $discount;

            return $d;
        });
    }

    public function setCheckDiscount(Cart $cart, int $discount, ?string $reason = null): void
    {
        $discount = $this->wholeTenge(max(0, $discount));
        $after = $this->totals($cart)['after_lines'];
        if ($discount > $after) {
            throw PostingException::because('discount_over_check', 'Скидка больше суммы чека');
        }
        if ($discount > 0 && trim((string) $reason) === '') {
            throw PostingException::because('reason_required', 'Укажите причину скидки');
        }
        $this->change($cart, fn (array $d) => ['check_discount' => $discount, 'discount_reason' => $discount > 0 ? $reason : null] + $d);
    }

    public function setCustomer(Cart $cart, ?int $customerId): void
    {
        if ($customerId !== null) {
            Customer::query()->whereKey($customerId)->whereNull('anonymized_at')->firstOrFail();
        }
        $this->change($cart, function (array $d) use ($customerId) {
            $d['customer_id'] = $customerId;
            if ($customerId === null) {
                $d['payments'] = array_values(array_filter($d['payments'], fn ($p) => ! in_array($p['method'], ['debt', 'bonus', 'prepayment'], true)));
            }

            return $d;
        });
    }

    /** @param list<array{method: string, amount: int, tendered?: ?int, due_date?: ?string}> $payments amounts in tiyn */
    public function setPayments(Cart $cart, array $payments): void
    {
        $clean = [];
        $seen = [];
        foreach ($payments as $p) {
            $method = PaymentMethodCode::tryFrom((string) ($p['method'] ?? ''));
            if (! $method || in_array($method, [PaymentMethodCode::TradeIn, PaymentMethodCode::ExchangeOffset], true)) {
                throw PostingException::because('bad_method', 'Этот способ оплаты на кассе недоступен');
            }
            if (isset($seen[$method->value])) {
                throw PostingException::because('method_twice', 'Один и тот же способ оплаты дважды нельзя');
            }
            $seen[$method->value] = true;
            $amount = $this->wholeTenge((int) ($p['amount'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $row = ['method' => $method->value, 'amount' => $amount];
            if ($method === PaymentMethodCode::Cash && isset($p['tendered']) && $p['tendered'] !== null) {
                $row['tendered'] = $this->wholeTenge((int) $p['tendered']);
                if ($row['tendered'] < $amount) {
                    throw PostingException::because('tendered_short', 'Внесено меньше, чем нужно наличными');
                }
            }
            if ($method === PaymentMethodCode::Debt) {
                $row['due_date'] = (string) ($p['due_date'] ?? '');
            }
            if (in_array($method, [PaymentMethodCode::Debt, PaymentMethodCode::Bonus, PaymentMethodCode::Prepayment], true) && ! $cart->get('customer_id')) {
                throw PostingException::because('customer_required', 'Для этого способа выберите клиента [F4]');
            }
            $clean[] = $row;
        }
        $this->change($cart, fn (array $d) => ['payments' => $clean] + $d);
    }

    public function setMeta(Cart $cart, array $meta): void
    {
        $allowed = array_intersect_key($meta, array_flip(['source', 'comment', 'delivery', 'print']));
        if (isset($allowed['comment'])) {
            $allowed['comment'] = mb_substr(trim((string) $allowed['comment']), 0, 500) ?: null;
        }
        $this->change($cart, fn (array $d) => $allowed + $d, bumpVersion: isset($allowed['source']) || isset($allowed['comment']) || array_key_exists('delivery', $allowed));
    }

    /** «Продать по заказу» (TZ 01 П10.2 p.6): the order's phone, the customer and the prepayment as a payment line. */
    public function loadOrder(Cart $cart, User $user, Order $order): void
    {
        if (! $cart->isEmpty()) {
            throw PostingException::because('cart_not_empty', 'Сначала закончите или очистите текущий чек');
        }
        if (! $order->isOpen()) {
            throw PostingException::because('order_not_open', 'Заказ уже закрыт');
        }
        $this->change($cart, fn (array $d) => ['order_document_id' => $order->document_id, 'customer_id' => $order->customer_id] + $d);
        $cart->refresh();
        if ($order->stock_unit_id) {
            $this->addUnit($cart, $user, (int) $order->stock_unit_id);
        }
        if ($order->prepayment > 0) {
            $this->setPayments($cart->refresh(), [['method' => 'prepayment', 'amount' => (int) $order->prepayment]]);
        }
    }

    /** «Добавить в чек» from the trade-in act (F8): a draft act of this store with a part «в зачёт». */
    public function attachTradeIn(Cart $cart, Document $act): void
    {
        $row = \App\Domain\TradeIn\Models\TradeInAct::query()->where('document_id', $act->id)->first();
        if (! $act->isDraft() || $act->type->value !== 'tradein' || (int) $act->store_id !== (int) $cart->store_id || ! $row || $row->offset_amount <= 0) {
            throw PostingException::because('bad_tradein', 'Этот акт нельзя добавить в чек');
        }
        $this->change($cart, fn (array $d) => ['tradein' => ['document_id' => $act->id, 'amount' => (int) $row->offset_amount], 'customer_id' => $d['customer_id'] ?? $row->customer_id] + $d);
    }

    public function detachTradeIn(Cart $cart): void
    {
        $this->change($cart, fn (array $d) => ['tradein' => null] + $d);
    }

    public function clear(Cart $cart, User $user): void
    {
        foreach ($cart->get('lines') as $line) {
            if ($line['kind'] === 'unit') {
                $this->release((int) $line['unit_id'], $user);
            }
        }
        $this->cancelPendingRequest($cart, $user);
        $cart->forceFill(['data' => Cart::EMPTY, 'version' => $cart->version + 1, 'draft_document_id' => null, 'approval_request_id' => null])->save();
    }

    // ------------------------------------------------------------------ view model

    /**
     * Lines with prices from the price list, totals and what blocks the payment. Never shows cost.
     *
     * @return array{lines: list<array>, subtotal: int, line_discounts: int, after_lines: int, check_discount: int, total: int, paid: int, change: int, reserve_until: ?CarbonImmutable}
     */
    public function totals(Cart $cart): array
    {
        $lines = [];
        $subtotal = 0;
        $lineDiscounts = 0;
        $reserveUntil = null;
        $orderPrice = $this->orderUnitPrice($cart);
        $maxAge = \App\Support\Tenancy\CurrentOrganization::instance()->require()->settings()->imeiCheckMaxAgeDays();
        foreach ($cart->get('lines') as $i => $line) {
            if ($line['kind'] === 'unit') {
                $unit = StockUnit::query()->with('variant')->find($line['unit_id']);
                $price = $unit ? $this->unitPrice($unit, $orderPrice) : null;
                $check = $unit?->imei1 ? $unit->latestImeiCheck() : null;
                $reservation = UnitReservation::query()->where('stock_unit_id', $line['unit_id'])->whereNull('released_at')->first();
                if ($reservation && (! $reserveUntil || $reservation->expires_at->lt($reserveUntil))) {
                    $reserveUntil = CarbonImmutable::parse($reservation->expires_at);
                }
                $row = [
                    'index' => $i, 'kind' => 'unit', 'id' => $unit?->id, 'title' => $unit?->variant?->title() ?? '—',
                    'imei' => $unit?->imei1 ? Imei::format($unit->imei1) : $unit?->serial, 'code' => $unit?->public_code,
                    'condition' => $unit?->condition?->value, 'grade' => $unit?->grade, 'battery' => $unit?->battery_percent,
                    'imei_check' => $check?->result?->value, 'imei_checked_at' => $check?->checked_at,
                    'imei_fresh' => $check?->result?->value === 'registered' && $check->checked_at->gte(now()->subDays($maxAge)),
                    'cost_unknown' => (bool) $unit?->cost_unknown, 'qty' => 1, 'price' => (int) $price, 'discount' => (int) $line['discount'],
                ];
            } else {
                $accessory = Accessory::query()->find($line['accessory_id']);
                $price = ! empty($line['gift']) ? 0 : (int) $this->accessoryPrice((int) $line['accessory_id']);
                $row = [
                    'index' => $i, 'kind' => 'accessory', 'id' => $accessory?->id, 'title' => $accessory?->name_ru ?? '—', 'gift' => ! empty($line['gift']),
                    'qty' => (int) $line['qty'], 'price' => $price, 'discount' => (int) $line['discount'],
                    'stock' => (int) AccessoryBalance::query()->where('accessory_id', $line['accessory_id'])
                        ->where('location_type', 'store')->where('location_id', $cart->store_id)->value('qty'),
                ];
            }
            $row['amount'] = $row['price'] * $row['qty'] - $row['discount'];
            $subtotal += $row['price'] * $row['qty'];
            $lineDiscounts += $row['discount'];
            $lines[] = $row;
        }
        $after = $subtotal - $lineDiscounts;
        $checkDiscount = min((int) $cart->get('check_discount', 0), $after);
        $total = $after - $checkDiscount;
        $tradein = $cart->get('tradein');
        $paid = (int) collect($cart->get('payments'))->sum('amount') + (int) ($tradein['amount'] ?? 0);
        $cash = collect($cart->get('payments'))->firstWhere('method', 'cash');
        $change = $cash && isset($cash['tendered']) ? (int) $cash['tendered'] - (int) $cash['amount'] : 0;

        return [
            'lines' => $lines, 'subtotal' => $subtotal, 'line_discounts' => $lineDiscounts, 'after_lines' => $after,
            'check_discount' => $checkDiscount, 'total' => $total, 'paid' => $paid, 'change' => max(0, $change), 'reserve_until' => $reserveUntil,
            'tradein' => $tradein,
        ];
    }

    // ------------------------------------------------------------------ payment

    /** Quick method on the whole sum: «Наличные · Карта · Kaspi · В долг». */
    public function payAllWith(Cart $cart, string $method, ?string $dueDate = null): void
    {
        $t = $this->totals($cart);
        $total = $t['total'] - (int) ($t['tradein']['amount'] ?? 0);
        $this->setPayments($cart, [['method' => $method, 'amount' => $total] + ($method === 'debt' ? ['due_date' => $dueDate ?? now()->addDays(30)->toDateString()] : [])]);
    }

    /**
     * «Провести» (TZ 04 Э3.4). One draft per version of the cart (idempotency key): a double click or a repeat after a
     * lost answer gives the same check, never two.
     *
     * @return array{status: string, document?: Document, message?: string, request?: ApprovalRequest}
     */
    public function checkout(Cart $cart, User $user): array
    {
        $cart->refresh();
        $t = $this->totals($cart);
        if ($t['lines'] === []) {
            throw PostingException::because('empty', 'Чек пуст. Отсканируйте товар');
        }
        if ($t['paid'] !== $t['total']) {
            $diff = $t['total'] - $t['paid'];
            throw PostingException::because('not_balanced', $diff > 0
                ? 'Баланс не сведён: не хватает '.Money::kztMinor($diff)->format()
                : 'Оплат больше, чем чек, на '.Money::kztMinor(-$diff)->format());
        }
        if ($request = $this->pendingRequest($cart)) {
            if ($request->status === ApprovalStatus::Pending) {
                return ['status' => 'waiting', 'request' => $request];
            }
        }

        $draft = $this->draft($cart, $user);
        try {
            // trade-in «в зачёт»: the act and the check in one operation, the act first (audit №40)
            $act = $cart->get('tradein.document_id') ? Document::query()->find($cart->get('tradein.document_id')) : null;
            $document = $act
                ? $this->poster->postMany([$act, $draft], $user)[1]
                : $this->poster->post($draft, $user);
        } catch (ApprovalRequired $e) {
            return ['status' => 'approval', 'message' => $e->getMessage()];
        }
        $this->afterPosted($cart, $document);

        return ['status' => 'posted', 'document' => $document];
    }

    /** «Запросить у владельца»: the request is tied to this very draft (any change after it needs a new one). */
    public function requestApproval(Cart $cart, User $user, ?string $reason = null): ApprovalRequest
    {
        $draft = $this->draft($cart->refresh(), $user);
        $request = $this->approvals->requestForDocument($draft, $user, $reason ?: $cart->get('discount_reason'));
        $cart->forceFill(['approval_request_id' => $request->id])->save();

        return $request;
    }

    public function pendingRequest(Cart $cart): ?ApprovalRequest
    {
        return $cart->approval_request_id ? ApprovalRequest::query()->find($cart->approval_request_id) : null;
    }

    public function cancelPendingRequest(Cart $cart, User $user): void
    {
        $request = $this->pendingRequest($cart);
        if ($request && $request->status === ApprovalStatus::Pending) {
            $this->approvals->cancel($request, $user);
        }
        $cart->forceFill(['approval_request_id' => null])->save();
    }

    // ------------------------------------------------------------------ internals

    private function draft(Cart $cart, User $user): Document
    {
        if ($cart->draft_document_id && ($existing = Document::query()->find($cart->draft_document_id))
            && $existing->idempotency_key === $this->draftKey($cart)) {
            return $existing;
        }
        $orderPrice = $this->orderUnitPrice($cart);
        $d = SaleDraft::make((int) $cart->store_id, $user)->idempotencyKey($this->draftKey($cart))->author($user);
        if ($cart->get('customer_id')) {
            $d->customer((int) $cart->get('customer_id'));
        }
        if ($cart->get('order_document_id')) {
            $d->fromOrder((int) $cart->get('order_document_id'));
        }
        if ($cart->get('source')) {
            $d->source((string) $cart->get('source'));
        }
        foreach ($cart->get('lines') as $line) {
            if ($line['kind'] === 'unit') {
                $unit = StockUnit::query()->findOrFail($line['unit_id']);
                $d->unit($unit, Money::kztMinor((int) $this->unitPrice($unit, $orderPrice)), Money::kztMinor((int) $line['discount']));
            } elseif (! empty($line['gift'])) {
                $d->gift((int) $line['accessory_id'], (int) $line['qty']);
            } else {
                $d->accessory((int) $line['accessory_id'], (int) $line['qty'], Money::kztMinor((int) $this->accessoryPrice((int) $line['accessory_id'])), Money::kztMinor((int) $line['discount']));
            }
        }
        if ($cart->get('tradein.document_id')) {
            $d->tradeIn((int) $cart->get('tradein.document_id'), Money::kztMinor((int) $cart->get('tradein.amount')));
        }
        if ($cart->get('check_discount')) {
            $d->discount(Money::kztMinor((int) $cart->get('check_discount')));
        }
        foreach ($cart->get('payments') as $p) {
            $amount = Money::kztMinor((int) $p['amount']);
            match ($p['method']) {
                'cash' => $d->cash($amount, isset($p['tendered']) ? Money::kztMinor((int) $p['tendered']) : null),
                // ТЗ 07 сценарий 12: no silent «+30 дней» — the seller must enter the date
                'debt' => $d->debt($amount, ($p['due_date'] ?? '') !== '' ? CarbonImmutable::parse($p['due_date'])
                    : throw PostingException::because('due_date_required', 'Укажите дату возврата долга')),
                default => $d->pay($p['method'], $amount),
            };
        }
        $d->comment($cart->get('comment'));
        $document = $d->save();
        $cart->forceFill(['draft_document_id' => $document->id])->save();

        return $document;
    }

    private function draftKey(Cart $cart): string
    {
        return Idempotency::derived('pos', $cart->id, $cart->key, $cart->version);
    }

    private function afterPosted(Cart $cart, Document $document): void
    {
        $print = $cart->get('print');
        $cart->forceFill([
            'key' => (string) Str::uuid(), 'version' => 0, 'data' => Cart::EMPTY + ['print' => $print],
            'draft_document_id' => null, 'approval_request_id' => null, 'last_document_id' => $document->id,
        ])->save();
    }

    private function change(Cart $cart, callable $mutate, bool $bumpVersion = true): void
    {
        DB::transaction(function () use ($cart, $mutate, $bumpVersion) {
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $data = $mutate(($locked->data ?? []) + Cart::EMPTY);
            $data['lines'] = array_values($data['lines']);
            $changes = ['data' => $data];
            if ($bumpVersion) {
                $changes['version'] = $locked->version + 1;
                $changes['approval_request_id'] = null; // an approval covers exactly the check it was asked for
            }
            $locked->forceFill($changes)->save();
            $cart->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function line(Cart $cart, int $index): array
    {
        return $cart->get('lines')[$index] ?? throw PostingException::because('no_line', 'Строки уже нет в чеке');
    }

    private function lineGross(array $line): int
    {
        if ($line['kind'] === 'unit') {
            $unit = StockUnit::query()->find($line['unit_id']);

            return $unit ? (int) $this->unitPrice($unit) : 0;
        }

        return ! empty($line['gift']) ? 0 : (int) $this->accessoryPrice((int) $line['accessory_id']) * (int) $line['qty'];
    }

    private function release(int $unitId, User $user): void
    {
        $reservation = UnitReservation::query()->where('stock_unit_id', $unitId)->whereNull('released_at')->where('user_id', $user->id)->first();
        if ($reservation) {
            $this->reservations->release($reservation, $user);
        }
    }

    private function assertStock(Cart $cart, Accessory $accessory, int $qty): void
    {
        $inCart = collect($cart->get('lines'))->filter(fn ($l) => $l['kind'] === 'accessory' && (int) $l['accessory_id'] === $accessory->id && ! empty($l['gift']))->sum('qty');
        $stock = (int) AccessoryBalance::query()->where('accessory_id', $accessory->id)
            ->where('location_type', 'store')->where('location_id', $cart->store_id)->value('qty');
        if ($qty + $inCart > $stock) {
            throw PostingException::because('accessory_short', "В магазине только {$stock} шт.");
        }
    }

    private function wholeTenge(int $tiyn): int
    {
        return intdiv($tiyn, Money::TENGE) * Money::TENGE;
    }

    /** List price of a phone (the order's price if this check sells an order and it is lower). */
    public function unitPrice(StockUnit $unit, ?array $orderPrice = null): ?int
    {
        $price = $unit->sale_price ?? VariantPrice::query()->where('variant_id', $unit->variant_id)->value('price_kzt');
        $price = $price === null ? null : (int) $price;
        if ($orderPrice && ($orderPrice['unit_id'] ? $orderPrice['unit_id'] === $unit->id : $orderPrice['variant_id'] === (int) $unit->variant_id)) {
            return $price === null ? $orderPrice['price'] : min($price, $orderPrice['price']);
        }

        return $price;
    }

    public function accessoryPrice(int $accessoryId): ?int
    {
        $price = AccessoryPrice::query()->where('accessory_id', $accessoryId)->value('price_kzt');

        return $price === null ? null : (int) $price;
    }

    /** @return array{unit_id: ?int, variant_id: int, price: int}|null */
    private function orderUnitPrice(Cart $cart): ?array
    {
        $order = $cart->get('order_document_id') ? Order::query()->where('document_id', $cart->get('order_document_id'))->first() : null;

        return $order ? ['unit_id' => $order->stock_unit_id ? (int) $order->stock_unit_id : null, 'variant_id' => (int) $order->variant_id, 'price' => (int) $order->price] : null;
    }

    /** Guard for Livewire: the cart belongs to this user, firm and an allowed store. */
    public function assertOwn(Cart $cart, User $user): void
    {
        if ((int) $cart->user_id !== $user->id) {
            throw AccessDenied::because('access_denied', 'Это не ваш чек');
        }
    }

    /** For tiles and checks: store location of the cart. */
    public function location(Cart $cart): Location
    {
        return Location::store((int) $cart->store_id);
    }
}
