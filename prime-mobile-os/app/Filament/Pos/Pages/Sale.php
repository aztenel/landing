<?php

namespace App\Filament\Pos\Pages;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Catalog\Models\Accessory;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\AccessoryBalance;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Inventory\Services\ImeiCheckService;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Ledger\Services\Shifts;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Money\Services\ShiftCash;
use App\Domain\Orders\Models\Order;
use App\Domain\Pos\Contracts\TradeInProvider;
use App\Domain\Pos\Models\Cart;
use App\Domain\Pos\Services\CartService;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Phone\Phone;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\Hotkey;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Э3 «Продажа» (/sale): computer (three zones) and the seller's iPhone (one column, fixed bottom) — TZ 04 Э3, 01 П8.
 * The browser sends only intentions and ids; prices, totals and limits are computed on the server (CartService,
 * posting core). Errors of the core are shown as they are: they are written for the seller.
 */
class Sale extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = 'sale.create';

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'sale';

    protected static string $view = 'pos.pages.sale';

    public int $tab = 1;

    public string $catalog = 'phones';

    public string $brand = '';

    public string $condition = '';

    public string $search = '';

    public ?string $flash = null;

    public ?string $error = null;

    public ?string $modal = null;   // customer | new-customer | discount | payment | shift-open | shift-close | done | imei | approval

    // customer search / new
    public string $customerQuery = '';

    public array $newCustomer = ['phone' => '', 'name' => '', 'surname' => '', 'consent' => false, 'consent_method' => 'oral'];

    // discount window: line index or null for the check; amount in tenge or percent
    public ?int $discountLine = null;

    public string $discountValue = '';

    public bool $discountPercent = false;

    public string $discountReason = '';

    // payment window rows in tenge
    public array $payRows = [];

    public string $fiscal = '';

    // shift windows: counted tenge/dollars per account id
    public array $counted = [];

    public string $shiftComment = '';

    public ?int $doneDocument = null;

    public ?int $imeiUnit = null;

    public string $approvalReason = '';

    public static function getNavigationLabel(): string
    {
        return __('Продажа');
    }

    public function getTitle(): string
    {
        return __('Продажа');
    }

    /** The page draws its own title next to the buttons (TZ 04 Э3.1). */
    public function getHeading(): string
    {
        return '';
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function hotkeys(): array
    {
        return [
            Hotkey::make('F2', __('Скан'))->focus('#pos-scan'),
            Hotkey::make('F3', __('Новый клиент'))->call('openNewCustomer'),
            Hotkey::make('F4', __('Найти клиента'))->call('openCustomer'),
            Hotkey::make('F6', __('Скидка'))->call('openDiscount'),
            Hotkey::make('F7', __('Новый чек'))->call('newTab'),
            Hotkey::make('F8', __('Trade-in'))->call('tradeIn'),
            Hotkey::make('F9', __('Оплатить / Провести'))->call('f9'),
            Hotkey::make('F10', __('Возврат'))->call('goReturn'),
        ];
    }

    public function mount(): void
    {
        if (! $this->store()) {
            abort(403, __('Нет доступа ни к одному магазину'));
        }
        $this->tab = min(max(1, (int) request()->integer('tab', 1)), CartService::MAX_TABS);
        if (! $this->openShift()) {
            $this->openShiftWindow();
        } elseif ($unitId = request()->integer('unit')) {
            // «В чек» from the passport (TZ 04 Э10.3)
            $inCart = collect($this->cart()->get('lines'))->contains(fn ($l) => $l['kind'] === 'unit' && (int) $l['unit_id'] === $unitId);
            if (! $inCart) {
                $this->attempt(fn () => $this->svc()->addUnit($this->cart(), $this->user(), $unitId), __('Добавлено'));
            }
        }
    }

    // ------------------------------------------------------------------ context

    protected function user(): User
    {
        return auth()->user();
    }

    public function store(): ?Store
    {
        return WorkingStore::get($this->user());
    }

    protected function svc(): CartService
    {
        return app(CartService::class);
    }

    protected function cart(): Cart
    {
        return $this->svc()->cart($this->user(), $this->store(), $this->tab);
    }

    public function openShift(): ?Shift
    {
        return app(Shifts::class)->current($this->store());
    }

    /** Run an action, turn user-facing errors into a message under the scan field, keep the scan focus. */
    protected function attempt(callable $action, ?string $success = null): mixed
    {
        $this->error = null;
        try {
            $result = $action();
            $this->flash = $success;

            return $result;
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
            if (($e->reason ?? null) === 'shift_closed') {
                $this->openShiftWindow();
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error = __('Не найдено');
        }
        $this->dispatch('pm-focus-scan');

        return null;
    }

    // ------------------------------------------------------------------ store and tabs

    public function switchStore(int $storeId): void
    {
        $this->attempt(fn () => WorkingStore::set($this->user(), $storeId));
        $this->tab = 1;
    }

    public function newTab(): void
    {
        $this->attempt(function () {
            $this->tab = $this->svc()->newTab($this->user(), $this->store());
        });
    }

    public function switchTab(int $tab): void
    {
        $this->svc()->dropEmptyTabs($this->user(), $this->store(), $tab);
        $this->tab = min(max(1, $tab), CartService::MAX_TABS);
        $this->modal = null;
    }

    // ------------------------------------------------------------------ goods

    public function scan(string $code): void
    {
        $this->attempt(fn () => $this->svc()->scan($this->cart(), $this->user(), $code), __('Добавлено'));
        $this->search = '';
    }

    /** Tile of a used phone (unique by photo, grade, battery) or of an accessory. New phones are added by scan only. */
    public function addTile(string $kind, int $id): void
    {
        $this->attempt(function () use ($kind, $id) {
            if ($kind === 'unit') {
                $unit = StockUnit::query()->findOrFail($id);
                if ($unit->condition?->value !== 'used') {
                    throw \App\Domain\Ledger\Exceptions\PostingException::because('scan_new', __('Отсканируйте IMEI нужной коробки'));
                }
                $this->svc()->addUnit($this->cart(), $this->user(), $id);
            } else {
                $this->svc()->addAccessory($this->cart(), $this->user(), $id);
            }
        });
    }

    public function removeLine(int $index): void
    {
        $this->attempt(fn () => $this->svc()->removeLine($this->cart(), $this->user(), $index));
    }

    public function qty(int $index, int $delta): void
    {
        $this->attempt(function () use ($index, $delta) {
            $line = $this->cart()->get('lines')[$index] ?? null;
            if ($line && $line['kind'] === 'accessory') {
                $this->svc()->setQty($this->cart(), $this->user(), $index, (int) $line['qty'] + $delta);
            }
        });
    }

    public function clearCart(): void
    {
        $this->attempt(fn () => $this->svc()->clear($this->cart(), $this->user()));
    }

    // ------------------------------------------------------------------ customer

    public function openCustomer(): void
    {
        $this->modal = 'customer';
        $this->customerQuery = '';
    }

    public function openNewCustomer(): void
    {
        $q = trim($this->customerQuery);
        $this->newCustomer = ['phone' => preg_match('/\d{4,}/', $q) ? $q : '', 'name' => preg_match('/\d/', $q) ? '' : $q, 'surname' => '', 'consent' => false, 'consent_method' => 'oral'];
        $this->modal = 'new-customer';
    }

    public function pickCustomer(int $id): void
    {
        $this->attempt(fn () => $this->svc()->setCustomer($this->cart(), $id));
        $this->modal = null;
    }

    public function dropCustomer(): void
    {
        $this->attempt(fn () => $this->svc()->setCustomer($this->cart(), null));
    }

    public function createCustomer(): void
    {
        Access::authorize('customer.create');
        $data = $this->validate([
            'newCustomer.phone' => 'required|string|max:32',
            'newCustomer.name' => 'required|string|min:2|max:60',
            'newCustomer.surname' => 'nullable|string|max:60',
            'newCustomer.consent' => 'accepted',
            'newCustomer.consent_method' => 'required|in:oral,receipt,qr',
        ], ['newCustomer.consent.accepted' => __('Нужно согласие клиента. Прочитайте ему текст или дайте QR')])['newCustomer'];
        try {
            $phone = Phone::normalize($data['phone']);
        } catch (\InvalidArgumentException) {
            $phone = null;
        }
        if (! $phone) {
            $this->addError('newCustomer.phone', __('Телефон в формате +7 7XX XXX XX XX'));

            return;
        }
        $existing = Customer::query()->where('phone', $phone)->first();
        if ($existing) {
            $this->addError('newCustomer.phone', __('Клиент с этим номером уже есть: :name', ['name' => $existing->name]));

            return;
        }
        $customer = Customer::query()->create([
            'phone' => $phone, 'name' => trim($data['name'].' '.($data['surname'] ?? '')),
            'pd_consent_at' => now(), 'pd_consent_method' => 'pos_'.$data['consent_method'], 'pd_consent_version' => '2026-11',
            'source' => 'pos', 'created_by' => $this->user()->id,
        ]);
        $this->pickCustomer($customer->id);
    }

    /** @return Collection<int, Customer> */
    public function customerResults(): Collection
    {
        $q = trim($this->customerQuery);
        $digits = preg_replace('/\D/', '', $q);
        if (mb_strlen($q) < 2 && strlen($digits) < 4) {
            return collect();
        }

        return Customer::query()->whereNull('anonymized_at')
            ->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($q, '%_').'%')
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%')))
            ->orderBy('name')->limit(8)->get();
    }

    // ------------------------------------------------------------------ discount

    public function openDiscount(?int $line = null): void
    {
        if ($this->cart()->isEmpty()) {
            return;
        }
        $this->discountLine = $line;
        $current = $line === null ? (int) $this->cart()->get('check_discount', 0) : (int) ($this->cart()->get('lines')[$line]['discount'] ?? 0);
        $this->discountValue = $current ? (string) intdiv($current, Money::TENGE) : '';
        $this->discountPercent = false;
        $this->discountReason = (string) $this->cart()->get('discount_reason', '');
        $this->modal = 'discount';
    }

    public function saveDiscount(): void
    {
        $this->attempt(function () {
            $value = (float) str_replace([' ', ','], ['', '.'], $this->discountValue);
            $t = $this->svc()->totals($this->cart());
            $base = $this->discountLine === null ? $t['after_lines'] : (($t['lines'][$this->discountLine]['price'] ?? 0) * ($t['lines'][$this->discountLine]['qty'] ?? 1));
            // % is rounded down to 100 ₸ (TZ 04 Э3.3)
            $tiyn = $this->discountPercent
                ? intdiv((int) floor($base * min(100, max(0, $value)) / 100), 100 * Money::TENGE) * 100 * Money::TENGE
                : (int) round($value) * Money::TENGE;
            $this->discountLine === null
                ? $this->svc()->setCheckDiscount($this->cart(), $tiyn, $this->discountReason)
                : $this->svc()->setLineDiscount($this->cart(), $this->discountLine, $tiyn);
            $this->modal = null;
        });
    }

    // ------------------------------------------------------------------ payment

    /** «Наличные · Карта · Kaspi · В долг» on the whole sum; «Сплит» opens the window. */
    public function quickPay(string $method): void
    {
        if ($method === 'split') {
            $this->openPayment();

            return;
        }
        $this->attempt(fn () => $this->svc()->payAllWith($this->cart(), $method));
    }

    public function openPayment(): void
    {
        $cart = $this->cart();
        if ($cart->isEmpty()) {
            $this->error = __('Чек пуст. Отсканируйте товар');

            return;
        }
        $t = $this->svc()->totals($cart);
        $rows = collect($cart->get('payments'))->map(fn ($p) => [
            'method' => $p['method'], 'amount' => (string) intdiv((int) $p['amount'], Money::TENGE),
            'tendered' => isset($p['tendered']) ? (string) intdiv((int) $p['tendered'], Money::TENGE) : '',
            'due_date' => $p['due_date'] ?? now()->addDays(30)->toDateString(),
        ])->all();
        $this->payRows = $rows ?: [['method' => 'cash', 'amount' => (string) intdiv($t['total'], Money::TENGE), 'tendered' => '', 'due_date' => now()->addDays(30)->toDateString()]];
        $this->modal = 'payment';
    }

    public function addPayRow(): void
    {
        $left = $this->payLeft();
        $used = array_column($this->payRows, 'method');
        $next = collect(['kaspi_qr', 'card', 'cash', 'kaspi_transfer', 'debt', 'bonus'])->first(fn ($m) => ! in_array($m, $used, true)) ?? 'card';
        $this->payRows[] = ['method' => $next, 'amount' => (string) max(0, $left), 'tendered' => '', 'due_date' => now()->addDays(30)->toDateString()];
    }

    public function removePayRow(int $i): void
    {
        unset($this->payRows[$i]);
        $this->payRows = array_values($this->payRows);
    }

    /** «Свести под расчёт»: the last row takes the rest. */
    public function settle(): void
    {
        if ($this->payRows === []) {
            return;
        }
        $last = count($this->payRows) - 1;
        $this->payRows[$last]['amount'] = (string) max(0, (int) $this->payRows[$last]['amount'] + $this->payLeft());
    }

    public function addBanknote(int $i, int $tenge): void
    {
        $row = $this->payRows[$i] ?? null;
        if ($row && $row['method'] === 'cash') {
            $this->payRows[$i]['tendered'] = (string) ($tenge === 0 ? (int) $row['amount'] : (int) $row['tendered'] + $tenge);
        }
    }

    /** Tenge left to distribute (+ not enough, − too much). */
    public function payLeft(): int
    {
        $total = intdiv($this->svc()->totals($this->cart())['total'], Money::TENGE);

        return $total - array_sum(array_map(fn ($r) => (int) preg_replace('/\D/', '', (string) $r['amount']), $this->payRows));
    }

    /** F9: open the payment window, or post from it. */
    public function f9(): void
    {
        $this->modal === 'payment' ? $this->pay() : $this->openPayment();
    }

    public function pay(): void
    {
        $this->attempt(function () {
            if ($this->modal === 'payment') {
                $this->svc()->setPayments($this->cart(), array_map(fn ($r) => [
                    'method' => $r['method'],
                    'amount' => (int) preg_replace('/\D/', '', (string) $r['amount']) * Money::TENGE,
                    'tendered' => $r['method'] === 'cash' && $r['tendered'] !== '' ? (int) preg_replace('/\D/', '', (string) $r['tendered']) * Money::TENGE : null,
                    'due_date' => $r['due_date'] ?? null,
                ], $this->payRows));
            }
            $result = $this->svc()->checkout($this->cart(), $this->user());
            $this->handleCheckout($result);
        });
    }

    protected function handleCheckout(array $result): void
    {
        if ($result['status'] === 'posted') {
            $doc = $result['document'];
            if (trim($this->fiscal) !== '') {
                $doc->forceFill(['fiscal_receipt_no' => mb_substr(trim($this->fiscal), 0, 64)])->save();
            }
            $this->fiscal = '';
            $this->doneDocument = $doc->id;
            $this->modal = 'done';
            $this->dispatch('pm-sale-done', url: route('erp.pos.receipt', $doc));

            return;
        }
        $this->modal = 'approval';
        $this->approvalReason = (string) $this->cart()->get('discount_reason', '');
        if ($result['status'] === 'approval') {
            $this->error = $result['message'];
        }
    }

    public function requestApproval(): void
    {
        $this->attempt(fn () => $this->svc()->requestApproval($this->cart(), $this->user(), $this->approvalReason ?: null));
    }

    public function cancelApproval(): void
    {
        $this->attempt(fn () => $this->svc()->cancelPendingRequest($this->cart(), $this->user()));
        $this->modal = null;
    }

    /** wire:poll while waiting: the answer comes without a reload (TZ 04 Э28.2). */
    public function pollApproval(): void
    {
        $request = $this->svc()->pendingRequest($this->cart());
        if ($request && $request->status === ApprovalStatus::Approved) {
            $this->attempt(fn () => $this->handleCheckout($this->svc()->checkout($this->cart(), $this->user())));
        }
    }

    public function newCheck(): void
    {
        $this->modal = null;
        $this->doneDocument = null;
        $this->dispatch('pm-focus-scan');
    }

    // ------------------------------------------------------------------ госбаза

    public function openImei(int $unitId): void
    {
        $this->imeiUnit = $unitId;
        $this->modal = 'imei';
    }

    public function saveImei(string $result): void
    {
        $this->attempt(function () use ($result) {
            $unit = StockUnit::query()->findOrFail($this->imeiUnit);
            app(ImeiCheckService::class)->record($unit, ImeiCheckResult::from($result), $this->user());
            $this->modal = null;
        });
    }

    // ------------------------------------------------------------------ shift (Э3.6, П1, П19)

    public function openShiftWindow(): void
    {
        $this->counted = collect(app(ShiftCash::class)->registers($this->store()))->mapWithKeys(fn ($a) => [$a->id => ''])->all();
        $this->shiftComment = '';
        $this->modal = 'shift-open';
    }

    public function openCloseShift(): void
    {
        if (! $this->openShift()) {
            return;
        }
        $this->counted = collect(app(ShiftCash::class)->registers($this->store()))->mapWithKeys(fn ($a) => [$a->id => ''])->all();
        $this->shiftComment = '';
        $this->modal = 'shift-close';
    }

    protected function countedMinor(): array
    {
        return collect($this->counted)->mapWithKeys(function ($v, $id) {
            $v = str_replace([' ', ','], ['', '.'], (string) $v);

            return [(int) $id => $v === '' ? -1 : (int) round((float) $v * 100)];
        })->filter(fn ($v) => $v >= 0)->all();
    }

    public function doOpenShift(): void
    {
        $this->attempt(function () {
            app(ShiftCash::class)->open($this->store(), $this->user(), $this->countedMinor(), $this->shiftComment ?: null);
            $this->modal = null;
        }, __('Смена открыта'));
    }

    public function doCloseShift(): void
    {
        $this->attempt(function () {
            $shift = $this->openShift() ?? throw \App\Domain\Ledger\Exceptions\PostingException::because('shift_not_open', __('Смена уже закрыта'));
            app(ShiftCash::class)->close($shift, $this->user(), $this->countedMinor(), $this->shiftComment ?: null);
            $this->modal = 'shift-open';
            $this->openShiftWindow();
        }, __('Смена закрыта'));
    }

    // ------------------------------------------------------------------ other modules

    public function tradeIn(): void
    {
        $provider = app(TradeInProvider::class);
        if (! $provider->available()) {
            $this->error = __('Trade-in подключается в следующем обновлении');

            return;
        }
        $this->redirect($provider->startUrl($this->cart()));
    }

    public function goReturn(): void
    {
        $this->error = __('Возврат открывается из списка чеков (подключается в следующем обновлении)');
    }

    public function sellOrder(int $orderId): void
    {
        $this->attempt(fn () => $this->svc()->loadOrder($this->cart(), $this->user(), Order::query()->findOrFail($orderId)));
    }

    public function setSource(?string $source): void
    {
        $this->attempt(fn () => $this->svc()->setMeta($this->cart(), ['source' => $source ?: null]));
    }

    public function setComment(string $comment): void
    {
        $this->attempt(fn () => $this->svc()->setMeta($this->cart(), ['comment' => $comment]));
    }

    public function setPrint(string $what, bool $on): void
    {
        $print = (array) ($this->cart()->get('print') ?? ['receipt' => true, 'warranty' => true, 'kk' => false]);
        $print[$what] = $on;
        $this->svc()->setMeta($this->cart(), ['print' => $print]);
    }

    // ------------------------------------------------------------------ view

    protected function getViewData(): array
    {
        $store = $this->store();
        $cart = $this->cart();
        $totals = $this->svc()->totals($cart);
        $customer = $cart->get('customer_id') ? Customer::query()->find($cart->get('customer_id')) : null;
        $request = $this->svc()->pendingRequest($cart);

        return [
            'store' => $store,
            'stores' => WorkingStore::available($this->user()),
            'cart' => $cart,
            't' => $totals,
            'tabs' => $this->svc()->tabs($this->user(), $store),
            'customer' => $customer,
            'bonuses' => $customer ? (int) CustomerBonusBalance::query()->where('customer_id', $customer->id)->value('available') : 0,
            'debt' => $customer ? CounterpartyBalance::query()->where('counterparty_type', 'customer')->where('counterparty_id', $customer->id)->where('currency', 'KZT')->first() : null,
            'shift' => $this->openShift(),
            'blocker' => $this->blocker($cart, $totals, $request),
            'request' => $request,
            'tiles' => $this->catalog === 'phones' ? $this->phoneTiles($store, $cart) : collect(),
            'accessoryTiles' => $this->catalog === 'accessories' ? $this->accessoryTiles($store) : collect(),
            'brands' => $this->brands($store),
            'methods' => PaymentMethod::query()->where('is_enabled', true)->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $store->id))
                ->whereNotIn('code', ['tradein', 'exchange_offset', 'kaspi_shop'])->orderBy('sort')->get(['code', 'name_ru'])->unique('code'),
            'registers' => $this->modal && str_starts_with($this->modal, 'shift') ? app(ShiftCash::class)->registers($store) : collect(),
            'expected' => $this->modal && str_starts_with($this->modal, 'shift') && Access::allows('money.balance') ? app(ShiftCash::class)->expected($store) : [],
            'customers' => $this->modal === 'customer' ? $this->customerResults() : collect(),
            'done' => $this->doneDocument ? Document::query()->find($this->doneDocument) : null,
            'orders' => Order::query()->whereIn('status', ['active', 'overdue'])
                ->whereIn('document_id', Document::query()->where('store_id', $store->id)->select('id'))->orderBy('hold_until')->limit(20)->get(),
            'money' => fn (int $tiyn) => Money::kztMinor($tiyn)->format(),
            'requireImei' => CurrentOrganization::instance()->require()->settings()->requireImeiCheck(),
            'imeiMaxAge' => CurrentOrganization::instance()->require()->settings()->imeiCheckMaxAgeDays(),
        ];
    }

    /** Exactly one reason why «Оплатить» is grey, the first of the list (TZ 04 Э3.3). */
    protected function blocker(Cart $cart, array $t, $request): ?array
    {
        if ($t['lines'] === []) {
            return ['text' => __('Чек пуст. Отсканируйте товар')];
        }
        if (! $this->openShift()) {
            return ['text' => __('Смена не открыта.'), 'action' => 'openShiftWindow', 'label' => __('Открыть смену')];
        }
        if ($request && $request->status === ApprovalStatus::Pending) {
            return ['text' => __('Ждём ответа владельца…')];
        }
        $settings = CurrentOrganization::instance()->require()->settings();
        foreach ($t['lines'] as $line) {
            if ($line['kind'] !== 'unit') {
                continue;
            }
            if ($line['cost_unknown']) {
                return ['text' => __('Телефон :code ещё без себестоимости — продать нельзя. Сообщите администратору', ['code' => $line['code']])];
            }
            $stale = $line['imei'] && $settings->requireImeiCheck() && ($line['imei_check'] !== 'registered'
                || ! $line['imei_checked_at'] || $line['imei_checked_at']->lt(now()->subDays($settings->imeiCheckMaxAgeDays())));
            if ($stale || in_array($line['imei_check'], ['not_registered', 'blocked'], true)) {
                return ['text' => __('Телефон :code без проверки госбазы.', ['code' => $line['code']]), 'action' => 'openImei('.$line['id'].')', 'label' => __('Проверить')];
            }
        }

        return null;
    }

    /** Phones in this store; new identical ones are folded into one tile (TZ 04 Э3.2). */
    protected function phoneTiles(Store $store, Cart $cart): Collection
    {
        $inCart = collect($cart->get('lines'))->where('kind', 'unit')->pluck('unit_id')->map(fn ($id) => (int) $id)->all();
        $mine = UnitReservation::query()->whereNull('released_at')->where('user_id', $this->user()->id)->pluck('stock_unit_id')->all();
        $q = trim($this->search);
        $units = StockUnit::query()->inStore($store->id)
            ->whereIn('status', [UnitStatus::InStock->value, UnitStatus::Reserved->value])
            ->with(['variant.productModel.family', 'variant.memory', 'variant.color'])
            ->when($this->condition, fn ($w) => $w->where('condition', $this->condition))
            ->when($this->brand, fn ($w) => $w->whereHas('variant.productModel.family', fn ($f) => $f->where('brand', $this->brand)))
            ->when($q !== '' && ! preg_match('/^\d{8,}$/', $q), fn ($w) => $w->whereHas('variant.productModel', fn ($m) => $m->where('name_ru', 'ilike', '%'.addcslashes($q, '%_').'%')))
            ->orderBy('variant_id')->orderBy('id')->limit(300)->get();
        $svc = $this->svc();
        $tiles = collect();
        foreach ($units as $u) {
            $price = $svc->unitPrice($u);
            $state = in_array($u->id, $inCart, true) ? 'in_cart' : ($u->status === UnitStatus::Reserved && ! in_array($u->id, $mine, true) ? 'reserved' : null);
            if ($u->condition?->value === 'new' && $state === null) {
                $key = 'v'.$u->variant_id.'-'.$price;
                if ($tiles->has($key)) {
                    $tiles[$key] = ['count' => $tiles[$key]['count'] + 1] + $tiles[$key];

                    continue;
                }
                $tiles[$key] = ['kind' => 'group', 'unit' => $u, 'price' => $price, 'count' => 1, 'state' => null];

                continue;
            }
            $tiles['u'.$u->id] = ['kind' => 'unit', 'unit' => $u, 'price' => $price, 'count' => 1, 'state' => $state];
        }

        return $tiles->values();
    }

    protected function accessoryTiles(Store $store): Collection
    {
        $q = trim($this->search);

        return AccessoryBalance::query()->where('location_type', 'store')->where('location_id', $store->id)->where('qty', '>', 0)
            ->join('accessories', 'accessories.id', '=', 'accessory_balances.accessory_id')
            ->where('accessories.is_active', true)
            ->when($q !== '' && ! preg_match('/^\d{8,}$/', $q), fn ($w) => $w->where('accessories.name_ru', 'ilike', '%'.addcslashes($q, '%_').'%'))
            ->orderBy('accessories.name_ru')->limit(200)
            ->get(['accessories.id', 'accessories.name_ru', 'accessory_balances.qty'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name_ru, 'qty' => (int) $a->qty, 'price' => $this->svc()->accessoryPrice((int) $a->id)]);
    }

    protected function brands(Store $store): array
    {
        return DB::table('stock_units as u')->join('variants as v', 'v.id', '=', 'u.variant_id')
            ->join('models as m', 'm.id', '=', 'v.model_id')->join('families as f', 'f.id', '=', 'm.family_id')
            ->where('u.organization_id', CurrentOrganization::instance()->requireId())
            ->where('u.location_type', 'store')->where('u.location_id', $store->id)->whereIn('u.status', ['in_stock', 'reserved'])
            ->whereNotNull('f.brand')->distinct()->orderBy('f.brand')->pluck('f.brand')->all();
    }
}
