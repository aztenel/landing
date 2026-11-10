<?php

namespace App\Support\Ui;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Storefront\Services\Leads;
use App\Filament\Migration\Pages\Migration;
use App\Filament\Sales\Pages\Sales;
use App\Filament\Site\Pages\Site;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Support\Facades\DB;

/**
 * Topbar search of the ERP (TZ 04 §0.2): a PM code or IMEI opens the phone passport, a site request code «СЗ-2041»
 * opens the request (TZ 05 §11.3 — the seller pastes the code from WhatsApp).
 */
final class ErpSearch implements GlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $q = trim($query);
        $results = GlobalSearchResults::make();
        if (preg_match('/^\s*(СЗ|CZ|SZ)[-\s]?\d+\s*$/iu', $q) && Access::allows('site.leads') && ($lead = Leads::find($q))) {
            $results->category(__('Заявки с сайта'), [new GlobalSearchResult($lead->number, Site::getUrl().'?lead='.$lead->number,
                [__('Товар') => (string) $lead->public_code, __('Статус') => $lead->status])]);
        }
        if (Access::allows('stock.passport') && mb_strlen($q) >= 3) {
            $digits = preg_replace('/\D/', '', $q);
            $units = StockUnit::query()->with('variant')
                ->where(fn ($w) => $w->where('public_code', mb_strtoupper($q))->orWhere('public_code', 'PM-'.$digits)
                    ->orWhereIn('id', UnitIdentifier::query()->where('value', $digits ?: $q)->select('stock_unit_id')))
                ->limit(5)->get();
            if ($units->isNotEmpty()) {
                $results->category(__('Телефоны'), $units->map(fn ($u) => new GlobalSearchResult($u->public_code.' · '.$u->variant?->title(),
                    route('erp.stock.units.show', ['code' => $u->public_code]), [__('Статус') => UnitStatus::labelOf($u->status)]))->all());
            }
        }

        // the history from sfon (TZ 07 §1.10): «Продан в sfon 12.03.2025, гарантия до …»
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) === 15 && Access::allows('stock.passport')) {
            $old = DB::table('sfon_archive')->where('organization_id', CurrentOrganization::instance()->requireId())
                ->where('imei', $digits)->orderByDesc('occurred_at')->limit(3)->get();
            if ($old->isNotEmpty()) {
                $results->category(__('Архив sfon'), $old->map(fn ($a) => new GlobalSearchResult(__('Продан в sfon :d', ['d' => $a->occurred_at ? Carbon::parse($a->occurred_at)->format('d.m.Y') : '']).' · '.$a->title,
                    Access::isOwner() ? Migration::getUrl().'?tab=archive' : Sales::getUrl(),
                    [__('Чек') => (string) $a->number, __('Сумма') => Money::kztMinor((int) $a->amount)->format()]))->all());
            }
        }

        return $results;
    }
}
