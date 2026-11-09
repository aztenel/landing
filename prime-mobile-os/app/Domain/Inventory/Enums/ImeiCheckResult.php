<?php

namespace App\Domain\Inventory\Enums;

enum ImeiCheckResult: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'зарегистрирован',
            self::NotRegistered => 'не зарегистрирован',
            self::Error => 'ошибка проверки',
        };
    }
}
