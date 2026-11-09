<?php

namespace App\Domain\Inventory\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cart reservation (30 min), one active per unit (partial unique index) — layer 2 of IMEI protection. */
class UnitReservation extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'stock_unit_id', 'user_id', 'cart_key', 'reserved_at', 'expires_at', 'released_at', 'release_reason'];

    protected function casts(): array
    {
        return ['reserved_at' => 'datetime', 'expires_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
