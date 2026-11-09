<?php

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Copy: quantity of an accessory in a place. Changed only by InventoryLedger. qty ≥ 0 (CHECK). */
class AccessoryBalance extends Model
{
    use BelongsToOrganization;

    public const CREATED_AT = null;

    protected $fillable = ['organization_id', 'location_type', 'location_id', 'accessory_id'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }
}
