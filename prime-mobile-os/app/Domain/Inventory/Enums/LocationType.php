<?php

namespace App\Domain\Inventory\Enums;

enum LocationType: string
{
    case Store = 'store';
    case Transit = 'transit';   // location_id = transfer document id («В пути (ПРМ-…)»)
}
