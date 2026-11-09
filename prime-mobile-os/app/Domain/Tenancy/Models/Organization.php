<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\OrganizationSettings;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Organization = owner account (architecture §2.1). Not tenant-scoped itself.
 *
 * @property int $id
 * @property string $name
 * @property array $settings
 * @property string $timezone
 * @property Carbon|null $closed_until
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /** closed_until is changed only by AccountingPeriods::close()/reopenLast() (owner, snapshots, reason — TZ 02 §9). */
    protected $fillable = ['name', 'legal_name', 'bin_iin', 'requisites', 'settings', 'timezone', 'is_active'];

    protected $attributes = [
        'requisites' => '{}',
        'settings' => '{}',
        'timezone' => 'Asia/Almaty',
    ];

    protected function casts(): array
    {
        return [
            'requisites' => 'array',
            'settings' => 'array',
            'closed_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function settings(): OrganizationSettings
    {
        return new OrganizationSettings($this->settings ?? []);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class)->withoutGlobalScopes();
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
