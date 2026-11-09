<?php

namespace App\Domain\Tenancy\Access;

/**
 * The five role templates copied into every new organization (TZ 03 §2).
 */
final class RoleTemplates
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const SENIOR_SELLER = 'senior_seller';

    public const SELLER = 'seller';

    public const ACCOUNTANT = 'accountant';

    public const TITLES = [
        self::OWNER => 'Владелец',
        self::ADMIN => 'Админ',
        self::SENIOR_SELLER => 'Старший продавец',
        self::SELLER => 'Продавец',
        self::ACCOUNTANT => 'Бухгалтер',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::TITLES);
    }
}
