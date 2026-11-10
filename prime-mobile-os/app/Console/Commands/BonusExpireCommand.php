<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\Services\BonusExpiry;
use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;
use Throwable;

/** Nightly «Сгорание бонусов» БСГ, one system document per firm (audit №54). */
class BonusExpireCommand extends Command
{
    protected $signature = 'prime:bonus-expire';

    protected $description = 'Nightly expiry of customer bonuses: one system document БСГ per firm';

    public function handle(BonusExpiry $expiry): int
    {
        $failed = 0;
        foreach (Organization::query()->where('is_active', true)->get() as $org) {
            try {
                $doc = CurrentOrganization::instance()->run($org, fn () => $expiry->run());
                $this->line($org->name.': '.($doc ? $doc->number : 'nothing expired'));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error($org->name.': '.$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
