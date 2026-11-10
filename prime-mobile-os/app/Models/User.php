<?php

namespace App\Models;

use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Phone\Phone;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person (global, TZ 03 §1). Login = phone in E.164 (+77010000001). Roles/permissions are per organization
 * (spatie teams = organizations): they are read for CurrentOrganization.
 *
 * @property string $phone
 * @property bool $is_superadmin integrator — creates organizations, sees NO organization data without access
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = ['phone', 'name', 'email', 'password', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('phone')) {
                $user->phone = Phone::normalize($user->phone);
            }
        });
    }

    /** Memberships in all organizations (bypasses the tenant scope on purpose: needed before an organization is chosen). */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScopes();
    }

    /** @return Collection<int, Organization> organizations where the membership is active */
    public function activeOrganizations(): Collection
    {
        $ids = $this->memberships()->where('status', MembershipStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('support_until')->orWhere('support_until', '>', now()))->pluck('organization_id');

        return Organization::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get();
    }

    /** Active membership in an active (not switched off by the integrator) organization. */
    public function hasActiveMembershipIn(int $organizationId): bool
    {
        return $this->activeMemberships()->where('memberships.organization_id', $organizationId)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_superadmin || $this->activeMemberships()->exists();
    }

    private function activeMemberships(): HasMany
    {
        return $this->memberships()
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('memberships.support_until')->orWhere('memberships.support_until', '>', now()))
            ->whereExists(fn ($q) => $q->from('organizations')
                ->whereColumn('organizations.id', 'memberships.organization_id')
                ->where('organizations.is_active', true));
    }

    /** «Дана С.» as printed on receipts. */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name));

        return count($parts) > 1 ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : $this->name;
    }
}
