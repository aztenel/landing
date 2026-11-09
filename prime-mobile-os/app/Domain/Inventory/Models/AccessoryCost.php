<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Copy: moving average cost of an accessory per organization (TZ 02 §4.2). Changed only by InventoryLedger. */
class AccessoryCost extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    public const CREATED_AT = null;

    protected $fillable = ['organization_id', 'accessory_id'];

    protected function casts(): array
    {
        return ['total_qty' => 'integer', 'avg_cost_kzt' => 'integer'];
    }

    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['avg_cost_kzt']];
    }
}
