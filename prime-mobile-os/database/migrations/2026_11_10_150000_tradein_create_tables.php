<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade-in and preparation of used phones (TZ 04 Э6, Э12, Э31; 01 П6, П12; audit №29, №40):
 *  - grid of buy-back prices (base per model + memory) and deductions;
 *  - act ТРД: device, checklist, grade, amount, the part «в зачёт» and payouts, the person (customer with IIN);
 *  - sale_payments.tradein_document_id: the offset of a check points to its act;
 *  - prep costs ЗПД: a cost added to a used phone, paid from an account or owed to a master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tradein_grid_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('model_id')->constrained('models')->restrictOnDelete();
            $table->foreignId('memory_id')->nullable()->constrained('memories')->restrictOnDelete();
            $table->bigInteger('base_price_kzt');                    // grade A, battery ≥ 85 %, tiyn
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'model_id', 'memory_id']);
        });
        Schema::create('tradein_grid_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 24);                              // grade_b, grade_c, battery_low, screen, parts, no_box, body
            $table->string('kind', 8);                               // amount | percent
            $table->bigInteger('value');                             // tiyn or percent × 100
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        DB::statement("ALTER TABLE tradein_grid_deductions ADD CONSTRAINT tradein_grid_deductions_check CHECK (kind IN ('amount','percent') AND value >= 0)");

        Schema::create('tradein_acts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->string('imei1', 32)->nullable();
            $table->string('imei2', 32)->nullable();
            $table->string('serial', 64)->nullable();
            $table->char('grade', 1);
            $table->unsignedSmallInteger('battery_percent')->nullable();
            $table->jsonb('checklist');                              // {icloud: yes|no|skip, screen: …}
            $table->jsonb('replaced')->nullable();                   // [screen, battery, …]
            $table->jsonb('kit')->nullable();                        // [box, cable]
            $table->string('imei_check', 16)->nullable();            // result of госбаза at the act
            $table->boolean('imei_verified')->default(false);        // compared with *#06#
            $table->bigInteger('amount');                            // buy-back price, tiyn
            $table->bigInteger('grid_price')->nullable();            // grid at the act (for the report «выше сетки»)
            $table->bigInteger('offset_amount')->default(0);         // part «в зачёт» of a check of the same operation
            $table->text('id_document_no')->nullable();              // encrypted
            $table->unsignedBigInteger('stock_unit_id')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE tradein_acts ADD CONSTRAINT tradein_acts_amount_check CHECK (amount > 0 AND offset_amount >= 0 AND offset_amount <= amount AND grade IN (\'A\',\'B\',\'C\'))');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS customers_org_id_unique ON customers (organization_id, id)');
        DB::statement('ALTER TABLE tradein_acts ADD CONSTRAINT tradein_acts_customer_same_org_fk FOREIGN KEY (organization_id, customer_id) REFERENCES customers (organization_id, id) ON DELETE RESTRICT');
        DB::unprepared("CREATE TRIGGER tradein_acts_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON tradein_acts FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('signed_at')");

        Schema::create('tradein_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32);                            // cash | kaspi_transfer | …
            $table->unsignedBigInteger('account_id')->nullable();    // from the payment method at posting
            $table->bigInteger('amount');
            $table->string('reference', 64)->nullable();             // Kaspi operation number
            $table->timestampsTz();
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE tradein_payouts ADD CONSTRAINT tradein_payouts_amount_check CHECK (amount > 0)');
        DB::unprepared('CREATE TRIGGER tradein_payouts_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON tradein_payouts FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()');

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('tradein_document_id')->nullable()->constrained('documents')->restrictOnDelete();
        });

        Schema::create('prep_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);                              // battery | screen | body | repair | other
            $table->bigInteger('amount');                            // tiyn
            $table->unsignedBigInteger('account_id')->nullable();    // paid from (null = owed to the master)
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete(); // the master
            $table->date('due_date')->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE prep_costs ADD CONSTRAINT prep_costs_check CHECK (amount > 0 AND kind IN ('battery','screen','body','repair','other') AND (account_id IS NOT NULL OR supplier_id IS NOT NULL))");
        DB::unprepared('CREATE TRIGGER prep_costs_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON prep_costs FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('prep_costs');
        Schema::table('sale_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('tradein_document_id'));
        foreach (['tradein_payouts', 'tradein_acts', 'tradein_grid_deductions', 'tradein_grid_prices'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
