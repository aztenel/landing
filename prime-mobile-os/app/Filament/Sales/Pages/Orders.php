<?php

namespace App\Filament\Sales\Pages;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Orders\Drafts\OrderDraft;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\Orders as OrderService;
use App\Domain\Pos\Services\CartService;
use App\Filament\Pos\Pages\Sale;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э7 «Заказы» (/orders): orders of the stores — active, overdue, closed; new order for a phone in stock (scan) or a
 * variant «под заказ», prepayment; extend, cancel (the prepayment goes back), «Продать по заказу» opens the cash
 * register with the order (CartService::loadOrder).
 */
class Orders extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'order.create';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'clipboard';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'orders';

    protected static string $view = 'sales.pages.orders';

    public string $tabName = 'open';

    public ?array $form = null;

    public string $customerQuery = '';

    public string $variantQuery = '';

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Заказы');
    }

    public function getTitle(): string
    {
        return __('Заказы');
    }

    public function newOrder(): void
    {
        $this->form = ['customer_id' => null, 'code' => '', 'unit_id' => null, 'variant_id' => null, 'price' => '', 'prepay' => '', 'method' => 'cash', 'note' => ''];
        $this->error = null;
    }

    public function findUnit(): void
    {
        $this->error = null;
        $code = trim((string) $this->form['code']);
        $unit = StockUnit::query()->where('public_code', mb_strtoupper($code))->first() ?? StockUnit::findByIdentifier($code);
        if (! $unit || ! $unit->isInStore(WorkingStore::get(auth()->user())->id)) {
            $this->error = __('Телефон не найден в этом магазине');

            return;
        }
        $this->form['unit_id'] = $unit->id;
        $this->form['variant_id'] = null;
        $this->form['price'] = (string) intdiv((int) app(CartService::class)->unitPrice($unit), 100);
    }

    public function pickVariant(int $id): void
    {
        $v = Variant::query()->findOrFail($id);
        $this->form['variant_id'] = $v->id;
        $this->form['unit_id'] = null;
        $this->variantQuery = $v->title();
        $price = \App\Domain\Catalog\Models\VariantPrice::query()->where('variant_id', $v->id)->value('price_kzt');
        $this->form['price'] = $price ? (string) intdiv((int) $price, 100) : '';
    }

    public function save(): void
    {
        $this->error = null;
        $f = $this->form;
        try {
            $customer = Customer::query()->findOrFail((int) $f['customer_id']);
            $price = Money::kzt((int) preg_replace('/\D/', '', (string) $f['price']));
            $store = WorkingStore::get(auth()->user());
            $draft = $f['unit_id']
                ? OrderDraft::unit($store, auth()->user(), $customer, StockUnit::query()->findOrFail($f['unit_id']), $price)
                : OrderDraft::variant($store, auth()->user(), $customer, (int) $f['variant_id'], $price);
            $prepay = (int) preg_replace('/\D/', '', (string) $f['prepay']);
            if ($prepay > 0) {
                $draft->prepay($f['method'], Money::kzt($prepay));
            }
            if (trim((string) $f['note']) !== '') {
                $draft->note(trim($f['note']));
            }
            $doc = $draft->post(auth()->user());
            $this->flash = __('Заказ :n оформлен', ['n' => $doc->number]);
            $this->form = null;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error = __('Выберите клиента и телефон или вариант');
        }
    }

    public function extend(int $orderId, int $days = 3): void
    {
        $this->act(fn () => app(OrderService::class)->extend(Order::query()->findOrFail($orderId), $days, auth()->user()), __('Бронь продлена'));
    }

    public function cancel(int $orderId): void
    {
        $this->act(function () use ($orderId) {
            $doc = Document::query()->findOrFail(Order::query()->findOrFail($orderId)->document_id);
            app(Reverser::class)->reverse($doc, ReversalReason::Other, 'Отмена заказа', auth()->user());
        }, __('Заказ отменён, предоплата возвращена'));
    }

    public function sell(int $orderId): void
    {
        $this->act(function () use ($orderId) {
            $order = Order::query()->findOrFail($orderId);
            $user = auth()->user();
            $svc = app(CartService::class);
            $store = WorkingStore::get($user);
            $tab = $svc->cart($user, $store)->isEmpty() ? 1 : $svc->newTab($user, $store);
            $svc->loadOrder($svc->cart($user, $store, $tab), $user, $order);
            $this->redirect(Sale::getUrl(['tab' => $tab]));
        }, '');
    }

    protected function act(callable $fn, string $ok): void
    {
        $this->error = null;
        try {
            $fn();
            $this->flash = $ok ?: null;
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function getViewData(): array
    {
        $stores = WorkingStore::available(auth()->user())->pluck('id');
        $orders = Order::query()->whereIn('document_id', Document::query()->whereIn('store_id', $stores)->where('status', '<>', 'draft')->select('id'))
            ->when($this->tabName === 'open', fn ($q) => $q->whereIn('status', ['active', 'overdue'])->orderBy('hold_until'))
            ->when($this->tabName === 'closed', fn ($q) => $q->whereIn('status', ['sold', 'cancelled', 'expired'])->latest('id'))
            ->limit(100)->get();
        $cq = trim($this->customerQuery);
        $vq = trim($this->variantQuery);

        return [
            'orders' => $orders,
            'numbers' => Document::query()->whereIn('id', $orders->pluck('document_id'))->pluck('number', 'id'),
            'customers' => Customer::query()->whereIn('id', $orders->pluck('customer_id'))->get(['id', 'name', 'phone'])->keyBy('id'),
            'units' => StockUnit::query()->with('variant')->whereIn('id', $orders->pluck('stock_unit_id')->filter())->get()->keyBy('id'),
            'variants' => Variant::query()->with(['productModel', 'memory', 'color'])->whereIn('id', $orders->pluck('variant_id')->filter())->get()->keyBy('id'),
            'customerResults' => $this->form && mb_strlen($cq) >= 2 ? Customer::query()->whereNull('anonymized_at')->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($cq, '%_').'%')->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $cq).'%'))->limit(6)->get() : collect(),
            'variantResults' => $this->form && mb_strlen($vq) >= 2 && ! ($this->form['variant_id'] ?? null) ? Variant::query()->with(['productModel', 'memory', 'color'])->whereHas('productModel', fn ($m) => $m->where('name_ru', 'ilike', '%'.addcslashes($vq, '%_').'%'))->limit(10)->get() : collect(),
            'pickedCustomer' => $this->form && $this->form['customer_id'] ? Customer::query()->find($this->form['customer_id']) : null,
            'pickedUnit' => $this->form && $this->form['unit_id'] ? StockUnit::query()->find($this->form['unit_id']) : null,
            'tz' => CurrentOrganization::instance()->require()->timezone,
        ];
    }
}
