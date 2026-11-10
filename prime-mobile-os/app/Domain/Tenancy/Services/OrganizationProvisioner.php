<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Money\Enums\AccountType;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Tenancy\Access\RoleProvisioner;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\OrganizationSettings;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates an organization ready to work (integrator action, TZ 03 §13 p.7): role templates with limits,
 * money articles, first store with the starter accounts and payment methods (TZ 02 §11.4), owner membership.
 */
class OrganizationProvisioner
{
    public function __construct(private readonly RoleProvisioner $roles) {}

    public function create(string $name, string $storeName, ?User $owner = null, array $settings = [], array $attributes = []): Organization
    {
        return DB::transaction(function () use ($name, $storeName, $owner, $settings, $attributes) {
            new OrganizationSettings($settings); // validate
            $org = Organization::query()->create($attributes + ['name' => $name, 'settings' => $settings]);
            $this->provision($org);
            CurrentOrganization::instance()->run($org, function () use ($org, $storeName, $owner) {
                $store = Store::query()->create(['name' => $storeName, 'created_by' => $owner?->id]);
                $this->installStarterAccounts($store);
                if ($owner) {
                    $this->addMember($org, $owner, RoleTemplates::OWNER);
                }
            });

            return $org;
        });
    }

    /** Roles + limits + money articles. Idempotent. */
    public function provision(Organization $org): void
    {
        $this->roles->installFor($org);
        CurrentOrganization::instance()->run($org, function () {
            $sort = 0;
            foreach (ArticleCode::cases() as $code) {
                [$nameRu, $kind, $profit] = $code->definition();
                MoneyArticle::query()->firstOrCreate(['code' => $code->value], [
                    'name_ru' => $nameRu, 'kind' => $kind, 'affects_profit' => $profit,
                    'is_system' => $kind !== 'expense', 'sort' => $sort += 10,
                ]);
            }
        });
    }

    /**
     * Member with exactly one role (TZ 03 §2 p.3).
     *
     * @param  list<int>|null  $storeIds  stores the employee works in («Продажи» and «Финансы» marked); null = every store
     *                                   the firm has now. An empty list is refused: no store = no access (audit №10).
     * @param  array<string, int|null>  $limits  personal limits {"L1": 1000000}
     */
    public function addMember(Organization $org, User $user, string $role, ?array $storeIds = null, array $limits = [], ?string $position = null): Membership
    {
        if (! array_key_exists($role, RoleTemplates::TITLES)) {
            throw new InvalidArgumentException("Unknown role {$role}");
        }

        return CurrentOrganization::instance()->run($org, function () use ($user, $role, $storeIds, $limits, $position) {
            $membership = Membership::query()->updateOrCreate(['user_id' => $user->id], [
                'status' => MembershipStatus::Active, 'limits' => $limits ?: new \stdClass, 'position' => $position,
            ]);
            if ($role !== RoleTemplates::OWNER) {
                $storeIds ??= Store::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($storeIds === []) {
                    throw new InvalidArgumentException('An employee needs at least one store.');
                }
                $membership->stores()->whereNotIn('store_id', $storeIds)->delete();
                foreach ($storeIds as $storeId) {
                    $membership->stores()->updateOrCreate(['store_id' => $storeId], ['can_sales' => true, 'can_finance' => true]);
                }
                $membership->unsetRelation('stores');
            }
            $user->unsetRelation('roles');
            $user->syncRoles([$role]);

            return $membership;
        });
    }

    /**
     * Starter accounts of TZ 02 §11.4 for a store + payment methods pointing to them. Commission percents are empty
     * by default (question 2 of TZ 02 §15: the owner fills them in).
     *
     * @param  array<string, string>  $commissions  payment method code => percent, e.g. ['kaspi_qr' => '1.00']
     * @return array<string, MoneyAccount> by code
     */
    public function installStarterAccounts(Store $store, array $commissions = []): array
    {
        // the cash registers belong to the store (audit №52): the first store keeps the short codes, others get a suffix
        $suffix = MoneyAccount::query()->where('code', 'cash_kzt')->where('store_id', '<>', $store->id)->exists() ? '_s'.$store->id : '';
        $defs = [
            'cash_kzt'.$suffix => ["Касса ₸ — {$store->name}", AccountType::Cash, Currency::KZT, $store->id, false, false],
            'cash_usd'.$suffix => ["Касса $ — {$store->name}", AccountType::Cash, Currency::USD, $store->id, false, false],
            'safe_kzt' => ['Сейф ₸', AccountType::Safe, Currency::KZT, null, false, false],
            'safe_usd' => ['Сейф $', AccountType::Safe, Currency::USD, null, false, false],
            'kaspi_gold' => ['Kaspi Gold', AccountType::Bank, Currency::KZT, null, false, false],
            'kaspi_qr' => ['Kaspi QR', AccountType::Bank, Currency::KZT, null, false, false],
            'kaspi_pay' => ['Kaspi Pay (рассрочки)', AccountType::InstallmentBank, Currency::KZT, null, false, false],
            'kaspi_shop' => ['Kaspi Магазин', AccountType::Bank, Currency::KZT, null, false, false],
            'halyk_terminal'.$suffix => ['Терминал Halyk — '.$store->name, AccountType::Bank, Currency::KZT, $store->id, false, false],
            // technical accounts (audit №28, №34, №40): the two offsets may go below zero inside a pair of documents
            MoneyAccount::CODE_TRADEIN_OFFSET => ['Зачёт trade-in', AccountType::Technical, Currency::KZT, null, true, true],
            MoneyAccount::CODE_EXCHANGE_OFFSET => ['Зачёт обмена', AccountType::Technical, Currency::KZT, null, true, true],
            'collection_transit_kzt' => ['Инкассация в пути ₸', AccountType::Technical, Currency::KZT, null, false, true],
            'collection_transit_usd' => ['Инкассация в пути $', AccountType::Technical, Currency::USD, null, false, true],
        ];
        $accounts = [];
        $sort = 0;
        foreach ($defs as $code => [$name, $type, $currency, $storeId, $allowNegative, $hidden]) {
            $accounts[$code] = MoneyAccount::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'currency' => $currency, 'store_id' => $storeId,
                'allow_negative' => $allowNegative, 'is_hidden' => $hidden, 'sort' => $sort += 10,
            ]);
        }

        $methods = [
            PaymentMethodCode::Card->value => 'halyk_terminal'.$suffix,
            PaymentMethodCode::KaspiQr->value => 'kaspi_qr',
            PaymentMethodCode::KaspiTransfer->value => 'kaspi_gold',
            PaymentMethodCode::KaspiRed->value => 'kaspi_pay',
            PaymentMethodCode::KaspiInstallment->value => 'kaspi_pay',
            PaymentMethodCode::HalykInstallment->value => 'halyk_terminal',
            PaymentMethodCode::Debt->value => null,
            PaymentMethodCode::Bonus->value => null,
            PaymentMethodCode::TradeIn->value => MoneyAccount::CODE_TRADEIN_OFFSET,
            PaymentMethodCode::Prepayment->value => null,
            PaymentMethodCode::KaspiShop->value => 'kaspi_shop',
            PaymentMethodCode::ExchangeOffset->value => MoneyAccount::CODE_EXCHANGE_OFFSET,
        ];
        // cash of THIS store (audit №52)
        PaymentMethod::query()->updateOrCreate(['code' => PaymentMethodCode::Cash->value, 'store_id' => $store->id], [
            'name_ru' => PaymentMethodCode::Cash->label(), 'account_id' => $accounts['cash_kzt'.$suffix]->id,
            'commission_percent' => '0.00', 'is_refundable' => true, 'sort' => 0,
        ]);
        $sort = 0;
        foreach ($methods as $code => $accountCode) {
            $method = PaymentMethodCode::from($code);
            $exists = PaymentMethod::query()->where('code', $code)->whereNull('store_id')->exists();
            if ($exists) {
                continue; // a firm-wide method is created once; a second store must not reset what the owner set
            }
            PaymentMethod::query()->updateOrCreate(['code' => $code, 'store_id' => null], [
                'name_ru' => $method->label(),
                // a bank method is on only once its commission is entered (audit №52)
                'is_enabled' => ! $method->hasBankCommission() || array_key_exists($code, $commissions),
                'account_id' => $accountCode ? $accounts[$accountCode]->id : null,
                'commission_percent' => $commissions[$code] ?? '0.00',
                'is_refundable' => ! in_array($code, ['debt', 'bonus', 'tradein', 'prepayment', 'exchange_offset'], true),
                'sort' => $sort += 10,
            ]);
        }

        return $accounts;
    }
}
