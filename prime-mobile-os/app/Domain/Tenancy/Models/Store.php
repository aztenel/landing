<?php

namespace App\Domain\Tenancy\Models;

use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;

/**
 * A shop of the organization (now one: ТРЦ «Керуен»).
 */
class Store extends Model
{
    use BelongsToOrganization, TracksCreator;

    protected $fillable = ['organization_id', 'name', 'address', 'phone', 'is_active', 'sort', 'created_by', 'meta'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'meta' => 'array'];   // meta: site fields (TZ 05 §2.3) whatsapp, hours, map_url, site_hidden …
    }
}
