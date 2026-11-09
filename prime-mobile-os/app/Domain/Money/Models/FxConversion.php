<?php

namespace App\Domain\Money\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Currency conversion: from account/amount → to account/amount; fx_diff_kzt = Σ amount_kzt of the entries. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class FxConversion extends Model
{
    use BelongsToOrganization;

    protected $table = 'fx_conversions';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['from_amount' => 'integer', 'to_amount' => 'integer', 'fx_diff_kzt' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
