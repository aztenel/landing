<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders (bookings) ЗАК (TZ 01 П10.2, TZ 02 §6.2 №22–23, §10.3, TZ 04 Э7): a concrete phone (status ordered) or a
 * variant «под заказ», price fixed at the order, hold 3 days, optional prepayment = money + customer advance.
 * The order document is immutable; its lifecycle columns (status, hold, linked sale/cancellation, phone) change
 * after posting. Cancelling with a refund is a second «order» document (order_cancellations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 8);                              // unit | variant
            $table->unsignedBigInteger('stock_unit_id')->nullable();
            $table->foreignId('variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('price');                            // fixed at the order, tiyn
            $table->bigInteger('list_price')->nullable();           // price list at the order (discount check)
            $table->timestampTz('hold_until');
            $table->bigInteger('prepayment')->default(0);
            $table->string('prepayment_method', 32)->nullable();
            $table->unsignedBigInteger('prepayment_account_id')->nullable();
            $table->string('status', 16)->default('draft');         // draft|active|overdue|sold|cancelled|expired
            $table->unsignedBigInteger('sale_document_id')->nullable();
            $table->unsignedBigInteger('cancel_document_id')->nullable();
            $table->timestampTz('overdue_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedSmallInteger('extended_count')->default(0);
            $table->text('comment')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status', 'hold_until']);
            $table->index(['organization_id', 'variant_id', 'status']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_check CHECK (kind IN ('unit','variant') AND status IN ('draft','active','overdue','sold','cancelled','expired')
            AND price > 0 AND prepayment >= 0 AND prepayment <= price AND (kind = 'variant' OR stock_unit_id IS NOT NULL) AND (prepayment = 0 OR prepayment_account_id IS NOT NULL))");
        foreach (['customer_id' => 'customers', 'stock_unit_id' => 'stock_units', 'prepayment_account_id' => 'money_accounts', 'sale_document_id' => 'documents', 'cancel_document_id' => 'documents'] as $column => $referenced) {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_{$column}_same_org_fk
                FOREIGN KEY (organization_id, {$column}) REFERENCES {$referenced} (organization_id, id) ON DELETE RESTRICT");
        }
        DB::unprepared("CREATE TRIGGER orders_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('status', 'hold_until', 'stock_unit_id', 'sale_document_id', 'cancel_document_id', 'overdue_at', 'closed_at', 'extended_count', 'comment')");

        Schema::create('order_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_document_id');
            $table->bigInteger('refund')->default(0);
            $table->unsignedBigInteger('refund_account_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE order_cancellations ADD CONSTRAINT order_cancellations_check CHECK (refund >= 0 AND (refund = 0 OR refund_account_id IS NOT NULL))');
        foreach (['order_document_id' => 'documents', 'refund_account_id' => 'money_accounts'] as $column => $referenced) {
            DB::statement("ALTER TABLE order_cancellations ADD CONSTRAINT order_cancellations_{$column}_same_org_fk
                FOREIGN KEY (organization_id, {$column}) REFERENCES {$referenced} (organization_id, id) ON DELETE RESTRICT");
        }
        DB::unprepared('CREATE TRIGGER order_cancellations_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON order_cancellations FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellations');
        Schema::dropIfExists('orders');
    }
};
