<?php

namespace App\Domain\Money\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Expense paid from an account (several accounts allowed). Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class ExpensePayment extends Model
{
    use BelongsToOrganization;

    protected $table = 'expense_payments';

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
