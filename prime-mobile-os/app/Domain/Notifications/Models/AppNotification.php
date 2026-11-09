<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\NotificationType;
use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notification of one user in the bell (TZ 04 Э28.1). Written by Notifier only; texts never contain IIN, IMEI
 * or customer phone numbers (TZ 06 §4.2 p.1) and no cost/profit (the owner card shows those after opening).
 *
 * @property NotificationType $type
 */
class AppNotification extends Model
{
    use BelongsToOrganization;

    protected $table = 'app_notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'silent' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /** Bell row for the UI (Э28.1): icon, text, time, action, unread dot. */
    public function toBell(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'icon' => $this->type->icon(),
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'approval_request_id' => $this->approval_request_id,
            'document_id' => $this->document_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'unread' => $this->read_at === null,
        ];
    }
}
