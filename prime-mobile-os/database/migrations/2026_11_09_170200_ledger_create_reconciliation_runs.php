<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Results of the nightly ledger checks in production (audit №16, TZ 02 §13). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->boolean('ok')->default(false);
            $table->unsignedInteger('problem_count')->default(0);
            $table->jsonb('problems')->nullable();       // human-readable lines, at most 200
            $table->timestampsTz();
            $table->index(['organization_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
