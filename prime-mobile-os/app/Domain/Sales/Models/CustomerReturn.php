<?php

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer return header (TZ 02 §6.2 №20): the sale returned, totals, bonuses. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class CustomerReturn extends Model
{
    use BelongsToOrganization;

    protected $table = 'customer_returns';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'bonus_returned' => 'integer', 'bonus_cancelled' => 'integer', 'bonus_withheld' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
