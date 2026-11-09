<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Channels\PushSender;
use App\Domain\Notifications\Channels\TelegramChannel;
use App\Domain\Notifications\Models\AppNotification;
use App\Domain\Notifications\Models\TelegramLink;
use App\Domain\Notifications\Services\NotificationPreferences;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one bell notification to the outside channels by the user's preferences: Web Push (PushSender) and the
 * Telegram copy. Runs in the organization context it was created in (the worker clears the context before a job).
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public readonly int $notificationId, public readonly int $organizationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(PushSender $push, TelegramChannel $telegram, NotificationPreferences $preferences): void
    {
        CurrentOrganization::instance()->run($this->organizationId, function () use ($push, $telegram, $preferences) {
            $notification = AppNotification::query()->find($this->notificationId);
            if (! $notification || $notification->silent) {
                return;
            }
            $user = User::query()->find($notification->user_id);
            if (! $user) {
                return;
            }
            $channels = $preferences->channels($user, $notification->type);
            $status = [];

            if ($notification->push_status === null) {
                $status['push_status'] = ! $channels['push'] ? 'off'
                    : (! $push->isConfigured() ? 'skipped' : ($push->send($user, $notification) ? 'sent' : 'failed'));
            }
            if ($notification->telegram_status === null || $notification->telegram_status === 'failed') {
                $link = TelegramLink::query()->where('user_id', $user->id)->first();
                $status['telegram_status'] = match (true) {
                    ! $channels['telegram'] => 'off',
                    ! $telegram->isEnabled(), ! $link?->isLinked() => 'skipped',
                    default => $telegram->send((string) $link->chat_id, $this->telegramText($notification)) ? 'sent' : 'failed',
                };
            }
            $notification->forceFill($status)->save();
        });
    }

    private function telegramText(AppNotification $n): string
    {
        $org = CurrentOrganization::instance()->require();

        return trim($org->name."\n".$n->title.($n->body ? "\n".$n->body : ''));
    }
}
