<?php

namespace App\Domain\Shell\Enums;

enum DeviceStatus: string
{
    case Allowed = 'allowed';
    case Pending = 'pending';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Allowed => __('Разрешено'),
            self::Pending => __('Ждёт разрешения'),
            self::Disabled => __('Отключено'),
        };
    }
}
