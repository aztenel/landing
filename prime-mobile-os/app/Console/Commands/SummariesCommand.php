<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Reports\Services\Summaries;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Owner summaries by the clock of the firm (TZ 06 §4.1): at settings.notifications.summary_time (09:00) — «Итог вчера»,
 * on Monday also «Итог недели», on the 1st — «Итог месяца»; at 22:30 — «Итог дня». Each one once a day.
 */
class SummariesCommand extends Command
{
    protected $signature = 'prime:summaries {--force= : day|yesterday|week|month — send now}';

    protected $description = 'Owner summaries: day, yesterday, week, month';

    public function handle(Summaries $s, Notifier $notifier): int
    {
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, function () use ($org, $s, $notifier) {
                $now = CarbonImmutable::now($org->timezone);
                $morning = (string) $org->settings()->get('notifications.summary_time', '09:00');
                $due = [];
                if ($this->option('force')) {
                    $due[] = $this->option('force');
                } else {
                    if ($now->format('H:i') === $morning) {
                        $due[] = 'yesterday';
                        $now->isMonday() && $due[] = 'week';
                        $now->day === 1 && $due[] = 'month';
                    }
                    $now->format('H:i') === '22:30' && $due[] = 'day';
                }
                foreach ($due as $kind) {
                    $key = 'summary:'.$org->id.':'.$kind.':'.$now->toDateString();
                    if (! $this->option('force') && ! Cache::add($key, 1, now()->addDay())) {
                        continue;
                    }
                    $msg = match ($kind) {
                        'yesterday' => $s->day($now->subDay(), true),
                        'week' => $s->week($now->subWeek()),
                        'month' => $s->month($now->subMonthNoOverflow()),
                        default => $s->day($now),
                    };
                    $notifier->toOwners(NotificationType::DailySummary, $msg['title'], $msg['body'], ['url' => '/']);
                    $this->info($org->name.': '.$msg['title']);
                }
            });
        }

        return self::SUCCESS;
    }
}
