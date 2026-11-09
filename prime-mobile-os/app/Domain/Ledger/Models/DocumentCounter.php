<?php

namespace App\Domain\Ledger\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Last issued number per organization × series (prefix) × year. Locked FOR UPDATE at posting. */
class DocumentCounter extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'series', 'year', 'last_number'];
}
