<?php

namespace App\Livewire\Stock;

use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Inventory\Models\PriceChange;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Inventory\Queries\UnitList;
use App\Domain\Inventory\Services\ImeiCheckService;
use App\Domain\Inventory\Services\PriceService;
use App\Domain\Inventory\Services\UnitStatusService;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Э10 «Паспорт телефона» (/unit/PM-10482, route erp.stock.units.show). Everything about one phone: header, «Можно
 * продать?», history from the journals, госбаза, prices, cost (by right), buttons by status and rights. Status only
 * changes by the transitions of UnitTransitions (UnitStatusService) or by documents of other modules.
 *
 * Extension points for other modules (docs/modules/stock.md): tab «Сайт» (storefront), «Гарантия» (warranty),
 * buttons of documents (transfer, write-off, unit edit) — the view shows them when their routes exist.
 */
class UnitPassport extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'stock.passport';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'stock.pages.passport';

    public int $unitId;

    public string $tabName = 'history';

    public ?string $modal = null;

    public string $newPrice = '';

    public ?string $error = null;

    public ?string $flash = null;

    public function mount(string $code): void
    {
        abort_unless(static::canAccess(), 403);
        $unit = preg_match('/^\d+$/', $code) && strlen($code) < 10
            ? StockUnit::query()->find((int) $code)
            : (StockUnit::query()->where('public_code', mb_strtoupper($code))->first() ?? StockUnit::findByIdentifier($code));
        abort_unless($unit, 404, __('Телефон с таким IMEI не найден. Проверьте цифры или оприходуйте его'));
        $this->unitId = $unit->id;
    }

    public function getTitle(): string
    {
        return $this->unit()->variant?->title() ?? __('Телефон');
    }

    public function getHeading(): string
    {
        return '';
    }

    protected function unit(): StockUnit
    {
        return StockUnit::query()->with(['variant.productModel', 'variant.memory', 'variant.color', 'variant.ram'])->findOrFail($this->unitId);
    }

    protected function act(callable $action, string $success): void
    {
        $this->error = null;
        try {
            $action();
            $this->flash = $success;
            $this->modal = null;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function imei(string $result): void
    {
        $this->act(fn () => app(ImeiCheckService::class)->record($this->unit(), ImeiCheckResult::from($result), auth()->user()), __('Отметка госбазы сохранена'));
    }

    public function price(): void
    {
        $tenge = (int) preg_replace('/\D/', '', $this->newPrice);
        $this->act(function () use ($tenge) {
            if ($tenge <= 0) {
                throw new \InvalidArgumentException(__('Введите новую цену'));
            }
            app(PriceService::class)->setUnitPrice($this->unit(), Money::kzt($tenge), auth()->user());
        }, __('Цена изменена'));
    }

    public function ready(): void
    {
        $this->act(fn () => app(UnitStatusService::class)->markReady($this->unit(), auth()->user()), __('Телефон готов к продаже'));
    }

    public function toPreparation(): void
    {
        $this->act(fn () => app(UnitStatusService::class)->sendToPreparation($this->unit(), auth()->user()), __('Отправлен на подготовку'));
    }

    public function decide(string $to): void
    {
        $this->act(fn () => app(UnitStatusService::class)->decideCheck($this->unit(), UnitStatus::from($to), auth()->user()), __('Решение сохранено'));
    }

    protected function getViewData(): array
    {
        $unit = $this->unit();
        $canCost = Access::allows('stock.view_cost');
        $canSupplier = Access::allows('stock.view_supplier');
        $tz = CurrentOrganization::instance()->require()->timezone;
        $listPrice = $unit->sale_price ?? VariantPrice::query()->where('variant_id', $unit->variant_id)->value('price_kzt');
        $reservation = $unit->status === UnitStatus::Reserved ? UnitReservation::query()->where('stock_unit_id', $unit->id)->whereNull('released_at')->first() : null;
        $saleLine = $unit->status === UnitStatus::Sold ? SaleLine::query()->where('stock_unit_id', $unit->id)->where('is_active', true)->latest('id')->first() : null;

        return [
            'unit' => $unit,
            'canCost' => $canCost,
            'canSupplier' => $canSupplier,
            'supplier' => $canSupplier && $unit->supplier_id ? Supplier::query()->find($unit->supplier_id)?->name : null,
            'price' => $listPrice === null ? null : (int) $listPrice,
            'blockers' => UnitList::sellBlockers($unit),
            'reservation' => $reservation,
            'reservedBy' => $reservation ? User::query()->find($reservation->user_id)?->name : null,
            'sale' => $saleLine ? Document::query()->find($saleLine->document_id) : null,
            'store' => $unit->location_type === 'store' ? Store::query()->find($unit->location_id)?->name : __('в пути'),
            'checks' => ImeiCheck::query()->where('stock_unit_id', $unit->id)->orderByDesc('checked_at')->orderByDesc('id')->get(),
            'history' => $this->history($unit, $canCost, $canSupplier),
            'prices' => PriceChange::query()->where('subject_type', 'stock_unit')->where('subject_id', $unit->id)->latest('id')->get(),
            'days' => $unit->received_at ? (int) $unit->received_at->diffInDays(now()) : null,
            'tz' => $tz,
            'can' => [
                'price' => Access::allows('price.edit'), 'imei' => Access::allows('stock.imei_check'),
                'prepare' => Access::allows('stock.prepare'), 'prepare_cost' => Access::allows('stock.prepare_cost'),
                'sell' => Access::allows('sale.create'),
            ],
            'users' => User::query()->whereIn('id', ImeiCheck::query()->where('stock_unit_id', $unit->id)->select('checked_by'))->pluck('name', 'id'),
        ];
    }

    /** Events from the journals, newest first: movements (documents), price changes, госбаза. */
    protected function history(StockUnit $unit, bool $canCost, bool $canSupplier): Collection
    {
        $docs = Document::query()->whereIn('id', InventoryMovement::query()->where('stock_unit_id', $unit->id)->select('document_id'))->get()->keyBy('id');
        $events = InventoryMovement::query()->where('stock_unit_id', $unit->id)->orderByDesc('id')->get()->map(function ($m) use ($docs, $canCost) {
            $doc = $docs[$m->document_id] ?? null;

            return [
                'at' => $m->occurred_at ?? $m->recorded_at, 'kind' => 'doc',
                'title' => ($doc?->type?->label() ?? $m->kind?->value).($doc ? ' '.$doc->number : ''),
                'detail' => trim(($m->status_before ? UnitStatus::labelOf($m->status_before) : '').' → '.UnitStatus::labelOf($m->status_after), ' →')
                    .($canCost && $m->total_cost_kzt ? ' · '.__('себестоимость').' '.Money::kztMinor((int) $m->total_cost_kzt)->format() : ''),
                'user' => $doc?->posted_by,
            ];
        });
        $prices = PriceChange::query()->where('subject_type', 'stock_unit')->where('subject_id', $unit->id)->get()->map(fn ($p) => [
            'at' => $p->created_at ? \Carbon\CarbonImmutable::parse($p->created_at) : null, 'kind' => 'price', 'title' => __('Смена цены'),
            'detail' => ($p->old_price ? Money::kztMinor((int) $p->old_price)->format() : '—').' → '.Money::kztMinor((int) $p->new_price)->format(), 'user' => $p->user_id,
        ]);
        $checks = ImeiCheck::query()->where('stock_unit_id', $unit->id)->get()->map(fn ($c) => [
            'at' => $c->checked_at, 'kind' => 'imei', 'title' => __('Госбаза'), 'detail' => $c->result->label(), 'user' => $c->checked_by,
        ]);
        $all = $events->concat($prices)->concat($checks)->sortByDesc(fn ($e) => $e['at']?->getTimestamp() ?? 0)->values();
        $names = User::query()->whereIn('id', $all->pluck('user')->filter()->unique())->pluck('name', 'id');

        return $all->map(fn ($e) => $e + ['who' => $names[$e['user']] ?? null]);
    }
}
