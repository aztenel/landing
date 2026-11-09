<?php

namespace App\Domain\Tenancy\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Limit value of a role (TZ 03 §5). code = 'L1'…'L18' or a permission name (value 0 = «только заявкой»).
 * value NULL = unlimited; money in tiyn, percent ×100, days as is.
 */
class RoleLimit extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'role_id', 'code', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
