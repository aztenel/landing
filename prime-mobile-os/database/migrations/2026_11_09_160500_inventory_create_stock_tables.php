<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory: serialized stock units (one physical phone), identifiers, reservations, IMEI registry checks,
 * price history, accessory balance copies (architecture §2.3–2.4, §2.7; TZ 02 §10, §11.3, §11.5).
 */
return new class extends Migration
{
    public const STATUSES = ['in_stock', 'reserved', 'ordered', 'in_transit', 'preparing', 'on_check', 'defective', 'sold', 'returned_to_supplier', 'written_off'];

    public function up(): void
    {
        // Public code for the storefront: <prefix>-10001… with a counter of each firm (audit №43, migration …170800).

        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained()->restrictOnDelete();
            $table->string('condition', 8)->default('new');          // new | used
            $table->char('grade', 1)->nullable();                     // A | B | C (used only)
            $table->unsignedSmallInteger('battery_percent')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->text('defects')->nullable();
            $table->text('description')->nullable();
            // Display/search copies. The authoritative uniqueness lives in unit_identifiers.
            $table->string('imei1', 32)->nullable();
            $table->string('imei2', 32)->nullable();
            $table->string('serial', 64)->nullable();
            $table->string('public_code', 16)->nullable();             // set by the trigger pm_stock_unit_public_code
            $table->boolean('show_on_site')->default(false);
            $table->bigInteger('sale_price')->nullable();              // tiyn
            // Copies maintained by InventoryLedger only (TZ 02 §11.3). status NULL = «нет в учёте» (creation reversed).
            $table->string('status', 24)->nullable();
            $table->string('location_type', 16)->nullable();           // store | transit
            $table->unsignedBigInteger('location_id')->nullable();
            $table->bigInteger('cost_kzt')->default(0);                // tiyn
            $table->char('cost_currency', 3)->nullable();
            $table->bigInteger('cost_amount_orig')->nullable();        // minor units of cost_currency
            $table->decimal('cost_rate', 14, 4)->nullable();
            $table->boolean('cost_unknown')->default(false);
            $table->unsignedBigInteger('last_movement_id')->nullable();
            // Origin
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('order_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'variant_id']);
            $table->index(['organization_id', 'location_type', 'location_id']);
            $table->index(['organization_id', 'imei1']);
        });
        // dropped together with the table (migrate:fresh drops tables only)
        $statuses = "'".implode("','", self::STATUSES)."'";
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_status_check CHECK (status IS NULL OR status IN ($statuses))");
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_condition_check CHECK (condition IN ('new','used'))");
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_grade_check CHECK (grade IS NULL OR grade IN ('A','B','C'))");
        DB::statement('ALTER TABLE stock_units ADD CONSTRAINT stock_units_battery_check CHECK (battery_percent IS NULL OR battery_percent BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE stock_units ADD CONSTRAINT stock_units_cost_check CHECK (cost_kzt >= 0)');
        DB::statement("ALTER TABLE stock_units ADD CONSTRAINT stock_units_location_check CHECK (location_type IS NULL OR location_type IN ('store','transit'))");

        // Layer 1 of IMEI protection (architecture §2.7): one IMEI/serial = one unit per organization, forever.
        Schema::create('unit_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                                 // imei1 | imei2 | serial
            $table->string('value', 64);
            $table->timestampTz('created_at')->nullable();
            $table->unique(['organization_id', 'value']);
            $table->index('value');                                    // cross-organization warning lookup
        });
        DB::statement("ALTER TABLE unit_identifiers ADD CONSTRAINT unit_identifiers_kind_check CHECK (kind IN ('imei1','imei2','serial'))");

        // Layer 2: cart reservation, one active per unit.
        Schema::create('unit_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('cart_key', 64)->nullable();
            $table->timestampTz('reserved_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 16)->nullable();          // removed | expired | sold | cancelled
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX unit_reservations_one_active ON unit_reservations (stock_unit_id) WHERE released_at IS NULL');

        Schema::create('imei_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->string('imei', 32);
            $table->string('result', 16);                              // registered | not_registered | error
            $table->string('source', 64)->default('manual');           // imei.rfs.gov.kz (не проверено), egov…
            $table->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_at');
            $table->string('attachment_path')->nullable();
            $table->text('comment')->nullable();
            $table->index(['organization_id', 'stock_unit_id', 'checked_at']);
        });
        DB::statement("ALTER TABLE imei_checks ADD CONSTRAINT imei_checks_result_check CHECK (result IN ('registered','not_registered','error'))");

        Schema::create('price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('subject_type', 16);                        // stock_unit | variant | accessory
            $table->unsignedBigInteger('subject_id');
            $table->bigInteger('old_price')->nullable();
            $table->bigInteger('new_price')->nullable();
            $table->string('reason', 16)->default('manual');           // manual | fx_recalc | promo
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        // Accessory copies: quantity per place, moving average cost per organization (TZ 02 §4.2).
        Schema::create('accessory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('location_type', 16);
            $table->unsignedBigInteger('location_id');
            $table->foreignId('accessory_id')->constrained()->restrictOnDelete();
            $table->integer('qty')->default(0);
            $table->unsignedBigInteger('last_movement_id')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['organization_id', 'location_type', 'location_id', 'accessory_id'], 'accessory_balances_key');
        });
        DB::statement('ALTER TABLE accessory_balances ADD CONSTRAINT accessory_balances_qty_check CHECK (qty >= 0)');

        Schema::create('accessory_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('accessory_id')->constrained()->restrictOnDelete();
            $table->integer('total_qty')->default(0);
            $table->bigInteger('avg_cost_kzt')->default(0);            // tiyn
            $table->unsignedBigInteger('last_movement_id')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['organization_id', 'accessory_id']);
        });
        DB::statement('ALTER TABLE accessory_costs ADD CONSTRAINT accessory_costs_check CHECK (total_qty >= 0 AND avg_cost_kzt >= 0)');
    }

    public function down(): void
    {
        foreach (['accessory_costs', 'accessory_balances', 'price_changes', 'imei_checks', 'unit_reservations', 'unit_identifiers', 'stock_units'] as $t) {
            Schema::dropIfExists($t);
        }
        
    }
};
