<?php

namespace App\Domain\Tenancy;

use InvalidArgumentException;

/**
 * Typed access to organizations.settings (JSON) with defaults from the TZ (02 §7, §8, §9, §10.4; 03 §5).
 * Stored JSON overrides defaults key by key.
 */
final class OrganizationSettings
{
    /** blue #2A4BD7 (default), graphite + 4 contrast-checked variants (TZ 04 Э26, App\Support\Ui\Accent). */
    public const ACCENTS = ['blue', 'graphite', 'navy', 'teal', 'violet', 'plum'];

    public const DEFAULTS = [
        'accent' => 'blue',                               // blue #2A4BD7 | graphite
        'bonus' => [
            'enabled' => true,
            'phone_percent' => '1.00',                    // % for phones and tablets
            'accessory_percent' => '3.00',                // % for accessories and services
            'activation_days' => 14,
            'lifetime_days' => 365,
            'max_pay_percent' => '20.00',                 // max share of a check paid with bonuses
            'confirm_by_code' => false,
        ],
        'sales' => [
            'require_imei_check' => true,                 // «без госбазы не продаём» (owner may switch off)
            'imei_check_max_age_days' => 7,
            'reservation_minutes' => 30,
            'order_hold_days' => 3,
            'customer_debt_ceiling' => 30000000,          // tiyn (300 000 ₸): above it only the owner (audit №17)
        ],
        'storefront' => [
            'code_prefix' => 'PM',                        // public code of phones PM-10001 (audit №43)
        ],
        'documents' => [
            'backdate_max_days' => 3,
            'backdate_grace_minutes' => 10,
        ],
        'cash' => [
            'discrepancy_threshold' => 100000,            // tiyn (1 000 ₸)
        ],
        'prices' => [
            'fx_round_step' => 100000,                    // tiyn (1 000 ₸)
        ],
        'rates' => [
            'confirm_change_percent' => '5.00',
        ],
        'approvals' => [
            'confirm_threshold' => 30000000,              // tiyn (300 000 ₸), PIN before «Разрешить»
            'deciders' => 'owner_then_admin',             // owner | owner_then_admin (TZ 04 Э25.3)
            'admin_after_minutes' => 5,
        ],
        // ---- module «shell», settings screen Э26 (TZ 04) ----
        'brand' => [
            'display_name' => null,                       // name for customers; null = organizations.name
            'logo_light' => null,                         // storage path (public disk)
            'logo_dark' => null,
        ],
        'rules' => [
            'used_to_preparation' => true,                // б/у сначала на подготовку
            'customer_debt_cap' => 30000000,              // tiyn (300 000 ₸) — firm ceiling per customer
            'debt_default_days' => 30,
            'accessories_negative' => false,              // продажа «в минус» по аксессуарам
            'customer_required_cashless' => false,
            'low_stock_threshold' => 2,                   // «заканчивается»
            'aging_buckets' => [30, 60, 90],              // «залежался» 0–30 / 31–60 / 61–90 / 90+
            'aging_threshold_days' => 30,
            'buy_usd_from_customers' => false,            // TZ 01 П18.3 — off
            'buy_usd_discount' => 300,                    // tiyn per $1 (курс дня − 3 ₸)
        ],
        'warranty' => [
            'new_months' => 12,
            'used_months' => 1,
            'accessory_months' => 1,
        ],
        'texts' => [
            'warranty_ru' => null, 'warranty_kk' => null,
            'consent_ru' => null, 'consent_kk' => null, 'consent_version' => 1, 'consent_date' => null,
            'tradein_act' => null, 'return_terms' => null, 'receipt_footer' => null,
            'staff_rules' => null, 'staff_rules_version' => 1,
        ],
        'printing' => [
            'receipt_width' => 80,                        // 58 | 80 mm
            'warranty_format' => 'a4',                    // a4 | tape
            'label_size' => '58x40',
            'label_fields' => ['model', 'memory', 'price', 'imei_barcode'],
            'language' => 'kk+ru',                        // kk+ru | ru | kk
        ],
        'dictionaries' => [
            'customer_sources' => ['Instagram', 'WhatsApp', '2ГИС', 'Kaspi', 'Рекомендация', 'Проходил мимо'],
            'delivery_services' => ['Яндекс Доставка', 'Курьер магазина', 'Kaspi Доставка'],
            'discount_reasons' => ['Постоянный клиент', 'Царапина на корпусе', 'Акция'],
            'write_off_reasons' => ['Брак', 'Разбит', 'Утерян'],
        ],
        'notifications' => [
            'summary_time' => '09:00',
            'events' => [],                               // event => {owner_push, owner_telegram, admin_push, admin_telegram}
        ],
        'security' => [
            'lock_store_computer' => 10,                  // minutes, TZ 03 §9
            'lock_iphone' => 5,
            'lock_laptop' => 15,
        ],
    ];

    private array $data;

    public function __construct(array $stored = [])
    {
        $this->data = self::merge(self::DEFAULTS, $stored);
        if (! in_array($this->data['accent'], self::ACCENTS, true)) {
            throw new InvalidArgumentException("Unknown accent «{$this->data['accent']}».");
        }
    }

    /** Stored values override defaults key by key; a stored list replaces the default list as a whole. */
    private static function merge(array $defaults, array $stored): array
    {
        foreach ($stored as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) && $defaults[$key] !== [] && ! array_is_list($defaults[$key])) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function accent(): string
    {
        return $this->data['accent'];
    }

    /** Firm ceiling of one customer's total debt, tiyn (audit №17): above it only the owner allows. */
    public function customerDebtCeiling(): int
    {
        return (int) $this->get('sales.customer_debt_ceiling');
    }

    public function bonusEnabled(): bool
    {
        return (bool) $this->get('bonus.enabled');
    }

    public function bonusPhonePercent(): string
    {
        return (string) $this->get('bonus.phone_percent');
    }

    public function bonusAccessoryPercent(): string
    {
        return (string) $this->get('bonus.accessory_percent');
    }

    public function bonusActivationDays(): int
    {
        return (int) $this->get('bonus.activation_days');
    }

    public function bonusLifetimeDays(): int
    {
        return (int) $this->get('bonus.lifetime_days');
    }

    public function bonusMaxPayPercent(): string
    {
        return (string) $this->get('bonus.max_pay_percent');
    }

    public function requireImeiCheck(): bool
    {
        return (bool) $this->get('sales.require_imei_check');
    }

    public function imeiCheckMaxAgeDays(): int
    {
        return (int) $this->get('sales.imei_check_max_age_days');
    }

    public function reservationMinutes(): int
    {
        return (int) $this->get('sales.reservation_minutes');
    }

    public function backdateMaxDays(): int
    {
        return (int) $this->get('documents.backdate_max_days');
    }

    public function backdateGraceMinutes(): int
    {
        return (int) $this->get('documents.backdate_grace_minutes');
    }

    public function rateConfirmChangePercent(): string
    {
        return (string) $this->get('rates.confirm_change_percent');
    }

    /** Return a new stored-settings array with a value changed (for $organization->settings = …). */
    public static function with(array $stored, string $key, mixed $value): array
    {
        data_set($stored, $key, $value);
        (new self($stored)); // validate

        return $stored;
    }
}
