<?php

namespace App\Support\Tenancy;

use App\Domain\Tenancy\Models\Organization;
use App\Support\Tenancy\Exceptions\NoOrganizationSelected;
use Spatie\Permission\PermissionRegistrar;

/**
 * The organization of the current request / job / console command (TZ 03 §12).
 * Singleton. Set by the SetCurrentOrganization middleware for HTTP, explicitly via run() in jobs, seeders, tests.
 * Also switches the spatie/permission team so that roles and permissions are read for this organization.
 */
final class CurrentOrganization
{
    private ?Organization $organization = null;

    public static function instance(): self
    {
        return app(self::class);
    }

    public function set(Organization|int $organization): Organization
    {
        $org = $organization instanceof Organization ? $organization : Organization::query()->findOrFail($organization);
        $this->organization = $org;
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

        return $org;
    }

    public function clear(): void
    {
        $this->organization = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function require(): Organization
    {
        return $this->organization ?? throw NoOrganizationSelected::generic();
    }

    public function requireId(): int
    {
        return $this->require()->id;
    }

    /**
     * Run a callback in the context of an organization and restore the previous context afterwards.
     *
     * @template T
     *
     * @param  callable(Organization): T  $callback
     * @return T
     */
    public function run(Organization|int $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $org = $this->set($organization);
        try {
            return $callback($org);
        } finally {
            $previous ? $this->set($previous) : $this->clear();
        }
    }
}
