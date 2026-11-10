<?php

namespace App\Console\Commands;

use App\Domain\Orders\Services\Orders;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/** Orders whose hold ran out: expire (no prepayment) or «Просрочен» with a notification (TZ 01 П10.2). */
class OrdersTickCommand extends Command
{
    protected $signature = 'prime:orders-tick';

    protected $description = 'Orders: expire holds without prepayment, mark overdue ones with prepayment';

    public function handle(Orders $orders): int
    {
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            $r = CurrentOrganization::instance()->run($org, fn () => $orders->tick());
            if ($r['expired'] + $r['overdue'] > 0) {
                $this->line("{$org->name}: expired {$r['expired']}, overdue {$r['overdue']}");
            }
        }

        return self::SUCCESS;
    }
}
