<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О7 «Скидки и заявки». */
class Discounts extends Report
{
    public function key(): string
    {
        return 'discounts';
    }

    public function code(): string
    {
        return 'О7';
    }

    public function tab(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Скидки и заявки';
    }

    public function permissions(): array
    {
        return ['report.sales', 'report.approvals'];
    }

    public function filters(): array
    {
        return ['period', 'store', 'seller'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $f = $this->metrics()->facts($p, $s)->where('kind', 'sale')->where('sign', 1)->filter(fn ($r) => $r->list_amount > $r->amount);
        $docs = DB::table('documents')->whereIn('id', $f->pluck('sale_doc'))->get(['id', 'number', 'approval_request_id'])->keyBy('id');
        $names = User::query()->pluck('name', 'id');
        $rows = $f->map(fn ($r) => ['date' => Carbon::parse($r->occurred_at)->setTimezone($tz)->format('d.m.Y H:i'), 'check' => $docs[$r->sale_doc]->number ?? '',
            'seller' => $names[$r->seller_id] ?? '', 'list' => $r->list_amount, 'amount' => $r->amount, 'discount' => $r->list_amount - $r->amount,
            'pct' => round(($r->list_amount - $r->amount) * 100 / $r->list_amount, 1), 'by_request' => ! empty($docs[$r->sale_doc]->approval_request_id) ? __('по заявке') : __('в лимите')])->values();
        $req = DB::table('approval_requests')->where('organization_id', CurrentOrganization::instance()->requireId())
            ->where('created_at', '>=', $p->startUtc())->where('created_at', '<', $p->endUtc())->orderByDesc('id')->limit(500)
            ->get(['kind', 'headline', 'status', 'requested_by', 'decided_by', 'created_at', 'decided_at', 'max_value']);
        $waits = $req->filter(fn ($r) => $r->decided_at)->map(fn ($r) => Carbon::parse($r->created_at)->diffInMinutes(Carbon::parse($r->decided_at), true));
        $status = ['approved' => __('разрешено'), 'rejected' => __('отклонено'), 'pending' => __('ждёт'), 'expired' => __('истекла'), 'cancelled' => __('отменена')];

        return [
            'tiles' => [self::tile(__('Скидки всего'), (int) $rows->sum('discount')), self::tile(__('По заявкам'), (int) $rows->where('by_request', __('по заявке'))->sum('discount')),
                self::tile(__('Заявок: разрешено / отклонено'), $req->where('status', 'approved')->count().' / '.$req->where('status', 'rejected')->count(), 'text'),
                self::tile(__('Среднее время ответа, мин'), $waits->isNotEmpty() ? (int) round($waits->avg()) : null, 'int')],
            'columns' => ['date' => self::col(__('Дата')), 'check' => self::col(__('Чек')), 'seller' => self::col(__('Продавец')), 'list' => self::col(__('По прайсу'), 'money'),
                'amount' => self::col(__('Продано за'), 'money'), 'discount' => self::col(__('Скидка'), 'money'), 'pct' => self::col(__('Скидка, %'), 'pct'), 'by_request' => self::col(__('Как'))],
            'rows' => $rows->all(),
            'extra' => [['title' => __('Заявки'), 'columns' => ['when' => self::col(__('Когда')), 'what' => self::col(__('Заявка')), 'who' => self::col(__('Кто просил')),
                'status' => self::col(__('Решение')), 'by' => self::col(__('Кто решил')), 'wait' => self::col(__('Ждали, мин'), 'int')],
                'rows' => $req->map(fn ($r) => ['when' => Carbon::parse($r->created_at)->setTimezone($tz)->format('d.m H:i'), 'what' => $r->headline, 'who' => $names[$r->requested_by] ?? '',
                    'status' => $status[$r->status] ?? $r->status, 'by' => $names[$r->decided_by] ?? '', 'wait' => $r->decided_at ? (int) Carbon::parse($r->created_at)->diffInMinutes(Carbon::parse($r->decided_at), true) : null])->all()]],
        ];
    }
}
