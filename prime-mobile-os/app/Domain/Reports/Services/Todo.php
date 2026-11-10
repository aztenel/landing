<?php

namespace App\Domain\Reports\Services;

use App\Domain\Inventory\Models\StockUnit;
use App\Filament\Approvals\Pages\Requests;
use App\Filament\Intake\Pages\Intakes;
use App\Filament\Money\Pages\Balance;
use App\Filament\Money\Pages\Debts;
use App\Filament\Pos\Pages\Sale;
use App\Filament\Reports\Pages\Reports;
use App\Filament\Sales\Pages\Orders;
use App\Filament\Stock\Pages\Phones;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Block «Нужно сделать» of Э2 (TZ 04 Э2.1): tasks in the order of importance, each with a colour, two lines and a
 * button. Only what the user may act on; an empty list shows «Всё в порядке ✓».
 */
final class Todo
{
    /** @return list<array{color: string, title: string, text: string, button: string, url: string}> */
    public function items(array $storeIds): array
    {
        $out = [];
        $org = CurrentOrganization::instance()->require();
        $add = function (string $color, string $title, string $text, string $button, string $url) use (&$out) {
            $out[] = compact('color', 'title', 'text', 'button', 'url');
        };
        $inStores = fn ($q) => $q->where('location_type', 'store')->whereIn('location_id', $storeIds ?: [0]);

        if (Access::allows('approval.decide') && ($n = DB::table('approval_requests')->where('organization_id', $org->id)->where('status', 'pending')->count())) {
            $add('bad', trans_choice(':n заявка ждёт решения|:n заявки ждут решения|:n заявок ждут решения', $n, ['n' => $n]), __('продавцы ждут ответа'), __('Открыть'), Requests::getUrl());
        }
        $days = $org->settings()->imeiCheckMaxAgeDays();
        $noImei = StockUnit::query()->where('status', 'in_stock')->whereNotNull('imei1')->where(fn ($q) => $inStores($q))
            ->whereNotExists(fn ($q) => $q->from('imei_checks')->whereColumn('imei_checks.stock_unit_id', 'stock_units.id')->where('result', 'registered')->where('checked_at', '>=', now()->subDays($days)))->count();
        if ($noImei && Access::allows('stock.imei_check')) {
            $add('bad', trans_choice(':n телефон без госбазы|:n телефона без госбазы|:n телефонов без госбазы', $noImei, ['n' => $noImei]), __('продать нельзя до отметки'), __('Проверить'), Phones::getUrl());
        }
        $noCost = StockUnit::query()->where('cost_unknown', true)->whereIn('status', ['in_stock', 'reserved', 'ordered', 'preparing'])->count();
        if ($noCost && Access::allows('stock.view_cost')) {
            $add('warn', trans_choice(':n телефон без себестоимости|:n телефона без себестоимости|:n телефонов без себестоимости', $noCost, ['n' => $noCost]), __('не продаётся, пока не внесена'), __('Внести'), Intakes::getUrl());
        }
        $openShift = DB::table('shifts')->where('organization_id', $org->id)->where('status', 'open')->whereIn('store_id', $storeIds ?: [0])
            ->where('opened_at', '<', now()->setTimezone($org->timezone)->startOfDay()->utc())->count();
        if ($openShift) {
            $add('bad', __('Касса не закрыта'), __('смена со вчерашнего дня'), __('Смена'), Sale::getUrl());
        }
        $orders = DB::table('orders')->where('organization_id', $org->id)->whereIn('status', ['active', 'overdue', 'arrived'])->count();
        $overdueOrders = DB::table('orders')->where('organization_id', $org->id)->where('status', 'overdue')->count();
        if ($orders && Access::allows('order.create')) {
            $add($overdueOrders ? 'bad' : 'warn', trans_choice(':n заказ ждёт выдачи|:n заказа ждут выдачи|:n заказов ждут выдачи', $orders, ['n' => $orders]),
                $overdueOrders ? __('из них просрочено: :n', ['n' => $overdueOrders]) : __('клиенты внесли предоплату или бронь'), __('Открыть'), Orders::getUrl());
        }
        $overdue = DB::table('counterparty_balances')->where('organization_id', $org->id)->where('counterparty_type', 'customer')->where('balance', '>', 0)
            ->whereNotNull('nearest_due_date')->where('nearest_due_date', '<', now()->toDateString())->selectRaw('count(*) n, coalesce(sum(balance_kzt), 0) s')->first();
        if ($overdue && $overdue->n && Access::allows('debt.customer_report')) {
            $add('warn', trans_choice('Просрочен долг :n клиента|Просрочены долги :n клиентов|Просрочены долги :n клиентов', (int) $overdue->n, ['n' => $overdue->n]),
                Money::kztMinor((int) $overdue->s)->format().' '.__('всего'), __('Список'), Debts::getUrl());
        }
        $supplier = DB::table('counterparty_balances')->where('organization_id', $org->id)->where('counterparty_type', 'supplier')->where('balance', '>', 0)
            ->whereNotNull('nearest_due_date')->where('nearest_due_date', '<=', now()->addDays(3)->toDateString())->count();
        if ($supplier && Access::allows('debt.supplier_view')) {
            $add('warn', __('Срок оплаты поставщику через ≤ 3 дня'), trans_choice(':n поставщик|:n поставщика|:n поставщиков', $supplier, ['n' => $supplier]), __('Долги'), Debts::getUrl());
        }
        $old = StockUnit::query()->whereIn('status', ['in_stock', 'reserved'])->where(fn ($q) => $inStores($q))->where('received_at', '<', now()->subDays(30));
        if (($n = $old->count()) && Access::allows('report.aging')) {
            $sum = Access::allows('stock.view_cost') ? Money::kztMinor((int) (clone $old)->sum('cost_kzt'))->format().' '.__('по себестоимости') : __('лежат на складе');
            $add('muted', trans_choice(':n телефон лежит 30+ дней|:n телефона лежат 30+ дней|:n телефонов лежат 30+ дней', $n, ['n' => $n]), $sum, __('Открыть'), Reports::getUrl().'?r=aging');
        }
        $rateAt = DB::table('exchange_rates')->where('organization_id', $org->id)->where('currency', 'USD')->max('valid_from');
        if ($rateAt && now()->diffInDays(Carbon::parse($rateAt), true) >= 2 && Access::allows('rate.set')) {
            $add('muted', __('Курс не обновлялся :n дн.', ['n' => (int) now()->diffInDays(Carbon::parse($rateAt), true)]), __('цены в $ и долги поставщикам считаются по нему'), __('Обновить'), Balance::getUrl());
        }

        return $out;
    }
}
