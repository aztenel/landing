<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse documents of round 3 (TZ 02 №24–32, №48–49; TZ 04 Э14–Э17): one header table and one line table for
 * transfer out / in, write-off, supplier return, revision, unit edit and cost adjustment; the revision scanning
 * sessions; warranty cases (not an accounting document: number ГРО, the phone stays «продан»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('to_store_id')->nullable()->constrained('stores')->restrictOnDelete();     // transfer out
            $table->foreignId('transfer_document_id')->nullable()->constrained('documents')->restrictOnDelete(); // transfer in → its out
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();          // supplier return
            $table->string('refund_mode', 8)->nullable();                                              // money | debt
            $table->unsignedBigInteger('refund_account_id')->nullable();
            $table->bigInteger('refund_amount')->default(0);                                           // tiyn
            $table->string('status', 16)->nullable();                                                  // transfer out: in_transit | received
            $table->unsignedBigInteger('revision_id')->nullable();
            $table->jsonb('changes')->nullable();                                                      // unit edit: {field: [old, new]}
            $table->text('reason')->nullable();
            $table->timestampsTz();
        });
        DB::unprepared("CREATE TRIGGER stock_ops_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_ops FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('status')");

        Schema::create('stock_op_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('line_type', 16);                     // unit | accessory
            $table->foreignId('stock_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('accessory_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('qty')->default(1);                  // revision: − shortage / + surplus; transfer in: accepted
            $table->integer('refused_qty')->default(0);          // transfer in: refused, goes back
            $table->bigInteger('cost_kzt')->nullable();          // revision surplus of a new phone: cost by the owner; cost adjustment: delta
            $table->string('kind', 16)->nullable();              // revision: shortage | surplus; write-off reason code
            $table->string('reason')->nullable();
            $table->timestampsTz();
            $table->index('document_id');
        });
        DB::statement("ALTER TABLE stock_op_lines ADD CONSTRAINT stock_op_lines_subject_check CHECK (line_type IN ('unit','accessory') AND num_nonnulls(stock_unit_id, accessory_id) = 1 AND refused_qty >= 0)");
        DB::unprepared('CREATE TRIGGER stock_op_lines_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON stock_op_lines FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()');

        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16)->default('all');         // all | phones | accessories
            $table->string('status', 16)->default('open');      // open | posted | cancelled
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('revision_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('revision_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->foreignId('stock_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('accessory_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('qty')->default(1);
            $table->foreignId('scanned_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('revision_id');
        });

        Schema::create('warranty_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('number', 32);                        // ГРО-2026-000012
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('complaint');
            $table->string('kit')->nullable();
            $table->string('status', 16)->default('accepted');   // accepted | in_repair | at_service | ready | issued | refused
            $table->string('repair_where', 8)->nullable();       // self | service
            $table->foreignId('service_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->text('result')->nullable();
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'number']);
        });
        DB::statement("ALTER TABLE warranty_cases ADD CONSTRAINT warranty_cases_status_check CHECK (status IN ('accepted','in_repair','at_service','ready','issued','refused'))");
    }

    public function down(): void
    {
        foreach (['warranty_cases', 'revision_scans', 'revisions', 'stock_op_lines', 'stock_ops'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
