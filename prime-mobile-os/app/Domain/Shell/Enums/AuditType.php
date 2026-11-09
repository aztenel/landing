<?php

namespace App\Domain\Shell\Enums;

/**
 * Event types of the action journal filter (TZ 04 Э27): each type is a set of activity_log
 * (log_name, description) pairs. Modules writing their own events pick a log_name from here
 * (AuditLog::record('prices', 'price_changed', …)) so the filter finds them.
 */
enum AuditType: string
{
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Posting = 'posting';
    case Reversal = 'reversal';
    case Printing = 'printing';
    case Export = 'export';
    case Iin = 'iin';
    case Price = 'price';
    case Settings = 'settings';
    case Device = 'device';
    case Approvals = 'approvals';
    case Support = 'support';
    case Stock = 'stock';
    case Money = 'money';

    public function label(): string
    {
        return match ($this) {
            self::Login => __('Вход и выход'),
            self::LoginFailed => __('Неудачный вход'),
            self::Posting => __('Проведение'),
            self::Reversal => __('Сторно'),
            self::Printing => __('Печать и перепечатка'),
            self::Export => __('Экспорт Excel'),
            self::Iin => __('Просмотр полного ИИН'),
            self::Price => __('Смена цены'),
            self::Settings => __('Настройки и права'),
            self::Device => __('Устройства'),
            self::Approvals => __('Заявки и решения'),
            self::Support => __('Доступ интегратора'),
            self::Stock => __('Склад'),
            self::Money => __('Деньги и курс'),
        };
    }

    /**
     * [log_name, list of descriptions or null = any].
     *
     * @return array{0: string, 1: list<string>|null}
     */
    public function match(): array
    {
        return match ($this) {
            self::Login => ['auth', ['login', 'logout', 'lock', 'unlock', 'organization_switched', 'password_changed', 'invitation_accepted']],
            self::LoginFailed => ['auth', ['login_failed', 'login_throttled', 'login_refused']],
            self::Posting => ['documents', ['posted']],
            self::Reversal => ['documents', ['reversed']],
            self::Printing => ['documents', ['printed', 'reprinted', 'sent']],
            self::Export => ['data', ['export']],
            self::Iin => ['data', ['iin_viewed']],
            self::Price => ['prices', null],
            self::Settings => ['settings', null],
            self::Device => ['devices', null],
            self::Approvals => ['approvals', null],
            self::Support => ['support', null],
            self::Stock => ['stock', null],
            self::Money => ['money', null],
        };
    }

    /** Types an accountant sees (TZ 03 §3.9 №99: «👁 денежные события»). */
    public static function moneyTypes(): array
    {
        return [self::Posting, self::Reversal, self::Money, self::Export];
    }
}
