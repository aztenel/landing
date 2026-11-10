<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a customer debt payment covered the sale lines sold on credit, oldest first (audit №25 p.4): the repaid part of a
 * line and the bonuses accrued for it. A reversed payment drops out by its document status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_line_id');
            $table->bigInteger('amount');                    // repaid part of the line's credit, tiyn
            $table->integer('bonuses')->default(0);          // bonuses accrued by this payment for the line
            $table->timestampsTz();
            $table->index('document_id');
            $table->index('sale_line_id');
        });
        DB::statement('ALTER TABLE debt_payment_allocations ADD CONSTRAINT debt_payment_allocations_check CHECK (amount > 0 AND bonuses >= 0)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sale_lines_org_id_unique ON sale_lines (organization_id, id)');
        DB::statement('ALTER TABLE debt_payment_allocations ADD CONSTRAINT debt_payment_allocations_sale_line_same_org_fk
            FOREIGN KEY (organization_id, sale_line_id) REFERENCES sale_lines (organization_id, id) ON DELETE RESTRICT');
        DB::unprepared('CREATE TRIGGER debt_payment_allocations_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON debt_payment_allocations FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payment_allocations');
        DB::statement('DROP INDEX IF EXISTS sale_lines_org_id_unique');
    }
};
