<?php

namespace App\Filament\Money\Pages;

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\DebtEntry;
use App\Domain\Ledger\Models\Document;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Orders\Models\Order;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\TradeIn\Models\TradeInAct;
use App\Domain\Warehouse\Models\WarrantyCase;
use App\Filament\Pos\Pages\Sale as SalePage;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Phone\Phone;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Filament\Pages\Page;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

/**
 * Э18 «Клиенты» (/customers): search, filters, card with tiles and tabs (purchases, phones and warranty, debt, bonuses,
 * trade-in, orders, data and consent); edit; the owner anonymizes. IIN never reaches a role without customer.view_iin.
 */
class Customers extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = 'customer.view';

    protected static ?MenuGroup $menuGroup = MenuGroup::Accounting;

    protected static ?string $navigationIcon = 'users';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'customers';

    protected static string $view = 'money.pages.customers';

    public string $q = '';

    public string $filter = '';

    public ?int $openId = null;

    public string $tabName = 'sales';

    public ?array $form = null;

    public ?string $error = null;

    public static function getNavigationLabel(): string
    {
        return __('Клиенты');
    }

    public function getTitle(): string
    {
        return __('Клиенты');
    }

    public function mount(): void
    {
        $this->openId = request()->integer('id') ?: null;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['q', 'filter'], true)) {
            $this->resetPage();
        }
    }

    public function open(int $id): void
    {
        $this->openId = Customer::query()->findOrFail($id)->id;
        $this->form = null;
        $this->tabName = 'sales';
    }

    public function edit(): void
    {
        Access::authorize('customer.edit');
        $c = Customer::query()->findOrFail($this->openId);
        $this->form = ['name' => $c->name, 'phone' => $c->phone, 'comment' => $c->comment];
    }

    public function save(): void
    {
        Access::authorize('customer.edit');
        $c = Customer::query()->findOrFail($this->openId);
        $data = $this->validate(['form.name' => 'required|string|min:2|max:120', 'form.phone' => 'required|string|max:32', 'form.comment' => 'nullable|string|max:2000'])['form'];
        try {
            $phone = Phone::normalize($data['phone']);
        } catch (\InvalidArgumentException) {
            $this->addError('form.phone', __('Телефон в формате +7 7XX XXX XX XX'));

            return;
        }
        if (Customer::query()->where('phone', $phone)->where('id', '<>', $c->id)->exists()) {
            $this->addError('form.phone', __('Клиент с этим номером уже есть'));

            return;
        }
        $c->fill(['name' => $data['name'], 'phone' => $phone, 'comment' => $data['comment']])->save();
        activity('customers')->performedOn($c)->causedBy(auth()->user())->log('customer_edited');
        $this->form = null;
    }

    /** «Обезличить» (TZ 04 Э18.2, 00-kazakhstan п. 2): name and phone are replaced, purchases and sums stay. */
    public function anonymize(): void
    {
        Access::authorize('customer.privacy');
        $c = Customer::query()->findOrFail($this->openId);
        $c->forceFill(['name' => 'Клиент №'.$c->id, 'phone' => null, 'iin' => null, 'comment' => null, 'anonymized_at' => now()])->save();
        activity('customers')->performedOn($c)->causedBy(auth()->user())->log('customer_anonymized');
    }

    protected function getViewData(): array
    {
        $q = trim($this->q);
        $digits = preg_replace('/\D/', '', $q);
        $debtIds = CounterpartyBalance::query()->where('counterparty_type', 'customer')->where('balance', '>', 0);
        $customers = Customer::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'ilike', '%'.addcslashes($q, '%_').'%')
                ->when(strlen($digits) >= 4, fn ($s) => $s->orWhere('phone', 'like', '%'.$digits.'%'))))
            ->when($this->filter === 'debt', fn ($w) => $w->whereIn('id', (clone $debtIds)->select('counterparty_id')))
            ->when($this->filter === 'overdue', fn ($w) => $w->whereIn('id', (clone $debtIds)->where('nearest_due_date', '<', today())->select('counterparty_id')))
            ->when($this->filter === 'bonus', fn ($w) => $w->whereIn('id', CustomerBonusBalance::query()->where('available', '>', 0)->select('customer_id')))
            ->orderByDesc('id')->paginate(30);
        $ids = $customers->pluck('id');
        $c = $this->openId ? Customer::query()->find($this->openId) : null;
        $saleDocs = $c ? Document::query()->where('type', 'sale')->whereIn('status', ['posted', 'reversed'])->whereIn('id', Sale::query()->where('customer_id', $c->id)->select('document_id'))->latest('id')->get() : collect();

        return [
            'customers' => $customers,
            'debts' => CounterpartyBalance::query()->where('counterparty_type', 'customer')->where('currency', 'KZT')->whereIn('counterparty_id', $ids)->get()->keyBy('counterparty_id'),
            'bonuses' => CustomerBonusBalance::query()->whereIn('customer_id', $ids)->pluck('available', 'customer_id'),
            'c' => $c,
            'cDebt' => $c ? CounterpartyBalance::query()->where('counterparty_type', 'customer')->where('counterparty_id', $c->id)->where('currency', 'KZT')->first() : null,
            'cBonus' => $c ? CustomerBonusBalance::query()->where('customer_id', $c->id)->first() : null,
            'saleDocs' => $saleDocs,
            'sales' => Sale::query()->whereIn('document_id', $saleDocs->pluck('id'))->get()->keyBy('document_id'),
            'phones' => $c ? StockUnit::query()->with('variant')->whereIn('id', SaleLine::query()->whereIn('document_id', $saleDocs->pluck('id'))->whereNotNull('stock_unit_id')->select('stock_unit_id'))->get() : collect(),
            'debtEntries' => $c && $this->tabName === 'debt' ? DebtEntry::query()->where('counterparty_type', 'customer')->where('counterparty_id', $c->id)->latest('id')->limit(50)->get() : collect(),
            'bonusEntries' => $c && $this->tabName === 'bonus' ? BonusEntry::query()->where('customer_id', $c->id)->latest('id')->limit(50)->get() : collect(),
            'acts' => $c && $this->tabName === 'tradein' ? TradeInAct::query()->where('customer_id', $c->id)->latest('id')->get() : collect(),
            'orders' => $c && $this->tabName === 'orders' ? Order::query()->where('customer_id', $c->id)->latest('id')->get() : collect(),
            'cases' => $c && $this->tabName === 'orders' ? WarrantyCase::query()->where('customer_id', $c->id)->latest('id')->get() : collect(),
            'saleUrl' => SalePage::getUrl(),
            'canEdit' => Access::allows('customer.edit'),
            'canPrivacy' => Access::allows('customer.privacy'),
        ];
    }
}
