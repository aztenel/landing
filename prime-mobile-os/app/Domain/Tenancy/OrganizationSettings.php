<?php

namespace App\Domain\Tenancy;

use InvalidArgumentException;

/**
 * Typed access to organizations.settings (JSON) with defaults from the TZ (02 §7, §8, §9, §10.4; 03 §5).
 * Stored JSON overrides defaults key by key.
 */
final class OrganizationSettings
{
    public const ACCENTS = ['blue', 'graphite'];

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
        ],
    ];

    private array $data;

    public function __construct(array $stored = [])
    {
        $this->data = array_replace_recursive(self::DEFAULTS, $stored);
        if (! in_array($this->data['accent'], self::ACCENTS, true)) {
            throw new InvalidArgumentException("Unknown accent «{$this->data['accent']}».");
        }
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
