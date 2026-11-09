<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Purchase header: supplier, currency, total, credit part, due date. Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class Purchase extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'purchases';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'credit_amount' => 'integer', 'due_date' => 'date'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** The purchase total and the debt reveal the cost; the supplier is closed too (TZ 03 §4.1). */
    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['total', 'credit_amount'], 'stock.view_supplier' => ['supplier_id', 'invoice_no']];
    }
}
