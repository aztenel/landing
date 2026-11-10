<?php

namespace App\Filament\Intake\Pages;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Inventory\Services\PriceService;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Money\Models\MoneyAccount;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Idempotency\Idempotency;
use App\Support\Imei\Imei;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\Hotkey;
use App\Support\Ui\WorkingStore;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Э11.2 / Э11.3 «Приход» (/intake/new): header once, then batches «model + price → scan IMEI one after another»,
 * accessories by quantity, totals, «Оприходовать». Every scan is checked on the server (Luhn, duplicate in the
 * batch, already in the database); the purchase is posted by PurchaseDraft with an idempotency key.
 * A seller without stock.cost_edit receives phones without the purchase price (cost unknown, not for sale until the
 * admin enters it — ВСБ); accessories need a price, so they are received by the admin (decision, docs/modules/intake.md).
 */
class NewIntake extends Page
{
    use HasErpAccess, WithFileUploads;

    protected static string|array|null $permission = ['stock.receive', 'stock.receive_acc'];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'intake/new';

    protected static string $view = 'intake.pages.new';

    public array $head = [];

    /** @var list<array{variant_id: ?int, search: string, condition: string, price: string, sale_price: string, expected: string, grade: ?string, battery: string, rows: list<array{imei1: string, imei2: ?string, error: ?string}>}> */
    public array $batches = [];

    public int $active = 0;

    /** @var list<array{accessory_id: int, qty: int, price: string, sale_price: string}> */
    public array $accessories = [];

    public string $accSearch = '';

    public ?string $error = null;

    /** The last refused scan (ТЗ 07 сценарии 2–4): the message and, for a known IMEI, the code of its passport. */
    public ?string $scanError = null;

    public ?string $scanPassport = null;

    /** ТЗ 07 сценарий 50: the IMEI is known in another firm — only a warning, without the firm's name. */
    public ?string $scanWarning = null;

    public ?string $notice = null;

    public string $key = '';

    public ?int $posted = null;

    public $file = null;

    /** @var list<array{line: int, variant_id: ?int, title: string, imei1: string, imei2: ?string, condition: string, price: ?int, sale_price: ?int, error: ?string}> */
    public array $preview = [];

    public bool $confirm = false;

    public function getTitle(): string
    {
        return __('Новый приход');
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function hotkeys(): array
    {
        return [Hotkey::make('F2', __('Скан IMEI'))->focus('#intake-scan'), Hotkey::make('Ctrl+Enter', __('Оприходовать'))->call('askConfirm')];
    }

    public function mount(): void
    {
        $store = WorkingStore::get(auth()->user());
        abort_unless($store, 403);
        $last = session('pm.intake.head', []);
        $this->head = [
            'store_id' => $store->id, 'supplier_id' => $last['supplier_id'] ?? null, 'currency' => $last['currency'] ?? 'KZT',
            'invoice' => '', 'pay' => $last['pay'] ?? 'credit', 'account_id' => null, 'paid' => '', 'due' => now()->addDays(14)->toDateString(),
        ];
        $this->batches = [$this->emptyBatch()];
        $this->key = (string) Str::uuid();
    }

    protected function emptyBatch(): array
    {
        return ['variant_id' => null, 'search' => '', 'condition' => 'new', 'price' => '', 'sale_price' => '', 'expected' => '', 'grade' => null, 'battery' => '', 'rows' => []];
    }

    public function canCost(): bool
    {
        return Access::allows('stock.cost_edit');
    }

    // ------------------------------------------------------------------ batches

    public function addBatch(): void
    {
        $this->batches[] = $this->emptyBatch();
        $this->active = count($this->batches) - 1;
    }

    public function removeBatch(int $i): void
    {
        unset($this->batches[$i]);
        $this->batches = array_values($this->batches) ?: [$this->emptyBatch()];
        $this->active = 0;
    }

    public function pickVariant(int $i, int $variantId): void
    {
        $variant = Variant::query()->with('productModel')->findOrFail($variantId);
        $this->batches[$i]['variant_id'] = $variant->id;
        $this->batches[$i]['search'] = $variant->title();
        $this->active = $i;
        $this->dispatch('pm-focus-intake-scan');
    }

    /**
     * One scanned code into the active batch. Bad codes stay as red rows with the reason (TZ 04 Э11.2), so the
     * person sees what happened; they are not posted.
     */
    public function scan(string $code): void
    {
        $this->error = null;
        $b = &$this->batches[$this->active];
        if (! $b['variant_id']) {
            $this->error = __('Сначала выберите модель партии');

            return;
        }
        $raw = trim($code);
        $value = Imei::normalize($raw);
        $error = null;
        if (preg_match('/^\d{15}$/', $value)) {
            if (! Imei::luhnValid($value)) {
                $error = __('IMEI с ошибкой (не сходится контрольная цифра)');
            }
        } elseif (! Imei::isSerial($raw)) {
            $error = __('Это не IMEI и не серийный номер');
        }
        if (! $error) {
            foreach ($this->batches as $bi => $batch) {
                foreach ($batch['rows'] as $ri => $row) {
                    if ($row['imei1'] === $value && ! $row['error']) {
                        $error = __('Уже отсканирован в этой партии (строка :n)', ['n' => $ri + 1]).($bi !== $this->active ? ' · '.__('партия :p', ['p' => $bi + 1]) : '');
                        break 2;
                    }
                }
            }
        }
        $this->scanPassport = null;
        if (! $error && ($existing = UnitIdentifier::query()->where('value', $value)->first())) {
            $unit = StockUnit::query()->find($existing->stock_unit_id);
            $error = __('Этот IMEI уже есть: :code, статус «:status»', ['code' => $unit?->public_code, 'status' => \App\Domain\Inventory\Enums\UnitStatus::labelOf($unit?->status)]);
            if ($unit && $unit->status === \App\Domain\Inventory\Enums\UnitStatus::Sold) {
                // «… статус „продан“ 12.10.2026, Дана. Открыть паспорт?» — when and who, from the active sale line
                $sold = \App\Domain\Sales\Models\SaleLine::query()->where('stock_unit_id', $unit->id)->latest('id')->first();
                $doc = $sold ? \App\Domain\Ledger\Models\Document::query()->find($sold->document_id) : null;
                if ($doc) {
                    $error .= ' '.\Carbon\Carbon::parse($doc->occurred_at)->timezone(config('app.timezone'))->format('d.m.Y')
                        .(($who = \App\Models\User::query()->whereKey($doc->created_by)->value('name')) ? ', '.$who : '');
                }
            }
            $this->scanPassport = $unit?->public_code;
        }
        if ($error) {
            // the row is not added: the counter stays as it was, a double sound (ТЗ 07 сценарии 2–4)
            $this->scanError = $error;
            $this->dispatch('pm-intake-bad');

            return;
        }
        $this->scanError = null;
        $b['rows'][] = ['imei1' => $value, 'imei2' => null, 'error' => null];
        $this->scanWarning = \Illuminate\Support\Facades\DB::table('unit_identifiers')->where('value', $value)
            ->where('organization_id', '<>', CurrentOrganization::instance()->requireId())->exists()
            ? __('IMEI :i встречается в другой фирме — проверьте происхождение телефона', ['i' => $value]) : null;
    }

    public function removeRow(int $batch, int $row): void
    {
        unset($this->batches[$batch]['rows'][$row]);
        $this->batches[$batch]['rows'] = array_values($this->batches[$batch]['rows']);
    }

    // ------------------------------------------------------------------ accessories

    public function addAccessory(int $id): void
    {
        $a = Accessory::query()->whereKey($id)->where('is_active', true)->firstOrFail();
        foreach ($this->accessories as $i => $row) {
            if ($row['accessory_id'] === $a->id) {
                $this->accessories[$i]['qty']++;

                return;
            }
        }
        $this->accessories[] = ['accessory_id' => $a->id, 'qty' => 1, 'price' => '', 'sale_price' => ''];
        $this->accSearch = '';
    }

    public function scanAccessory(string $code): void
    {
        $a = Accessory::query()->where('is_active', true)->where(fn ($q) => $q->where('barcode', trim($code))->orWhere('sku', trim($code)))->first();
        $a ? $this->addAccessory($a->id) : $this->error = __('Товар со штрихкодом :c не найден. Добавьте его в справочник', ['c' => $code]);
    }

    public function removeAccessory(int $i): void
    {
        unset($this->accessories[$i]);
        $this->accessories = array_values($this->accessories);
    }

    // ------------------------------------------------------------------ Excel (Э11.4)

    public function importFile(): void
    {
        $this->validate(['file' => 'required|file|max:5120|mimes:xlsx']);
        $reader = new Reader;
        $reader->open($this->file->getRealPath());
        $rows = [];
        $seen = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $n => $row) {
                $c = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row->toArray());
                if ($n === 1 || count(array_filter($c, fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= 5000) {
                    break 2;
                }
                [$model, $memory, $color, $imei1, $imei2, $condition, $price, $sale] = array_pad($c, 8, null);
                $variant = $this->findVariant((string) $model, (string) $memory, (string) $color);
                $imei = Imei::normalize((string) $imei1);
                $error = match (true) {
                    ! $variant => __('Неизвестная модель «:m :p :c»', ['m' => $model, 'p' => $memory, 'c' => $color]),
                    ! preg_match('/^\d{15}$/', $imei) || ! Imei::luhnValid($imei) => __('IMEI с ошибкой'),
                    isset($seen[$imei]) => __('Дубль IMEI (строка :n)', ['n' => $seen[$imei]]),
                    UnitIdentifier::query()->where('value', $imei)->exists() => __('IMEI уже есть в базе'),
                    $price !== null && $price !== '' && (! is_numeric($price) || $price < 0) => __('Цена закупа не числом'),
                    default => null,
                };
                $seen[$imei] ??= $n;
                $rows[] = ['line' => $n, 'variant_id' => $variant?->id, 'title' => $variant?->title() ?? trim("$model $memory $color"), 'imei1' => $imei,
                    'imei2' => $imei2 ? Imei::normalize((string) $imei2) : null, 'condition' => mb_strtolower((string) $condition) === 'б/у' ? 'used' : 'new',
                    'price' => is_numeric($price) ? (int) round($price * 100) : null, 'sale_price' => is_numeric($sale) ? (int) round($sale) * 100 : null, 'error' => $error];
            }
            break;
        }
        $reader->close();
        $this->preview = $rows;
        $this->file = null;
    }

    public function dropPreviewRow(int $i): void
    {
        unset($this->preview[$i]);
        $this->preview = array_values($this->preview);
    }

    /** Rows of the preview into batches (one batch per variant × condition × price × retail). */
    public function usePreview(): void
    {
        $this->error = null;
        if (collect($this->preview)->contains(fn ($r) => $r['error'])) {
            $this->error = __('Ошибок: :n — исправьте в файле и загрузите снова или удалите строки', ['n' => collect($this->preview)->whereNotNull('error')->count()]);

            return;
        }
        $groups = collect($this->preview)->groupBy(fn ($r) => $r['variant_id'].'|'.$r['condition'].'|'.$r['price'].'|'.$r['sale_price']);
        $batches = array_values(array_filter($this->batches, fn ($b) => $b['rows'] !== []));
        foreach ($groups as $rows) {
            $first = $rows->first();
            $batches[] = ['variant_id' => $first['variant_id'], 'search' => $first['title'], 'condition' => $first['condition'],
                'price' => $first['price'] === null ? '' : (string) ($first['price'] / 100), 'sale_price' => $first['sale_price'] === null ? '' : (string) intdiv($first['sale_price'], 100),
                'expected' => '', 'grade' => null, 'battery' => '',
                'rows' => $rows->map(fn ($r) => ['imei1' => $r['imei1'], 'imei2' => $r['imei2'], 'error' => null])->values()->all()];
        }
        $this->batches = $batches ?: [$this->emptyBatch()];
        $this->preview = [];
    }

    protected function findVariant(string $model, string $memory, string $color): ?Variant
    {
        return Variant::query()
            ->whereHas('productModel', fn ($q) => $q->whereRaw('lower(name_ru) = ?', [mb_strtolower($model)]))
            ->when($memory !== '', fn ($q) => $q->whereHas('memory', fn ($m) => $m->whereRaw("lower(replace(name, ' ', '')) = ?", [mb_strtolower(str_replace(' ', '', $memory))])))
            ->when($color !== '', fn ($q) => $q->whereHas('color', fn ($c) => $c->whereRaw('lower(name_ru) = ?', [mb_strtolower($color)])))
            ->first();
    }

    // ------------------------------------------------------------------ totals and posting

    /** @return array{units: int, acc: int, cost: int, retail: int, errors: int, short: list<string>, below: list<string>} cost in the purchase currency */
    public function totals(): array
    {
        $units = 0;
        $cost = 0;
        $retail = 0;
        $errors = 0;
        $short = [];
        $below = [];
        $rate = $this->head['currency'] === 'USD' ? $this->rate() : 1.0;
        foreach ($this->batches as $i => $b) {
            $ok = collect($b['rows'])->whereNull('error')->count();
            $errors += collect($b['rows'])->whereNotNull('error')->count();
            $units += $ok;
            $price = $this->money($b['price']);
            $sale = (int) preg_replace('/\D/', '', (string) $b['sale_price']) * 100;
            $cost += $ok * $price;
            $retail += $ok * $sale;
            if ((int) $b['expected'] > 0 && $ok < (int) $b['expected']) {
                $short[] = __('Партия :n: отсканировано :a из :e', ['n' => $i + 1, 'a' => $ok, 'e' => (int) $b['expected']]);
            }
            if ($sale && $price && $sale < $price * $rate) {
                $below[] = __('Партия :n: розница ниже себестоимости', ['n' => $i + 1]);
            }
        }
        $acc = 0;
        foreach ($this->accessories as $a) {
            $acc += (int) $a['qty'];
            $cost += (int) $a['qty'] * $this->money($a['price']);
        }

        return compact('units', 'acc', 'cost', 'retail', 'errors', 'short', 'below');
    }

    protected function money(string|int|null $value): int
    {
        $v = str_replace([' ', ','], ['', '.'], (string) $value);

        return $v === '' ? 0 : (int) round((float) $v * 100);
    }

    protected function rate(): float
    {
        return (float) (ExchangeRate::query()->where('currency', 'USD')->where('valid_from', '<=', now())->orderByDesc('valid_from')->value('rate') ?? 0);
    }

    public function askConfirm(): void
    {
        $this->error = null;
        $t = $this->totals();
        $this->error = match (true) {
            $t['errors'] > 0 => __('Есть строки с ошибками (:n). Исправьте или удалите их', ['n' => $t['errors']]),
            ! $this->head['supplier_id'] => __('Не выбран поставщик'),
            $t['units'] + $t['acc'] === 0 => __('В приходе нет товара'),
            $this->head['currency'] === 'USD' && $this->rate() <= 0 => __('Для прихода в $ нужен курс'),
            default => null,
        };
        $this->confirm = $this->error === null;
    }

    public function post(): void
    {
        $this->askConfirm();
        if ($this->error) {
            return;
        }
        $user = auth()->user();
        $canCost = $this->canCost();
        $currency = Currency::from($this->head['currency']);
        try {
            WorkingStore::set($user, (int) $this->head['store_id']);
            $draft = PurchaseDraft::make((int) $this->head['store_id'], (int) $this->head['supplier_id'], $currency)
                ->idempotencyKey(Idempotency::derived('intake', $user->id, $this->key))->author($user);
            if (trim((string) $this->head['invoice']) !== '') {
                $draft->invoice(mb_substr(trim($this->head['invoice']), 0, 64));
            }
            foreach ($this->batches as $b) {
                $price = $canCost && $b['price'] !== '' ? Money::of($this->money($b['price']), $currency) : null;
                $sale = (int) preg_replace('/\D/', '', (string) $b['sale_price']);
                foreach ($b['rows'] as $row) {
                    if ($row['error']) {
                        continue;
                    }
                    $draft->unit((int) $b['variant_id'], $row['imei1'], $price, array_filter([
                        'imei2' => $row['imei2'], 'condition' => $b['condition'], 'sale_price' => $sale ? Money::kzt($sale) : null,
                        'grade' => $b['condition'] === 'used' ? $b['grade'] : null, 'battery_percent' => $b['condition'] === 'used' && $b['battery'] !== '' ? (int) $b['battery'] : null,
                    ], fn ($v) => $v !== null));
                }
            }
            foreach ($this->accessories as $a) {
                $draft->accessory((int) $a['accessory_id'], max(1, (int) $a['qty']), $canCost ? Money::of($this->money($a['price']), $currency) : null);
            }
            $total = $this->totals()['cost'];
            if ($canCost && $total > 0) {
                if (in_array($this->head['pay'], ['paid', 'partial'], true) && $this->head['account_id']) {
                    $amount = $this->head['pay'] === 'paid' ? $total : min($total, $this->money($this->head['paid']));
                    if ($amount > 0) {
                        $draft->payFrom((int) $this->head['account_id'], Money::of($amount, $currency));
                    }
                }
                if ($this->head['pay'] !== 'paid') {
                    $draft->onCredit(CarbonImmutable::parse($this->head['due']));
                }
            }
            $doc = $draft->post($user);
            // retail prices of accessories, if given (PriceService: L14 and the price journal)
            foreach ($this->accessories as $a) {
                $sale = (int) preg_replace('/\D/', '', (string) $a['sale_price']);
                if ($sale > 0 && Access::allows('price.edit')) {
                    app(PriceService::class)->setAccessoryPrice((int) $a['accessory_id'], Money::kzt($sale), $user);
                }
            }
            session(['pm.intake.head' => array_intersect_key($this->head, array_flip(['supplier_id', 'currency', 'pay']))]);
            $this->posted = $doc->id;
            $this->confirm = false;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
            $this->confirm = false;
        }
    }

    public function again(): void
    {
        $this->posted = null;
        $this->batches = [$this->emptyBatch()];
        $this->accessories = [];
        $this->active = 0;
        $this->key = (string) Str::uuid();
    }

    protected function getViewData(): array
    {
        $search = trim($this->batches[$this->active]['search'] ?? '');
        $variants = $search !== '' && ! ($this->batches[$this->active]['variant_id'] ?? null)
            ? Variant::query()->with(['productModel', 'memory', 'color'])->whereHas('productModel', fn ($q) => $q->where('name_ru', 'ilike', '%'.addcslashes($search, '%_').'%'))->limit(20)->get()
            : collect();
        $doc = $this->posted ? Document::query()->find($this->posted) : null;

        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stores' => WorkingStore::available(auth()->user()),
            'accounts' => MoneyAccount::query()->where('currency', $this->head['currency'])->whereIn('type', ['cash', 'bank', 'safe'])->where('is_active', true)->orderBy('sort')->get(['id', 'name']),
            'variants' => $variants,
            'accResults' => trim($this->accSearch) !== '' ? Accessory::query()->where('is_active', true)->where('name_ru', 'ilike', '%'.addcslashes(trim($this->accSearch), '%_').'%')->limit(10)->get() : collect(),
            'accNames' => Accessory::query()->whereIn('id', array_column($this->accessories, 'accessory_id'))->pluck('name_ru', 'id'),
            't' => $this->totals(),
            'canCost' => $this->canCost(),
            'rate' => $this->rate(),
            'doc' => $doc,
            'postedUnits' => $doc ? StockUnit::query()->whereIn('id', \App\Domain\Inventory\Models\PurchaseLine::query()->where('document_id', $doc->id)->whereNotNull('stock_unit_id')->select('stock_unit_id'))->get(['id', 'public_code', 'imei1']) : collect(),
        ];
    }
}
