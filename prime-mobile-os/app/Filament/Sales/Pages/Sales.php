<?php

namespace App\Filament\Sales\Pages;

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Models\CustomerReturn;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Imei\Imei;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;

/**
 * Э4 «Чеки» (/sales): the seller sees own checks of the last 7 days, the admin — the checks of the stores, the owner —
 * all. Card of a check, fiscal number, print, return, reversal (doc.reverse + reason). Cost and profit by right.
 */
class Sales extends Page
{
    use HasErpAccess, WithPagination;

    protected static string|array|null $permission = ['sale.create', 'debt.customer_report'];

    protected static ?\App\Support\Ui\MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'receipt';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'sales';

    protected static string $view = 'sales.pages.sales';

    public string $q = '';

    public string $period = '7';

    public string $filter = '';

    public ?int $openId = null;

    public string $fiscal = '';

    public bool $reverseOpen = false;

    public string $reverseReason = 'input_error';

    public string $reverseText = '';

    public ?string $error = null;

    public static function getNavigationLabel(): string
    {
        return __('Чеки');
    }

    public function getTitle(): string
    {
        return __('Чеки');
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['q', 'period', 'filter'], true)) {
            $this->resetPage();
        }
    }

    /** The checks this person may see (TZ 04 §1: seller — own, 7 days). */
    public static function visible(User $user): Builder
    {
        $own = ! Access::isOwner($user) && ! Access::allows('sale.set_seller', $user) && ! Access::allows('stock.view_cost', $user); // seller
        $stores = WorkingStore::available($user)->pluck('id');

        return Document::query()->where('type', 'sale')->whereIn('status', ['posted', 'reversed'])
            ->when(! Access::isOwner($user), fn ($q) => $q->whereIn('store_id', $stores))
            ->when($own, fn ($q) => $q->whereIn('id', Sale::query()->where('seller_id', $user->id)->select('document_id'))->where('posted_at', '>=', now()->subDays(7)));
    }

    public function open(int $id): void
    {
        $doc = self::visible(auth()->user())->findOrFail($id); // a forged id of a check one may not see: 404
        $this->openId = $doc->id;
        $this->fiscal = (string) $doc->fiscal_receipt_no;
        $this->reverseOpen = false;
        $this->error = null;
    }

    public function saveFiscal(): void
    {
        Access::authorize('sale.fiscal_mark');
        $doc = self::visible(auth()->user())->findOrFail($this->openId);
        $doc->forceFill(['fiscal_receipt_no' => mb_substr(trim($this->fiscal), 0, 64) ?: null])->save();
    }

    public function reverse(): void
    {
        $this->error = null;
        try {
            $doc = self::visible(auth()->user())->findOrFail($this->openId);
            app(Reverser::class)->reverse($doc, ReversalReason::from($this->reverseReason), trim($this->reverseText) ?: null, auth()->user());
            $this->reverseOpen = false;
        } catch (UserFacingException|\ValueError $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $q = trim($this->q);
        $digits = preg_replace('/\D/', '', $q);
        $docs = self::visible($user)
            ->when($this->period !== 'all', fn ($w) => $w->where('posted_at', '>=', now()->subDays((int) $this->period)))
            ->when($this->filter === 'no_fiscal', fn ($w) => $w->whereNull('fiscal_receipt_no')->where('status', 'posted'))
            ->when($this->filter === 'debt', fn ($w) => $w->whereIn('id', SalePayment::query()->where('method', 'debt')->select('document_id')))
            ->when($this->filter === 'discount', fn ($w) => $w->whereIn('id', Sale::query()->where('discount_total', '>', 0)->select('document_id')))
            ->when($this->filter === 'returned', fn ($w) => $w->whereIn('id', CustomerReturn::query()->select('sale_document_id')))
            ->when($q !== '', function ($w) use ($q, $digits) {
                $w->where(function ($s) use ($q, $digits) {
                    $s->where('number', 'ilike', '%'.addcslashes($q, '%_').'%');
                    if (strlen($digits) >= 4) {
                        $s->orWhereIn('id', SaleLine::query()->whereIn('stock_unit_id', StockUnit::query()->where('imei1', 'like', '%'.$digits)->orWhere('imei2', 'like', '%'.$digits)->select('id'))->select('document_id'))
                            ->orWhereIn('id', Sale::query()->whereIn('customer_id', Customer::query()->where('phone', 'like', '%'.$digits.'%')->select('id'))->select('document_id'));
                    }
                    $s->orWhereIn('id', Sale::query()->whereIn('customer_id', Customer::query()->where('name', 'ilike', '%'.addcslashes($q, '%_').'%')->select('id'))->select('document_id'));
                });
            })
            ->latest('posted_at')->paginate(30);
        $sales = Sale::query()->whereIn('document_id', $docs->pluck('id'))->get()->keyBy('document_id');
        $open = $this->openId ? self::visible($user)->find($this->openId) : null;

        return [
            'docs' => $docs,
            'sales' => $sales,
            'customers' => Customer::query()->whereIn('id', $sales->pluck('customer_id')->filter())->pluck('name', 'id'),
            'users' => User::query()->whereIn('id', $sales->pluck('seller_id'))->pluck('name', 'id'),
            'methods' => SalePayment::query()->whereIn('document_id', $docs->pluck('id'))->get()->groupBy('document_id'),
            'returned' => CustomerReturn::query()->whereIn('sale_document_id', $docs->pluck('id'))->pluck('sale_document_id')->flip(),
            'open' => $open,
            'openSale' => $open ? Sale::query()->where('document_id', $open->id)->first() : null,
            'openLines' => $open ? SaleLine::query()->where('document_id', $open->id)->orderBy('line_no')->get() : collect(),
            'openPayments' => $open ? SalePayment::query()->where('document_id', $open->id)->get() : collect(),
            'canProfit' => Access::allows('stock.view_cost'),
            'canReverse' => Access::allows('doc.reverse'),
            'canReturn' => ! Access::instance()->decision('return.phone')->isDenied() || ! Access::instance()->decision('return.accessory')->isDenied(),
            'canFiscal' => Access::allows('sale.fiscal_mark'),
            'tz' => CurrentOrganization::instance()->require()->timezone,
            'imei' => fn (?string $v) => $v ? Imei::format($v) : '',
        ];
    }
}
