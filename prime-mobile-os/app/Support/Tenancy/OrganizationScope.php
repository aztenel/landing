<?php

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\NoOrganizationSelected;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope «organization_id = current organization». Querying without a selected organization throws —
 * a forgotten context must fail loudly, never return another firm's rows.
 * System code that really needs all organizations uses Model::withoutOrganizationScope().
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = CurrentOrganization::instance()->id();
        if ($id === null) {
            throw NoOrganizationSelected::forRead($model::class);
        }
        $builder->where($model->qualifyColumn('organization_id'), $id);
    }
}
