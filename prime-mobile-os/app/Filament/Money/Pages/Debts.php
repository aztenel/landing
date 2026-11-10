<?php

namespace App\Filament\Money\Pages;

use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Debts\Drafts\DebtPaymentDraft;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\PaymentMethod;
use App\Filament\Shell\Concerns\HasErpAccess;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Ui\Access;
use App\Support\Ui\MenuGroup;
use App\Support\Ui\WorkingStore;
use Filament\Pages\Page;

/**
 * Э19 «Долги» (/debts): «Должны нам» (customers, payment with the method of the firm), «Мы должны» (suppliers in the
 * currency of the debt, payment from an account — admin and owner), «Авансы клиентов». Deadline colours as in sfon.
 */
class Debts extends Page
{
    use HasErpAccess;

    protected static string|array|null $permission = ['debt.customer_pay', 'debt.supplier_view'];

    protected static ?MenuGroup $menuGroup = MenuGroup::Accounting;

    protected static ?string $navigationIcon = 'wallet';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'debts';

    protected static string $view = 'money.pages.debts';

    public string $tabName = 'customers';

    public ?array $pay = null;

    public ?string $error = null;

    public ?string $flash = null;

    public static function getNavigationLabel(): string
    {
        return __('Долги');
    }

    public function getTitle(): string
    {
        return __('Долги');
    }

    public function payCustomer(int $customerId): void
    {
        $this->pay = ['kind' => 'customer', 'id' => $customerId, 'amount' => '', 'method' => 'cash', 'account_id' => null, 'currency' => 'KZT'];
        $this->error = null;
    }

    public function paySupplier(int $supplierId, string $currency): void
    {
        Access::authorize('debt.supplier');
        $this->pay = ['kind' => 'supplier', 'id' => $supplierId, 'amount' => '', 'method' => null, 'account_id' => null, 'currency' => $currency];
        $this->error = null;
    }

    public function submit(): void
    {
        $this->error = null;
        $p = $this->pay;
        try {
            $store = WorkingStore::get(auth()->user());
            $minor = (int) round((float) str_replace([' ', ','], ['', '.'], (string) $p['amount']) * 100);
            if ($p['kind'] === 'customer') {
                $method = PaymentMethod::resolve($p['method'], $store->id) ?? throw new \InvalidArgumentException(__('Способ оплаты выключен'));
                $doc = DebtPaymentDraft::fromCustomer((int) $p['id'], (int) $method->account_id, Money::kztMinor(intdiv($minor, 100) * 100), $store)->post(auth()->user());
            } else {
                $account = MoneyAccount::query()->findOrFail((int) $p['account_id']);
                $doc = DebtPaymentDraft::toSupplier((int) $p['id'], $account, Money::of($minor, $account->currency->value), Currency::from($p['currency']), null, $store)->post(auth()->user());
            }
            $this->flash = __('Оплата проведена: :n', ['n' => $doc->number]);
            $this->pay = null;
        } catch (UserFacingException|\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->error = __('Выберите счёт');
        }
    }

    protected function getViewData(): array
    {
        $balances = CounterpartyBalance::query()->where('balance', '<>', 0)->get();
        $customers = $balances->where('counterparty_type', 'customer');
        $suppliers = Access::allows('debt.supplier_view') ? $balances->where('counterparty_type', 'supplier') : collect();
        $rate = (float) (ExchangeRate::query()->where('currency', 'USD')->where('valid_from', '<=', now())->orderByDesc('valid_from')->value('rate') ?? 0);
        $owed = $customers->where('balance', '>', 0);

        return [
            'owed' => $owed->sortBy(fn ($b) => $b->nearest_due_date?->timestamp ?? PHP_INT_MAX),
            'advances' => $customers->where('balance', '<', 0),
            'weOwe' => $suppliers->where('balance', '<', 0),
            'names' => Customer::query()->whereIn('id', $customers->pluck('counterparty_id'))->get(['id', 'name', 'phone'])->keyBy('id'),
            'supplierNames' => Supplier::query()->whereIn('id', $suppliers->pluck('counterparty_id'))->pluck('name', 'id'),
            'totalOwed' => (int) $owed->sum('balance'),
            'overdue' => $owed->filter(fn ($b) => $b->nearest_due_date && $b->nearest_due_date->lt(today())),
            'weOweKzt' => (int) $suppliers->where('balance', '<', 0)->where('currency', 'KZT')->sum('balance'),
            'weOweUsd' => (int) $suppliers->where('balance', '<', 0)->where('currency', 'USD')->sum('balance'),
            'rate' => $rate,
            'methods' => PaymentMethod::query()->where('is_enabled', true)->whereNotIn('code', ['debt', 'bonus', 'tradein', 'prepayment', 'exchange_offset', 'kaspi_shop'])->get(['code', 'name_ru'])->unique('code'),
            'accounts' => MoneyAccount::query()->whereIn('type', ['cash', 'bank', 'safe'])->where('is_active', true)->get(['id', 'name', 'currency']),
            'canSupplier' => Access::allows('debt.supplier_view'),
            'canPaySupplier' => Access::allows('debt.supplier'),
        ];
    }
}
