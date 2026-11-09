<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan migrate:fresh --seed
 *
 * NB: no WithoutModelEvents here — tenancy, creators and append-only guards are model events.
 * Modules add their seeders by appending lines below.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(CatalogSeeder::class);

        // Demo firm with demo logins (password «password»): never on a production server.
        if (! app()->isProduction()) {
            $this->call(PrimeMobileSeeder::class);
        }
    }
}
