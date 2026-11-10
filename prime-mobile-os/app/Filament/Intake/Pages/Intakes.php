<?php

namespace App\Filament\Intake\Pages;

use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\Purchase;
use App\Domain\Inventory\Models\PurchaseLine;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Reverser;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Livewire\WithPagination;

/** Э11.1 «Приходы» (/intake): posted / without cost; sums and supplier by right; reversal with a reason. */
class Intakes extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = ['stock.receive', 'stock.receive_acc'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'inbox';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'intake';

    protected static string $view = 'intake.pages.list';

    public string $tabName = 'posted';

    public ?int $supplier_id = null;

    public ?int $reverseId = null;

    public string $reason = '';

    public ?string $error = null;

    public static function getNavigationLabel(): string
    {
        return __('Приход');
    }

    public function getTitle(): string
    {
        return __('Приходы');
    }

    public function reverse(): void
    {
        $this->error = null;
        try {
            $doc = Document::query()->where('type', 'purchase')->findOrFail($this->reverseId);
            app(Reverser::class)->reverse($doc, ReversalReason::Other, trim($this->reason) ?: null, auth()->user());
            $this->reverseId = null;
            $this->reason = '';
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $canSupplier = Access::allows('stock.view_supplier');
        $docs = Document::query()->where('type', 'purchase')
            ->when($this->tabName === 'posted', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))
            ->when($this->tabName === 'drafts', fn ($q) => $q->where('status', 'draft'))
            ->when($this->tabName === 'no_cost', fn ($q) => $q->where('status', 'posted')->whereIn('id', PurchaseLine::query()->whereNull('price')->select('document_id')))
            ->when($this->supplier_id && $canSupplier, fn ($q) => $q->whereIn('id', Purchase::query()->where('supplier_id', $this->supplier_id)->select('document_id')))
            ->latest('id')->paginate(30);
        $purchases = Purchase::query()->whereIn('document_id', $docs->pluck('id'))->get()->keyBy('document_id');
        $lines = PurchaseLine::query()->whereIn('document_id', $docs->pluck('id'))->toBase()
            ->selectRaw("document_id, sum(case when line_type = 'unit' then 1 else 0 end) as units, sum(case when line_type = 'accessory' then qty else 0 end) as acc")
            ->groupBy('document_id')->get()->keyBy('document_id');

        return [
            'docs' => $docs, 'purchases' => $purchases, 'lines' => $lines,
            'suppliers' => $canSupplier ? Supplier::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'canSupplier' => $canSupplier, 'canCost' => Access::allows('stock.view_cost'), 'canReverse' => Access::allows('doc.reverse'),
            'users' => \App\Models\User::query()->pluck('name', 'id'),
            'noCost' => Document::query()->where('type', 'purchase')->where('status', 'posted')->whereIn('id', PurchaseLine::query()->whereNull('price')->select('document_id'))->count(),
            // ТЗ 07 сценарий 7: the rows of a seller's intake show «себестоимость не задана»
            'noCostIds' => PurchaseLine::query()->whereNull('price')->whereIn('document_id', $docs->pluck('id'))->distinct()->pluck('document_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
