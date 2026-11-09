<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by with the authenticated user when not set explicitly (TZ 02 §11: common fields).
 *
 * @mixin Model
 */
trait TracksCreator
{
    public static function bootTracksCreator(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('created_by') === null && Auth::id() !== null) {
                $model->setAttribute('created_by', Auth::id());
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
