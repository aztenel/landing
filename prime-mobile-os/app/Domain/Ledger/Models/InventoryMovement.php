<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Ledger\Enums\MovementKind;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Goods journal row (append-only). Written only by InventoryLedger. */
class InventoryMovement extends Model
{
    use AppendOnly, BelongsToOrganization, HasSensitiveFields;

    public $timestamps = false;

    /** Journal row: written only by the ledgers (forceFill), never mass-assigned (ENGINEERING.md rule 4). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => MovementKind::class,
            'status_before' => UnitStatus::class,
            'status_after' => UnitStatus::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'qty' => 'integer',
            'unit_cost_kzt' => 'integer',
            'total_cost_kzt' => 'integer',
        ];
    }

    public function location(): Location
    {
        return Location::fromColumns($this->location_type, $this->location_id);
    }

    public function locationBefore(): ?Location
    {
        return Location::fromColumns($this->location_before_type, $this->location_before_id);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => ['unit_cost_kzt', 'total_cost_kzt', 'cost_currency', 'cost_amount_orig', 'exchange_rate', 'avg_cost_before', 'avg_cost_after']];
    }
}
