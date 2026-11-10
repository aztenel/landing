<?php

namespace App\Filament\Money\Pages;

use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Models\Expense;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\MoneyArticle;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э21 «Расходы» (/expenses): list for a period with the sum by articles (vs the previous month), «+ Расход» — article,
 * sum, from which account (a seller — the cash of the store within the limit; above — a request), description.
 * «Комиссия банка» is created only by the system and is not offered.
 */
class Expenses extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = ['expense.create', 'expense.bank', 'expense.articles'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Accounting;

    protected static ?string $navigationIcon = 'receipt';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'expenses';

    protected static string $view = 'money.pages.expenses';

    public string $month = '';

    public ?array $form = null;

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Расходы');
    }

    public function getTitle(): string
    {
        return __('Расходы');
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function newExpense(): void
    {
        $this->form = ['article_id' => null, 'amount' => '', 'account_id' => null, 'description' => ''];
        $this->error = null;
    }

    public function save(): void
    {
        $this->error = null;
        $f = $this->form;
        try {
            $article = MoneyArticle::query()->where('kind', 'expense')->findOrFail((int) $f['article_id']);
            $account = $this->accounts()->firstWhere('id', (int) $f['account_id']) ?? throw new \InvalidArgumentException(__('Выберите, откуда платим'));
            if ($article->code === 'other' && trim((string) $f['description']) === '') {
                throw new \InvalidArgumentException(__('Для статьи «Другое» напишите, на что'));
            }
            $amount = (int) round((float) str_replace([' ', ','], ['', '.'], (string) $f['amount']) * 100);
            $d = ExpenseDraft::make($article, WorkingStore::get(auth()->user()))->pay($account, Money::of($amount, $account->currency->value));
            if (trim((string) $f['description']) !== '') {
                $d->description(trim($f['description']));
            }
            $doc = $d->post(auth()->user());
            $this->flash = __('Расход записан: :n', ['n' => $doc->number]);
            $this->form = null;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error = __('Выберите статью');
        }
    }

    protected function accounts()
    {
        $stores = WorkingStore::available(auth()->user())->pluck('id');

        return MoneyAccount::query()->where('is_active', true)->whereIn('type', ['cash', 'bank', 'safe'])
            ->where(fn ($q) => $q->whereIn('store_id', $stores)->orWhere(fn ($w) => $w->whereNull('store_id')->when(! Access::allows('expense.bank'), fn ($x) => $x->whereRaw('false'))))->get();
    }

    protected function getViewData(): array
    {
        [$y, $m] = array_map('intval', explode('-', $this->month ?: now()->format('Y-m')));
        $from = now()->setDate($y, $m, 1)->startOfDay();
        $docs = Document::query()->where('type', 'expense')->where('status', 'posted')->whereBetween('occurred_at', [$from, $from->copy()->endOfMonth()])
            ->whereIn('store_id', WorkingStore::available(auth()->user())->pluck('id'))->latest('occurred_at')->get();
        $expenses = Expense::query()->whereIn('document_id', $docs->pluck('id'))->get()->keyBy('document_id');
        $prevDocs = Document::query()->where('type', 'expense')->where('status', 'posted')->whereBetween('occurred_at', [$from->copy()->subMonth(), $from->copy()->subSecond()])->pluck('id');
        $prev = Expense::query()->whereIn('document_id', $prevDocs)->get()->groupBy('article_id')->map->sum('total_kzt');

        return [
            'docs' => $docs, 'expenses' => $expenses,
            'byArticle' => $expenses->groupBy('article_id')->map->sum('total_kzt')->sortDesc(),
            'prev' => $prev,
            'articles' => MoneyArticle::query()->where('kind', 'expense')->where('is_active', true)->where('code', '<>', 'bank_fee')->orderBy('sort')->get(['id', 'name_ru', 'code']),
            'articleNames' => MoneyArticle::query()->pluck('name_ru', 'id'),
            'accounts' => $this->accounts(),
            'users' => \App\Models\User::query()->whereIn('id', $docs->pluck('posted_by'))->pluck('name', 'id'),
            'canCreate' => ! Access::instance()->decision('expense.create')->isDenied(),
        ];
    }
}
