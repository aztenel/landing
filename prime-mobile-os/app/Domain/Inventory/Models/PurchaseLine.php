<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Purchase line: a phone by IMEI (unit) or accessories × qty; price per unit in the purchase currency (NULL = cost unknown). Rows of a posted document are read-only (DB trigger pm_posted_detail_guard). */
class PurchaseLine extends Model
{
    use BelongsToOrganization, HasSensitiveFields;

    protected $table = 'purchase_lines';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'price' => 'integer', 'cost_kzt' => 'integer', 'sale_price' => 'integer', 'show_on_site' => 'boolean'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['price', 'cost_kzt']];
    }
}
