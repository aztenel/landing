<?php

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Refund row: money method with account, or 'debt' (reduces the customer's debt). Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class CustomerReturnPayment extends Model
{
    use BelongsToOrganization;

    protected $table = 'customer_return_payments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
