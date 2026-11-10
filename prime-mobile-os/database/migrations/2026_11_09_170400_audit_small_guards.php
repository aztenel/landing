<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit fixes (docs/audit/decisions.md):
 *  №14 the activity log of employees is append-only (no UPDATE / DELETE / TRUNCATE), like the journals;
 *  №44 «Показывать на сайте» is on by default (stock units, purchase lines, opening balance lines);
 *  №55 the IMEI registry check has the result «blocked» («Заблокирован»).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER activity_log_append_only BEFORE UPDATE OR DELETE ON activity_log FOR EACH ROW EXECUTE FUNCTION pm_append_only()');
        DB::unprepared('CREATE TRIGGER activity_log_no_truncate BEFORE TRUNCATE ON activity_log FOR EACH STATEMENT EXECUTE FUNCTION pm_append_only()');

        foreach (['stock_units', 'purchase_lines', 'opening_balance_lines'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN show_on_site SET DEFAULT true");
        }

        DB::statement('ALTER TABLE imei_checks DROP CONSTRAINT imei_checks_result_check');
        DB::statement("ALTER TABLE imei_checks ADD CONSTRAINT imei_checks_result_check CHECK (result IN ('registered','not_registered','blocked','error'))");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_append_only ON activity_log');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_truncate ON activity_log');
        foreach (['stock_units', 'purchase_lines', 'opening_balance_lines'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN show_on_site SET DEFAULT false");
        }
    }
};
