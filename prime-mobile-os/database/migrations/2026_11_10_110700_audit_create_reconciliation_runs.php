<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly reconciliation runs (TZ 02 §11.5, §13): result, discrepancies, owner lists (§13.2 №15–17) and the heads
 * of the hash chains (last chain_seq + hash per journal) — a deleted «tail» of a journal is caught by comparing
 * with the previous run (CORE.md §12 «Осталось»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('business_date');                     // night of (organization timezone)
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->boolean('ok')->default(false);
            $table->jsonb('problems')->nullable();             // list of strings
            $table->jsonb('lists')->nullable();                // informational lists for the owner
            $table->jsonb('chain_heads')->nullable();          // journal => {seq, hash}
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
