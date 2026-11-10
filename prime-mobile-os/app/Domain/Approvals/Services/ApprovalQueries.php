<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read side of the approvals for the bell and the «Заявки» screen (TZ 04 Э28): requests the user may decide now
 * (the owner — all; an admin — after the escalation, within his limits) and the user's own open requests.
 */
class ApprovalQueries
{
    public function __construct(private readonly DeciderPolicy $policy) {}

    /** @return Collection<int, ApprovalRequest> open requests this user may decide now, oldest first */
    public function awaiting(User $user): Collection
    {
        return ApprovalRequest::query()->open()->where('requested_by', '<>', $user->id)
            ->with('requester')->orderBy('id')->get()
            ->filter(fn (ApprovalRequest $r) => $this->policy->canDecide($user, $r))->values();
    }

    public function awaitingCount(User $user): int
    {
        return $this->awaiting($user)->count();
    }

    /** @return Collection<int, ApprovalRequest> the user's own requests of the last day, newest first */
    public function mine(User $user): Collection
    {
        return ApprovalRequest::query()->where('requested_by', $user->id)
            ->where('created_at', '>=', now()->subDay())->orderByDesc('id')->get();
    }

    public function mineOpenCount(User $user): int
    {
        return ApprovalRequest::query()->open()->where('requested_by', $user->id)->count();
    }
}
