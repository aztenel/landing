<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\AppNotification;
use App\Models\User;

/** Default PushSender until the PWA module provides Web Push: nothing is sent, the bell still has the notification. */
final class NullPushSender implements PushSender
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(User $user, AppNotification $notification): bool
    {
        return false;
    }
}
