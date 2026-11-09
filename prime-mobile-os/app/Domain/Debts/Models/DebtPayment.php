<?php

namespace App\Domain\Debts\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Debt payment in (customer pays us) or out (we pay a supplier). Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class DebtPayment extends Model
{
    use BelongsToOrganization;

    protected $table = 'debt_payments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'debt_amount' => 'integer', 'commission_amount' => 'integer', 'fx_diff_kzt' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
