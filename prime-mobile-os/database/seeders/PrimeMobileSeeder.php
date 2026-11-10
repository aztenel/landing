<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Accessory;
use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Customer;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Debts\Drafts\DebtPaymentDraft;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Drafts\FxConvertDraft;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\ExchangeRate;
use App\Domain\Money\Models\MoneyAccount;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\RoleTemplates;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Models\User;
use App\Support\Imei\Imei;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo organization «Prime Mobile» with data close to the design screens (docs/design/screens):
 * ~43 phones (new and used with grades/battery), accessories, money, debts, bonuses — all entered through real
 * documents and the Poster (opening balance 45 days ago, purchases, ~24 sales over the last 14 days, expenses,
 * a supplier payment, a conversion), so statistics have consistent journals behind them.
 *
 * Logins (password «password»): Кайрат +77010000001 (Владелец), Айгерим +77010000002, Данияр +77010000003 (Продавцы).
 */
class PrimeMobileSeeder extends Seeder
{
    private const TZ = 'Asia/Almaty';

    private Organization $org;

    private Store $store;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<int, StockUnit> phone number in PHONES => unit */
    private array $units = [];

    /** Real «today» in Almaty, fixed before time travel starts. */
    private CarbonImmutable $today;

    /**
     * n => [model, memory, color, ram, condition, grade, battery, cost currency, cost (major units), sale price ₸, check IMEI]
     * Origin: 1–14 opening balance, 15–22 purchase P1, 23–28 P2, 29–34 P3, 35–39 P4, 40–43 P5.
     */
    private const PHONES = [
        1 => ['iPhone 13', '128 ГБ', 'Midnight', null, 'used', 'A', 89, 'KZT', 165000, 214000, true],
        2 => ['iPhone 14 Pro', '256 ГБ', 'Deep Purple', null, 'used', 'B', 84, 'KZT', 262000, 329000, true],
        3 => ['iPhone 15 Pro Max', '256 ГБ', 'Natural Titanium', null, 'used', 'A', 93, 'KZT', 410000, 489000, true],
        4 => ['iPhone 16', '128 ГБ', 'Ultramarine', null, 'new', null, null, 'USD', 790, 459000, true],
        5 => ['iPad Air 11 M2', '128 ГБ', 'Space Gray', null, 'new', null, null, 'USD', 560, 329000, true],
        6 => ['iPhone 15', '128 ГБ', 'Black', null, 'new', null, null, 'USD', 660, 389000, true],
        7 => ['iPhone 14', '128 ГБ', 'Midnight', null, 'new', null, null, 'USD', 590, 349000, true],
        8 => ['iPhone 13', '256 ГБ', 'Starlight', null, 'used', 'A', 91, 'KZT', 175000, 229000, true],
        9 => ['Galaxy A35', '128 ГБ', 'Navy', '8 ГБ', 'new', null, null, 'USD', 280, 159000, true],
        10 => ['iPhone 15 Pro', '256 ГБ', 'Black Titanium', null, 'used', 'A', 95, 'KZT', 380000, 449000, true],
        11 => ['iPhone 16e', '128 ГБ', 'Black', null, 'new', null, null, 'USD', 520, 299000, true],
        12 => ['iPhone 14 Pro Max', '256 ГБ', 'Gold', null, 'used', 'B', 86, 'KZT', 330000, 399000, true],
        13 => ['Redmi Note 14', '128 ГБ', 'Black', '8 ГБ', 'new', null, null, 'USD', 170, 99900, true],
        14 => ['iPhone 15', '256 ГБ', 'Blue', null, 'new', null, null, 'USD', 720, 429000, true],
        15 => ['iPhone 16 Pro', '256 ГБ', 'Black Titanium', null, 'new', null, null, 'USD', 1140, 649000, true],
        16 => ['iPhone 16 Pro', '256 ГБ', 'White Titanium', null, 'new', null, null, 'USD', 1140, 649000, true],
        17 => ['iPhone 16 Pro Max', '256 ГБ', 'Desert Titanium', null, 'new', null, null, 'USD', 1290, 729000, true],
        18 => ['iPhone 16 Pro Max', '512 ГБ', 'Natural Titanium', null, 'new', null, null, 'USD', 1480, 849000, true],
        19 => ['iPhone 16', '128 ГБ', 'Black', null, 'new', null, null, 'USD', 790, 459000, true],
        20 => ['iPhone 16', '128 ГБ', 'Pink', null, 'new', null, null, 'USD', 790, 459000, true],
        21 => ['Galaxy S25 Ultra', '256 ГБ', 'Titanium Black', '12 ГБ', 'new', null, null, 'USD', 1180, 659000, true],
        22 => ['Galaxy S25', '256 ГБ', 'Navy', '12 ГБ', 'new', null, null, 'USD', 780, 449000, true],
        23 => ['iPhone 15', '128 ГБ', 'Pink', null, 'new', null, null, 'KZT', 338180, 389000, true],
        24 => ['iPhone 15', '128 ГБ', 'Green', null, 'new', null, null, 'KZT', 338180, 389000, true],
        25 => ['Galaxy A55', '256 ГБ', 'Navy', '8 ГБ', 'new', null, null, 'KZT', 163970, 189000, false],
        26 => ['Galaxy A55', '128 ГБ', 'Iceblue', '8 ГБ', 'new', null, null, 'KZT', 152000, 179000, true],
        27 => ['Redmi Note 14 Pro', '256 ГБ', 'Ocean Blue', '8 ГБ', 'new', null, null, 'KZT', 140910, 164900, true],
        28 => ['iPhone 16e', '256 ГБ', 'White', null, 'new', null, null, 'KZT', 300000, 349000, true],
        29 => ['iPhone 17 Pro', '256 ГБ', 'Cosmic Orange', null, 'new', null, null, 'USD', 1350, 779000, true],
        30 => ['iPhone 17 Pro', '256 ГБ', 'Deep Blue', null, 'new', null, null, 'USD', 1350, 779000, true],
        31 => ['iPhone 17 Pro Max', '256 ГБ', 'Silver', null, 'new', null, null, 'USD', 1490, 859000, true],
        32 => ['iPhone Air', '256 ГБ', 'Sky Blue', null, 'new', null, null, 'USD', 1150, 669000, true],
        33 => ['iPhone 17', '256 ГБ', 'Lavender', null, 'new', null, null, 'USD', 880, 519000, true],
        34 => ['iPhone 17', '256 ГБ', 'Sage', null, 'new', null, null, 'USD', 880, 519000, true],
        35 => ['iPhone 16 Pro', '256 ГБ', 'Desert Titanium', null, 'new', null, null, 'USD', 1140, 649000, true],
        36 => ['iPhone 16 Pro', '256 ГБ', 'Desert Titanium', null, 'new', null, null, 'USD', 1140, 649000, true],
        37 => ['iPhone 16 Pro', '256 ГБ', 'Black Titanium', null, 'new', null, null, 'USD', 1140, 649000, true],
        38 => ['Galaxy S25 Ultra', '512 ГБ', 'Titanium Gray', '12 ГБ', 'new', null, null, 'USD', 1260, 719000, true],
        39 => ['Galaxy S25 Ultra', '512 ГБ', 'Titanium Gray', '12 ГБ', 'new', null, null, 'USD', 1260, 719000, true],
        40 => ['Redmi Note 14 Pro', '256 ГБ', 'Black', '8 ГБ', 'new', null, null, 'USD', 275, 164900, true],
        41 => ['Redmi Note 14 Pro', '256 ГБ', 'Black', '8 ГБ', 'new', null, null, 'USD', 275, 164900, false],
        42 => ['Galaxy A55', '256 ГБ', 'Navy', '8 ГБ', 'new', null, null, 'USD', 320, 189000, true],
        43 => ['iPhone 15 Pro', '128 ГБ', 'Natural Titanium', null, 'new', null, null, 'USD', 890, 499000, true],
    ];

    /** sku => [opening qty, opening cost ₸, retail price ₸] */
    private const ACCESSORY_STOCK = [
        'APP-AIRPODS-PRO2' => [8, 85000, 115000],
        'CASE-16P-SIL' => [20, 2400, 9000],
        'CASE-15-CLEAR' => [25, 1500, 6000],
        'CASE-S25U' => [10, 2200, 8000],
        'GLASS-16P' => [40, 620, 5000],
        'GLASS-15' => [40, 600, 4000],
        'GLASS-CAM' => [30, 500, 8000],
        'APP-20W' => [15, 6500, 12000],
        'CBL-C-L-1M' => [20, 2000, 7000],
        'CBL-C-C-1M' => [20, 1800, 6000],
        'PB-10000' => [10, 8000, 15000],
    ];

    public function run(): void
    {
        $this->today = CarbonImmutable::now(self::TZ);
        $provisioner = app(OrganizationProvisioner::class);
        $owner = User::query()->updateOrCreate(['phone' => '+77010000001'], ['name' => 'Кайрат Ахметов', 'password' => 'password']);
        $this->users = [
            'owner' => $owner,
            'aigerim' => User::query()->updateOrCreate(['phone' => '+77010000002'], ['name' => 'Айгерим Жумабаева', 'password' => 'password']),
            'daniyar' => User::query()->updateOrCreate(['phone' => '+77010000003'], ['name' => 'Данияр Касымов', 'password' => 'password']),
        ];

        $this->org = $provisioner->create('Prime Mobile', 'ТРЦ «Керуен»', $owner, ['accent' => 'blue'], [
            'legal_name' => 'ИП «Prime Mobile»',
            'requisites' => ['address' => 'Астана, ТРЦ «Керуен», ул. Достык, 9', 'phone' => '+7 701 000 00 01', 'instagram' => '@primemobile.kz'],
        ]);
        $provisioner->addMember($this->org, $this->users['aigerim'], RoleTemplates::SELLER, position: 'Продавец');
        $provisioner->addMember($this->org, $this->users['daniyar'], RoleTemplates::SELLER, position: 'Продавец');

        CurrentOrganization::instance()->run($this->org, function () {
            $this->store = Store::query()->firstOrFail();
            CatalogSeeder::accessoriesForCurrentOrganization();
            $this->configurePaymentMethods();
            $this->directories();
            $this->rates();
            $this->history();
            $this->reservations();
        });
        $this->at(null);
    }

    // ------------------------------------------------------------------ setup

    private function configurePaymentMethods(): void
    {
        // Demo commissions (не проверено — the owner sets real ones, TZ 02 §15 q.2)
        foreach (['card' => '1.00', 'kaspi_qr' => '0.95', 'kaspi_installment' => '12.00', 'kaspi_red' => '8.00', 'halyk_installment' => '10.00'] as $code => $pct) {
            PaymentMethod::query()->where('code', $code)->update(['commission_percent' => $pct, 'is_enabled' => true]);
        }
    }

    private function directories(): void
    {
        $this->suppliers = [
            'dubai' => Supplier::query()->create(['name' => 'Dubai Mobile', 'phone' => '+971501112233', 'contact_person' => 'Ахмед']),
            'technoopt' => Supplier::query()->create(['name' => 'ТехноОпт', 'phone' => '+77272500100', 'bin_iin' => '180940012345']),
            'master' => Supplier::query()->create(['name' => 'Сервис-мастер Нурлан', 'phone' => '+77015554433', 'is_repair_master' => true]),
        ];
        $people = [
            'erlan' => ['Ерлан', '+77011112233'], 'askhat' => ['Асхат', '+77012223344'], 'madina' => ['Мадина', '+77013334455'],
            'timur' => ['Тимур', '+77014445566'], 'aliya' => ['Алия', '+77015556677'], 'sanzhar' => ['Санжар', '+77016667788'],
            'gulmira' => ['Гульмира', '+77017778899'], 'nurlan' => ['Нурлан', '+77018889900'], 'zhanna' => ['Жанна', '+77019990011'],
        ];
        foreach ($people as $key => [$name, $phone]) {
            $this->customers[$key] = Customer::query()->create([
                'name' => $name, 'phone' => $phone, 'source' => 'pos',
                'pd_consent_at' => now(), 'pd_consent_method' => 'pos_checkbox', 'pd_consent_version' => '2026-11',
            ]);
        }

        foreach (self::ACCESSORY_STOCK as $sku => [, , $retail]) {
            AccessoryPrice::query()->create(['accessory_id' => $this->accessory($sku)->id, 'price_kzt' => Money::kzt($retail)->amount]);
        }
    }

    private function rates(): void
    {
        $todayRate = CarbonImmutable::now(self::TZ)->setTime(9, 12);
        if ($todayRate->greaterThan(CarbonImmutable::now())) {
            $todayRate = CarbonImmutable::now()->subMinute();
        }
        foreach ([
            ['508.90', $this->day(50, 9, 5)],
            ['510.50', $this->day(20, 9, 10)],
            ['511.80', $this->day(7, 9, 3)],
            ['512.40', $todayRate],
        ] as [$rate, $from]) {
            ExchangeRate::query()->create(['currency' => 'USD', 'rate' => $rate, 'valid_from' => $from, 'set_by' => $this->users['owner']->id, 'comment' => 'Курс дня']);
        }
    }

    // ------------------------------------------------------------------ the 45 days of history

    private function history(): void
    {
        $owner = $this->users['owner'];
        $aigerim = $this->users['aigerim'];
        $daniyar = $this->users['daniyar'];

        // the shop's shift is open for the whole demo history (sales and cash documents need it, audit №18)
        $this->at($this->day(45, 9, 0), fn () => app(\App\Domain\Ledger\Services\Shifts::class)->open($this->store, $owner));

        // Day X: opening balance (phones 1–14, accessories, money, debts, bonuses)
        $this->at($this->day(45, 10, 0), function () use ($owner) {
            $draft = OpeningBalanceDraft::make($this->store)->comment('Входящие остатки из sfon');
            foreach (range(1, 14) as $n) {
                $this->phoneLine($draft, $n, $this->phoneSupplier($n));
            }
            foreach (self::ACCESSORY_STOCK as $sku => [$qty, $cost]) {
                $draft->accessory($this->accessory($sku), $qty, Money::kzt($cost));
            }
            $draft->money($this->account('cash_kzt'), Money::kzt(350000))
                ->money($this->account('cash_usd'), Money::usd(2900))
                ->money($this->account('safe_kzt'), Money::kzt(3000000))
                ->money($this->account('safe_usd'), Money::usd(15000))
                ->money($this->account('kaspi_gold'), Money::kzt(2500000))
                ->customerDebt($this->customers['askhat'], Money::kzt(120000), $this->date(3))
                ->customerDebt($this->customers['timur'], Money::kzt(450000), $this->date(10))
                ->customerDebt($this->customers['nurlan'], Money::kzt(764000), $this->date(1))
                ->supplierDebt($this->suppliers['dubai'], Money::usd(8000), $this->date(-10), '505.00')
                ->supplierDebt($this->suppliers['technoopt'], Money::kzt(1200000), $this->date(-5))
                ->bonus($this->customers['madina'], 3500)
                ->bonus($this->customers['erlan'], 1200)
                ->post($owner);
            $this->checkImeis(range(1, 14), $owner);
        });

        // Purchases
        $this->at($this->day(33, 11, 30), function () use ($owner) {
            $this->purchase('dubai', Currency::USD, range(15, 22))->onCredit($this->date(3))->invoice('DM-7781')->post($owner);
            $this->checkImeis(range(15, 22), $owner);
        });
        $this->at($this->day(21, 12, 0), function () use ($owner) {
            $p = $this->purchase('technoopt', Currency::KZT, range(23, 28));
            $p->payFrom($this->account('kaspi_gold'), Money::kzt(1433240))->invoice('ТО-1024')->post($owner);
            $this->checkImeis([23, 24, 26, 27, 28], $owner);
        });
        $this->at($this->day(15, 16, 0), fn () => DebtPaymentDraft::toSupplier($this->suppliers['dubai'], $this->account('safe_usd'), Money::usd(3000), Currency::USD)->post($owner));
        $this->at($this->day(12, 10, 40), function () use ($owner) {
            $this->purchase('dubai', Currency::USD, range(29, 34))->payFrom($this->account('safe_usd'), Money::usd(3000))->onCredit($this->date(-21))->invoice('DM-7810')->post($owner);
            $this->checkImeis(range(29, 34), $owner);
        });
        $this->at($this->day(10, 10, 0), fn () => ExpenseDraft::make(ArticleCode::Rent, $this->store)->pay($this->account('kaspi_gold'), Money::kzt(450000))->description('Аренда ноябрь, ТРЦ «Керуен»')->post($owner));
        $this->at($this->day(8, 18, 30), fn () => FxConvertDraft::make($this->account('cash_usd'), Money::usd(500), $this->account('safe_kzt'), Money::kzt(255000), $this->store)->comment('Обмен в обменнике')->post($owner));
        $this->at($this->day(6, 10, 15), function () use ($owner) {
            $this->purchase('dubai', Currency::USD, range(35, 39))->onCredit($this->date(-24))->invoice('DM-7835')->post($owner);
            $this->checkImeis(range(35, 39), $owner);
        });
        $this->at($this->day(4, 15, 0), fn () => DebtPaymentDraft::fromCustomer($this->customers['askhat'], $this->account('cash_kzt'), Money::kzt(50000), $this->store)->post($daniyar));
        $this->at($this->day(3, 12, 0), fn () => ExpenseDraft::make(ArticleCode::Courier, $this->store)->pay($this->account('cash_kzt'), Money::kzt(3000))->description('Такси: доставка клиенту')->post($daniyar));
        $this->at($this->day(2, 10, 20), function () use ($owner) {
            $this->purchase('technoopt', Currency::USD, range(40, 43))->payFrom($this->account('safe_usd'), Money::usd(1760))->invoice('ТО-1077')->post($owner);
            $this->checkImeis([40, 42, 43], $owner);
        });
        $this->at($this->day(1, 10, 5), fn () => PurchaseDraft::make($this->store, $this->suppliers['technoopt'])
            ->accessory($this->accessory('CASE-16P-SIL'), 20, Money::kzt(2600))
            ->accessory($this->accessory('GLASS-16P'), 30, Money::kzt(650))
            ->onCredit($this->date(-14))->post($owner));

        // Sales of the last 14 days: [days ago, h, m, seller, customer, phones, accessories [sku => [qty, price]], discount, payments]
        $sales = [
            [13, 11, 20, $aigerim, null, [7], [], 0, [['cash', 349000, 350000]]],
            [13, 16, 40, $daniyar, null, [], ['APP-AIRPODS-PRO2' => [1, 115000]], 0, [['kaspi_qr', 115000]]],
            [12, 12, 10, $aigerim, 'erlan', [15], ['CASE-16P-SIL' => [1, 9000]], 3000, [['kaspi_qr', 200000], ['cash', 455000, 460000]]],
            [11, 15, 5, $daniyar, null, [8], [], 0, [['cash', 229000, 230000]]],
            [10, 13, 30, $aigerim, 'madina', [17], [], 0, [['kaspi_installment', 729000]]],
            [10, 18, 0, $daniyar, null, [], ['GLASS-16P' => [2, 5000]], 0, [['cash', 10000]]],
            [9, 12, 45, $daniyar, 'aliya', [9], [], 0, [['card', 159000]]],
            [8, 14, 20, $aigerim, null, [19], ['GLASS-16P' => [1, 5000]], 0, [['kaspi_qr', 464000]]],
            [7, 11, 50, $daniyar, null, [10], [], 0, [['cash', 300000], ['card', 149000]]],
            [6, 17, 15, $aigerim, 'erlan', [21], [], 0, [['kaspi_qr', 659000]]],
            [5, 13, 40, $daniyar, null, [16], [], 0, [['kaspi_installment', 649000]]],
            [5, 19, 10, $aigerim, null, [], ['CASE-15-CLEAR' => [1, 6000], 'GLASS-15' => [1, 4000]], 0, [['cash', 10000]]],
            [4, 12, 30, $aigerim, null, [13], [], 0, [['cash', 99900, 100000]]],
            [3, 16, 20, $daniyar, 'gulmira', [23], ['CASE-15-CLEAR' => [1, 6000]], 2000, [['kaspi_qr', 393000]]],
            [2, 11, 15, $aigerim, 'zhanna', [29], [], 0, [['halyk_installment', 779000]]],
            [2, 15, 0, $daniyar, null, [26], [], 0, [['cash', 179000, 180000]]],
            [1, 13, 25, $aigerim, null, [27], [], 0, [['card', 164900]]],
            [1, 17, 45, $daniyar, 'sanzhar', [33], ['APP-20W' => [1, 12000]], 0, [['kaspi_qr', 531000]]],
        ];
        foreach ($sales as [$daysAgo, $h, $m, $seller, $customer, $phones, $accessories, $discount, $payments]) {
            $this->sale($this->day($daysAgo, $h, $m), $seller, $seller, $customer, $phones, $accessories, $discount, $payments);
        }

        // Today (as on the «Статистика» screen), relative to now so nothing is in the future
        $now = CarbonImmutable::now();
        $this->sale($now->subMinutes(190), $aigerim, $aigerim, null, [40], [], 0, [['card', 164900]]);
        $this->sale($now->subMinutes(162), $owner, $daniyar, 'sanzhar', [38], [], 0, [['debt', 719000, null, 14]]);
        $this->sale($now->subMinutes(115), $aigerim, $aigerim, 'erlan', [1], [], 0, [['cash', 100000], ['kaspi_qr', 114000]]);
        $this->sale($now->subMinutes(80), $daniyar, $daniyar, null, [], ['APP-AIRPODS-PRO2' => [1, 115000], 'GLASS-CAM' => [1, 8000]], 3000, [['cash', 120000, 120000]]);
        $this->sale($now->subMinutes(40), $aigerim, $aigerim, null, [35], [], 0, [['kaspi_qr', 649000]]);
    }

    private function reservations(): void
    {
        $reservations = app(ReservationService::class);
        $reservations->reserve($this->units[36], $this->users['aigerim'], 'cart-aigerim-1');
        $reservations->reserve($this->units[2], $this->users['daniyar'], 'cart-daniyar-1');
    }

    // ------------------------------------------------------------------ helpers

    private function sale(CarbonImmutable $at, User $actor, User $seller, ?string $customer, array $phones, array $accessories, int $discount, array $payments): void
    {
        $this->at($at, function () use ($at, $actor, $seller, $customer, $phones, $accessories, $discount, $payments) {
            $draft = SaleDraft::make($this->store, $seller)->customer($customer ? $this->customers[$customer] : null);
            foreach ($phones as $n) {
                $unit = $this->units[$n];
                ImeiCheck::query()->create([
                    'stock_unit_id' => $unit->id, 'imei' => $unit->imei1 ?? $unit->serial, 'result' => ImeiCheckResult::Registered->value,
                    'source' => 'imei.rfs.gov.kz', 'checked_by' => $seller->id, 'checked_at' => $at->subMinutes(10),
                ]);
                $draft->unit($unit, Money::kzt(self::PHONES[$n][9]));
            }
            foreach ($accessories as $sku => [$qty, $price]) {
                $draft->accessory($this->accessory($sku), $qty, Money::kzt($price));
            }
            if ($discount) {
                $draft->discount(Money::kzt($discount));
            }
            foreach ($payments as $p) {
                [$method, $amount] = $p;
                match ($method) {
                    'cash' => $draft->cash(Money::kzt($amount), isset($p[2]) ? Money::kzt($p[2]) : null),
                    'debt' => $draft->debt(Money::kzt($amount), $at->addDays($p[3])),
                    default => $draft->pay($method, Money::kzt($amount)),
                };
            }
            $draft->post($actor);
        });
    }

    private function purchase(string $supplier, Currency $currency, array $numbers): PurchaseDraft
    {
        $draft = PurchaseDraft::make($this->store, $this->suppliers[$supplier], $currency);
        foreach ($numbers as $n) {
            [$model, $memory, $color, $ram, $condition, $grade, $battery, $costCurrency, $cost, $sale] = self::PHONES[$n];
            $price = $currency === Currency::USD ? Money::usd($cost) : Money::kzt($cost);
            $variant = CatalogSeeder::variant($model, $memory, $color, $ram);
            $this->rememberPrice($variant->id, $sale);
            [$imei, $serial] = $this->identifiers($n);
            $draft->unit($variant, $imei, $price, array_filter([
                'serial' => $serial, 'condition' => $condition, 'grade' => $grade, 'battery_percent' => $battery,
                'sale_price' => Money::kzt($sale), 'show_on_site' => true,
            ], fn ($v) => $v !== null));
        }
        $this->afterPost[] = $numbers;

        return $draft;
    }

    /** @var list<list<int>> phone numbers whose units must be looked up after the next posting */
    private array $afterPost = [];

    private function phoneLine(OpeningBalanceDraft $draft, int $n, ?Supplier $supplier): void
    {
        [$model, $memory, $color, $ram, $condition, $grade, $battery, $costCurrency, $cost, $sale] = self::PHONES[$n];
        $variant = CatalogSeeder::variant($model, $memory, $color, $ram);
        $this->rememberPrice($variant->id, $sale);
        [$imei, $serial] = $this->identifiers($n);
        $draft->unit($variant, $imei, $costCurrency === 'USD' ? Money::usd($cost) : Money::kzt($cost), array_filter([
            'serial' => $serial, 'condition' => $condition, 'grade' => $grade, 'battery_percent' => $battery,
            'sale_price' => Money::kzt($sale), 'show_on_site' => true, 'supplier_id' => $supplier?->id,
        ], fn ($v) => $v !== null));
        $this->afterPost[] = [$n];
    }

    private function phoneSupplier(int $n): ?Supplier
    {
        return self::PHONES[$n][4] === 'used' ? null : $this->suppliers[$n % 2 ? 'dubai' : 'technoopt'];
    }

    /** @return array{0: string|null, 1: string|null} IMEI (phones) or serial (iPad) */
    private function identifiers(int $n): array
    {
        if (str_starts_with(self::PHONES[$n][0], 'iPad')) {
            return [null, 'DMPXK2LQ'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)];
        }
        $tac = str_starts_with(self::PHONES[$n][0], 'Redmi') ? '86330415' : '35'.str_pad((string) (693812 + $n * 37), 6, '0', STR_PAD_LEFT);

        return [Imei::withCheckDigit($tac.str_pad((string) (447120 + $n * 113), 6, '0', STR_PAD_LEFT)), null];
    }

    /** Resolve stock units created by the last posted documents and record IMEI registry checks. */
    private function checkImeis(array $numbers, User $by): void
    {
        foreach ($this->afterPost as $group) {
            foreach ($group as $n) {
                [$imei, $serial] = $this->identifiers($n);
                $this->units[$n] = StockUnit::findByIdentifier($imei ?? $serial);
            }
        }
        $this->afterPost = [];
        foreach ($numbers as $n) {
            $unit = $this->units[$n];
            if (! self::PHONES[$n][10] || ! $unit->imei1) {
                continue;
            }
            ImeiCheck::query()->create([
                'stock_unit_id' => $unit->id, 'imei' => $unit->imei1, 'result' => ImeiCheckResult::Registered->value,
                'source' => 'imei.rfs.gov.kz', 'checked_by' => $by->id, 'checked_at' => now()->addMinutes(20),
            ]);
        }
    }

    private function rememberPrice(int $variantId, int $sale): void
    {
        VariantPrice::query()->updateOrCreate(['variant_id' => $variantId], ['price_kzt' => Money::kzt($sale)->amount]);
    }

    private function accessory(string $sku): Accessory
    {
        return Accessory::query()->where('sku', $sku)->firstOrFail();
    }

    private function account(string $code): MoneyAccount
    {
        return MoneyAccount::byCode($code);
    }

    /** A moment N days ago at h:m in Almaty. */
    private function day(int $daysAgo, int $h, int $m): CarbonImmutable
    {
        return $this->today->subDays($daysAgo)->setTime($h, $m)->utc();
    }

    /** A date N days ago from the REAL today (negative = in the future), also inside at(). */
    private function date(int $daysAgo): CarbonImmutable
    {
        return $this->today->subDays($daysAgo)->startOfDay();
    }

    /** Run with the clock set to $moment (documents get that business date and posting time). */
    private function at(?CarbonImmutable $moment, ?callable $callback = null): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
        try {
            $callback && $callback();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
