<?php

namespace App\Domain\Warehouse\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class RevisionScan extends Model
{
    use BelongsToOrganization;

    protected $table = 'revision_scans';

    protected $guarded = ['id', 'organization_id'];
}
