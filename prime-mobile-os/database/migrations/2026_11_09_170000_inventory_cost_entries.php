<?php

use App\Domain\Ledger\Enums\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fixes (docs/audit/decisions.md):
 *  - №4  «Внесение себестоимости» ВСБ: cost entered later for phones received without a purchase price;
 *  - №3, №54, №57: new document types ВДП, БСГ, РКС (the type «shift_close» is replaced by «cash_adjustment»).
 * The CHECK on documents.type is rebuilt from DocumentType, so it always lists every type of the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = "'".implode("','", array_map(fn (DocumentType $t) => $t->value, DocumentType::cases()))."'";
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ($types))");

        Schema::create('cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->date('due_date')->nullable();
            $table->bigInteger('total')->nullable();                  // purchase currency, set when posted
            $table->bigInteger('credit_amount')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
        });
        Schema::create('cost_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_unit_id')->constrained()->restrictOnDelete();
            $table->bigInteger('price');                              // purchase currency, minor units
            $table->bigInteger('cost_kzt')->nullable();               // set when posted
            $table->timestampsTz();
            $table->unique(['document_id', 'stock_unit_id']);
        });
        Schema::create('cost_entry_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('amount');                             // account currency
            $table->bigInteger('covered_amount')->nullable();         // purchase currency, set when posted
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE cost_entry_lines ADD CONSTRAINT cost_entry_lines_price_check CHECK (price > 0)');
        DB::statement('ALTER TABLE cost_entry_payments ADD CONSTRAINT cost_entry_payments_amount_check CHECK (amount > 0)');
        foreach (['cost_entries', 'cost_entry_lines', 'cost_entry_payments'] as $t) {
            DB::unprepared("CREATE TRIGGER {$t}_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard()");
        }
    }

    public function down(): void
    {
        foreach (['cost_entry_payments', 'cost_entry_lines', 'cost_entries'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
