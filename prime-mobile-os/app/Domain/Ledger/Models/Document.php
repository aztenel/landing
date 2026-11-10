<?php

namespace App\Domain\Ledger\Models;

use App\Domain\Ledger\Enums\DocumentStatus;
use App\Domain\Ledger\Enums\DocumentType;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Money\Rate;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Common header of every document (TZ 02 §11.1). Detail rows live in module tables keyed by document_id
 * (sales/sale_lines/…, purchases/purchase_lines/…). Status draft → posted → reversed; only Poster/Reverser
 * change status, number, posted_*; after posting only comment and fiscal_receipt_no may change (DB trigger).
 *
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property Carbon $occurred_at
 */
class Document extends Model
{
    use BelongsToOrganization, TracksCreator;

    protected $fillable = [
        'organization_id', 'type', 'store_id', 'occurred_at', 'reason_code', 'reason_text', 'exchange_rate',
        'exchange_rate_manual', 'idempotency_key', 'approval_request_id', 'exchange_group_id', 'fiscal_receipt_no',
        'comment', 'meta', 'created_by',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'occurred_at' => 'datetime',
            'occurred_at_manual' => 'boolean',
            'posted_at' => 'datetime',
            'exchange_rate_manual' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === DocumentStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->status === DocumentStatus::Reversed;
    }

    public function rate(): ?Rate
    {
        return $this->exchange_rate === null ? null : Rate::of((string) $this->exchange_rate);
    }

    /** «Продажа ПРД-2026-000012» */
    public function title(): string
    {
        return $this->type->label().' '.($this->number ?? '(черновик)');
    }

    public static function findByIdempotencyKey(string $key): ?self
    {
        return static::query()->where('idempotency_key', $key)->first();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_document_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_document_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_document_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function moneyEntries(): HasMany
    {
        return $this->hasMany(MoneyEntry::class);
    }

    public function debtEntries(): HasMany
    {
        return $this->hasMany(DebtEntry::class);
    }

    public function bonusEntries(): HasMany
    {
        return $this->hasMany(BonusEntry::class);
    }
}
