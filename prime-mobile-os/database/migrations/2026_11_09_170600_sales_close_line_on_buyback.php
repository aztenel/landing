<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit №39: a phone we sold and took back by trade-in closes its earlier sale line («выкуплен обратно»), so it can be
 * sold again (the partial unique index allows one ACTIVE sale line per phone). The closing columns may change on a
 * posted sale, like is_active and returned_qty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->string('closed_reason', 32)->nullable();             // bought_back
            $table->unsignedBigInteger('closed_by_document_id')->nullable();
        });
        DB::unprepared('DROP TRIGGER sale_lines_posted_guard ON sale_lines');
        DB::unprepared("CREATE TRIGGER sale_lines_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON sale_lines FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('is_active', 'returned_qty', 'closed_reason', 'closed_by_document_id')");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER sale_lines_posted_guard ON sale_lines');
        DB::unprepared("CREATE TRIGGER sale_lines_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON sale_lines FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('is_active', 'returned_qty')");
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropColumn(['closed_reason', 'closed_by_document_id']));
    }
};
