<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Detail row of «Внесение себестоимости» ВСБ (audit №4). Rows of a posted document are read-only (DB trigger). */
class CostEntryLine extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'cost_entry_lines';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'credit_amount' => 'integer', 'price' => 'integer', 'cost_kzt' => 'integer', 'amount' => 'integer', 'covered_amount' => 'integer', 'due_date' => 'date'];
    }

    /** Cost data (TZ 03 §4.1). */
    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['total', 'credit_amount', 'price', 'cost_kzt', 'amount', 'covered_amount', 'supplier_id']];
    }
}
