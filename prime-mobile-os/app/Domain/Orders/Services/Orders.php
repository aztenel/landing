<?php

namespace App\Domain\Orders\Services;

use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\UnitTransitions;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Orders\Models\Order;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Life of a posted order besides posting and cancelling (TZ 01 П10.2, «Исключения»):
 *   extend()  — new hold date (seller of the order or order.cancel_any), an overdue order becomes active again;
 *   tick()    — the hold ran out: without prepayment the order expires and the phone is free; with prepayment —
 *               «Просрочен», the seller and the owner are notified, the phone stays held.
 */
class Orders
{
    public function __construct(private readonly AccessChecker $access, private readonly Notifier $notifier) {}

    public function extend(Order $order, int $days, User $user): Order
    {
        $right = (int) $order->seller_id === (int) $user->id ? 'order.create' : 'order.cancel_any';
        if (! $this->access->decide($user, $right, storeId: Document::query()->whereKey($order->document_id)->value('store_id'))->isAllowed()) {
            throw AccessDenied::because('access_denied', 'Продлить чужой заказ может админ');
        }
        if ($days < 1 || $days > 30) {
            throw PostingException::because('bad_days', 'Продлить можно на 1–30 дней');
        }

        return DB::transaction(function () use ($order, $days) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $o->isOpen()) {
                throw PostingException::because('order_not_open', 'Заказ уже закрыт');
            }
            $from = $o->hold_until->max(now());
            $o->forceFill(['hold_until' => $from->addDays($days), 'status' => 'active', 'overdue_at' => null, 'extended_count' => $o->extended_count + 1])->save();

            return $o;
        });
    }

    /** «Есть заказ на этот вариант. Привязать?» (TZ 01 П10.2 p.7): a phone in stock of the order's variant and store. */
    public function attachUnit(Order $order, StockUnit $unit, User $user): Order
    {
        $storeId = (int) Document::query()->whereKey($order->document_id)->value('store_id');
        if (! $this->access->decide($user, 'order.create', storeId: $storeId)->isAllowed()) {
            throw AccessDenied::because('access_denied', 'Привязать телефон к заказу может продавец этого магазина');
        }

        return DB::transaction(function () use ($order, $unit, $storeId) {
            $o = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $u = StockUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            if (! $o->isOpen() || $o->stock_unit_id) {
                throw PostingException::because('order_has_unit', 'У заказа уже есть телефон или он закрыт');
            }
            if ((int) $u->variant_id !== (int) $o->variant_id || $u->status !== UnitStatus::InStock || ! Location::store($storeId)->equals($u->location())) {
                throw PostingException::because('unit_not_fit', 'Нужен телефон этого варианта, в наличии, в магазине заказа');
            }
            UnitTransitions::assert(UnitStatus::InStock, UnitStatus::Ordered, UnitAction::Order);
            $u->forceFill(['status' => UnitStatus::Ordered->value, 'order_document_id' => $o->document_id])->save();
            $o->forceFill(['stock_unit_id' => $u->id])->save();
            activity('orders')->performedOn($o)->withProperties(['stock_unit_id' => $u->id])->log('order_unit_attached');

            return $o;
        });
    }

    /** @return array{expired: int, overdue: int} */
    public function tick(): array
    {
        $result = ['expired' => 0, 'overdue' => 0];
        $ids = Order::query()->where('status', 'active')->where('hold_until', '<', now())->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$result) {
                $o = Order::query()->whereKey($id)->lockForUpdate()->first();
                if (! $o || $o->status !== 'active' || $o->hold_until->gte(now())) {
                    return;
                }
                $number = Document::query()->whereKey($o->document_id)->value('number');
                if ($o->prepayment === 0) {
                    if ($o->stock_unit_id) {
                        $unit = StockUnit::query()->whereKey($o->stock_unit_id)->lockForUpdate()->first();
                        if ($unit && $unit->status === UnitStatus::Ordered && (int) $unit->order_document_id === (int) $o->document_id) {
                            UnitTransitions::assert(UnitStatus::Ordered, UnitStatus::InStock, UnitAction::CancelOrder);
                            $unit->forceFill(['status' => UnitStatus::InStock->value, 'order_document_id' => null])->save();
                        }
                    }
                    $o->forceFill(['status' => 'expired', 'closed_at' => now()])->save();
                    activity('orders')->performedOn($o)->withProperties(['number' => $number])->log('order_expired');
                    $result['expired']++;

                    return;
                }
                $o->forceFill(['status' => 'overdue', 'overdue_at' => now()])->save();
                $title = __('Заказ :number просрочен — позвоните клиенту', ['number' => $number]);
                $options = ['document_id' => $o->document_id, 'data' => ['order_id' => $o->id]];
                $this->notifier->toUser((int) $o->seller_id, NotificationType::OrderOverdue, $title, null, $options);
                $this->notifier->toOwners(NotificationType::OrderOverdue, $title, null, $options);
                $result['overdue']++;
            });
        }

        return $result;
    }
}
