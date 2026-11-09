<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User × organization: status, stores, personal limits (TZ 03 §1, §5.3). The role is a spatie role in the
 * organization team (model_has_roles.organization_id).
 *
 * @property int $user_id
 * @property MembershipStatus $status
 * @property array<int> $store_ids
 * @property array<string, int|null> $limits
 */
class Membership extends Model
{
    use BelongsToOrganization, TracksCreator;

    protected $fillable = ['organization_id', 'user_id', 'status', 'store_ids', 'limits', 'position', 'started_on', 'dismissed_at', 'created_by'];

    protected $attributes = [
        'status' => 'active',
        'store_ids' => '[]',
        'limits' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'store_ids' => 'array',
            'limits' => 'array',
            'started_on' => 'date',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /** Empty store list = all stores of the organization. */
    public function allowsStore(?int $storeId): bool
    {
        return $storeId === null || $this->store_ids === [] || in_array($storeId, array_map('intval', $this->store_ids), true);
    }

    /** Personal limit override (TZ 03 §5.3). Returns [found, value]; value null = unlimited. */
    public function personalLimit(string $code): array
    {
        $limits = $this->limits ?? [];

        return array_key_exists($code, $limits) ? [true, $limits[$code] === null ? null : (int) $limits[$code]] : [false, null];
    }
}
