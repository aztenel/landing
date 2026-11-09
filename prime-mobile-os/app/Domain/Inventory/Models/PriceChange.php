<?php

namespace App\Domain\Inventory\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Price history (append-only): subject stock_unit | variant | accessory, old → new, reason. */
class PriceChange extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'subject_type', 'subject_id', 'old_price', 'new_price', 'reason', 'user_id'];
}
