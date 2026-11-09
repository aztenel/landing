<?php

namespace App\Domain\Shell\Enums;

enum DeviceType: string
{
    case StoreComputer = 'store_computer';
    case Personal = 'personal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::StoreComputer => __('Компьютер магазина'),
            self::Personal => __('Личное устройство'),
            self::Other => __('Другое'),
        };
    }
}
