<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\AppNotification;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;

/**
 * The single entry point to notify people of the current organization (TZ 04 Э28, TZ 06 §4.3). Writes the bell row
 * at once (in the caller's transaction) and queues the delivery (push / Telegram) AFTER commit, so a rolled back
 * operation never pings the owner.
 *
 *   $notifier->toOwners(NotificationType::ShiftDiscrepancy, 'Смена закрыта: недостача 2 000 ₸ (Дана)', null,
 *       ['document_id' => $doc->id, 'url' => '/admin/shifts/12']);
 *
 * Texts: no IIN, IMEI, customer phones (TZ 06 §4.2 p.1); no cost / profit (the card shows them after opening, to
 * those who may see them — TZ 03 §4.2 p.2 «уведомления»). Options: data, url, approval_request_id, document_id,
 * silent (feed only, no push), headline (lock-screen text without money).
 */
class Notifier
{
    public function __construct(private readonly Recipients $recipients) {}

    /** @param array{data?: array, url?: string, approval_request_id?: int, document_id?: int, silent?: bool, headline?: string} $options */
    public function toUser(User|int $user, NotificationType $type, string $title, ?string $body = null, array $options = []): ?AppNotification
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (! $this->recipients->isActiveMember($userId)) {
            return null;
        }
        $data = $options['data'] ?? [];
        if (isset($options['headline'])) {
            $data['headline'] = $options['headline'];
        }
        $notification = AppNotification::query()->create([
            'user_id' => $userId,
            'type' => $type->value,
            'title' => mb_substr($title, 0, 200),
            'body' => $body,
            'data' => $data ?: null,
            'url' => $options['url'] ?? null,
            'approval_request_id' => $options['approval_request_id'] ?? null,
            'document_id' => $options['document_id'] ?? null,
            'silent' => (bool) ($options['silent'] ?? false),
        ]);
        if (! $notification->silent) {
            DeliverNotification::dispatch($notification->id, CurrentOrganization::instance()->requireId())->afterCommit();
        }

        return $notification;
    }

    /** @param iterable<User|int> $users @return Collection<int, AppNotification> */
    public function toUsers(iterable $users, NotificationType $type, string $title, ?string $body = null, array $options = []): Collection
    {
        $sent = collect();
        $seen = [];
        foreach ($users as $user) {
            $id = $user instanceof User ? $user->id : (int) $user;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($n = $this->toUser($id, $type, $title, $body, $options)) {
                $sent->push($n);
            }
        }

        return $sent;
    }

    /** @return Collection<int, AppNotification> */
    public function toOwners(NotificationType $type, string $title, ?string $body = null, array $options = []): Collection
    {
        return $this->toUsers($this->recipients->owners(), $type, $title, $body, $options);
    }
}
