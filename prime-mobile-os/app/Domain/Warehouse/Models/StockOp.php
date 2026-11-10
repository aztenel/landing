<?php

namespace App\Domain\Warehouse\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class StockOp extends Model
{
    use BelongsToOrganization;

    protected $table = 'stock_ops';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'refund_amount' => 'integer'];
    }
}
