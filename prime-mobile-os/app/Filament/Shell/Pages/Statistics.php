<?php

namespace App\Filament\Shell\Pages;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Domain\Reports\Services\Todo;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\DB;

/**
 * Э2 «Статистика» at the root «/» (TZ 04 Э2, макеты erp-1-statistika.png, iphone-vladelec-1-statistika.png).
 * Figures — App\Domain\Reports\Services\Metrics (the same formula as reports and Excel). A seller without report
 * rights sees «Мои продажи» (Э2.4): his checks today, by payment method, no profit.
 */
class Statistics extends Dashboard
{
    use HasErpAccess;

    protected static string|array|null $permission = ['report.dashboard', 'report.own'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Work;

    protected static ?string $navigationIcon = 'chart';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'shell.pages.statistics';

    public string $period = 'today';

    public ?string $from = null;

    public ?string $to = null;

    public string $dir = '';

    public ?int $store = null;

    protected $queryString = ['period' => ['except' => 'today'], 'from', 'to', 'dir' => ['except' => ''], 'store'];

    public static function getNavigationLabel(): string
    {
        return __('Статистика');
    }

    public function getTitle(): string
    {
        return __('Статистика');
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [];
    }

    public static function full(): bool
    {
        return Access::allows('report.dashboard') || Access::allows('report.sales');
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $org = CurrentOrganization::instance()->require();
        $tz = $org->timezone;
        $full = self::full();
        $p = Period::make($this->period, $tz, $this->from, $this->to);
        $scope = Scope::for($user, ['store' => $this->store, 'dir' => $this->dir, 'seller' => $full ? null : $user->id]);
        $m = app(Metrics::class);
        $facts = $m->facts($p, $scope);
        $t = $m->totals($p, $scope, $facts);
        $prev = $m->totals($p->previous(), $scope);
        $profit = $full && Access::allows('profit.view');

        // chart: the last 14 days for «today/yesterday», else the period itself
        $chartP = in_array($p->key, ['today', 'yesterday'], true) ? Period::between($p->to->subDays(13)->startOfDay(), $p->to) : $p;
        $bucket = $chartP->bucket();
        $byDay = $m->by($chartP === $p ? $facts : $m->facts($chartP, $scope), fn ($r) => Metrics::bucketKey($r->occurred_at, $bucket, $tz));
        $bars = [];
        for ($d = $chartP->from; $d->lte($chartP->to); $d = $bucket === 'day' ? $d->addDay() : ($bucket === 'week' ? $d->addWeek() : $d->addMonth())) {
            $k = $bucket === 'week' ? $d->startOfWeek()->format('Y-m-d') : ($bucket === 'month' ? $d->format('Y-m') : $d->format('Y-m-d'));
            $bars[$k] ??= ['label' => $bucket === 'month' ? $d->translatedFormat('M') : $d->format('d'), 'date' => $d,
                'revenue' => $byDay[$k]['revenue'] ?? 0, 'gross' => $byDay[$k]['gross'] ?? 0, 'checks' => $byDay[$k]['checks'] ?? 0];
        }

        $sellers = $m->by($facts, 'seller_id')->sortByDesc('revenue');
        $names = User::query()->whereIn('id', $sellers->keys()->filter())->pluck('name', 'id');
        $recent = DB::table('documents as d')->join('sales as s', 's.document_id', '=', 'd.id')
            ->where('d.organization_id', $org->id)->where('d.type', 'sale')->where('d.status', 'posted')->whereIn('d.store_id', $scope->storeIds ?: [0])
            ->when(! $full, fn ($q) => $q->where('s.seller_id', $user->id))
            ->orderByDesc('d.occurred_at')->limit($full ? 5 : 20)->get(['d.id', 'd.number', 'd.occurred_at', 's.seller_id', 's.total']);
        $lines = DB::table('sale_lines as sl')->leftJoin('stock_units as su', 'su.id', '=', 'sl.stock_unit_id')
            ->leftJoin('variants as v', 'v.id', '=', 'su.variant_id')->leftJoin('accessories as a', 'a.id', '=', 'sl.accessory_id')
            ->whereIn('sl.document_id', $recent->pluck('id'))->orderBy('sl.line_no')->get(['sl.document_id', 'sl.line_type', 'sl.qty', 'su.condition', 'v.id as variant_id', 'a.name_ru'])->groupBy('document_id');
        $variants = Variant::query()->with(['productModel', 'memory', 'color'])->whereIn('id', $lines->flatten()->pluck('variant_id')->filter())->get()->keyBy('id');
        $pays = DB::table('sale_payments')->whereIn('document_id', $recent->pluck('id'))->get(['document_id', 'method'])->groupBy('document_id');
        $sellerNames = User::query()->whereIn('id', $recent->pluck('seller_id')->merge($sellers->keys())->filter())->pluck('name', 'id');

        $todayLabel = CarbonImmutable::now($tz)->locale('ru')->translatedFormat('l, j F');

        return [
            'full' => $full, 'profit' => $profit, 'p' => $p, 't' => $t, 'prev' => $prev, 'tz' => $tz,
            'cash' => $full && Access::allows('money.balance') ? $m->cash($scope->storeIds) : null,
            'bars' => array_values($bars), 'barMax' => max([1, ...array_map(fn ($b) => $b['revenue'], array_values($bars))]),
            'avgDay' => count($bars) ? intdiv(array_sum(array_column($bars, 'revenue')), max(1, count(array_filter($bars, fn ($b) => $b['revenue'] !== 0)) ?: 1)) : 0,
            'bucket' => $bucket,
            'payments' => $m->payments($p, $scope),
            'sellers' => $sellers, 'sellerNames' => $sellerNames,
            'recent' => $recent, 'lines' => $lines, 'variants' => $variants, 'pays' => $pays,
            'todo' => $full && ! (Access::allows('report.dashboard') && ! Access::allows('approval.decide') && ! Access::isOwner()) ? app(Todo::class)->items($scope->storeIds) : null,
            'stores' => WorkingStore::available($user),
            'dateLine' => mb_strtoupper(mb_substr($todayLabel, 0, 1)).mb_substr($todayLabel, 1),
            'pending' => Access::allows('approval.decide') ? ApprovalRequest::query()->where('status', 'pending')->latest('id')->limit(3)->get() : collect(),
        ];
    }
}
