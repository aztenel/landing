<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\ImeiCheckResult;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Tenancy\Access\AccessChecker;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\CrossOrganizationViolation;
use Carbon\CarbonInterface;

/** «Отметить госбазу» (architecture §2.9): who, when, result. A sale requires a fresh «registered» mark. */
class ImeiCheckService
{
    public function __construct(private readonly AccessChecker $access) {}

    public function record(StockUnit $unit, ImeiCheckResult $result, User $user, string $source = 'manual', ?string $comment = null, ?CarbonInterface $at = null): ImeiCheck
    {
        if (! $this->access->can($user, 'stock.imei_check')) {
            throw AccessDenied::because('access_denied', 'Нет права отмечать проверку госбазы');
        }
        $current = CurrentOrganization::instance()->requireId();
        if ((int) $unit->organization_id !== $current) {
            throw CrossOrganizationViolation::make(StockUnit::class, (int) $unit->organization_id, $current);
        }

        return ImeiCheck::query()->create([
            'stock_unit_id' => $unit->id,
            'imei' => $unit->imei1 ?? $unit->serial,
            'result' => $result->value,
            'source' => $source,
            'checked_by' => $user->id,
            'checked_at' => $at ?? now(),
            'comment' => $comment,
        ]);
    }
}
