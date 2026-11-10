<?php

namespace App\Domain\Reports\Services;

use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner summaries (TZ 06 §4.1–4.2): «Итог дня», «Итог вчера», «Итог недели», «Итог месяца». The figures come from
 * Metrics (the same as the reports). No personal data: no customer names, phones, IIN, IMEI — sellers by first name
 * only (Telegram is outside Kazakhstan).
 */
final class Summaries
{
    public function __construct(private readonly Metrics $m) {}

    private function storeIds(): array
    {
        return DB::table('stores')->where('organization_id', CurrentOrganization::instance()->requireId())->where('is_active', true)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private static function money(int $tiyn): string
    {
        return Money::kztMinor($tiyn)->format();
    }

    private function pct(int $now, int $before): string
    {
        return $before ? ' ('.(($p = (int) round(($now - $before) * 100 / abs($before))) >= 0 ? '+' : '').$p.'%)' : '';
    }

    /** @return array{title: string, body: string} */
    public function day(CarbonImmutable $day, bool $morning = false): array
    {
        $org = CurrentOrganization::instance()->require();
        $tz = $org->timezone;
        $p = Period::between($day->setTimezone($tz)->startOfDay(), $day->setTimezone($tz)->endOfDay());
        $scope = Scope::all($this->storeIds());
        $facts = $this->m->facts($p, $scope);
        $t = $this->m->totals($p, $scope, $facts);
        $weekAgo = $this->m->totals(Period::between($p->from->subWeek(), $p->to->subWeek()), $scope);
        $name = $org->settings()->get('brand.display_name') ?: $org->name;
        $date = $p->from->locale('ru')->translatedFormat('l, j F');
        $lines = [$name.' · '.$date];
        if ($t['checks'] === 0 && $t['revenue'] === 0) {
            $lines[] = __('Продаж не было. Магазин работал?');
        } else {
            $lines[] = __('Выручка').' '.self::money($t['revenue']).$this->pct($t['revenue'], $weekAgo['revenue']);
            $lines[] = __('Прибыль').' '.self::money($t['gross']).' · '.__('маржа').' '.str_replace('.', ',', (string) $t['margin']).'%';
            $lines[] = __('Чеков').' '.$t['checks'].' · '.__('средний чек').' '.self::money($t['avg_check']).' · '.__('телефонов').' '.$t['phones'];
            $pay = $this->m->payments($p, $scope)->take(4)->map(fn ($r, $k) => Metrics::methodLabel($k).' '.number_format($r['amount'] / 100, 0, '', ' '))->implode(' · ');
            if ($pay) {
                $lines[] = __('Оплаты').': '.$pay;
            }
            $best = $this->m->by($facts, 'seller_id')->sortByDesc('revenue')->keys()->first();
            if ($best) {
                $lines[] = __('Лучший продавец').': '.strtok((string) User::query()->find($best)?->name, ' ').' — '.self::money($this->m->by($facts, 'seller_id')[$best]['revenue']);
            }
        }
        $diffs = DB::table('shift_counts as c')->join('shifts as s', 's.id', '=', 'c.shift_id')->where('s.organization_id', $org->id)->where('c.phase', 'close')
            ->where('s.closed_at', '>=', $p->startUtc())->where('s.closed_at', '<', $p->endUtc())->sum('c.diff');
        if ($diffs) {
            $lines[] = __('Касса закрыта, расхождение :s', ['s' => self::money((int) $diffs)]);
        }
        $todo = collect(app(Todo::class)->items($scope->storeIds))->take(3)->pluck('title')->implode(' · ');
        if ($todo) {
            $lines[] = __('Нужно сделать').': '.$todo;
        }
        if ($morning) {
            $orders = DB::table('orders')->where('organization_id', $org->id)->whereIn('status', ['active', 'overdue'])->count();
            $debts = DB::table('debt_entries')->where('organization_id', $org->id)->where('counterparty_type', 'customer')->whereDate('due_date', now($tz)->toDateString())->count();
            $lines[] = __('Сегодня: :o заказов к выдаче, :d долгов к возврату', ['o' => $orders, 'd' => $debts]);
        }

        return ['title' => ($morning ? __('Итог вчера') : __('Итог дня')).' · '.self::money($t['revenue']), 'body' => implode("\n", $lines)];
    }

    public function week(CarbonImmutable $anyDay): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $from = $anyDay->setTimezone($tz)->startOfWeek();
        $p = Period::between($from, $from->endOfWeek());
        $scope = Scope::all($this->storeIds());
        $f = $this->m->facts($p, $scope);
        $t = $this->m->totals($p, $scope, $f);
        $prev = $this->m->totals($p->previous(), $scope);
        $models = DB::table('stock_units as su')->join('variants as v', 'v.id', '=', 'su.variant_id')->join('models as m', 'm.id', '=', 'v.model_id')
            ->whereIn('su.id', $f->pluck('stock_unit_id')->filter())->pluck('m.name_ru', 'su.id');
        $top = $f->where('kind', 'sale')->filter(fn ($r) => $r->stock_unit_id)->groupBy(fn ($r) => $models[$r->stock_unit_id] ?? '?')
            ->map(fn ($g) => $g->sum(fn ($r) => $r->sign * ($r->amount - $r->cost)))->sortDesc()->take(3);
        $old = DB::table('stock_units')->where('organization_id', CurrentOrganization::instance()->requireId())->whereIn('status', ['in_stock', 'reserved'])->where('received_at', '<', now()->subDays(30));

        return ['title' => __('Итог недели').' · '.self::money($t['revenue']), 'body' => implode("\n", array_filter([
            __('Неделя :a – :b', ['a' => $p->from->format('d.m'), 'b' => $p->to->format('d.m')]),
            __('Выручка').' '.self::money($t['revenue']).$this->pct($t['revenue'], $prev['revenue']).' · '.__('прибыль').' '.self::money($t['gross']).$this->pct($t['gross'], $prev['gross']),
            $top->isNotEmpty() ? __('Топ моделей по прибыли').': '.$top->map(fn ($v, $k) => $k.' '.number_format($v / 100, 0, '', ' '))->implode(' · ') : null,
            __('Залежалось 30+ дней: :n шт. на :s', ['n' => (clone $old)->count(), 's' => self::money((int) (clone $old)->sum('cost_kzt'))]),
        ]))];
    }

    public function month(CarbonImmutable $anyDay): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $p = Period::between($anyDay->setTimezone($tz)->startOfMonth(), $anyDay->setTimezone($tz)->endOfMonth());
        $scope = Scope::all($this->storeIds());
        $t = $this->m->totals($p, $scope);
        $exp = collect($this->m->expensesByArticle($p, $scope))->sortDesc()->take(5)->map(fn ($v, $k) => $k.' '.number_format($v / 100, 0, '', ' '))->implode(' · ');
        $bonus = (int) DB::table('customer_bonus_balances')->where('organization_id', CurrentOrganization::instance()->requireId())->sum(DB::raw('available + pending'));

        return ['title' => __('Итог месяца').' · '.self::money($t['net']), 'body' => implode("\n", array_filter([
            $p->from->locale('ru')->translatedFormat('F Y'),
            __('Выручка').' '.self::money($t['revenue']).' → '.__('валовая').' '.self::money($t['gross']).' → '.__('чистая прибыль').' '.self::money($t['net']),
            $exp ? __('Расходы').': '.$exp : null,
            __('Обязательство по бонусам').': '.self::money($bonus),
        ]))];
    }
}
