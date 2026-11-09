<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money references: accounts, payment methods, articles, exchange rates (TZ 02 §3.3, §11.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete(); // NULL = organization level (Сейф)
            $table->string('code', 32)->nullable();          // system code: cash_kzt, tradein_offset…
            $table->string('name');
            $table->string('type', 24);                      // cash, bank, installment_bank, safe, technical
            $table->char('currency', 3);
            $table->decimal('default_commission_percent', 5, 2)->default(0);
            $table->boolean('allow_negative')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'currency']);              // target of the composite FK from money_entries
            $table->unique(['organization_id', 'code']);
        });
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_type_check CHECK (type IN ('cash','bank','installment_bank','safe','technical'))");
        DB::statement("ALTER TABLE money_accounts ADD CONSTRAINT money_accounts_currency_check CHECK (currency IN ('KZT','USD'))");

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 32);                      // cash, card, kaspi_qr, … (PaymentMethodCode)
            $table->string('name_ru');
            $table->string('name_kk')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->boolean('is_refundable')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX payment_methods_unique ON payment_methods (organization_id, store_id, code) NULLS NOT DISTINCT');

        Schema::create('money_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 48);
            $table->string('name_ru');
            $table->string('name_kk')->nullable();
            $table->string('kind', 16);                      // income, expense, system
            $table->boolean('affects_profit')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        DB::statement("ALTER TABLE money_articles ADD CONSTRAINT money_articles_kind_check CHECK (kind IN ('income','expense','system'))");

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('USD');
            $table->decimal('rate', 14, 4);                  // ₸ per 1 unit of currency
            $table->timestampTz('valid_from');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('comment')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->index(['organization_id', 'currency', 'valid_from']);
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive CHECK (rate > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('money_articles');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('money_accounts');
    }
};
