<?php

namespace App\Support\Tenancy;

use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\Exceptions\CrossOrganizationViolation;
use App\Support\Tenancy\Exceptions\NoOrganizationSelected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant model trait (ENGINEERING.md rule 1):
 *  - global scope by the current organization (reads without a context throw);
 *  - organization_id is filled from the context on create; another organization's id throws;
 *  - organization_id can never be changed.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $current = CurrentOrganization::instance()->id();
            if ($current === null) {
                throw NoOrganizationSelected::forWrite($model::class);
            }
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', $current);
            } elseif ((int) $model->getAttribute('organization_id') !== $current) {
                throw CrossOrganizationViolation::make($model::class, (int) $model->getAttribute('organization_id'), $current);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw CrossOrganizationViolation::make($model::class, (int) $model->getAttribute('organization_id'), (int) $model->getOriginal('organization_id'));
            }
            $current = CurrentOrganization::instance()->id();
            if ($current !== null && (int) $model->getAttribute('organization_id') !== $current) {
                throw CrossOrganizationViolation::make($model::class, (int) $model->getAttribute('organization_id'), $current);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Escape hatch for system code (integrator panel, nightly reconciliation across organizations). */
    public static function withoutOrganizationScope(): Builder
    {
        return static::query()->withoutGlobalScope(OrganizationScope::class);
    }
}
