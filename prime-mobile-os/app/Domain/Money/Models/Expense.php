<?php

namespace App\Domain\Money\Models;

use App\Domain\Ledger\Models\Document;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Expense header: article, optional counterparty and credit part. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class Expense extends Model
{
    use BelongsToOrganization;

    protected $table = 'expenses';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['credit_amount' => 'integer', 'total_kzt' => 'integer', 'due_date' => 'date'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
