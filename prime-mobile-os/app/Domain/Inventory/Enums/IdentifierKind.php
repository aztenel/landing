<?php

namespace App\Domain\Inventory\Enums;

enum IdentifierKind: string
{
    case Imei1 = 'imei1';
    case Imei2 = 'imei2';
    case Serial = 'serial';
}
