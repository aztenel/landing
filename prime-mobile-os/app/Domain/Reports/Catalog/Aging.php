<?php

namespace App\Domain\Reports\Catalog;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/** О9 «Залежался» — возраст товара по корзинам 0–30 / 31–60 / 61–90 / 90+. */
class Aging extends Report
{
    use StockQuery;

    public function key(): string
    {
        return 'aging';
    }

    public function code(): string
    {
        return 'О9';
    }

    public function tab(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Залежался';
    }

    public function permissions(): array
    {
        return ['report.aging', 'report.stock_value'];
    }

    public function filters(): array
    {
        return ['store', 'cond'];
    }

    public function build(Period $p, Scope $s, array $q): array
    {
        $units = $this->stock($s, ['in_stock', 'reserved', 'ordered', 'preparing']);
        $views = DB::table('site_stats')->where('organization_id', CurrentOrganization::instance()->requireId())->where('kind', 'view')->where('day', '>=', now()->subDays(30)->toDateString())
            ->groupBy('key')->selectRaw('key, sum(count) n')->pluck('n', 'key');
        $listed = DB::table('storefront_listings')->where('public_status', '!=', 'sold')->pluck('public_code')->flip();
        $bucket = fn (?int $a) => $a === null ? '0–30' : ($a <= 30 ? '0–30' : ($a <= 60 ? '31–60' : ($a <= 90 ? '61–90' : '90+')));
        $tiles = [];
        foreach (['0–30', '31–60', '61–90', '90+'] as $b) {
            $g = $units->filter(fn ($u) => $bucket(self::age($u->received_at)) === $b);
            $tiles[] = self::tile($b.' '.__('дней'), $g->count().' '.__('шт.'), 'text', null, Money::kztMinor((int) $g->sum('retail'))->format().' '.__('в рознице'));
        }
        $rows = $units->map(fn ($u) => ['code' => $u->public_code, 'model' => trim($u->model.' '.$u->memory.' '.$u->color), 'state' => $u->condition === 'used' ? __('б/у').' '.$u->grade : __('новый'),
            'status' => UnitStatus::labelOf(UnitStatus::tryFrom($u->status)), 'age' => self::age($u->received_at), 'cost' => (int) $u->cost_kzt, 'retail' => (int) $u->retail,
            'site' => isset($listed[$u->public_code]) ? __('да') : __('нет'), 'views' => (int) ($views[$u->public_code] ?? 0),
            '_url' => route('erp.stock.units.show', ['code' => $u->public_code]), '_class' => ($a = self::age($u->received_at)) > 90 ? 'text-bad' : ($a > 60 ? 'text-warn' : '')])
            ->sortByDesc('age')->values();

        return [
            'tiles' => $tiles,
            'columns' => ['code' => self::col(__('Код')), 'model' => self::col(__('Телефон')), 'state' => self::col(__('Состояние')), 'status' => self::col(__('Статус')),
                'age' => self::col(__('Возраст, дн.'), 'int'), 'cost' => self::col(__('Себестоимость'), 'money', 'cost'), 'retail' => self::col(__('Розница'), 'money'),
                'site' => self::col(__('На сайте')), 'views' => self::col(__('Просмотров за 30 дн.'), 'int')],
            'rows' => $rows->all(),
        ];
    }
}
