<?php

namespace App\Livewire\Approvals;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalQueries;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Notifications\Services\NotificationCenter;
use App\Support\Exceptions\UserFacingException;
use Livewire\Component;

/**
 * TZ 04 Э28.1 «Колокольчик»: requests waiting for this user's decision (owner — all; admin — after 5 minutes, within
 * his limits) and the user's notifications. Refreshes every 10 s (wire:poll). «Разрешить» / «Отклонить» go through
 * ApprovalService, which checks the decider's right on the server.
 */
class Bell extends Component
{
    public bool $open = false;

    public ?string $error = null;

    public function decide(int $requestId, string $decision): void
    {
        $this->error = null;
        $request = ApprovalRequest::query()->findOrFail($requestId); // only requests of the current firm
        try {
            $decision === 'approve'
                ? app(ApprovalService::class)->approve($request, auth()->user())
                : app(ApprovalService::class)->reject($request, auth()->user());
        } catch (UserFacingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function markAllRead(): void
    {
        app(NotificationCenter::class)->markAllRead(auth()->user());
    }

    public function render()
    {
        $user = auth()->user();
        $center = app(NotificationCenter::class);

        return view('livewire.approvals.bell', [
            'counts' => $center->bell($user),
            'awaiting' => $this->open ? app(ApprovalQueries::class)->awaiting($user) : collect(),
            'notifications' => $this->open ? $center->latest($user, 15) : collect(),
            'tz' => \App\Support\Tenancy\CurrentOrganization::instance()->require()->timezone,
        ]);
    }
}
