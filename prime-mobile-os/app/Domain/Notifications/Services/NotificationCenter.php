<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Approvals\Services\ApprovalQueries;
use App\Domain\Notifications\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * API of the bell (TZ 04 Э28.1) for Livewire / Filament: tabs «Заявки (2)» · «Уведомления» · «Мои заявки».
 * Everything is scoped to the current organization and to the given user; ids from the browser are checked here.
 *
 *   $center->bell($user)          // ['approvals' => 2, 'notifications' => 5, 'my_requests' => 1, 'badge' => 7]
 *   $center->latest($user, 30)    // Collection of AppNotification (->toBell() for the row)
 *   $center->markRead($user, $id); $center->markAllRead($user);
 */
class NotificationCenter
{
    public function __construct(private readonly ApprovalQueries $approvals) {}

    /** @return array{approvals: int, notifications: int, my_requests: int, badge: int} */
    public function bell(User $user): array
    {
        $approvals = $this->approvals->awaitingCount($user);
        $notifications = $this->unreadCount($user);

        return [
            'approvals' => $approvals,
            'notifications' => $notifications,
            'my_requests' => $this->approvals->mineOpenCount($user),
            'badge' => $approvals + $notifications,
        ];
    }

    public function unreadCount(User $user): int
    {
        return AppNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count();
    }

    /** @return Collection<int, AppNotification> */
    public function latest(User $user, int $limit = 30, bool $unreadOnly = false): Collection
    {
        return AppNotification::query()->where('user_id', $user->id)
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')->limit(max(1, min($limit, 100)))->get();
    }

    /** Marks one own notification read; another user's id (forged in the browser) is ignored. */
    public function markRead(User $user, int $notificationId): bool
    {
        return AppNotification::query()->whereKey($notificationId)->where('user_id', $user->id)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]) > 0;
    }

    public function markAllRead(User $user): int
    {
        return AppNotification::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /** Mark read the notifications about one approval request (the user opened its card). */
    public function markReadForRequest(User $user, int $requestId): int
    {
        return AppNotification::query()->where('user_id', $user->id)->where('approval_request_id', $requestId)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }
}
