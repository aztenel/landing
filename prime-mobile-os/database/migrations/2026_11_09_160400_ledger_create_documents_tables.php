<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents header, numbering counters, accounting periods, shifts (TZ 02 §2, §8, §9, §11.1, §11.5).
 */
return new class extends Migration
{
    public const TYPES = [
        'purchase', 'sale', 'customer_return', 'tradein', 'prep_cost', 'unit_edit', 'cost_adjustment',
        'transfer_out', 'transfer_in', 'write_off', 'supplier_return', 'revision', 'expense',
        'debt_payment_in', 'debt_payment_out', 'debt_adjustment', 'cash_move', 'fx_convert', 'shift_close',
        'bonus_manual', 'order', 'opening_balance', 'reversal',
    ];

    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('number', 32)->nullable();          // ПРД-2026-004812, NULL for drafts
            $table->unsignedSmallInteger('number_year')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('occurred_at');                // business date (хоз. дата), UTC
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('reversed_by_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('reason_code', 32)->nullable();
            $table->text('reason_text')->nullable();
            $table->decimal('exchange_rate', 14, 4)->nullable();
            $table->boolean('exchange_rate_manual')->default(false);
            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable(); // FK added by the Approvals module
            $table->uuid('exchange_group_id')->nullable();
            $table->string('fiscal_receipt_no', 64)->nullable();
            $table->text('comment')->nullable();
            $table->jsonb('meta')->nullable();                 // module-specific header data
            $table->timestampsTz();

            $table->index(['organization_id', 'type', 'occurred_at']);
            $table->index(['organization_id', 'status', 'occurred_at']);
            $table->unique(['organization_id', 'idempotency_key']);
        });

        $types = "'".implode("','", self::TYPES)."'";
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ($types))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('draft','posted','reversed'))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_number_check CHECK ((status = 'draft') = (number IS NULL))");
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_reversal_check CHECK ((type = 'reversal') = (reversal_of_document_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX documents_number_unique ON documents (organization_id, type, number_year, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX documents_number_org_unique ON documents (organization_id, number) WHERE number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX documents_one_reversal ON documents (reversal_of_document_id) WHERE reversal_of_document_id IS NOT NULL');

        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('series', 8);                      // number prefix: ПРД, ПРХ, …
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'series', 'year']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->date('month');                            // first day of the month
            $table->string('status', 8)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'month']);
        });
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('open','closed'))");

        Schema::create('period_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->jsonb('data');
            $table->timestampTz('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_snapshots');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('document_counters');
        Schema::dropIfExists('documents');
    }
};
