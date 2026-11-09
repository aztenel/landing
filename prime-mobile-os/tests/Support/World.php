<?php

namespace Tests\Support;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryCategory;
use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\Family;
use App\Domain\Catalog\Models\Memory;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\AccessoryBalance;
use App\Domain\Inventory\Models\AccessoryCost;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Models\AccountBalance;
use App\Domain\Ledger\Models\CounterpartyBalance;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Models\User;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Test fixture: one ready organization in the current context.
 */
final class World
{
    private static int $imeiSeq = 0;

    public Organization $org;

    public Store $store;

    public User $owner;

    public User $admin;

    public User $seller;

    public User $seller2;

    public User $accountant;

    public Supplier $supplier;

    public Customer $customer;

    public Variant $iphone;

    public Variant $usedIphone;

    public Accessory $case;

    public Accessory $glass;

    public static function create(array $settings = [], string $name = 'Prime Mobile'): self
    {
        $w = new self;
        $provisioner = app(OrganizationProvisioner::class);
        $w->owner = User::factory()->create(['name' => 'Кайрат Ахметов']);
        $w->org = $provisioner->create($name, 'ТРЦ «Керуен»', $w->owner, $settings);
        CurrentOrganization::instance()->set($w->org);
        $w->store = Store::query()->firstOrFail();

        $w->admin = User::factory()->create(['name' => 'Ержан Админ']);
        $w->seller = User::factory()->create(['name' => 'Айдар Продавец']);
        $w->seller2 = User::factory()->create(['name' => 'Дана Продавец']);
        $w->accountant = User::factory()->create(['name' => 'Гульнара Бухгалтер']);
        $provisioner->addMember($w->org, $w->admin, RoleTemplates::ADMIN);
        $provisioner->addMember($w->org, $w->seller, RoleTemplates::SELLER, [$w->store->id]);
        $provisioner->addMember($w->org, $w->seller2, RoleTemplates::SELLER, [$w->store->id]);
        $provisioner->addMember($w->org, $w->accountant, RoleTemplates::ACCOUNTANT);

        $w->supplier = Supplier::query()->create(['name' => 'Dubai Mobile']);
        $w->customer = Customer::query()->create(['name' => 'Ерлан', 'phone' => '+77011112233']);

        $type = ProductType::query()->firstOrCreate(['code' => 'smartphone'], ['name_ru' => 'Смартфон']);
        $family = Family::query()->firstOrCreate(['product_type_id' => $type->id, 'name' => 'iPhone'], ['brand' => 'Apple']);
        $m15 = ProductModel::query()->firstOrCreate(['family_id' => $family->id, 'name_ru' => 'iPhone 15 Pro']);
        $m13 = ProductModel::query()->firstOrCreate(['family_id' => $family->id, 'name_ru' => 'iPhone 13']);
        $mem256 = Memory::query()->firstOrCreate(['name' => '256 ГБ'], ['gb' => 256]);
        $mem128 = Memory::query()->firstOrCreate(['name' => '128 ГБ'], ['gb' => 128]);
        $black = Color::query()->firstOrCreate(['name_ru' => 'Black Titanium']);
        $midnight = Color::query()->firstOrCreate(['name_ru' => 'Midnight']);
        $w->iphone = Variant::findOrCreateFor($m15->id, $mem256->id, $black->id);
        $w->usedIphone = Variant::findOrCreateFor($m13->id, $mem128->id, $midnight->id);
        $cat = AccessoryCategory::query()->firstOrCreate(['name_ru' => 'Чехлы']);
        $w->case = Accessory::query()->firstOrCreate(['sku' => 'CASE-15P'], ['name_ru' => 'Чехол iPhone 15 Pro', 'category_id' => $cat->id, 'barcode' => '4600000000011']);
        $w->glass = Accessory::query()->firstOrCreate(['sku' => 'GLASS-15P'], ['name_ru' => 'Стекло iPhone 15 Pro', 'category_id' => $cat->id, 'barcode' => '4600000000028']);

        return $w;
    }

    public function rate(string $rate = '515', ?\DateTimeInterface $validFrom = null): void
    {
        ExchangeRate::query()->create(['currency' => 'USD', 'rate' => $rate, 'valid_from' => $validFrom ?? now()->subHour(), 'set_by' => $this->owner->id]);
    }

    public function account(string $code): MoneyAccount
    {
        return MoneyAccount::byCode($code);
    }

    /** Balance copy of an account in minor units. */
    public function balance(string $code): int
    {
        return (int) (AccountBalance::query()->where('account_id', $this->account($code)->id)->value('balance') ?? 0);
    }

    public function debt(string $type, int $id, string $currency = 'KZT'): ?CounterpartyBalance
    {
        return CounterpartyBalance::query()->where('counterparty_type', $type)->where('counterparty_id', $id)->where('currency', $currency)->first();
    }

    public function bonuses(Customer $customer): ?CustomerBonusBalance
    {
        return CustomerBonusBalance::query()->where('customer_id', $customer->id)->first();
    }

    public function accessoryQty(Accessory $a): int
    {
        return (int) (AccessoryBalance::query()->where('accessory_id', $a->id)->where('location_type', 'store')->where('location_id', $this->store->id)->value('qty') ?? 0);
    }

    public function accessoryAvg(Accessory $a): int
    {
        return (int) (AccessoryCost::query()->where('accessory_id', $a->id)->value('avg_cost_kzt') ?? 0);
    }

    public static function imei(): string
    {
        self::$imeiSeq++;

        return Imei::withCheckDigit('35'.str_pad((string) (600000000000 + self::$imeiSeq * 7919), 12, '0', STR_PAD_LEFT));
    }

    /** A phone in stock via an opening balance document, with a fresh «registered» IMEI check. */
    public function phone(?Variant $variant = null, ?Money $cost = null, array $attributes = [], bool $imeiCheck = true): StockUnit
    {
        $imei = self::imei();
        $doc = OpeningBalanceDraft::make($this->store)
            ->unit($variant ?? $this->iphone, $imei, $cost ?? Money::kzt(489250), $attributes + ['supplier_id' => $this->supplier->id, 'sale_price' => Money::kzt(549000)])
            ->post($this->owner);
        $unit = StockUnit::findByIdentifier($imei);
        if ($imeiCheck) {
            ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $imei, 'result' => 'registered', 'checked_by' => $this->owner->id, 'checked_at' => now()]);
        }

        return $unit->refresh();
    }

    public function accessories(Accessory $accessory, int $qty, Money $unitCost): Document
    {
        return OpeningBalanceDraft::make($this->store)->accessory($accessory, $qty, $unitCost)->post($this->owner);
    }

    public function cash(Money $amount, string $code = 'cash_kzt'): Document
    {
        return OpeningBalanceDraft::make($this->store)->money($this->account($code), $amount)->post($this->owner);
    }
}
