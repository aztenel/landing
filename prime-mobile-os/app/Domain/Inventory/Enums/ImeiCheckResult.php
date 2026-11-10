<?php

namespace App\Domain\Inventory\Enums;

enum ImeiCheckResult: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case Blocked = 'blocked';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Верифицирован',
            self::NotRegistered => 'Не верифицирован',
            self::Blocked => 'Заблокирован',
            self::Error => 'Не удалось проверить',
        };
    }
}
