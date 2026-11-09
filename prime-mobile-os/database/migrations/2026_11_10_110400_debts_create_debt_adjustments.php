<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Корректировка долга» КДЛ (TZ 02 §6.2 №35, TZ 01 П14.1): ± debt of a customer or supplier with a reason, and the
 * postponement of a customer due date (TZ 03 №25 debt.customer_postpone) as a pair of entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('operation', 16);                 // adjust | postpone
            $table->string('counterparty_type', 16);         // customer | supplier
            $table->unsignedBigInteger('counterparty_id');
            $table->char('currency', 3)->default('KZT');
            $table->bigInteger('amount');                    // adjust: + they owe us more / − less; postpone: moved amount
            $table->date('due_date')->nullable();            // new due date (customer increase, postpone)
            $table->date('previous_due_date')->nullable();   // postpone: filled at posting
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE debt_adjustments ADD CONSTRAINT debt_adjustments_check CHECK (amount <> 0 AND operation IN ('adjust','postpone') AND counterparty_type IN ('customer','supplier') AND (operation = 'adjust' OR (amount > 0 AND due_date IS NOT NULL)))");
        DB::unprepared("CREATE TRIGGER debt_adjustments_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON debt_adjustments FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()");
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_adjustments');
    }
};
