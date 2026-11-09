<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Models\User;

/**
 * «Что присылать» (TZ 04 Э26 «Уведомления», TZ 06 §4.1 «каждую сводку можно выключить»): channels per user and
 * notification type. In-app (the bell) cannot be switched off for approvals — a request must never be lost.
 *
 *   $prefs->channels($owner, NotificationType::BigDiscount)  // ['in_app' => true, 'push' => true, 'telegram' => false]
 *   $prefs->set($owner, NotificationType::BigDiscount, push: false, telegram: true);
 */
class NotificationPreferences
{
    /** @return array{in_app: bool, push: bool, telegram: bool} */
    public function channels(User|int $user, NotificationType $type): array
    {
        $row = NotificationPreference::query()->where('user_id', $this->id($user))->where('type', $type->value)->first();
        $defaults = ['in_app' => true, 'push' => $type->defaultPush(), 'telegram' => $type->defaultTelegram()];
        if (! $row) {
            return $defaults;
        }

        return [
            'in_app' => $this->alwaysInApp($type) ? true : (bool) $row->in_app,
            'push' => (bool) $row->push,
            'telegram' => (bool) $row->telegram,
        ];
    }

    /** @return array<string, array{label: string, in_app: bool, push: bool, telegram: bool}> owner settings table */
    public function table(User|int $user): array
    {
        $out = [];
        foreach (NotificationType::ownerConfigurable() as $type) {
            $out[$type->value] = ['label' => $type->label()] + $this->channels($user, $type);
        }

        return $out;
    }

    public function set(User|int $user, NotificationType $type, bool $push, bool $telegram, bool $inApp = true): NotificationPreference
    {
        $row = NotificationPreference::query()->updateOrCreate(
            ['user_id' => $this->id($user), 'type' => $type->value],
            ['in_app' => $this->alwaysInApp($type) ? true : $inApp, 'push' => $push, 'telegram' => $telegram],
        );
        activity('settings')->performedOn($row)->withProperties(['type' => $type->value, 'push' => $push, 'telegram' => $telegram])->log('notification_preference');

        return $row;
    }

    private function alwaysInApp(NotificationType $type): bool
    {
        return in_array($type, [NotificationType::ApprovalNew, NotificationType::ApprovalEscalated, NotificationType::ApprovalDecided], true);
    }

    private function id(User|int $user): int
    {
        return $user instanceof User ? $user->id : $user;
    }
}
