<?php

namespace App\Console\Commands;

use App\Domain\Migration\Services\SfonClient;
use App\Domain\Migration\Services\SfonReconcile;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Nightly check with sfon during the parallel run (TZ 07 §1.9). Works only while the key is set and sfon is not switched off. */
class SfonReconcileCommand extends Command
{
    protected $signature = 'prime:sfon-reconcile';

    protected $description = 'Nightly comparison of stock and prices with sfon (parallel run)';

    public function handle(SfonClient $api): int
    {
        if (! $api->configured()) {
            $this->info('SFON_API_KEY is not set — nothing to do');

            return self::SUCCESS;
        }
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, function () use ($org) {
                if (DB::table('migration_checklist')->where('organization_id', $org->id)->where('step', 'Ш11')->value('status') === 'done'
                    || DB::table('migration_checklist')->where('organization_id', $org->id)->where('step', 'Ш8')->value('status') !== 'done') {
                    return;   // before day X or after switching sfon off — no check
                }
                $r = app(SfonReconcile::class)->run();
                $text = __('Сверка с sfon за :d: :n расхождений. Дней без расхождений: :s из 5.', ['d' => $r['day'], 'n' => count($r['diffs']), 's' => $r['streak']]);
                $body = collect($r['diffs'])->take(10)->map(fn ($d, $i) => ($i + 1).') '.$d['text'].' — '.$d['cause'])->implode("\n");
                app(Notifier::class)->toOwners(NotificationType::ReconciliationFailed, $text, $body ?: null);
                $this->info($org->name.': '.$text);
            });
        }

        return self::SUCCESS;
    }
}
