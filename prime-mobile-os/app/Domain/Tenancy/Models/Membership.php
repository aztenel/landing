<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use App\Support\Tenancy\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * User × organization: status, stores, personal limits (TZ 03 §1, §5.3). The role is a spatie role in the
 * organization team (model_has_roles.organization_id).
 *
 * @property int $user_id
 * @property MembershipStatus $status
 * @property array<string, int|null> $limits
 */
class Membership extends Model
{
    use BelongsToOrganization, TracksCreator;

    protected $fillable = ['organization_id', 'user_id', 'status', 'limits', 'position', 'started_on', 'dismissed_at', 'created_by'];

    protected $attributes = [
        'status' => 'active',
        'limits' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'limits' => 'array',
            'payroll' => 'array',
            'started_on' => 'date',
            'dismissed_at' => 'datetime',
            'blocked_at' => 'datetime',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rules_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
            'hints_seen' => 'boolean',
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

    public function stores(): HasMany
    {
        return $this->hasMany(MembershipStore::class);
    }

    /**
     * May the employee work in this store (audit №10)? No row = no access. $scope «sales» / «finance» also needs the
     * mark «Продажи» / «Финансы» of that store (TZ 03 §1). The owner is checked by AccessChecker, not here.
     */
    public function allowsStore(?int $storeId, ?string $scope = null): bool
    {
        if ($storeId === null) {
            return true;
        }
        $row = $this->stores->firstWhere('store_id', $storeId);

        return $row !== null && match ($scope) {
            'sales' => (bool) $row->can_sales,
            'finance' => (bool) $row->can_finance,
            default => true,
        };
    }

    /** Personal limit override (TZ 03 §5.3). Returns [found, value]; value null = unlimited. */
    public function personalLimit(string $code): array
    {
        $limits = $this->limits ?? [];

        return array_key_exists($code, $limits) ? [true, $limits[$code] === null ? null : (int) $limits[$code]] : [false, null];
    }
}
