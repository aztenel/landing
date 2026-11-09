<?php

namespace App\Domain\Tenancy\Enums;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Blocked = 'blocked';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Приглашён',
            self::Active => 'Активен',
            self::Blocked => 'Заблокирован',
            self::Dismissed => 'Уволен',
        };
    }
}
