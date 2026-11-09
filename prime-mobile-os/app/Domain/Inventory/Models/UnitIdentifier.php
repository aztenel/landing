<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\IdentifierKind;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** IMEI1 / IMEI2 / serial of a unit; (organization_id, value) is unique — layer 1 of IMEI protection. */
class UnitIdentifier extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'stock_unit_id', 'kind', 'value'];

    protected function casts(): array
    {
        return ['kind' => IdentifierKind::class];
    }
}
