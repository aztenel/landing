<?php

namespace App\Domain\Approvals;

use App\Domain\Tenancy\Access\PermissionCatalog;

/**
 * Approval types (TZ 03 §6.1, TZ 01 П9) = the $approvalType strings of PostingContext::authorize() and of
 * ApprovalRequired. A type not listed here (e.g. a permission code «stock.unit_edit» used as the type of a «З»
 * permission) works with the defaults: label from PermissionCatalog, no change, owner or admin within limits.
 *
 *  owner_only — «решает только владелец» (§6.6 p.3);
 *  reason     — the requester must give a reason ≥ 5 characters (§6.1 «Обязательная причина»);
 *  change     — values the decider may change («Изменить и разрешить», §6.3 p.2) → fingerprint groups released;
 *  confirm    — counts for the PIN threshold (300 000 ₸, «кроме скидок», §6.3 p.3).
 */
final class ApprovalKinds
{
    public const DEFAULTS = ['owner_only' => false, 'reason' => false, 'change' => [], 'release' => [], 'confirm' => true];

    public const KINDS = [
        'discount' => ['label' => 'скидка', 'change' => ['discount'], 'release' => ['discount', 'payments'], 'confirm' => false],
        'below_cost' => ['label' => 'продажа ниже себестоимости', 'owner_only' => true],
        'debt' => ['label' => 'долг клиенту', 'change' => ['debt', 'debt_due'], 'release' => ['payments']],
        'debt_overdue' => ['label' => 'долг клиенту с просрочкой', 'owner_only' => true, 'change' => ['debt', 'debt_due'], 'release' => ['payments']],
        'no_imei_check' => ['label' => 'продажа без отметки госбазы', 'reason' => true],
        'return' => ['label' => 'возврат', 'reason' => true],
        'expense' => ['label' => 'расход', 'reason' => true],
        'reversal' => ['label' => 'сторно', 'reason' => true],
        'price_change' => ['label' => 'изменение цены'],
        'buyout' => ['label' => 'выкуп б/у'],
        'write_off' => ['label' => 'списание', 'reason' => true],
        'bonus_manual' => ['label' => 'бонусы вручную'],
        'cash_adjust' => ['label' => 'корректировка кассы', 'reason' => true],
        'debt_adjust' => ['label' => 'корректировка долга', 'reason' => true],
        'debt_postpone' => ['label' => 'перенос срока долга'],
        'order_cancel' => ['label' => 'отмена чужого заказа'],
        'customer_export' => ['label' => 'выгрузка клиентов', 'owner_only' => true, 'reason' => true],
        'staff_dismiss' => ['label' => 'увольнение сотрудника', 'owner_only' => true, 'reason' => true],
        'new_device' => ['label' => 'вход с нового устройства', 'owner_only' => true],
        'integrator_access' => ['label' => 'допуск интегратора', 'owner_only' => true],
    ];

    /** Fingerprint value key => [unit, title] for the texts of «Разрешено с изменением». */
    public const VALUE_KEYS = [
        'discount' => ['money', 'скидка'],
        'debt' => ['money', 'долг'],
        'debt_due' => ['date', 'срок долга'],
    ];

    public static function meta(string $kind): array
    {
        return (self::KINDS[$kind] ?? []) + self::DEFAULTS;
    }

    /** «скидка» — for «Дана просит разрешение: скидка». */
    public static function label(string $kind): string
    {
        if (isset(self::KINDS[$kind]['label'])) {
            return __(self::KINDS[$kind]['label']);
        }

        return mb_strtolower(PermissionCatalog::title($kind));
    }

    public static function ownerOnly(string $kind): bool
    {
        return (bool) self::meta($kind)['owner_only'];
    }

    public static function reasonRequired(string $kind): bool
    {
        return (bool) self::meta($kind)['reason'];
    }

    /** @return list<string> value keys the decider may change */
    public static function changeable(string $kind): array
    {
        return self::meta($kind)['change'];
    }

    /** @return list<string> fingerprint groups no longer compared once the decider changed a value */
    public static function released(string $kind): array
    {
        return self::meta($kind)['release'];
    }

    public static function countsForConfirmation(string $kind): bool
    {
        return (bool) self::meta($kind)['confirm'];
    }
}
