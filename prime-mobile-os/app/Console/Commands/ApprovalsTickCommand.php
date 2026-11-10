<?php

namespace App\Console\Commands;

use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Every minute: expire requests (30 min), escalate to the admin (5 min), repeat the push (3 min) — TZ 03 §6.4. */
class ApprovalsTickCommand extends Command
{
    protected $signature = 'prime:approvals-tick';

    protected $description = 'Expire, escalate and re-notify approval requests';

    public function handle(ApprovalService $approvals): int
    {
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, fn () => $approvals->tick());
        }

        return self::SUCCESS;
    }
}
