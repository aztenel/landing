<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Releases expired cart reservations of every active firm (audit №51, TZ 02 §10.1: 30 minutes). */
class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'prime:release-reservations';

    protected $description = 'Release expired cart reservations';

    public function handle(ReservationService $reservations): int
    {
        $total = 0;
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            $total += CurrentOrganization::instance()->run($org, fn () => $reservations->releaseExpired());
        }
        $this->info("released {$total}");

        return self::SUCCESS;
    }
}
