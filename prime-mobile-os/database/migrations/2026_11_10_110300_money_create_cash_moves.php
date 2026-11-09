<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Движение денег» ДДС (TZ 02 §6.2 №37–39, TZ 01 П18): deposit, withdrawal, transfer between accounts of one
 * currency, collection (register → «в пути» → safe / owner, accepted by the owner) and the cash adjustment
 * (limit L16). One document = one currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kind', 24);                     // deposit|withdrawal|transfer|collection|collection_accept|adjustment
            $table->unsignedBigInteger('from_account_id')->nullable();
            $table->unsignedBigInteger('to_account_id')->nullable();
            $table->bigInteger('amount');                   // minor units of the account currency (adjustment: signed)
            $table->bigInteger('fee_amount')->default(0);   // transfer commission, from the source account
            $table->string('target', 8)->nullable();        // collection: safe | owner
            $table->unsignedBigInteger('collection_document_id')->nullable(); // accept → its collection
            $table->string('status', 16)->nullable();       // collection: sent | accepted (changes after posting)
            $table->unsignedBigInteger('accepted_document_id')->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'kind', 'status']);
        });
        DB::statement("ALTER TABLE cash_moves ADD CONSTRAINT cash_moves_kind_check CHECK (kind IN ('deposit','withdrawal','transfer','collection','collection_accept','adjustment'))");
        DB::statement("ALTER TABLE cash_moves ADD CONSTRAINT cash_moves_amount_check CHECK (amount <> 0 AND (kind = 'adjustment' OR amount > 0) AND fee_amount >= 0)");
        foreach (['from_account_id' => 'money_accounts', 'to_account_id' => 'money_accounts', 'collection_document_id' => 'documents', 'accepted_document_id' => 'documents'] as $column => $referenced) {
            DB::statement("ALTER TABLE cash_moves ADD CONSTRAINT cash_moves_{$column}_same_org_fk
                FOREIGN KEY (organization_id, {$column}) REFERENCES {$referenced} (organization_id, id) ON DELETE RESTRICT");
        }
        DB::unprepared("CREATE TRIGGER cash_moves_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON cash_moves FOR EACH ROW EXECUTE FUNCTION pm_posted_detail_guard('status', 'accepted_document_id')");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_moves');
    }
};
