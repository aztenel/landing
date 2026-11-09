<?php

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Returned sale line × qty; amount NULL = proportional share; unit_status_after for phones. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class CustomerReturnLine extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'customer_return_lines';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'amount' => 'integer', 'cost_kzt' => 'integer'];
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
