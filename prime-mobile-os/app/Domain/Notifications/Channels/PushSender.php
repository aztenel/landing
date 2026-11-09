<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\AppNotification;
use App\Models\User;

/**
 * Web Push (PWA) channel — interface only in this round (TZ 03 §18 p.9: pushes on iPhone work only for the app added
 * to the Home screen). The PWA module binds its implementation (VAPID keys, subscriptions) in its provider:
 *
 *   $this->app->singleton(PushSender::class, WebPushSender::class);
 *
 * The lock-screen text is $notification->data['headline'] when present (no money, no names of customers —
 * TZ 03 §6.3), otherwise the title.
 */
interface PushSender
{
    public function isConfigured(): bool;

    /** @return bool delivered to at least one device of the user */
    public function send(User $user, AppNotification $notification): bool;
}
