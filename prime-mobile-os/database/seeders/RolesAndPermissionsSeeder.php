<?php

namespace Database\Seeders;

use App\Domain\Tenancy\Access\RoleProvisioner;
use App\Domain\Tenancy\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Global permission list of TZ 03 (PermissionCatalog) + the five role templates with limits in every existing
 * organization. Idempotent: roles already present are not touched (the owner may have edited them).
 * New organizations get their roles from OrganizationProvisioner::create().
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(RoleProvisioner::class);
        $provisioner->syncPermissions();
        Organization::query()->each(fn (Organization $org) => $provisioner->installFor($org));
    }
}
