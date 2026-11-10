<?php

namespace App\Filament\Reports\Pages;

use App\Domain\Reports\Catalog\Registry;
use App\Domain\Reports\Catalog\Report;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * Э30 «Отчёты» (TZ 06 §1.4): tabs Продажи · Склад · Деньги · Долги · Клиенты и бонусы · Контроль; one frame for every
 * report — filters in the address, tiles, table with sorting and a total row, «Excel». Closed columns never leave the
 * server (Report::redact). Opening a report with cost or profit is written to the activity log (§1.3 п. 3).
 */
class Reports extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = ['report.sales', 'report.profit', 'report.stock_value', 'report.aging', 'report.used', 'report.approvals', 'debt.customer_report', 'debt.supplier_view', 'audit.view'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Accounting;

    protected static ?string $navigationIcon = 'chart';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'reports';

    protected static string $view = 'reports.pages.reports';

    public string $r = '';

    public string $period = 'month';

    public ?string $from = null;

    public ?string $to = null;

    public ?int $store = null;

    public string $dir = '';

    public ?int $seller = null;

    public string $cond = '';

    public string $group = 'day';

    public string $sort = '';

    public bool $desc = true;

    public bool $all = false;

    protected $queryString = ['r', 'period' => ['except' => 'month'], 'from', 'to', 'store', 'dir' => ['except' => ''], 'seller', 'cond' => ['except' => ''], 'group' => ['except' => 'day']];

    public static function getNavigationLabel(): string
    {
        return __('Отчёты');
    }

    public function getTitle(): string
    {
        return __('Отчёты');
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return auth()->check() && Registry::allowed() !== [];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $allowed = Registry::allowed();
        if (! collect($allowed)->contains(fn ($x) => $x->key() === $this->r)) {
            $this->r = $allowed[0]?->key() ?? '';
        }
    }

    public function updated(string $name): void
    {
        if ($name === 'r') {
            $this->sort = '';
            $this->all = false;
        }
    }

    public function sortBy(string $col): void
    {
        $this->desc = $this->sort === $col ? ! $this->desc : true;
        $this->sort = $col;
    }

    public function query(): array
    {
        return array_filter(['store' => $this->store, 'dir' => $this->dir, 'seller' => $this->seller, 'cond' => $this->cond, 'group' => $this->group], fn ($v) => $v !== null && $v !== '');
    }

    /** Built and redacted result of the current report, sorted. Also used by the Excel export. */
    public static function result(Report $report, User $user, array $q): array
    {
        $tz = CurrentOrganization::instance()->require()->timezone;
        $p = Period::make($q['period'] ?? 'month', $tz, $q['from'] ?? null, $q['to'] ?? null);
        $res = Report::redact($report->build($p, Scope::for($user, $q), $q));
        if (in_array('period', $report->filters(), true) && (Access::allows('stock.view_cost') || Access::allows('profit.view'))) {
            activity('reports')->causedBy($user)->withProperties(['report' => $report->code(), 'period' => $p->label(), 'filters' => $q])->log('report.opened');
        }

        return $res + ['period' => $p];
    }

    protected function getViewData(): array
    {
        $report = Registry::find($this->r);
        abort_unless($report && $report->allowed(), 403);
        $q = $this->query() + ['period' => $this->period, 'from' => $this->from, 'to' => $this->to];
        $res = self::result($report, auth()->user(), $q);
        if ($this->sort && isset($res['columns'][$this->sort])) {
            usort($res['rows'], fn ($a, $b) => ($this->desc ? -1 : 1) * (($a[$this->sort] ?? null) <=> ($b[$this->sort] ?? null)));
        }

        return [
            'report' => $report, 'res' => $res, 'reports' => collect(Registry::allowed())->groupBy(fn ($x) => $x->tab()),
            'stores' => WorkingStore::available(auth()->user()), 'sellers' => User::query()->whereIn('id', DB::table('sales')->distinct()->select('seller_id'))->orderBy('name')->pluck('name', 'id'),
            'exportUrl' => Access::allows('report.export') ? route('erp.reports.export', ['r' => $this->r] + $q) : null,
            'rowsShown' => $this->all ? 5000 : 50,
        ];
    }
}
