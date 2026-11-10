<?php

namespace App\Domain\Warehouse\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Revision extends Model
{
    use BelongsToOrganization;

    protected $table = 'revisions';

    protected $guarded = ['id', 'organization_id'];
}
