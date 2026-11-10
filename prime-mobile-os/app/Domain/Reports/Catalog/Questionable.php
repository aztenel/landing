<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** О25 «Продажи с вопросами»: неизвестная себестоимость, ниже себестоимости, без фискального чека. */
class Questionable extends Report
{
    public function key(): string
    {
        return 'questions';
    }

    public function code(): string
    {
        return 'О25';
    }

    public function tab(): string
    {
        return 'control';
    }

    public function title(): string
    {
        return 'Продажи с вопросами';
    }

    public function permissions(): array
    {
        return ['report.profit', 'report.no_fiscal'];
    }

    public function filters(): array
    {
        return ['period', 'store'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $f = $this->metrics()->facts($p, $s)->where('kind', 'sale')->where('sign', 1)->where('doc_status', 'posted');
        $docs = DB::table('documents')->whereIn('id', $f->pluck('sale_doc'))->get(['id', 'number', 'fiscal_receipt_no'])->keyBy('id');
        $rows = $f->map(function ($r) use ($docs, $tz) {
            $why = array_filter([! $r->cost_known ? __('себестоимость неизвестна') : null, $r->cost_known && $r->amount < $r->cost ? __('ниже себестоимости') : null,
                empty($docs[$r->sale_doc]->fiscal_receipt_no) ? __('нет фискального чека') : null]);

            return $why ? ['date' => Carbon::parse($r->occurred_at)->setTimezone($tz)->format('d.m.Y H:i'), 'check' => $docs[$r->sale_doc]->number ?? '', 'amount' => $r->amount,
                'cost' => $r->cost_known ? $r->cost : null, 'why' => implode(', ', $why)] : null;
        })->filter()->values();

        return [
            'tiles' => [self::tile(__('Строк с вопросами'), $rows->count(), 'int'), self::tile(__('Без фискального чека, чеков'), $rows->filter(fn ($r) => str_contains($r['why'], __('нет фискального чека')))->pluck('check')->unique()->count(), 'int')],
            'columns' => ['date' => self::col(__('Дата')), 'check' => self::col(__('Чек')), 'amount' => self::col(__('Сумма'), 'money'), 'cost' => self::col(__('Себестоимость'), 'money', 'profit'), 'why' => self::col(__('Вопрос'))],
            'rows' => $rows->all(),
        ];
    }
}
