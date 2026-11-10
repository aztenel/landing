<?php

namespace App\Domain\Warehouse\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class StockOpLine extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_op_lines';

    protected $guarded = ['id', 'organization_id'];
}
