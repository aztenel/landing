<?php

namespace App\Domain\Sales\Models;

use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Payment of a sale (split = several rows). Σ amount = check total; change = tendered − amount ≥ 0. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class SalePayment extends Model
{
    use BelongsToOrganization;

    protected $table = 'sale_payments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['method' => PaymentMethodCode::class, 'amount' => 'integer', 'commission_amount' => 'integer', 'tendered' => 'integer', 'change' => 'integer', 'debt_due_date' => 'date'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
