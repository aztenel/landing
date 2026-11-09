<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immediate payment of a purchase from an account (in the account currency). Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class PurchasePayment extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'purchase_payments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'covered_amount' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Payment of a purchase = its cost (TZ 03 §4.1). */
    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['amount', 'covered_amount']];
    }
}
