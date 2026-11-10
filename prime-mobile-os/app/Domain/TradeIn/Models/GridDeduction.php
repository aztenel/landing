<?php

namespace App\Domain\TradeIn\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class GridDeduction extends Model
{
    use BelongsToOrganization;

    protected $table = 'tradein_grid_deductions';

    protected $guarded = ['id', 'organization_id'];
}
