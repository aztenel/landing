<?php

namespace App\Domain\TradeIn\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class GridPrice extends Model
{
    use BelongsToOrganization;

    protected $table = 'tradein_grid_prices';

    protected $guarded = ['id', 'organization_id'];
}
