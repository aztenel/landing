<?php

namespace App\Filament\Warehouse\Pages;

use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Warehouse\Models\WarrantyCase;
use App\Domain\Warehouse\Services\Warranty;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/** Э17 «Гарантия» (/warranty): accept by IMEI of a sold phone, statuses up to «Выдан»; the phone stays «продан». */
class WarrantyCases extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'sale.create';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'shield';

    protected static ?int $navigationSort = 18;

    protected static ?string $slug = 'warranty';

    protected static string $view = 'warehouse.pages.warranty';

    public string $code = '';

    public string $complaint = '';

    public string $kit = '';

    public ?int $serviceId = null;

    public string $result = '';

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Гарантия');
    }

    public function getTitle(): string
    {
        return __('Гарантийные обращения');
    }

    public function mount(): void
    {
        if ($unit = request()->integer('unit')) {
            $this->code = (string) StockUnit::query()->find($unit)?->public_code;
        }
    }

    public function accept(): void
    {
        $this->error = null;
        try {
            $unit = StockUnit::query()->where('public_code', mb_strtoupper(trim($this->code)))->first() ?? StockUnit::findByIdentifier(trim($this->code))
                ?? throw \App\Domain\Ledger\Exceptions\PostingException::because('not_found', __('Телефон не найден. Если продан не нами — гарантии нет'));
            $case = app(Warranty::class)->accept($unit, auth()->user(), WorkingStore::get(auth()->user())->id, $this->complaint, $this->kit ?: null);
            $this->flash = __('Обращение :n принято', ['n' => $case->number]);
            $this->code = $this->complaint = $this->kit = '';
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function move(int $caseId, string $to): void
    {
        $this->error = null;
        try {
            $case = WarrantyCase::query()->whereIn('store_id', WorkingStore::available(auth()->user())->pluck('id'))->findOrFail($caseId);
            app(Warranty::class)->move($case, $to, auth()->user(), $this->result ?: null, $this->serviceId);
            $this->result = '';
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $cases = WarrantyCase::query()->whereIn('store_id', WorkingStore::available(auth()->user())->pluck('id'))->latest('id')->limit(50)->get();

        return [
            'cases' => $cases,
            'units' => StockUnit::query()->with('variant')->whereIn('id', $cases->pluck('stock_unit_id'))->get()->keyBy('id'),
            'services' => Supplier::query()->where('is_active', true)->orderByDesc('is_repair_master')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
