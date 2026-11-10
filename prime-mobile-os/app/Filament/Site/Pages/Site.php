<?php

namespace App\Filament\Site\Pages;

use App\Domain\Catalog\Models\Family;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Storefront\Models\Banner;
use App\Domain\Storefront\Models\Lead;
use App\Domain\Storefront\Models\Listing;
use App\Domain\Storefront\Models\SiteAccessory;
use App\Domain\Storefront\Models\SitePage;
use App\Domain\Storefront\Models\SitePhoto;
use App\Domain\Storefront\Services\Leads;
use App\Domain\Storefront\Services\ListingBuilder;
use App\Domain\Storefront\Services\Photos;
use App\Domain\Storefront\Services\SitePages;
use App\Domain\Storefront\Services\SiteSettings;
use App\Domain\Storefront\Services\Visibility;
use App\Domain\Storefront\Support\SiteChange;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Exceptions\UserFacingException;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\WithFileUploads;

/**
 * Раздел «Сайт» (TZ 05 §14): товары на сайте и почему нет, без фото, новые фото, заявки, категории и магазины,
 * страницы, баннеры, настройки, состояние, статистика. Daily photo work goes through the passport (Э10 tab «Сайт»).
 */
class Site extends Page
{
    use HasErpAccess;
    use WithFileUploads;

    protected static string|array|null $permission = ['site.photo', 'site.visibility', 'site.leads', 'site.rules', 'site.content', 'site.settings', 'site.order'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Management;

    protected static ?string $navigationIcon = 'globe';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'site';

    protected static string $view = 'site.pages.site';

    public const TABS = [
        'goods' => ['Товары', 'site.visibility'], 'nophoto' => ['Без фото', 'site.photo'], 'review' => ['Новые фото', 'site.visibility'],
        'leads' => ['Заявки', 'site.leads'], 'rules' => ['Категории и магазины', 'site.rules'], 'pages' => ['Страницы', 'site.content'],
        'banners' => ['Баннеры', 'site.content'], 'settings' => ['Настройки', 'site.settings'], 'state' => ['Состояние', null], 'stats' => ['Статистика', null],
    ];

    public string $tab = 'goods';

    public string $filter = 'off';      // goods: on | off | all

    public string $search = '';

    public array $selected = [];

    public string $leadTab = 'in_work';

    public ?int $leadId = null;

    public array $close = ['outcome' => '', 'comment' => ''];

    public ?string $pageSlug = null;

    public array $pageForm = [];

    public ?array $banner = null;

    public array $settings = [];

    public array $storesForm = [];

    public ?string $confirm = null;

    public array $modelPhoto = [];

    public ?int $modelPhotoFor = null;

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Сайт');
    }

    public function getTitle(): string
    {
        return __('Сайт');
    }

    public function mount(): void
    {
        $tab = (string) request()->query('tab');
        $this->tab = isset(self::TABS[$tab]) && $this->allowedTab($tab) ? $tab : (collect(array_keys(self::TABS))->first(fn ($t) => $this->allowedTab($t)) ?? 'state');
        if ($code = request()->query('lead')) {
            $lead = Leads::find((string) $code);
            if ($lead && Access::allows('site.leads')) {
                [$this->tab, $this->leadId] = ['leads', $lead->id];
            }
        }
        $this->loadSettings();
    }

    public function allowedTab(string $tab): bool
    {
        $perm = self::TABS[$tab][1] ?? null;

        return match ($tab) {
            'state', 'stats' => Access::isOwner() || Access::allows('site.settings') || Access::allows('report.view'),
            default => $perm === null || Access::allows($perm),
        };
    }

    public function updatedTab(): void
    {
        abort_unless($this->allowedTab($this->tab), 403);
        $this->reset(['selected', 'error', 'flash', 'confirm', 'leadId', 'banner', 'pageSlug']);
    }

    private function done(string $msg): void
    {
        $this->flash = $msg;
        $this->error = null;
    }

    // ---- Товары (§4.5 массовые действия) ----

    public function bulk(bool $show): void
    {
        Access::authorize('site.visibility');
        $ids = array_map('intval', $this->selected);
        if (! $ids) {
            return;
        }
        $units = StockUnit::query()->whereIn('id', $ids)->get();
        foreach ($units as $u) {
            $u->forceFill(['show_on_site' => $show, 'site_hidden_by' => $show ? null : auth()->id(), 'site_hidden_at' => $show ? null : now()])->save();
        }
        activity('site')->causedBy(auth()->user())->withProperties(['show' => $show, 'codes' => $units->pluck('public_code')->all()])->log('units.visibility');
        SiteChange::units($ids, 'visibility');
        app(ListingBuilder::class)->units($ids);
        $this->selected = [];
        $this->done(($show ? __('Показано на сайте') : __('Скрыто с сайта')).': '.$units->count());
    }

    // ---- Без фото: фото модели (§3.6) ----

    public function updatedModelPhoto(): void
    {
        Access::authorize('site.photo');
        $variant = Variant::query()->find($this->modelPhotoFor);
        foreach ($this->modelPhoto as $file) {
            try {
                app(Photos::class)->upload(['model_id' => $variant->model_id, 'color_id' => $variant->color_id], $file, auth()->user());
            } catch (UserFacingException $e) {
                $this->error = $e->getMessage();
            }
        }
        $this->modelPhoto = [];
        $this->modelPhotoFor = null;
        app(ListingBuilder::class)->units(StockUnit::query()->where('variant_id', $variant->id)->pluck('id')->all());
        if (! $this->error) {
            $this->done(__('Фото модели добавлено'));
        }
    }

    // ---- Новые фото (§3.5) ----

    public function review(int $id, bool $keep): void
    {
        Access::authorize('site.visibility');
        $p = SitePhoto::query()->findOrFail($id);
        app(Photos::class)->review($p, auth()->user(), $keep);
        $p->stock_unit_id && app(ListingBuilder::class)->units([$p->stock_unit_id]);
        $this->done($keep ? __('Фото оставлено') : __('Фото убрано с сайта'));
    }

    // ---- Заявки (§11.3, §11.4) ----

    public function openLead(int $id): void
    {
        $this->leadId = $id;
        $this->close = ['outcome' => '', 'comment' => ''];
        $this->error = null;
    }

    public function findLead(): void
    {
        $lead = Leads::find($this->search);
        $lead ? $this->openLead($lead->id) : $this->error = __('Заявка не найдена');
    }

    public function takeLead(): void
    {
        Access::authorize('site.leads');
        try {
            app(Leads::class)->take(Lead::query()->findOrFail($this->leadId), auth()->user());
            $this->done(__('Заявка взята в работу'));
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function closeLead(): void
    {
        Access::authorize('site.leads');
        try {
            app(Leads::class)->close(Lead::query()->findOrFail($this->leadId), (string) $this->close['outcome'], (string) $this->close['comment'], auth()->user());
            $this->leadId = null;
            $this->done(__('Заявка закрыта'));
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    // ---- Категории и магазины (§4.2) ----

    public function askHide(string $kind, int $id): void
    {
        Access::authorize('site.rules');
        $this->confirm = $kind.':'.$id;
    }

    /** Units of a level that are on the site now — for «Скрыть модель iPhone 11? С сайта уйдут 6 телефонов». */
    public function affected(string $kind, int $id): int
    {
        $q = Listing::query()->where('public_status', '!=', 'sold');

        return match ($kind) {
            'model' => $q->where('model_ref', $id)->count(),
            'family' => $q->whereIn('model_ref', ProductModel::query()->where('family_id', $id)->select('id'))->count(),
            'type' => $q->where('type_code', ProductType::query()->whereKey($id)->value('code'))->count(),
            default => 0,
        };
    }

    public function setHidden(string $kind, int $id, bool $hidden): void
    {
        Access::authorize('site.rules');
        $model = ['model' => ProductModel::class, 'family' => Family::class, 'type' => ProductType::class][$kind] ?? abort(404);
        $model::query()->whereKey($id)->update(['site_hidden' => $hidden]);
        activity('site')->causedBy(auth()->user())->withProperties(['kind' => $kind, 'id' => $id, 'hidden' => $hidden])->log('rules.visibility');
        SiteChange::all('rules');
        app(ListingBuilder::class)->all();
        $this->confirm = null;
        $this->done($hidden ? __('Скрыто с сайта') : __('Показано на сайте'));
    }

    public function saveStores(): void
    {
        Access::authorize('site.rules');
        foreach ($this->storesForm as $id => $f) {
            $store = Store::query()->findOrFail((int) $id);
            $store->meta = array_merge((array) $store->meta, [
                'site_hidden' => ! ($f['shown'] ?? true), 'site_show_new' => (bool) ($f['new'] ?? true), 'site_show_used' => (bool) ($f['used'] ?? true),
                'whatsapp' => trim((string) ($f['whatsapp'] ?? '')) ?: null, 'hours' => trim((string) ($f['hours'] ?? '')) ?: null,
                'map_url' => preg_match('#^https?://#', (string) ($f['map_url'] ?? '')) ? trim($f['map_url']) : null,
                'address_kk' => trim((string) ($f['address_kk'] ?? '')) ?: null, 'city' => trim((string) ($f['city'] ?? '')) ?: null,
            ]);
            $store->save();
        }
        activity('site')->causedBy(auth()->user())->log('stores.saved');
        SiteChange::all('stores');
        app(ListingBuilder::class)->all();
        $this->done(__('Магазины сохранены'));
    }

    // ---- Страницы (§12.3) ----

    public function editPage(string $slug): void
    {
        Access::authorize('site.content');
        abort_unless(isset(SitePages::DEFAULTS[$slug]), 404);
        $p = SitePage::query()->where('slug', $slug)->first();
        $this->pageSlug = $slug;
        $this->pageForm = ['title_ru' => $p->title_ru ?? SitePages::DEFAULTS[$slug]['title'], 'title_kk' => (string) ($p->title_kk ?? ''),
            'body_ru' => $p->body_ru ?? SitePages::DEFAULTS[$slug]['body'], 'body_kk' => (string) ($p->body_kk ?? ''), 'is_draft' => (bool) ($p->is_draft ?? true)];
    }

    public function savePage(): void
    {
        Access::authorize('site.content');
        $f = $this->pageForm;
        SitePage::query()->updateOrCreate(['slug' => $this->pageSlug], [
            'title_ru' => mb_substr(trim($f['title_ru']), 0, 200) ?: SitePages::DEFAULTS[$this->pageSlug]['title'], 'title_kk' => trim($f['title_kk']) ?: null,
            'body_ru' => (string) $f['body_ru'], 'body_kk' => trim((string) $f['body_kk']) ?: null, 'is_draft' => (bool) $f['is_draft'], 'updated_by' => auth()->id(),
        ]);
        activity('site')->causedBy(auth()->user())->withProperties(['page' => $this->pageSlug])->log('page.saved');
        ListingBuilder::touch();
        $this->pageSlug = null;
        $this->done(__('Страница сохранена'));
    }

    // ---- Баннеры (§12.1) ----

    public function editBanner(?int $id = null): void
    {
        Access::authorize('site.content');
        $b = $id ? Banner::query()->findOrFail($id) : null;
        $this->banner = ['id' => $b?->id, 'template' => $b->template ?? 'new', 'title_ru' => $b->title_ru ?? '', 'title_kk' => $b->title_kk ?? '',
            'subtitle_ru' => $b->subtitle_ru ?? '', 'subtitle_kk' => $b->subtitle_kk ?? '', 'button_ru' => $b->button_ru ?? '', 'button_kk' => $b->button_kk ?? '',
            'link' => $b->link ?? '/catalog', 'price_model_ref' => $b?->price_model_ref, 'sort' => $b->sort ?? 0, 'is_active' => $b->is_active ?? true,
            'starts_at' => $b?->starts_at?->format('Y-m-d'), 'ends_at' => $b?->ends_at?->format('Y-m-d')];
    }

    public function saveBanner(): void
    {
        Access::authorize('site.content');
        $f = $this->banner;
        $this->error = match (true) {
            trim($f['title_ru']) === '' => __('Введите заголовок'),
            mb_strlen($f['title_ru']) > 40 || mb_strlen((string) $f['title_kk']) > 40 => __('Заголовок — не длиннее 40 символов'),
            ! str_starts_with((string) $f['link'], '/') => __('Ссылка — адрес страницы сайта, например /catalog/smartfony'),
            ! $f['id'] && $f['is_active'] && Banner::query()->where('is_active', true)->count() >= 6 => __('Активных баннеров уже 6 — выключите один'),
            default => null,
        };
        if ($this->error) {
            return;
        }
        Banner::query()->updateOrCreate(['id' => $f['id']], [
            'template' => in_array($f['template'], ['new', 'tradein', 'used', 'promo'], true) ? $f['template'] : 'new',
            'title_ru' => trim($f['title_ru']), 'title_kk' => trim((string) $f['title_kk']) ?: null, 'subtitle_ru' => mb_substr(trim((string) $f['subtitle_ru']), 0, 80) ?: null,
            'subtitle_kk' => mb_substr(trim((string) $f['subtitle_kk']), 0, 80) ?: null, 'button_ru' => mb_substr(trim((string) $f['button_ru']), 0, 20) ?: null,
            'button_kk' => mb_substr(trim((string) $f['button_kk']), 0, 20) ?: null, 'link' => mb_substr(trim($f['link']), 0, 200),
            'price_model_ref' => $f['price_model_ref'] ? (int) $f['price_model_ref'] : null, 'sort' => (int) $f['sort'], 'is_active' => (bool) $f['is_active'],
            'starts_at' => $f['starts_at'] ?: null, 'ends_at' => $f['ends_at'] ? Carbon::parse($f['ends_at'])->endOfDay() : null,
        ]);
        activity('site')->causedBy(auth()->user())->log('banner.saved');
        ListingBuilder::touch();
        $this->banner = null;
        $this->done(__('Баннер сохранён'));
    }

    public function removeBanner(int $id): void
    {
        Access::authorize('site.content');
        Banner::query()->whereKey($id)->delete();
        ListingBuilder::touch();
        $this->done(__('Баннер удалён'));
    }

    // ---- Настройки (§12.4) ----

    private function loadSettings(): void
    {
        $s = SiteSettings::current()->all();
        $this->settings = [
            'enabled' => (bool) $s['enabled'], 'city_ru' => (string) $s['city_ru'], 'city_kk' => (string) $s['city_kk'], 'instagram' => (string) $s['instagram'],
            'photo_review' => (bool) $s['photo_review'], 'templates' => array_map(fn ($v) => (string) $v, $s['templates']),
            'installment' => ['enabled' => (bool) $s['installment']['enabled'], 'title_ru' => $s['installment']['title_ru'], 'title_kk' => (string) $s['installment']['title_kk'],
                'months' => implode(', ', $s['installment']['months']), 'markup' => implode(', ', $s['installment']['markup']), 'min' => (string) $s['installment']['min'], 'max' => (string) $s['installment']['max']],
            'grades' => $s['grades'],
        ];
        $this->storesForm = Store::query()->where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($st) => [$st->id => [
            'shown' => empty($st->meta['site_hidden']), 'new' => ($st->meta['site_show_new'] ?? true) !== false, 'used' => ($st->meta['site_show_used'] ?? true) !== false,
            'whatsapp' => (string) ($st->meta['whatsapp'] ?? ''), 'hours' => (string) ($st->meta['hours'] ?? ''), 'map_url' => (string) ($st->meta['map_url'] ?? ''),
            'address_kk' => (string) ($st->meta['address_kk'] ?? ''), 'city' => (string) ($st->meta['city'] ?? ''),
        ]])->all();
    }

    public function saveSettings(): void
    {
        Access::authorize('site.settings');
        $f = $this->settings;
        $months = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', (string) $f['installment']['months']))));
        $markup = array_map(fn ($v) => (float) str_replace(',', '.', $v), array_values(array_filter(preg_split('/[\s;]+|,\s+/', (string) $f['installment']['markup']), fn ($v) => $v !== '')));
        if (! $months || count($markup) !== count($months)) {
            $this->error = __('Сроков и переплат должно быть поровну: например «3, 6, 12, 24» и «0, 0, 0, 0»');

            return;
        }
        $privacy = SitePage::query()->where('slug', 'privacy')->first();
        if ($f['enabled'] && (! $privacy || $privacy->is_draft || trim((string) $privacy->body_ru) === '')) {
            $this->error = __('Сайт нельзя включить, пока «Политика конфиденциальности» — черновик. Вставьте текст юриста в «Страницы» и снимите «черновик».');

            return;
        }
        $org = CurrentOrganization::instance()->require();
        $org->settings = array_replace_recursive($org->settings ?? [], ['storefront' => [
            'enabled' => (bool) $f['enabled'], 'city_ru' => trim($f['city_ru']) ?: 'Алматы', 'city_kk' => trim($f['city_kk']) ?: null, 'instagram' => trim((string) $f['instagram']) ?: null,
            'photo_review' => (bool) $f['photo_review'], 'templates' => array_map(fn ($v) => trim((string) $v) ?: null, $f['templates']), 'grades' => $f['grades'],
            'installment' => ['enabled' => (bool) $f['installment']['enabled'], 'title_ru' => trim($f['installment']['title_ru']), 'title_kk' => trim($f['installment']['title_kk']) ?: null,
                'months' => $months, 'markup' => $markup, 'min' => (int) preg_replace('/\D/', '', $f['installment']['min']), 'max' => (int) preg_replace('/\D/', '', $f['installment']['max'])],
        ]]);
        // lists are replaced whole (array_replace_recursive would keep a longer old list)
        $data = $org->settings;
        $data['storefront']['installment']['months'] = $months;
        $data['storefront']['installment']['markup'] = $markup;
        $org->settings = $data;
        $org->save();
        activity('site')->causedBy(auth()->user())->log('settings.saved');
        SiteChange::all('settings');
        app(ListingBuilder::class)->all();
        $this->done(__('Настройки сохранены'));
    }

    // ---- Состояние (§8.8) ----

    public function rebuild(): void
    {
        abort_unless(Access::isOwner() || Access::allows('site.settings'), 403);
        $changed = app(ListingBuilder::class)->all();
        DB::table('storefront_runs')->insert(['organization_id' => CurrentOrganization::instance()->requireId(), 'kind' => 'rebuild', 'fixed' => count($changed), 'created_at' => now()]);
        activity('site')->causedBy(auth()->user())->withProperties(['fixed' => count($changed)])->log('site.rebuild');
        $this->done(__('Сайт пересобран, исправлено карточек: :n', ['n' => count($changed)]));
    }

    public function previewUrl(): string
    {
        return URL::temporarySignedRoute('storefront.preview', now()->addMinutes(10));
    }

    protected function getViewData(): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $data = ['tz' => $tz, 'siteOn' => SiteSettings::current()->enabled(), 'tabs' => array_filter(self::TABS, fn ($v, $k) => $this->allowedTab($k), ARRAY_FILTER_USE_BOTH)];

        return $data + match ($this->tab) {
            'goods' => $this->goodsData(),
            'nophoto' => $this->noPhotoData(),
            'review' => ['photos' => SitePhoto::query()->where('needs_review', true)->whereNull('deleted_at')->latest('id')->limit(60)->get(),
                'units' => StockUnit::query()->whereIn('id', SitePhoto::query()->where('needs_review', true)->select('stock_unit_id'))->with('variant')->get()->keyBy('id'),
                'uploaders' => User::query()->whereIn('id', SitePhoto::query()->where('needs_review', true)->select('uploaded_by'))->pluck('name', 'id')],
            'leads' => $this->leadsData(),
            'rules' => ['types' => ProductType::query()->orderBy('sort')->get(), 'families' => Family::query()->orderBy('brand')->orderBy('name')->get(),
                'models' => ProductModel::query()->whereIn('id', StockUnit::query()->join('variants', 'variants.id', '=', 'stock_units.variant_id')->whereIn('stock_units.status', ['in_stock', 'reserved', 'ordered'])->select('variants.model_id'))->orderBy('name_ru')->get(),
                'stores' => Store::query()->where('is_active', true)->orderBy('sort')->get()],
            'pages' => ['pages' => SitePage::query()->get()->keyBy('slug')],
            'banners' => ['banners' => Banner::query()->orderBy('sort')->orderBy('id')->get(), 'models' => ProductModel::query()->whereIn('id', Listing::query()->select('model_ref'))->orderBy('name_ru')->get()],
            'state' => $this->stateData(),
            'stats' => $this->statsData(),
            default => [],
        };
    }

    private function units()
    {
        $q = StockUnit::query()->with(['variant.productModel.family', 'variant.memory', 'variant.color'])
            ->whereIn('status', array_map(fn ($s) => $s->value, Visibility::SHOWN_STATUSES));
        if (($s = trim($this->search)) !== '') {
            $q->where(fn ($w) => $w->where('public_code', 'ilike', '%'.addcslashes($s, '%_').'%')
                ->orWhereHas('variant.productModel', fn ($m) => $m->where('name_ru', 'ilike', '%'.addcslashes($s, '%_').'%')));
        }

        return $q->orderByDesc('id')->limit(300)->get();
    }

    private function goodsData(): array
    {
        $listed = Listing::query()->where('public_status', '!=', 'sold')->pluck('unit_ref')->flip();
        $rows = $this->units()->map(fn ($u) => ['unit' => $u, 'on' => isset($listed[$u->id]), 'reasons' => isset($listed[$u->id]) ? [] : Visibility::reasons($u)])
            ->filter(fn ($r) => $this->filter === 'all' || ($this->filter === 'on') === $r['on'])->values();

        return ['rows' => $rows, 'counts' => ['on' => $listed->count(), 'accessories' => SiteAccessory::query()->count()]];
    }

    private function noPhotoData(): array
    {
        $rows = $this->units()->filter(fn ($u) => ! Visibility::hasPhoto($u))->values();
        $variants = $rows->filter(fn ($u) => $u->condition?->value !== 'used')->pluck('variant')->unique('id')->values();

        return ['used' => $rows->filter(fn ($u) => $u->condition?->value === 'used')->values(), 'variants' => $variants,
            'newCount' => $rows->filter(fn ($u) => $u->condition?->value !== 'used')->countBy('variant_id')];
    }

    private function leadsData(): array
    {
        $q = Lead::query()->latest('id');
        match ($this->leadTab) {
            'in_work' => $q->where('status', 'in_work'),
            'clicked' => $q->where('status', 'clicked')->where('created_at', '>=', now()->subDays(3)),
            default => $q->where('status', 'closed'),
        };
        $leads = $q->limit(100)->get();
        $lead = $this->leadId ? Lead::query()->find($this->leadId) : null;
        $codes = $leads->pluck('public_code')->push($lead?->public_code)->filter()->unique();

        return ['leads' => $leads, 'lead' => $lead, 'leadUnits' => StockUnit::query()->with('variant')->whereIn('public_code', $codes)->get()->keyBy('public_code'),
            'leadCounts' => ['in_work' => Lead::query()->where('status', 'in_work')->count(), 'clicked' => Lead::query()->where('status', 'clicked')->where('created_at', '>=', now()->subDays(3))->count()],
            'names' => User::query()->whereIn('id', $leads->pluck('taken_by')->push($lead?->taken_by)->filter())->pluck('name', 'id')];
    }

    private function stateData(): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $oldest = DB::table('outbox_events')->where('organization_id', $org)->whereNull('processed_at')->min('created_at');
        $synced = Cache::get('site:synced:'.$org);

        return [
            'lagSeconds' => $oldest ? (int) now()->diffInSeconds(Carbon::parse($oldest), true) : 0,
            'pending' => DB::table('outbox_events')->where('organization_id', $org)->whereNull('processed_at')->count(),
            'failed' => DB::table('outbox_events')->where('organization_id', $org)->whereNull('processed_at')->where('attempts', '>', 0)->count(),
            'synced' => $synced ? Carbon::parse($synced) : null,
            'onSite' => Listing::query()->where('public_status', '!=', 'sold')->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state'),
            'accessories' => SiteAccessory::query()->count(),
            'runs' => DB::table('storefront_runs')->where('organization_id', $org)->latest('id')->limit(5)->get(),
        ];
    }

    private function statsData(): array
    {
        $from = now()->subDays(6)->toDateString();
        $stats = DB::table('site_stats')->where('organization_id', CurrentOrganization::instance()->requireId())->where('day', '>=', $from);
        $leads = Lead::query()->where('created_at', '>=', now()->subDays(7));

        return [
            'views' => (clone $stats)->where('kind', 'view')->sum('count'),
            'clicks' => (clone $leads)->count(),
            'inWork' => (clone $leads)->whereIn('status', ['in_work', 'closed'])->whereNotNull('taken_by')->count(),
            'bought' => (clone $leads)->where('outcome', 'bought')->count(),
            'top' => (clone $stats)->where('kind', 'view')->selectRaw('key, sum(count) as n')->groupBy('key')->orderByDesc('n')->limit(10)->get(),
            'misses' => (clone $stats)->where('kind', 'miss')->selectRaw('key, sum(count) as n')->groupBy('key')->orderByDesc('n')->limit(15)->get(),
            'silent' => DB::table('site_stats')->where('organization_id', CurrentOrganization::instance()->requireId())->where('kind', 'view')->where('day', '>=', now()->subDays(13)->toDateString())
                ->selectRaw('key, sum(count) as n')->groupBy('key')->havingRaw('sum(count) > 50')->get()
                ->filter(fn ($r) => ! Lead::query()->where('public_code', $r->key)->where('created_at', '>=', now()->subDays(14))->exists())->values(),
        ];
    }
}
