<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Services\Reconciliation;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly ledger checks in production (audit №16, TZ 02 §13.2–13.3): balance copies = journals, hash chains intact.
 * The result of every firm is stored in reconciliation_runs; a problem goes to the log (error) and to the activity log,
 * where the notifications module picks it up for the owner.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'prime:reconcile {--organization= : only this organization id}';

    protected $description = 'Nightly ledger checks: balances equal journals, hash chains are intact';

    public function handle(): int
    {
        $failed = 0;
        $orgs = Organization::query()->where('is_active', true)
            ->when($this->option('organization'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($orgs as $org) {
            $started = now();
            $problems = CurrentOrganization::instance()->run($org, fn () => Reconciliation::problems()); // includes hash chains
            DB::table('reconciliation_runs')->insert([
                'organization_id' => $org->id, 'started_at' => $started, 'finished_at' => now(),
                'ok' => $problems === [], 'problem_count' => count($problems),
                'problems' => json_encode(array_slice($problems, 0, 200), JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($problems !== []) {
                $failed++;
                Log::error('prime:reconcile found problems', ['organization_id' => $org->id, 'problems' => array_slice($problems, 0, 20)]);
                activity('ledger')->withProperties(['organization_id' => $org->id, 'problem_count' => count($problems)])->log('reconciliation_failed');
            }
            $this->line(sprintf('%s: %s', $org->name, $problems === [] ? 'ok' : count($problems).' problem(s)'));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
