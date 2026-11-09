<?php

namespace App\Domain\Approvals\Models;

use App\Domain\Approvals\ApprovalKinds;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Ledger\Models\Document;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to the owner (TZ 03 §6). Written only by ApprovalService / DatabaseApprovalGate. The `sensitive` column
 * (cost, profit) never leaves the server except through ApprovalPresenter::forViewer() for those who may see it.
 *
 * @property ApprovalStatus $status
 * @property list<array{type: string, permission: string, limit: ?string, limit_value: ?int, actual_value: ?int, message: string}> $requirements
 * @property array<string, string> $fingerprint
 * @property array<string, int|string|null>|null $values
 * @property Carbon $expires_at
 * @property Carbon|null $valid_until
 */
class ApprovalRequest extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected $hidden = ['sensitive', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'requirements' => 'array',
            'fingerprint' => 'array',
            'values' => 'array',
            'context' => 'array',
            'sensitive' => 'array',
            'approved_values' => 'array',
            'released_groups' => 'array',
            'needs_confirmation' => 'boolean',
            'max_value' => 'integer',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'valid_until' => 'datetime',
            'reminded_at' => 'datetime',
            'escalated_at' => 'datetime',
            'used_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function subjectDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'subject_document_id');
    }

    public function usedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'used_document_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Pending and still alive (the tick may not have marked an old one yet). */
    public function isPending(?\DateTimeInterface $now = null): bool
    {
        return $this->status === ApprovalStatus::Pending && $this->expires_at->greaterThan($now ?? now());
    }

    /** Approved, not used and within the 15 minutes. */
    public function isUsable(?\DateTimeInterface $now = null): bool
    {
        return $this->status === ApprovalStatus::Approved && $this->valid_until !== null && $this->valid_until->greaterThan($now ?? now());
    }

    /** Effective status including the lazy expiry/lapse not yet written by the tick. */
    public function effectiveStatus(?\DateTimeInterface $now = null): ApprovalStatus
    {
        $now ??= now();

        return match (true) {
            $this->status === ApprovalStatus::Pending && ! $this->expires_at->greaterThan($now) => ApprovalStatus::Expired,
            $this->status === ApprovalStatus::Approved && ($this->valid_until === null || ! $this->valid_until->greaterThan($now)) => ApprovalStatus::Lapsed,
            default => $this->status,
        };
    }

    /** @return list<string> kinds of all requirements */
    public function kinds(): array
    {
        return array_values(array_unique(array_map(fn ($r) => (string) $r['type'], $this->requirements ?? [])));
    }

    public function isOwnerOnly(): bool
    {
        foreach ($this->kinds() as $kind) {
            if (ApprovalKinds::ownerOnly($kind)) {
                return true;
            }
        }

        return false;
    }

    /** Covers a check of ctx->authorize($permission, …, $type)? (any limit of that permission — L1/L2/L3 of a discount) */
    public function covers(string $type, string $permission): bool
    {
        foreach ($this->requirements ?? [] as $r) {
            if ($r['type'] === $type && $r['permission'] === $permission) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> value keys the decider may change for this request */
    public function changeableKeys(): array
    {
        $keys = [];
        foreach ($this->kinds() as $kind) {
            $keys = array_merge($keys, ApprovalKinds::changeable($kind));
        }

        return array_values(array_unique(array_filter($keys, fn ($k) => array_key_exists($k, $this->values ?? []))));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value)->where('expires_at', '>', now());
    }
}
