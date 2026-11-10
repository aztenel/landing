<?php

namespace App\Domain\TradeIn\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PrepCost extends Model
{
    use BelongsToOrganization;

    protected $table = 'prep_costs';

    protected $guarded = ['id', 'organization_id'];
}
