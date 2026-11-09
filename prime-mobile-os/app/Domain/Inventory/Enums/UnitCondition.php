<?php

namespace App\Domain\Inventory\Enums;

enum UnitCondition: string
{
    case New = 'new';
    case Used = 'used';

    public function label(): string
    {
        return $this === self::New ? 'новый' : 'б/у';
    }
}
