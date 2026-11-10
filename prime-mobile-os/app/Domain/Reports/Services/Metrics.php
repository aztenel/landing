<?php

namespace App\Domain\Reports\Services;

use App\Domain\Money\Enums\PaymentMethodCode;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ONE formula for every figure (TZ 06 §0 п. 1, §2.1): «Статистика», reports, Excel and summaries call these functions.
 * Source — only posted documents (§1.1): a sale counts on its date; its reversal counts on the reversal date with the
 * opposite sign; a customer return counts on the return date. Money in tiyn.
 *
 * Today and small periods are computed from the documents directly. Nightly report_daily summaries (§1.5) are a
 * speed-up for later — the figures must stay the same.
 */
final class Metrics
{
    /**
     * Sale and return lines of the period with a sign (facts). Fields: kind (sale|return), sign, sale_doc, doc_status,
     * occurred_at, store_id, seller_id, source, customer_id, line_type, stock_unit_id, accessory_id, qty, amount, cost,
     * cost_known, list_amount, bonus_paid, returned_all.
     */
    public function facts(Period $p, Scope $s): Collection
    {
        $org = CurrentOrganization::instance()->requireId();
        $docs = "((d.type = ? and d.status in ('posted','reversed')) or (d.type = 'reversal' and d.status = 'posted'))";
        $filters = function (string $alias = 'sl') use ($s) {
            $sql = '';
            $b = [];
            if ($s->direction) {
                $sql .= " and {$alias}.line_type = ?";
                $b[] = $s->direction === 'phones' ? 'unit' : 'accessory';
            }
            if ($s->sellerId) {
                $sql .= ' and sa.seller_id = ?';
                $b[] = $s->sellerId;
            }
            if ($s->source) {
                $sql .= " and coalesce(sa.source, '') = ?";
                $b[] = $s->source === '—' ? '' : $s->source;
            }
            if ($s->condition) {
                $sql .= ' and su.condition = ?';
                $b[] = $s->condition;
            }
            if ($s->modelId) {
                $sql .= ' and v.model_id = ?';
                $b[] = $s->modelId;
            }
            if ($s->typeCode) {
                $sql .= ' and pt.code = ?';
                $b[] = $s->typeCode;
            }

            return [$sql, $b];
        };
        $catalog = 'left join stock_units su on su.id = sl.stock_unit_id left join variants v on v.id = su.variant_id
            left join models m on m.id = v.model_id left join families f on f.id = m.family_id left join product_types pt on pt.id = f.product_type_id';
        $stores = $s->storeIds ?: [0];
        $in = implode(',', array_fill(0, count($stores), '?'));
        [$fs, $fb] = $filters();

        $sales = DB::select("select 'sale' as kind, case when d.type = 'reversal' then -1 else 1 end as sign, o.id as sale_doc, o.status as doc_status,
                d.occurred_at, o.store_id, sa.seller_id, sa.source, sa.customer_id, sl.line_type, sl.stock_unit_id, sl.accessory_id, sl.qty, sl.amount,
                coalesce(sl.cost_kzt, 0) as cost, sl.cost_kzt is not null as cost_known, coalesce(sl.list_price_kzt, sl.price) * sl.qty as list_amount,
                coalesce(sl.bonus_paid_share, 0) as bonus_paid, coalesce(sl.returned_qty, 0) >= sl.qty as returned_all
            from documents d
            join documents o on o.id = case when d.type = 'reversal' then d.reversal_of_document_id else d.id end
            join sales sa on sa.document_id = o.id join sale_lines sl on sl.document_id = o.id {$catalog}
            where d.organization_id = ? and o.type = 'sale' and {$docs} and d.occurred_at >= ? and d.occurred_at < ? and o.store_id in ({$in}) {$fs}",
            array_merge([$org, 'sale', $p->startUtc(), $p->endUtc()], $stores, $fb));

        $returns = DB::select("select 'return' as kind, case when d.type = 'reversal' then -1 else 1 end as sign, sl.document_id as sale_doc, o.status as doc_status,
                d.occurred_at, o.store_id, sa.seller_id, sa.source, sa.customer_id, sl.line_type, sl.stock_unit_id, sl.accessory_id, crl.qty, crl.amount,
                coalesce(crl.cost_kzt, 0) as cost, true as cost_known, 0 as list_amount, 0 as bonus_paid, false as returned_all
            from documents d
            join documents o on o.id = case when d.type = 'reversal' then d.reversal_of_document_id else d.id end
            join customer_return_lines crl on crl.document_id = o.id join sale_lines sl on sl.id = crl.sale_line_id
            join sales sa on sa.document_id = sl.document_id {$catalog}
            where d.organization_id = ? and o.type = 'customer_return' and {$docs} and d.occurred_at >= ? and d.occurred_at < ? and o.store_id in ({$in}) {$fs}",
            array_merge([$org, 'customer_return', $p->startUtc(), $p->endUtc()], $stores, $fb));

        return collect($sales)->concat($returns)->map(function ($r) {
            foreach (['sign', 'sale_doc', 'store_id', 'seller_id', 'customer_id', 'stock_unit_id', 'accessory_id', 'qty', 'amount', 'cost', 'list_amount', 'bonus_paid'] as $k) {
                $r->{$k} = $r->{$k} === null ? null : (int) $r->{$k};
            }
            $r->cost_known = (bool) $r->cost_known;
            $r->returned_all = (bool) $r->returned_all;

            return $r;
        });
    }

    /** All figures of §2.1 for a period. */
    public function totals(Period $p, Scope $s, ?Collection $facts = null): array
    {
        $f = $facts ?? $this->facts($p, $s);
        $t = $this->fold($f);
        $t['commissions'] = $this->commissions($p, $s);
        $t['bonus_paid'] = $f->where('kind', 'sale')->sum(fn ($r) => $r->sign * $r->bonus_paid) - $this->bonusReturned($p, $s);
        $t['profit_sales'] = $t['gross'] - $t['commissions'] - $t['bonus_paid'];
        $t['expenses'] = $s->lineFiltered() ? 0 : array_sum($this->expensesByArticle($p, $s));
        $t['other'] = $s->lineFiltered() ? 0 : $this->other($p, $s);
        $t['net'] = $t['profit_sales'] - $t['expenses'] + $t['other'];

        return $t;
    }

    /** Line figures only (no commissions/expenses) — for tables grouped by day, seller, model … */
    public function fold(Collection $f): array
    {
        $sale = $f->where('kind', 'sale');
        $ret = $f->where('kind', 'return');
        $sales = $sale->sum(fn ($r) => $r->sign * $r->amount);
        $returns = $ret->sum(fn ($r) => $r->sign * $r->amount);
        $revenue = $sales - $returns;
        $cost = $sale->sum(fn ($r) => $r->sign * $r->cost) - $ret->sum(fn ($r) => $r->sign * $r->cost);
        $gross = $revenue - $cost;
        $checks = $sale->filter(fn ($r) => $r->sign === 1 && $r->doc_status === 'posted')->groupBy('sale_doc')
            ->reject(fn ($lines) => $lines->every(fn ($r) => $r->returned_all))->count();
        $discounts = $sale->sum(fn ($r) => $r->sign * max(0, $r->list_amount - $r->amount));
        $units = fn (string $type) => $sale->where('line_type', $type)->sum(fn ($r) => $r->sign * $r->qty) - $ret->where('line_type', $type)->sum(fn ($r) => $r->sign * $r->qty);
        $unknown = $sale->filter(fn ($r) => ! $r->cost_known && $r->sign === 1);

        return [
            'sales' => $sales, 'returns' => $returns, 'revenue' => $revenue, 'cost' => $cost, 'gross' => $gross,
            'margin' => $revenue ? round($gross * 100 / $revenue, 2) : null,
            'markup' => $cost ? round($gross * 100 / $cost, 2) : null,
            'checks' => $checks, 'avg_check' => $checks ? intdiv($revenue, $checks) : 0,
            'phones' => $units('unit'), 'accessories' => $units('accessory'),
            'discounts' => $discounts, 'discount_pct' => ($sales + $discounts) ? round($discounts * 100 / ($sales + $discounts), 2) : null,
            'unknown_cost_count' => $unknown->count(), 'unknown_cost_amount' => $unknown->sum('amount'),
        ];
    }

    /** «Комиссии банков» — the money journal, article bank_fee (sale payments and debt payments). */
    public function commissions(Period $p, Scope $s): int
    {
        $q = $this->money($p, $s)->where('a.code', 'bank_fee');
        if ($s->lineFiltered()) {
            $q->whereIn('me.sale_payment_id', DB::table('sale_payments as sp')->join('sales as sa', 'sa.document_id', '=', 'sp.document_id')
                ->when($s->sellerId, fn ($w) => $w->where('sa.seller_id', $s->sellerId))
                ->when($s->source, fn ($w) => $w->whereRaw("coalesce(sa.source, '') = ?", [$s->source === '—' ? '' : $s->source]))->select('sp.id'));
        }

        return (int) -$q->sum('me.amount_kzt');
    }

    private function bonusReturned(Period $p, Scope $s): int
    {
        return (int) DB::table('documents as d')
            ->join('documents as o', 'o.id', '=', DB::raw("case when d.type = 'reversal' then d.reversal_of_document_id else d.id end"))
            ->join('customer_returns as cr', 'cr.document_id', '=', 'o.id')
            ->where('d.organization_id', CurrentOrganization::instance()->requireId())->where('o.type', 'customer_return')
            ->whereRaw("((d.type = 'customer_return' and d.status in ('posted','reversed')) or (d.type = 'reversal' and d.status = 'posted'))")
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())->whereIn('o.store_id', $s->storeIds ?: [0])
            ->when($s->sellerId, fn ($w) => $w->whereIn('cr.sale_document_id', DB::table('sales')->where('seller_id', $s->sellerId)->select('document_id')))
            ->sum(DB::raw("case when d.type = 'reversal' then -cr.bonus_returned else cr.bonus_returned end"));
    }

    private function money(Period $p, Scope $s)
    {
        return DB::table('money_entries as me')->join('money_articles as a', 'a.id', '=', 'me.article_id')
            ->where('me.organization_id', CurrentOrganization::instance()->requireId())
            ->where('me.occurred_at', '>=', $p->startUtc())->where('me.occurred_at', '<', $p->endUtc())
            ->where(fn ($w) => $w->whereIn('me.store_id', $s->storeIds ?: [0])->orWhereNull('me.store_id'));
    }

    /** «Расходы» (§2.1): posted expense documents by article «идёт в прибыль»; a reversal subtracts on its date. @return array<string, int> article => tiyn */
    public function expensesByArticle(Period $p, Scope $s): array
    {
        return DB::table('documents as d')
            ->join('documents as o', 'o.id', '=', DB::raw("case when d.type = 'reversal' then d.reversal_of_document_id else d.id end"))
            ->join('expenses as e', 'e.document_id', '=', 'o.id')->join('money_articles as a', 'a.id', '=', 'e.article_id')
            ->where('d.organization_id', CurrentOrganization::instance()->requireId())->where('o.type', 'expense')
            ->whereRaw("((d.type = 'expense' and d.status in ('posted','reversed')) or (d.type = 'reversal' and d.status = 'posted'))")
            ->where('a.affects_profit', true)->where('a.code', '!=', 'bank_fee')
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())
            ->where(fn ($w) => $w->whereIn('o.store_id', $s->storeIds ?: [0])->orWhereNull('o.store_id'))
            ->groupBy('a.name_ru')->selectRaw("a.name_ru as article, sum(case when d.type = 'reversal' then -e.total_kzt else e.total_kzt end) as total")
            ->pluck('total', 'article')->map(fn ($v) => (int) $v)->all();
    }

    /** «Прочее» (§2.1): cash shortages/surpluses, supplier return differences, write-offs and revision differences, fx differences. */
    public function other(Period $p, Scope $s): int
    {
        $cash = (int) $this->money($p, $s)->whereIn('a.code', ['cash_diff', 'supplier_return_diff'])->sum('me.amount_kzt');
        $stock = (int) DB::table('inventory_movements as im')->join('documents as d', 'd.id', '=', 'im.document_id')
            ->where('im.organization_id', CurrentOrganization::instance()->requireId())->where('d.status', 'posted')
            ->whereIn('im.kind', ['write_off', 'shortage', 'surplus'])
            ->where('im.occurred_at', '>=', $p->startUtc())->where('im.occurred_at', '<', $p->endUtc())->whereIn('im.store_id', $s->storeIds ?: [0])
            ->sum(DB::raw("case when im.kind = 'surplus' then abs(coalesce(im.total_cost_kzt, 0)) else -abs(coalesce(im.total_cost_kzt, 0)) end"));
        $fx = (int) DB::table('debt_entries')->where('organization_id', CurrentOrganization::instance()->requireId())
            ->where('occurred_at', '>=', $p->startUtc())->where('occurred_at', '<', $p->endUtc())->sum(DB::raw('-coalesce(fx_diff_kzt, 0)'));

        return $cash + $stock + $fx;
    }

    /** Group facts by a key and fold each group. @return Collection<string, array> */
    public function by(Collection $facts, string|callable $key): Collection
    {
        return $facts->groupBy($key)->map(fn ($g) => $this->fold($g));
    }

    /** Local day / week / month key of a fact. */
    public static function bucketKey(string $occurredAt, string $bucket, string $tz): string
    {
        $d = CarbonImmutable::parse($occurredAt)->setTimezone($tz);

        return match ($bucket) {
            'week' => $d->startOfWeek()->format('Y-m-d'),
            'month' => $d->format('Y-m'),
            default => $d->format('Y-m-d'),
        };
    }

    /** «Деньги в кассах» (Э2): balances of real accounts, $ by the current rate. */
    public function cash(array $storeIds): array
    {
        $rows = DB::table('account_balances as b')->join('money_accounts as a', 'a.id', '=', 'b.account_id')
            ->where('a.organization_id', CurrentOrganization::instance()->requireId())->where('a.type', '!=', 'technical')->where('a.is_active', true)
            ->where(fn ($w) => $w->whereIn('a.store_id', $storeIds ?: [0])->orWhereNull('a.store_id'))
            ->get(['a.type', 'b.currency', 'b.balance']);
        $rate = (string) (DB::table('exchange_rates')->where('organization_id', CurrentOrganization::instance()->requireId())->where('currency', 'USD')
            ->where('valid_from', '<=', now())->orderByDesc('valid_from')->value('rate') ?? '0');
        $usd = (int) $rows->where('currency', 'USD')->sum('balance');
        $cashKzt = (int) $rows->where('currency', 'KZT')->whereIn('type', ['cash', 'safe'])->sum('balance');
        $nonCash = (int) $rows->where('currency', 'KZT')->whereNotIn('type', ['cash', 'safe'])->sum('balance');

        return ['total' => $cashKzt + $nonCash + (int) round($usd * (float) $rate), 'cash' => $cashKzt, 'non_cash' => $nonCash, 'usd' => $usd, 'rate' => $rate];
    }

    /**
     * Payments by method (TZ 06 О5): sale payments on the sale date, minus a reversal on its date, minus customer refunds
     * on the return date. @return Collection<string, array{count: int, amount: int, commission: int}>
     */
    public function payments(Period $p, Scope $s): Collection
    {
        $org = CurrentOrganization::instance()->requireId();
        $base = fn (string $type) => DB::table('documents as d')
            ->join('documents as o', 'o.id', '=', DB::raw("case when d.type = 'reversal' then d.reversal_of_document_id else d.id end"))
            ->where('d.organization_id', $org)->where('o.type', $type)
            ->whereRaw("((d.type = ? and d.status in ('posted','reversed')) or (d.type = 'reversal' and d.status = 'posted'))", [$type])
            ->where('d.occurred_at', '>=', $p->startUtc())->where('d.occurred_at', '<', $p->endUtc())->whereIn('o.store_id', $s->storeIds ?: [0]);
        $sales = $base('sale')->join('sale_payments as sp', 'sp.document_id', '=', 'o.id')
            ->when($s->sellerId, fn ($w) => $w->whereIn('o.id', DB::table('sales')->where('seller_id', $s->sellerId)->select('document_id')))
            ->groupBy('sp.method')->selectRaw("sp.method, sum(case when d.type = 'reversal' then -1 else 1 end) as n,
                sum(case when d.type = 'reversal' then -sp.amount else sp.amount end) as amount,
                sum(case when d.type = 'reversal' then -coalesce(sp.commission_amount, 0) else coalesce(sp.commission_amount, 0) end) as commission")->get();
        $refunds = $base('customer_return')->join('customer_return_payments as rp', 'rp.document_id', '=', 'o.id')
            ->groupBy('rp.method')->selectRaw("rp.method, sum(case when d.type = 'reversal' then -rp.amount else rp.amount end) as amount")->pluck('amount', 'method');
        $out = collect();
        foreach ($sales as $r) {
            $out[$r->method] = ['count' => (int) $r->n, 'amount' => (int) $r->amount, 'commission' => (int) $r->commission];
        }
        foreach ($refunds as $m => $a) {
            $row = $out[$m] ?? ['count' => 0, 'amount' => 0, 'commission' => 0];
            $row['amount'] -= (int) $a;
            $out[$m] = $row;
        }

        return $out->sortByDesc('amount');
    }

    public static function methodLabel(string $m): string
    {
        return PaymentMethodCode::tryFrom($m)?->label() ?? $m;
    }
}
