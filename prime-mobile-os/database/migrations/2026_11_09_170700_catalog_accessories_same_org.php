<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Audit №9: an accessory of another firm can never get stock, cost or a movement here (composite FKs). */
return new class extends Migration
{
    public const TABLES = ['accessory_balances', 'accessory_costs', 'inventory_movements', 'sale_lines', 'purchase_lines', 'opening_balance_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_accessory_same_org_fk
                FOREIGN KEY (organization_id, accessory_id) REFERENCES accessories (organization_id, id) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_accessory_same_org_fk");
        }
    }
};
