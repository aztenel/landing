<?php

namespace App\Domain\Approvals;

use App\Support\Tenancy\CurrentOrganization;

/**
 * Timings and rules of approvals (TZ 03 §6.4–6.6, README «Единые решения: Заявки») read from organizations.settings
 * «approvals.*» with these defaults (Допущение: values of the TZ; the owner changes them in Э25.3 / Э26).
 */
final class ApprovalSettings
{
    public const DEFAULTS = [
        'lifetime_minutes' => 30,        // a pending request expires
        'reminder_minutes' => 3,         // repeated push to the owner
        'escalate_minutes' => 5,         // visible to admins within their limits
        'valid_minutes' => 15,           // an approval must be used within
        'max_pending' => 3,              // per employee (§6.5 p.3)
        'escalate_to_admins' => true,    // Э25.3 «Владелец, а через 5 минут — и Админ» (default)
    ];

    public static function get(string $key): mixed
    {
        $org = CurrentOrganization::instance()->require();

        return $org->settings()->get('approvals.'.$key, self::DEFAULTS[$key] ?? null);
    }

    public static function minutes(string $key): int
    {
        return max(0, (int) self::get($key));
    }

    /** PIN before «Разрешить» above this amount, tiyn (core setting approvals.confirm_threshold, 300 000 ₸). */
    public static function confirmThreshold(): int
    {
        return (int) CurrentOrganization::instance()->require()->settings()->get('approvals.confirm_threshold', 30000000);
    }
}
