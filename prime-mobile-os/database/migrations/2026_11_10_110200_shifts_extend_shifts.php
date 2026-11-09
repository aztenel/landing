<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash register shifts (TZ 02 §8, TZ 01 П1, П19): ONE open shift per store; opening and closing are documents
 * «СМН» (type shift_close) whose detail rows shift_counts keep «по учёту / посчитано / разница» per cash account
 * (₸ and $). A difference is written at once as a money entry «Недостача/излишек кассы».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('status', 16)->default('open');                         // open | closed | auto_closed
            $table->unsignedBigInteger('usd_account_id')->nullable();
            $table->bigInteger('opening_expected_usd')->nullable();
            $table->bigInteger('opening_counted_usd')->nullable();
            $table->bigInteger('closing_expected_usd')->nullable();
            $table->bigInteger('closing_counted_usd')->nullable();
            $table->jsonb('opening_denominations')->nullable();
            $table->text('opening_comment')->nullable();
            $table->text('closing_comment')->nullable();
            $table->bigInteger('kaspi_reported')->nullable();                      // «Итог Kaspi Pay за смену» (П19 p.4)
            $table->unsignedBigInteger('opening_document_id')->nullable();
            $table->unsignedBigInteger('closing_document_id')->nullable();
            $table->string('resolution', 16)->nullable();                          // accepted | withheld (П19 p.7)
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->jsonb('report')->nullable();                                   // Ф10 snapshot at closing
            $table->timestampTz('not_closed_notified_at')->nullable();
        });
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status_check CHECK (status IN ('open','closed','auto_closed') AND ((status = 'open') = (closed_at IS NULL)))");
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_resolution_check CHECK (resolution IS NULL OR resolution IN ('accepted','withheld'))");
        // «одна смена на магазин» (TZ README «Касса»)
        DB::statement('CREATE UNIQUE INDEX shifts_one_open_per_store ON shifts (store_id) WHERE closed_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX shifts_org_id_unique ON shifts (organization_id, id)');
        foreach (['usd_account_id' => 'money_accounts', 'opening_document_id' => 'documents', 'closing_document_id' => 'documents'] as $column => $referenced) {
            DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_{$column}_same_org_fk
                FOREIGN KEY (organization_id, {$column}) REFERENCES {$referenced} (organization_id, id) ON DELETE RESTRICT");
        }

        Schema::create('shift_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id')->nullable();                     // set when the shift row exists
            $table->string('phase', 8);                                            // open | close
            $table->unsignedBigInteger('account_id');
            $table->char('currency', 3);
            $table->bigInteger('expected')->nullable();                            // by the journal, filled at posting
            $table->bigInteger('counted');
            $table->bigInteger('difference')->nullable();
            $table->jsonb('denominations')->nullable();                            // {"20000": 3, "5000": 1, …}
            $table->timestampsTz();
            $table->index('document_id');
        });
        DB::statement("ALTER TABLE shift_counts ADD CONSTRAINT shift_counts_check CHECK (counted >= 0 AND phase IN ('open','close'))");
        DB::statement('ALTER TABLE shift_counts ADD CONSTRAINT shift_counts_account_same_org_fk
            FOREIGN KEY (organization_id, account_id) REFERENCES money_accounts (organization_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE shift_counts ADD CONSTRAINT shift_counts_shift_same_org_fk
            FOREIGN KEY (organization_id, shift_id) REFERENCES shifts (organization_id, id) ON DELETE RESTRICT');
        // shift_id is linked right after the opening document is posted (the row is created inside the posting)
        DB::unprepared("CREATE TRIGGER shift_counts_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON shift_counts FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('shift_id')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_counts');
        DB::statement('DROP INDEX IF EXISTS shifts_one_open_per_store');
        foreach (['usd_account_id', 'opening_document_id', 'closing_document_id'] as $column) {
            DB::statement("ALTER TABLE shifts DROP CONSTRAINT IF EXISTS shifts_{$column}_same_org_fk");
        }
        DB::statement('DROP INDEX IF EXISTS shifts_org_id_unique');
        DB::statement('ALTER TABLE shifts DROP CONSTRAINT IF EXISTS shifts_status_check');
        DB::statement('ALTER TABLE shifts DROP CONSTRAINT IF EXISTS shifts_resolution_check');
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn([
                'status', 'usd_account_id', 'opening_expected_usd', 'opening_counted_usd', 'closing_expected_usd', 'closing_counted_usd',
                'opening_denominations', 'opening_comment', 'closing_comment', 'kaspi_reported', 'opening_document_id', 'closing_document_id',
                'resolution', 'resolved_at', 'resolution_comment', 'report', 'not_closed_notified_at',
            ]);
        });
    }
};
