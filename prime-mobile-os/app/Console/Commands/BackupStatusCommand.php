<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Enums\NotificationType;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Watches deploy/backup/backup.sh (it writes storage/app/backup-status.json). No success for 26 hours or a failed
 * restore check → the integrator sees it in /health at once; the owner gets «Бэкап не прошёл» if it is not fixed
 * within 2 hours (TZ 06 §4.3), once a day.
 */
class BackupStatusCommand extends Command
{
    protected $signature = 'prime:backup-status';

    protected $description = 'Alert when the nightly backup or the restore check did not pass';

    public function handle(Notifier $notifier): int
    {
        if (! app()->environment('production')) {
            return self::SUCCESS;
        }
        $s = @json_decode((string) @file_get_contents(storage_path('app/backup-status.json')), true) ?: [];
        $last = isset($s['last_ok']) ? Carbon::parse($s['last_ok']) : null;
        $bad = ! $last || now()->diffInHours($last, true) >= 28;   // 26 h + the 2 hours to fix
        if (! $bad || ! Cache::add('backup-alert:'.now()->toDateString(), 1, now()->addDay())) {
            return self::SUCCESS;
        }
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            CurrentOrganization::instance()->run($org, fn () => $notifier->toOwners(NotificationType::System, __('Резервная копия не сделана'),
                __('Последняя успешная копия: :d. Интегратор уже знает.', ['d' => $last?->setTimezone($org->timezone)->format('d.m H:i') ?? __('нет')])));
        }
        $this->warn('backup is stale');

        return self::SUCCESS;
    }
}
