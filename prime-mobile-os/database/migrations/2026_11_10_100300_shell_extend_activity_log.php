<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The action journal (TZ 03 §11, TZ 04 Э27) lives in spatie activity_log. Here: the fields of TZ 03 §11.2
 * that are filtered on (IP, device, store, role at that moment) and the «only INSERT» rule at the DB level
 * (TZ 03 §11.3 p.1) — the same pm_append_only() trigger as the accounting journals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // No FKs on purpose: the journal outlives deleted devices and a FK action would UPDATE an append-only row.
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('role', 64)->nullable();
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'causer_id', 'created_at']);
        });

        DB::unprepared('CREATE TRIGGER activity_log_append_only BEFORE UPDATE OR DELETE ON activity_log FOR EACH ROW EXECUTE FUNCTION pm_append_only()');
        DB::unprepared('CREATE TRIGGER activity_log_no_truncate BEFORE TRUNCATE ON activity_log FOR EACH STATEMENT EXECUTE FUNCTION pm_append_only()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_truncate ON activity_log');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_append_only ON activity_log');
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'causer_id', 'created_at']);
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn(['ip_address', 'user_agent', 'device_id', 'store_id', 'role']);
        });
    }
};
