<?php

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sale header (customer, seller, totals) of a document of type sale. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class Sale extends Model
{
    use BelongsToOrganization;

    protected $table = 'sales';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['subtotal' => 'integer', 'check_discount' => 'integer', 'discount_total' => 'integer', 'total' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
