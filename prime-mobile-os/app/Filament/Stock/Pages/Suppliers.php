<?php

namespace App\Filament\Stock\Pages;

use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Inventory\Models\StockUnit;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Illuminate\Validation\Rule;

/** Э23 «Поставщики» (/suppliers): list with debts, card, create / edit; the seller does not see suppliers at all. */
class Suppliers extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'debt.supplier_view';

    protected static ?MenuGroup $menuGroup = MenuGroup::Accounting;

    protected static ?string $navigationIcon = 'truck';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'suppliers';

    protected static string $view = 'stock.pages.suppliers';

    public string $q = '';

    public bool $showHidden = false;

    public ?int $openId = null;

    public ?array $form = null;

    public static function getNavigationLabel(): string
    {
        return __('Поставщики');
    }

    public function getTitle(): string
    {
        return __('Поставщики');
    }

    public function edit(?int $id = null): void
    {
        Access::authorize('supplier.edit');
        $s = $id ? Supplier::query()->findOrFail($id) : new Supplier(['currency' => 'KZT']);
        $this->form = ['id' => $s->id] + $s->only(['name', 'contact_person', 'phone', 'whatsapp', 'city', 'currency', 'payment_days', 'bin_iin', 'requisites', 'comment', 'is_repair_master', 'is_active']);
        $this->form['is_active'] ??= true;
        $this->form['is_repair_master'] = (bool) ($this->form['is_repair_master'] ?? false);
    }

    public function save(): void
    {
        Access::authorize('supplier.edit');
        $id = $this->form['id'] ?? null;
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120', Rule::unique('suppliers', 'name')->where('organization_id', \App\Support\Tenancy\CurrentOrganization::instance()->requireId())->ignore($id)],
            'form.contact_person' => 'nullable|string|max:120', 'form.phone' => 'nullable|string|max:20', 'form.whatsapp' => 'nullable|string|max:20',
            'form.city' => 'nullable|string|max:80', 'form.currency' => 'required|in:KZT,USD', 'form.payment_days' => 'nullable|integer|min:0|max:365',
            'form.bin_iin' => 'nullable|digits:12', 'form.requisites' => 'nullable|string|max:2000', 'form.comment' => 'nullable|string|max:2000',
            'form.is_repair_master' => 'boolean', 'form.is_active' => 'boolean',
        ], ['form.name.unique' => __('Такой поставщик уже есть')])['form'];
        $supplier = $id ? Supplier::query()->findOrFail($id) : new Supplier;
        $supplier->fill($data)->save();
        $this->form = null;
        $this->openId = $supplier->id;
    }

    protected function getViewData(): array
    {
        $q = trim($this->q);
        $suppliers = Supplier::query()
            ->when(! $this->showHidden, fn ($w) => $w->where('is_active', true))
            ->when($q !== '', fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($q, '%_').'%'))
            ->orderBy('name')->get();
        $debts = CounterpartyBalance::query()->where('counterparty_type', 'supplier')->get()->groupBy('counterparty_id');
        $open = $this->openId ? Supplier::query()->find($this->openId) : null;

        return [
            'suppliers' => $suppliers,
            'debts' => $debts,
            'open' => $open,
            'canEdit' => Access::allows('supplier.edit'),
            'purchases' => $open ? Document::query()->where('type', 'purchase')->where('status', 'posted')
                ->whereIn('id', \App\Domain\Inventory\Models\Purchase::query()->where('supplier_id', $open->id)->select('document_id'))->latest('id')->limit(20)->get() : collect(),
            'phones' => $open && Access::allows('stock.view_supplier') ? StockUnit::query()->where('supplier_id', $open->id)->toBase()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status') : collect(),
        ];
    }
}
