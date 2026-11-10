<?php

namespace App\Console\Commands;

use App\Domain\Storefront\Services\Promotions;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Promotions by the clock (TZ 05 §6.3): start at «с», put the prices back at «по». */
class PromotionsTickCommand extends Command
{
    protected $signature = 'prime:promotions-tick';

    protected $description = 'Start and finish site promotions';

    public function handle(Promotions $p): int
    {
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, fn () => $p->tick());
        }

        return self::SUCCESS;
    }
}
