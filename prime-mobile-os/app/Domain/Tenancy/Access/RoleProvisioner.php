<?php

namespace App\Domain\Tenancy\Access;

use App\Domain\Tenancy\Models\Organization;
use App\Domain\Tenancy\Models\RoleLimit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the global permission list and copies the five role templates (with limits) into an organization.
 * Idempotent: running again adds missing permissions/roles and does not touch roles the owner already edited
 * unless $resetExisting is true.
 */
class RoleProvisioner
{
    public function syncPermissions(): void
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
        $missing = array_values(array_diff(PermissionCatalog::codes(), $existing));
        if ($missing) {
            $now = now();
            DB::table('permissions')->insert(array_map(fn (string $code) => [
                'name' => $code, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now,
            ], $missing));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /** @return array<string, Role> */
    public function installFor(Organization $organization, bool $resetExisting = false): array
    {
        $this->syncPermissions();

        return CurrentOrganization::instance()->run($organization, function (Organization $org) use ($resetExisting) {
            $roles = [];
            foreach (RoleTemplates::TITLES as $code => $title) {
                $role = Role::query()->where('organization_id', $org->id)->where('name', $code)->where('guard_name', 'web')->first();
                $isNew = $role === null;
                if ($isNew) {
                    $role = Role::query()->create([
                        'name' => $code,
                        'guard_name' => 'web',
                        'organization_id' => $org->id,
                        'title' => $title,
                        'is_owner' => $code === RoleTemplates::OWNER,
                    ]);
                }
                if ($isNew || $resetExisting) {
                    $role->syncPermissions(PermissionCatalog::grantedTo($code));
                    $this->installLimits($role, $code);
                }
                $roles[$code] = $role;
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $roles;
        });
    }

    private function installLimits(Role $role, string $code): void
    {
        RoleLimit::query()->where('role_id', $role->id)->delete();
        if ($code === RoleTemplates::OWNER) {
            return; // owner: all rights, no limits
        }
        $rows = [];
        $now = now();
        $row = fn (string $limitCode, ?int $value) => [
            'organization_id' => $role->organization_id, 'role_id' => $role->id, 'code' => $limitCode,
            'value' => $value, 'created_at' => $now, 'updated_at' => $now,
        ];
        foreach (Limit::cases() as $limit) {
            $defaults = $limit->defaults();
            $rows[] = $row($limit->value, array_key_exists($code, $defaults) ? $defaults[$code] : null);
        }
        foreach (PermissionCatalog::approvalOnlyFor($code) as $permission) {
            $rows[] = $row($permission, 0);
        }
        DB::table('role_limits')->insert($rows);
    }
}
