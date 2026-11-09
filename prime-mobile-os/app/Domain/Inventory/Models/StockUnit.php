<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Enums\UnitCondition;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Location;
use App\Domain\Tenancy\Access\HasSensitiveFields;
use App\Support\Imei\Imei;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physical phone (architecture §2.3). The copy fields status, location_*, cost_*, last_movement_id are
 * changed ONLY by App\Domain\Ledger\Posting\Ledgers\InventoryLedger inside posting (or by ReservationService /
 * UnitStatusService for the status-only transitions of TZ 02 §10.3). Do not write them from screens.
 *
 * Cost and supplier must not reach the browser without stock.view_cost / stock.view_supplier — see SENSITIVE.
 *
 * @property UnitStatus|null $status
 * @property UnitCondition $condition
 */
class StockUnit extends Model
{
    use BelongsToOrganization, HasSensitiveFields, TracksCreator;

    /** Fields hidden from roles without stock.view_cost (cost) and stock.view_supplier (supplier). */
    public const SENSITIVE_COST = ['cost_kzt', 'cost_currency', 'cost_amount_orig', 'cost_rate', 'cost_unknown'];

    public const SENSITIVE_SUPPLIER = ['supplier_id', 'purchase_document_id'];

    /**
     * Only descriptive card fields. Identity (variant, IMEI/serial, condition), supplier, purchase document and the
     * retail price change through documents / services only: UnitIntake, «Исправление карточки» (unit_edit),
     * PriceService (limit L14 + price_changes) — a form must not bypass them with fill()/update().
     */
    protected $fillable = [
        'organization_id', 'grade', 'battery_percent', 'year', 'defects', 'description', 'show_on_site', 'created_by',
    ];

    /** Never mass-assignable: maintained by the ledger. */
    protected $guarded = ['status', 'location_type', 'location_id', 'cost_kzt', 'last_movement_id', 'public_code'];

    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
            'condition' => UnitCondition::class,
            'show_on_site' => 'boolean',
            'cost_unknown' => 'boolean',
            'sale_price' => 'integer',
            'cost_kzt' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(UnitIdentifier::class);
    }

    public function imeiChecks(): HasMany
    {
        return $this->hasMany(ImeiCheck::class);
    }

    public function location(): ?Location
    {
        return Location::fromColumns($this->location_type, $this->location_id);
    }

    public function isInStore(int $storeId): bool
    {
        return Location::store($storeId)->equals($this->location());
    }

    public function scopeInStore(Builder $query, int $storeId): Builder
    {
        return $query->where('location_type', 'store')->where('location_id', $storeId);
    }

    public function scopeWithStatus(Builder $query, UnitStatus ...$statuses): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, $statuses));
    }

    /** Find by IMEI1/IMEI2/serial within the current organization. */
    public static function findByIdentifier(string $value): ?self
    {
        $identifier = UnitIdentifier::query()->where('value', Imei::normalize($value))->first();

        return $identifier ? static::query()->find($identifier->stock_unit_id) : null;
    }

    /** The latest IMEI registry check (госбаза). */
    public function latestImeiCheck(): ?ImeiCheck
    {
        return $this->imeiChecks()->orderByDesc('checked_at')->orderByDesc('id')->first();
    }

    public static function sensitiveFields(): array
    {
        return ['stock.view_cost' => self::SENSITIVE_COST, 'stock.view_supplier' => self::SENSITIVE_SUPPLIER];
    }
}
