<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Opening balance (day X) line: unit | accessory | money | debt | bonus. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class OpeningBalanceLine extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'opening_balance_lines';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'amount' => 'integer', 'unit_cost_kzt' => 'integer', 'cost_amount_orig' => 'integer', 'cost_unknown' => 'boolean', 'bonus_amount' => 'integer', 'sale_price' => 'integer', 'show_on_site' => 'boolean', 'due_date' => 'date', 'available_from' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function sensitiveFields(): array
    {
        return [
            'stock.view_cost' => ['cost_currency', 'cost_amount_orig', 'cost_rate', 'unit_cost_kzt', 'cost_unknown'],
            'stock.view_supplier' => ['supplier_id'],
        ];
    }
}
