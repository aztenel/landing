<?php

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sale line: a unit OR an accessory × qty; price, discounts, amount, cost (server only). is_active / returned_qty change after posting (returns, reversal). Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class SaleLine extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'sale_lines';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'price' => 'integer', 'list_price_kzt' => 'integer', 'is_gift' => 'boolean', 'line_discount' => 'integer', 'check_discount_share' => 'integer', 'amount' => 'integer', 'cost_kzt' => 'integer', 'bonus_paid_share' => 'integer', 'debt_share' => 'integer', 'bonus_accrued' => 'integer', 'returned_qty' => 'integer', 'is_active' => 'boolean'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['cost_kzt']];
    }
}
