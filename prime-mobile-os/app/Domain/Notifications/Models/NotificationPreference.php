<?php

namespace App\Domain\Notifications\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** «Что присылать» for one user × notification type (TZ 04 Э26 «Уведомления»). Absent row = type defaults. */
class NotificationPreference extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'push' => 'boolean', 'telegram' => 'boolean'];
    }
}
