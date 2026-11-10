<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module «Перенос из sfon» (TZ 07 §1): the import never writes the live journals directly —
 * file/API → raw rows (import_rows) → checks → draft ВХО → posting. Every moved object keeps sfon_id.
 */
return new class extends Migration
{
    private const SFON_ID = ['stores', 'product_types', 'families', 'models', 'memories', 'colors', 'rams', 'stock_units', 'customers', 'suppliers', 'accessories'];

    public function up(): void
    {
        foreach (self::SFON_ID as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->bigInteger('sfon_id')->nullable()->index();
            });
        }

        Schema::create('import_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('source', 12);                    // api | excel | manual
            $t->string('kind', 24);
            $t->string('file_name')->nullable();
            $t->jsonb('columns')->nullable();            // our field => column of the file
            $t->jsonb('totals')->nullable();             // rows / ok / error / skipped
            $t->string('status', 12)->default('done');   // done | failed
            $t->text('error')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestampTz('created_at');
        });

        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('run_id')->nullable();
            $t->string('kind', 24);
            $t->string('sfon_key', 120);                 // sfon id or a synthetic key (phone, store|variant …)
            $t->jsonb('data');                           // the source row as is
            $t->jsonb('fields')->nullable();             // normalised: variant_id, store_id, qty, cost, price, phone …
            $t->string('status', 12)->default('new');    // new | matched | error | skipped | in_vxo
            $t->string('error')->nullable();
            $t->timestampTz('updated_at');
            $t->unique(['organization_id', 'kind', 'sfon_key']);
            $t->index(['organization_id', 'kind', 'status']);
        });

        Schema::create('import_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('kind', 16);                      // variant | color | model | account | store
            $t->string('source_value', 200);
            $t->bigInteger('target_id');
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'kind', 'source_value']);
        });

        Schema::create('migration_checklist', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->string('step', 4);                       // Ш0 … Ш11
            $t->string('status', 8)->default('todo');
            $t->foreignId('done_by')->nullable();
            $t->timestampTz('done_at')->nullable();
            $t->text('note')->nullable();
            $t->string('attachment_path')->nullable();   // private disk: signed lists
            $t->unique(['organization_id', 'step']);
        });

        Schema::create('migration_scans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('store_id');
            $t->string('imei', 20);
            $t->foreignId('import_row_id')->nullable();  // the sfon row this phone «took»
            $t->foreignId('variant_id')->nullable();
            $t->string('condition', 8)->default('new');
            $t->boolean('extra')->default(false);        // physically here, not in sfon
            $t->bigInteger('cost')->nullable();          // tiyn
            $t->bigInteger('price')->nullable();         // tiyn
            $t->smallInteger('battery')->nullable();
            $t->foreignId('scanned_by')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'imei']);
        });

        Schema::create('sfon_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->date('day');
            $t->integer('discrepancies');
            $t->jsonb('details')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'day']);
        });

        Schema::create('sfon_archive', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained();
            $t->foreignId('run_id')->nullable();
            $t->string('kind', 16);                      // sale | return | receipt | expense | customer | debt
            $t->string('month', 7)->nullable();          // 2025-03
            $t->timestampTz('occurred_at')->nullable();
            $t->string('number', 60)->nullable();
            $t->string('imei', 20)->nullable()->index();
            $t->string('customer_phone', 20)->nullable()->index();
            $t->string('title', 200)->nullable();
            $t->bigInteger('amount')->nullable();        // tiyn
            $t->bigInteger('cost')->nullable();
            $t->jsonb('data');
            $t->index(['organization_id', 'kind', 'month']);
        });
    }

    public function down(): void
    {
        foreach (['sfon_archive', 'sfon_reconciliations', 'migration_scans', 'migration_checklist', 'import_mappings', 'import_rows', 'import_runs'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (self::SFON_ID as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('sfon_id'));
        }
    }
};
