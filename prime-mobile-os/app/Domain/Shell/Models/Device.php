<?php

namespace App\Domain\Shell\Models;

use App\Domain\Shell\Enums\DeviceStatus;
use App\Domain\Shell\Enums\DeviceType;
use App\Domain\Tenancy\Models\Store;
use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser on a laptop/iPhone that works with the firm (TZ 03 §8). Recognised by a random key cookie;
 * only its SHA-256 is stored. Status and «старый ноутбук» are changed by DeviceRegistry.
 *
 * @property DeviceStatus $status
 * @property DeviceType $type
 * @property bool $legacy_mode
 */
class Device extends Model
{
    use BelongsToOrganization;

    /** Only descriptive fields; status/key are changed by DeviceRegistry. */
    protected $fillable = ['name', 'type', 'store_id', 'owner_user_id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'type' => DeviceType::class,
            'legacy_mode' => 'boolean',
            'last_seen_at' => 'datetime',
            'approved_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function lastUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_user_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isDisabled(): bool
    {
        return $this->status === DeviceStatus::Disabled;
    }

    /** «Ноутбук касса · Chrome 109» for the journal. */
    public function label(): string
    {
        $name = $this->name ?: ($this->platform ?: __('Устройство'));

        return $this->browser ? $name.' · '.$this->browser : $name;
    }
}
