<?php

namespace App\Console\Commands;

use App\Domain\Storefront\Services\ListingBuilder;
use App\Domain\Storefront\Services\Photos;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly full check of the site (TZ 05 §8.6), 03:40: every card is rebuilt from the warehouse; what differed is
 * fixed and written to storefront_runs (and the activity log). Expired pins and old prices are dropped, files of
 * photos removed more than 30 days ago are deleted.
 */
class SiteReconcileCommand extends Command
{
    protected $signature = 'prime:site-reconcile';

    protected $description = 'Nightly check: rebuild the public site from the warehouse and report differences';

    public function handle(ListingBuilder $builder, Photos $photos): int
    {
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, function () use ($builder, $photos, $org) {
                DB::table('stock_units')->where('organization_id', $org->id)->whereNotNull('site_pinned_until')->where('site_pinned_until', '<', now()->toDateString())
                    ->update(['site_pinned_rank' => null, 'site_pinned_until' => null]);
                $changed = $builder->all();
                $purged = $photos->purge();
                DB::table('storefront_runs')->insert(['organization_id' => $org->id, 'kind' => 'reconcile', 'fixed' => count($changed),
                    'details' => json_encode(['units' => array_slice($changed, 0, 50), 'purged_photos' => $purged]), 'created_at' => now()]);
                if ($changed) {
                    activity('site')->withProperties(['organization_id' => $org->id, 'fixed' => count($changed)])->log('site.reconcile_fixed');
                }
                $this->info($org->name.': fixed '.count($changed));
            });
        }

        return self::SUCCESS;
    }
}
