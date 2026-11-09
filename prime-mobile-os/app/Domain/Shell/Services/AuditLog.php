<?php

namespace App\Domain\Shell\Services;

use App\Domain\Ledger\Models\Document;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

/**
 * The action journal (TZ 03 §11, TZ 04 Э27) on top of spatie activity_log.
 *
 *   AuditLog::record('settings', 'settings_changed', $org, ['group' => 'bonus', 'old' => [...], 'new' => [...]]);
 *   AuditLog::describeUsing('prices.price_changed', fn (Activity $a) => __('Сменил цену …'));
 *
 * IP, browser, device, store and the role at that moment are filled for every activity automatically
 * (ShellServiceProvider). Rows are append-only (DB trigger). Sensitive values (cost, IIN) must be masked
 * by the caller before they go to old/new.
 */
final class AuditLog
{
    /** @var array<string, Closure(ActivityModel): string> */
    private static array $describers = [];

    public static function record(string $logName, string $event, ?Model $subject = null, array $properties = [], ?User $causer = null): ?Activity
    {
        $causer ??= Auth::user();
        $logger = activity($logName)->event($event)->withProperties($properties);
        if ($subject) {
            $logger->performedOn($subject);
        }
        if ($causer instanceof User) {
            $logger->causedBy($causer);
        }

        return $logger->log($event);
    }

    /** Human text for an event: «log_name.description» => fn (Activity): string. */
    public static function describeUsing(string $key, Closure $describer): void
    {
        self::$describers[$key] = $describer;
    }

    public static function describe(ActivityModel $a): string
    {
        $key = $a->log_name.'.'.$a->description;
        if (isset(self::$describers[$key])) {
            return (self::$describers[$key])($a);
        }
        $p = $a->properties;

        return match ($key) {
            'auth.login' => __('Вошёл в систему'),
            'auth.logout' => __('Вышел из системы'),
            'auth.lock' => __('Экран заблокирован'),
            'auth.unlock' => __('Разблокировал экран'),
            'auth.login_failed' => __('Неудачная попытка входа').($p['phone'] ?? null ? ' · '.$p['phone'] : ''),
            'auth.login_throttled' => __('Вход остановлен: слишком много попыток'),
            'auth.login_refused' => __('Вход не разрешён').($p['why'] ?? null ? ': '.$p['why'] : ''),
            'auth.organization_switched' => __('Сменил фирму'),
            'auth.password_changed' => __('Сменил пароль'),
            'auth.invitation_accepted' => __('Принял приглашение и задал пароль'),
            'documents.posted' => __('Провёл :doc', ['doc' => self::documentTitle($a, $p['number'] ?? null)]),
            'documents.reversed' => __('Сторнировал :doc', ['doc' => self::documentTitle($a, $p['number'] ?? null)]).(($p['reason_text'] ?? null) ? ' · '.$p['reason_text'] : ''),
            'stock.status_changed' => __('Сменил статус телефона'),
            'money.rate_set' => __('Установил курс $1 = :rate ₸', ['rate' => $p['rate'] ?? '']).(($p['previous'] ?? null) ? ' ('.__('было :old', ['old' => $p['previous']]).')' : ''),
            'settings.settings_changed' => __('Изменил настройки: :group', ['group' => $p['group_label'] ?? ($p['group'] ?? '')]),
            'settings.role_saved' => __('Изменил права роли «:role»', ['role' => $p['role'] ?? '']),
            'settings.role_created' => __('Создал роль «:role»', ['role' => $p['role'] ?? '']),
            'settings.role_deleted' => __('Удалил роль «:role»', ['role' => $p['role'] ?? '']),
            'settings.approvals_deciders' => __('Изменил, кто решает заявки'),
            'settings.member_invited' => __('Пригласил сотрудника :name', ['name' => $p['name'] ?? '']),
            'settings.member_updated' => __('Изменил сотрудника :name', ['name' => $p['name'] ?? '']),
            'settings.member_blocked' => __('Заблокировал сотрудника :name', ['name' => $p['name'] ?? '']),
            'settings.member_unblocked' => __('Разблокировал сотрудника :name', ['name' => $p['name'] ?? '']),
            'settings.member_dismissed' => __('Уволил сотрудника :name', ['name' => $p['name'] ?? '']),
            'settings.member_restored' => __('Восстановил сотрудника :name', ['name' => $p['name'] ?? '']),
            'settings.password_reset_link' => __('Выдал ссылку для нового пароля: :name', ['name' => $p['name'] ?? '']),
            'settings.sessions_closed' => __('Закрыл все сессии: :name', ['name' => $p['name'] ?? '']),
            'devices.registered' => __('Новое устройство: :device', ['device' => $p['device'] ?? '']),
            'devices.disabled' => __('Отключил устройство :device', ['device' => $p['device'] ?? '']),
            'devices.enabled' => __('Включил устройство :device', ['device' => $p['device'] ?? '']),
            'devices.renamed' => __('Переименовал устройство: :old → :new', ['old' => $p['old'] ?? '', 'new' => $p['new'] ?? '']),
            'devices.legacy_mode' => ($p['on'] ?? false) ? __('Включил режим «старый ноутбук»') : __('Выключил режим «старый ноутбук»'),
            'devices.deleted' => __('Удалил устройство :device из списка', ['device' => $p['device'] ?? '']),
            default => $a->description,
        };
    }

    private static function documentTitle(ActivityModel $a, ?string $number): string
    {
        $subject = $a->subject;
        if ($subject instanceof Document) {
            return $subject->title();
        }

        return $number ?: __('документ');
    }
}
