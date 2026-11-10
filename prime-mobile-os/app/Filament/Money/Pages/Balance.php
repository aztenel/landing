<?php

namespace App\Filament\Money\Pages;

use App\Domain\Ledger\Models\AccountBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Money\Drafts\CashAdjustmentDraft;
use App\Domain\Money\Drafts\CashMoveDraft;
use App\Domain\Money\Drafts\FxConvertDraft;
use App\Domain\Money\Models\CashMove;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Tenancy\Models\Store;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Money;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э20 «Баланс» (/balance): accounts grouped (store / firm / in transit), the journal of an account, «Инкассация»,
 * «Перевод», «Обмен валюты», «Корректировка» (owner), incoming collections for the owner to accept.
 * Bank balances only with money.view_bank_balances; a seller sees only the cash of the store.
 */
class Balance extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = ['money.balance', 'money.collect_send'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Accounting;

    protected static ?string $navigationIcon = 'coins';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'balance';

    protected static string $view = 'money.pages.balance';

    public ?int $accountId = null;

    public ?string $modal = null;   // collection | transfer | fx | adjust | accept

    public array $f = [];

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Баланс');
    }

    public function getTitle(): string
    {
        return __('Баланс');
    }

    protected function visibleAccounts()
    {
        $user = auth()->user();
        $stores = WorkingStore::available($user)->pluck('id');
        $bank = Access::allows('money.view_bank_balances');

        return MoneyAccount::query()->where('is_active', true)->where('is_hidden', false)
            ->where(fn ($q) => $q->whereIn('store_id', $stores)->orWhere(fn ($w) => $w->whereNull('store_id')->when(! $bank, fn ($x) => $x->whereRaw('false'))))
            ->when(! Access::allows('money.balance'), fn ($q) => $q->where('type', 'cash'))
            ->orderBy('store_id')->orderBy('sort')->orderBy('id')->get();
    }

    public function show(int $id): void
    {
        $this->accountId = $this->visibleAccounts()->firstWhere('id', $id)?->id ?? abort(404);
    }

    public function openModal(string $name, ?int $id = null): void
    {
        $this->modal = $name;
        $this->error = null;
        $this->f = ['from' => null, 'to' => null, 'amount' => '', 'fee' => '', 'to_amount' => '', 'note' => '', 'owner' => false, 'id' => $id];
    }

    protected function minor(string|int|null $v): int
    {
        return (int) round((float) str_replace([' ', ','], ['', '.'], (string) $v) * 100);
    }

    public function submit(): void
    {
        $this->error = null;
        $f = $this->f;
        $user = auth()->user();
        try {
            $store = WorkingStore::get($user);
            $doc = match ($this->modal) {
                'collection' => CashMoveDraft::collection($store, (int) $f['from'], Money::of($this->minor($f['amount']), MoneyAccount::query()->findOrFail($f['from'])->currency->value))->note((string) $f['note'])->post($user),
                'transfer' => CashMoveDraft::transfer($store, (int) $f['from'], (int) $f['to'], Money::of($this->minor($f['amount']), MoneyAccount::query()->findOrFail($f['from'])->currency->value),
                    $f['fee'] !== '' ? Money::of($this->minor($f['fee']), MoneyAccount::query()->findOrFail($f['from'])->currency->value) : null)->note((string) $f['note'])->post($user),
                'fx' => FxConvertDraft::make((int) $f['from'], Money::of($this->minor($f['amount']), MoneyAccount::query()->findOrFail($f['from'])->currency->value),
                    (int) $f['to'], Money::of($this->minor($f['to_amount']), MoneyAccount::query()->findOrFail($f['to'])->currency->value), $store)->post($user),
                'adjust' => CashAdjustmentDraft::make($store, (int) $f['from'], Money::of($this->minor($f['amount']), MoneyAccount::query()->findOrFail($f['from'])->currency->value), (string) $f['note'])->post($user),
                'accept' => CashMoveDraft::acceptCollection($store, (int) $f['id'], Money::of($this->minor($f['amount']), 'KZT'), (bool) $f['owner'])->note((string) $f['note'])->post($user),
                default => null,
            };
            $this->flash = $doc ? __('Проведено: :n', ['n' => $doc->number]) : null;
            $this->modal = null;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error = __('Выберите счета');
        }
    }

    protected function getViewData(): array
    {
        $accounts = $this->visibleAccounts();
        $balances = AccountBalance::query()->whereIn('account_id', $accounts->pluck('id'))->pluck('balance', 'account_id');
        $rate = (float) (ExchangeRate::query()->where('currency', 'USD')->where('valid_from', '<=', now())->orderByDesc('valid_from')->value('rate') ?? 0);
        $total = 0;
        foreach ($accounts as $a) {
            $b = (int) ($balances[$a->id] ?? 0);
            $total += $a->currency->value === 'USD' ? (int) round($b * $rate) : $b;
        }
        $pending = Access::allows('money.collect_receive')
            ? CashMove::query()->where('kind', 'collection')->where('status', 'sent')->whereIn('document_id', Document::query()->where('status', 'posted')->select('id'))->get()
            : collect();

        return [
            'groups' => $accounts->groupBy(fn ($a) => $a->store_id ? 'store:'.$a->store_id : ($a->type->value === 'technical' ? 'transit' : 'firm')),
            'storeNames' => Store::query()->pluck('name', 'id'),
            'balances' => $balances,
            'rate' => $rate,
            'total' => $total,
            'account' => $this->accountId ? $accounts->firstWhere('id', $this->accountId) : null,
            'entries' => $this->accountId ? MoneyEntry::query()->where('account_id', $this->accountId)->latest('id')->limit(100)->get() : collect(),
            'articles' => MoneyArticle::query()->pluck('name_ru', 'id'),
            'docs' => $this->accountId ? Document::query()->whereIn('id', MoneyEntry::query()->where('account_id', $this->accountId)->latest('id')->limit(100)->select('document_id'))->pluck('number', 'id') : collect(),
            'pending' => $pending,
            'pendingDocs' => Document::query()->whereIn('id', $pending->pluck('document_id'))->get()->keyBy('id'),
            'all' => $accounts,
            'can' => ['collect' => Access::allows('money.collect_send'), 'transfer' => Access::allows('money.transfer'), 'adjust' => ! Access::instance()->decision('money.adjust')->isDenied()],
        ];
    }
}
