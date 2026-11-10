<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Models\Shift;
use App\Domain\Ledger\Services\Shifts;
use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Models\AppNotification;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\Store;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/**
 * Unclosed shifts (audit №57 p.5, TZ 01 П19 p.8), by the firm's clock:
 *   from 23:59 to 03:00 — once per shift the owner gets «Касса не закрыта»;
 *   at 03:00 a shift opened before today 03:00 is closed by itself, status «не сверена» (auto_closed), without a count
 *   and without РКС — the next opening count shows the difference.
 */
class ShiftsTickCommand extends Command
{
    protected $signature = 'prime:shifts-tick';

    protected $description = 'Unclosed shifts: «Касса не закрыта» at 23:59, auto close at 03:00';

    public function handle(Shifts $shifts, Notifier $notifier): int
    {
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, function () use ($org, $shifts, $notifier) {
                $local = now($org->timezone);
                $cutoff = $local->copy()->setTime(3, 0);
                $late = $local->format('H:i') >= '23:59' || $local->lt($cutoff);

                foreach (Shift::query()->where('status', 'open')->orderBy('id')->get() as $shift) {
                    if ($local->gte($cutoff) && $shift->opened_at->lt($cutoff)) {
                        $shifts->close($shift, null, auto: true);
                        $this->line("{$org->name}: {$shift->number} auto closed");

                        continue;
                    }
                    if ($late && ! AppNotification::query()->where('type', NotificationType::CashNotClosed->value)->where('data->shift_id', $shift->id)->exists()) {
                        $store = Store::query()->find($shift->store_id)?->name ?? '';
                        $notifier->toOwners(NotificationType::CashNotClosed, __('Касса не закрыта: :store', ['store' => $store]),
                            __('Смена :number открыта. В 03:00 закроется сама без пересчёта.', ['number' => $shift->number]),
                            ['data' => ['shift_id' => $shift->id, 'store_id' => $shift->store_id]]);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
