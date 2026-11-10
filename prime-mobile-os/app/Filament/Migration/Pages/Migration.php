<?php

namespace App\Filament\Migration\Pages;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Migration\Models\ImportRow;
use App\Domain\Migration\Models\ImportRun;
use App\Domain\Migration\Services\DayX;
use App\Domain\Migration\Services\ExcelImport;
use App\Domain\Migration\Services\SfonClient;
use App\Domain\Migration\Services\SfonPull;
use App\Domain\Migration\Services\SfonReconcile;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;

/**
 * Э «Перенос» (TZ 07 §1.13): owner only. Чек-лист Ш0–Ш11 · Файлы и API · Доработать · День X (скан) ·
 * Проведение · Сверка · Архив · Отключение.
 */
class Migration extends Page
{
    use HasErpAccess;
    use WithFileUploads;

    protected static string|array|null $permission = null;   // owner only — canAccess()

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'download';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'migration';

    protected static string $view = 'migration.pages.migration';

    public const TABS = ['checklist' => 'Чек-лист', 'files' => 'Файлы и API', 'fix' => 'Доработать', 'scan' => 'День X: скан', 'post' => 'Проведение', 'reconcile' => 'Сверка', 'archive' => 'Архив sfon', 'off' => 'Отключение'];

    public string $tab = 'checklist';

    public $file = null;

    public string $kind = 'customers';

    public array $costs = [];

    public ?int $storeId = null;

    public string $imei = '';

    public ?array $pending = null;       // scan waiting for a choice: imei, kind

    public array $extra = ['variant' => '', 'variant_id' => null, 'condition' => 'new', 'price' => '', 'cost' => ''];

    public array $money = [];

    public string $search = '';

    public array $off = [];

    public ?string $error = null;

    public ?string $flash = null;

    public static function canAccess(): bool
    {
        return Access::isOwner();
    }

    public static function getNavigationLabel(): string
    {
        return __('Перенос из sfon');
    }

    public function getTitle(): string
    {
        return __('Перенос из sfon');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->storeId = Store::query()->where('is_active', true)->orderBy('id')->value('id');
    }

    private function ok(string $m): void
    {
        $this->flash = $m;
        $this->error = null;
    }

    private function guard(callable $fn): void
    {
        $this->error = null;
        try {
            $fn();
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    // ---- Чек-лист ----
    public function toggleStep(string $step): void
    {
        abort_unless(isset(DayX::STEPS[$step]), 404);
        $org = CurrentOrganization::instance()->requireId();
        $done = DB::table('migration_checklist')->where('organization_id', $org)->where('step', $step)->value('status') === 'done';
        DB::table('migration_checklist')->updateOrInsert(['organization_id' => $org, 'step' => $step], ['status' => $done ? 'todo' : 'done', 'done_by' => $done ? null : auth()->id(), 'done_at' => $done ? null : now()]);
        activity('migration')->causedBy(auth()->user())->withProperties(['step' => $step, 'done' => ! $done])->log('checklist');
    }

    // ---- Файлы и API ----
    public function pullSfon(): void
    {
        $this->guard(function () {
            $t = app(SfonPull::class)->all(auth()->user());
            $this->ok(__('Забрано из sfon: магазинов :s, моделей :m, новых :n шт. (:g групп), б/у :u, ошибок :e', ['s' => $t['stores'], 'm' => $t['models'], 'n' => $t['new_units'], 'g' => $t['new_groups'], 'u' => $t['used'], 'e' => $t['errors']]));
        });
    }

    public function upload(): void
    {
        $this->guard(function () {
            if (! $this->file) {
                throw UserFacingException::because('no_file', __('Выберите файл Excel или CSV'));
            }
            $r = app(ExcelImport::class)->import($this->kind, $this->file->getRealPath(), $this->file->getClientOriginalName(), auth()->user());
            $this->file = null;
            $this->ok(__('Загружено строк: :n, с ошибкой: :e', ['n' => $r['rows'], 'e' => $r['errors']]));
        });
    }

    // ---- Доработать ----
    public function saveCost(int $rowId): void
    {
        $row = ImportRow::query()->whereIn('kind', ['stock_new', 'stock_used'])->findOrFail($rowId);
        $tenge = (int) preg_replace('/\D/', '', (string) ($this->costs[$rowId] ?? ''));
        $row->update(['fields' => array_merge((array) $row->fields, ['cost' => $tenge > 0 ? $tenge * 100 : null, 'cost_set_by' => auth()->id()]), 'updated_at' => now()]);
        $this->ok(__('Себестоимость сохранена'));
    }

    public function skipRow(int $rowId): void
    {
        ImportRow::query()->where('status', '!=', 'in_vxo')->findOrFail($rowId)->update(['status' => 'skipped', 'updated_at' => now()]);
        $this->ok(__('Строка пропущена'));
    }

    // ---- День X: скан ----
    public function scan(): void
    {
        $store = Store::query()->findOrFail($this->storeId);
        $r = app(DayX::class)->scan($store, $this->imei, auth()->user());
        if ($r['result'] === 'choose') {
            $this->pending = ['imei' => $r['imei']];
            $this->flash = null;
            $this->error = null;

            return;
        }
        $r['result'] === 'ok' ? $this->ok($r['message']) : $this->error = $r['message'];
        $this->imei = '';
    }

    public function choose(string $kind, int $rowId): void
    {
        $store = Store::query()->findOrFail($this->storeId);
        $dx = app(DayX::class);
        $r = $kind === 'new' ? $dx->takeNew($store, $this->pending['imei'], $rowId, auth()->user()) : $dx->scan($store, $this->pending['imei'], auth()->user(), $rowId);
        $r['result'] === 'ok' ? $this->ok($r['message']) : $this->error = $r['message'];
        $this->pending = $r['result'] === 'full' ? $this->pending : null;
        $this->imei = '';
    }

    public function pickExtraVariant(int $id): void
    {
        $this->extra['variant_id'] = $id;
        $this->extra['variant'] = Variant::query()->find($id)?->title() ?? '';
    }

    public function saveExtra(): void
    {
        if (! $this->extra['variant_id']) {
            $this->error = __('Выберите модель');

            return;
        }
        $money = fn ($v) => ($t = (int) preg_replace('/\D/', '', (string) $v)) > 0 ? $t * 100 : null;
        $r = app(DayX::class)->extra(Store::query()->findOrFail($this->storeId), $this->pending['imei'], (int) $this->extra['variant_id'], $this->extra['condition'], $money($this->extra['price']), $money($this->extra['cost']), auth()->user());
        $this->ok($r['message']);
        $this->pending = null;
        $this->imei = '';
        $this->extra = ['variant' => '', 'variant_id' => null, 'condition' => 'new', 'price' => '', 'cost' => ''];
    }

    public function removeScan(int $id): void
    {
        DB::table('migration_scans')->where('organization_id', CurrentOrganization::instance()->requireId())->where('id', $id)->delete();
        $this->ok(__('Скан удалён'));
    }

    // ---- Проведение ----
    public function postVxo(): void
    {
        $this->guard(function () {
            $money = collect($this->money)->map(fn ($v) => (int) round(((float) str_replace([' ', ','], ['', '.'], (string) $v)) * 100))->filter()->all();
            $doc = app(DayX::class)->post(Store::query()->findOrFail($this->storeId), $money, auth()->user());
            $this->ok(__('Входящие остатки :n проведены. Распечатайте акт сверки.', ['n' => $doc->number]));
        });
    }

    // ---- Сверка ----
    public function reconcileNow(): void
    {
        $this->guard(function () {
            $r = app(SfonReconcile::class)->run();
            $this->ok(__('Сверка: расхождений :n, дней без расхождений :s из 5', ['n' => count($r['diffs']), 's' => $r['streak']]));
        });
    }

    // ---- Отключение ----
    public function switchOff(): void
    {
        $this->error = null;
        $auto = $this->offConditions();
        if (in_array(false, $auto, true) || count(array_filter($this->off)) < 4) {
            $this->error = __('Отключить можно, когда выполнены все 7 условий');

            return;
        }
        DB::table('migration_checklist')->updateOrInsert(['organization_id' => CurrentOrganization::instance()->requireId(), 'step' => 'Ш11'],
            ['status' => 'done', 'done_by' => auth()->id(), 'done_at' => now(), 'note' => __('sfon отключён')]);
        activity('migration')->causedBy(auth()->user())->log('sfon.switched_off');
        $this->ok(__('Сверка с sfon выключена. Экран переноса — только для чтения.'));
    }

    /** Automatic conditions of TZ 07 §1.11 (п. 1, 2, 5); the rest are ticked by the owner. */
    public function offConditions(): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $dayX = DB::table('migration_checklist')->where('organization_id', $org)->where('step', 'Ш8')->value('done_at');

        return ['days14' => $dayX && Carbon::parse($dayX)->diffInDays(now(), true) >= 14, 'streak5' => SfonReconcile::streak() >= 5];
    }

    protected function getViewData(): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $store = $this->storeId ? Store::query()->find($this->storeId) : null;
        $dx = app(DayX::class);
        $data = ['steps' => DB::table('migration_checklist')->where('organization_id', $org)->get()->keyBy('step'), 'stores' => Store::query()->where('is_active', true)->orderBy('id')->get(),
            'tz' => CurrentOrganization::instance()->require()->timezone, 'keySet' => app(SfonClient::class)->configured(), 'readonly' => DB::table('migration_checklist')->where('organization_id', $org)->where('step', 'Ш11')->value('status') === 'done'];

        return $data + match ($this->tab) {
            'files' => ['runs' => ImportRun::query()->latest('id')->limit(30)->get(), 'kinds' => ExcelImport::KINDS],
            'fix' => ['problems' => ImportRow::query()->whereIn('status', ['error', 'matched'])->whereIn('kind', ['stock_new', 'stock_used', 'accessories', 'customer_debts', 'supplier_debts', 'bonuses', 'customers', 'costs'])
                ->get()->filter(fn ($r) => $r->status === 'error' || (in_array($r->kind, ['stock_new', 'stock_used'], true) && empty($r->fields['cost'])))->sortBy('kind')->values()->take(300),
                'quality' => $dx->quality(), 'variants' => Variant::query()->with(['productModel', 'memory', 'color'])->whereIn('id', ImportRow::query()->whereIn('kind', ['stock_new', 'stock_used'])->get()->pluck('fields.variant_id')->filter())->get()->keyBy('id')],
            'scan' => ['newGroups' => $store ? $dx->candidates($store, 'stock_new') : collect(), 'usedCards' => $store ? $dx->candidates($store, 'stock_used') : collect(),
                'scans' => DB::table('migration_scans')->where('organization_id', $org)->where('store_id', $this->storeId)->orderByDesc('id')->limit(50)->get(),
                'variants' => Variant::query()->with(['productModel', 'memory', 'color'])->get()->keyBy('id'), 'quality' => $dx->quality(),
                'found' => mb_strlen(trim($this->extra['variant'])) >= 2 && ! $this->extra['variant_id'] ? Variant::query()->with(['productModel', 'memory', 'color'])
                    ->whereHas('productModel', fn ($q) => $q->where('name_ru', 'ilike', '%'.addcslashes(trim($this->extra['variant']), '%_').'%'))->limit(10)->get() : collect()],
            'post' => ['quality' => $dx->quality(), 'accounts' => MoneyAccount::query()->where('type', '!=', 'technical')->where('is_active', true)
                ->where(fn ($q) => $q->where('store_id', $this->storeId)->orWhereNull('store_id'))->orderBy('sort')->get()],
            'reconcile' => ['runs' => DB::table('sfon_reconciliations')->where('organization_id', $org)->orderByDesc('day')->limit(30)->get(), 'streak' => SfonReconcile::streak()],
            'archive' => ['found' => mb_strlen(trim($this->search)) >= 3 ? DB::table('sfon_archive')->where('organization_id', $org)
                ->where(fn ($q) => $q->where('imei', preg_replace('/\D/', '', $this->search) ?: '-')->orWhere('number', trim($this->search))->orWhere('customer_phone', 'like', '%'.preg_replace('/\D/', '', $this->search).'%')
                    ->orWhere('title', 'ilike', '%'.addcslashes(trim($this->search), '%_').'%'))->orderByDesc('occurred_at')->limit(100)->get() : collect(),
                'months' => DB::table('sfon_archive')->where('organization_id', $org)->where('kind', 'sale')->groupBy('month')->orderByDesc('month')->selectRaw('month, count(*) n, sum(amount) s')->limit(36)->get()],
            'off' => ['auto' => $this->offConditions()],
            default => [],
        };
    }
}
