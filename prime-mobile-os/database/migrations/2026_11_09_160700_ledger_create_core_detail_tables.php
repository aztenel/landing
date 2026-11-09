<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detail tables of the document types implemented in the core (TZ 02 §11.1):
 * sale, customer_return, purchase, expense, debt_payment_in/out, fx_convert, opening_balance.
 * All money in minor units (tiyn / cents). Editing is blocked by trigger once the document is posted.
 */
return new class extends Migration
{
    /** Columns describing a phone being taken into stock (purchase line, opening line). */
    private function unitIntakeColumns(Blueprint $table): void
    {
        $table->foreignId('variant_id')->nullable()->constrained()->restrictOnDelete();
        $table->string('imei1', 32)->nullable();
        $table->string('imei2', 32)->nullable();
        $table->string('serial', 64)->nullable();
        $table->string('condition', 8)->nullable();
        $table->char('grade', 1)->nullable();
        $table->unsignedSmallInteger('battery_percent')->nullable();
        $table->unsignedSmallInteger('year')->nullable();
        $table->text('defects')->nullable();
        $table->bigInteger('sale_price')->nullable();
        $table->boolean('show_on_site')->default(false);
        $table->string('status_after', 24)->nullable();            // in_stock | preparing
        $table->foreignId('stock_unit_id')->nullable()->constrained()->restrictOnDelete(); // filled when posted
    }

    public function up(): void
    {
        // ---------------- Sale ----------------
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('source', 16)->default('pos');              // pos | site | phone | kaspi
            $table->foreignId('order_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->bigInteger('subtotal')->default(0);                // Σ price × qty
            $table->bigInteger('check_discount')->default(0);          // discount on the whole check (input)
            $table->bigInteger('discount_total')->default(0);          // line discounts + check discount
            $table->bigInteger('total')->default(0);                   // to pay
            $table->timestampsTz();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('line_type', 16);                           // unit | accessory
            $table->foreignId('stock_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('accessory_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('qty')->default(1);
            $table->bigInteger('price');                               // per unit, before discounts
            $table->bigInteger('list_price_kzt')->nullable();          // price list at sale time (discount report)
            $table->boolean('is_gift')->default(false);
            $table->bigInteger('line_discount')->default(0);
            $table->bigInteger('check_discount_share')->default(0);    // TZ 02 §3.2, largest remainder
            $table->bigInteger('amount')->default(0);                  // line total after all discounts
            $table->bigInteger('cost_kzt')->nullable();                // line cost at posting (server only)
            $table->bigInteger('bonus_paid_share')->default(0);        // part of amount paid with bonuses (tiyn)
            $table->bigInteger('debt_share')->default(0);              // part of amount sold on credit (tiyn)
            $table->bigInteger('bonus_accrued')->default(0);           // bonuses accrued for this line
            $table->integer('returned_qty')->default(0);
            $table->boolean('is_active')->default(false);              // true once posted, false when fully returned / reversed
            $table->timestampsTz();
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_subject_check CHECK (num_nonnulls(stock_unit_id, accessory_id) = 1)');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_qty_check CHECK (qty > 0 AND (stock_unit_id IS NULL OR qty = 1) AND returned_qty BETWEEN 0 AND qty)');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_money_check CHECK (price >= 0 AND line_discount >= 0 AND check_discount_share >= 0)');
        // Layer 4 of IMEI protection (architecture §2.7): one active sale per unit.
        DB::statement('CREATE UNIQUE INDEX sale_lines_one_active_sale ON sale_lines (stock_unit_id) WHERE is_active AND stock_unit_id IS NOT NULL');

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32);
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->bigInteger('commission_amount')->default(0);
            $table->bigInteger('tendered')->nullable();                // cash only: внесено
            $table->bigInteger('change')->default(0);                  // cash only: сдача
            $table->date('debt_due_date')->nullable();
            $table->timestampsTz();
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_check CHECK (amount > 0 AND "change" >= 0)');

        // ---------------- Customer return ----------------
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('sale_document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('total')->default(0);                   // Σ line amounts
            $table->bigInteger('bonus_returned')->default(0);          // bonuses given back (paid with bonuses)
            $table->bigInteger('bonus_cancelled')->default(0);         // accrued bonuses cancelled
            $table->bigInteger('bonus_withheld')->default(0);          // tiyn kept from refund (bonuses already spent)
            $table->timestampsTz();
        });

        Schema::create('customer_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->integer('qty')->default(1);
            $table->bigInteger('amount')->nullable();                  // NULL = proportional share of the sale line
            $table->string('unit_status_after', 24)->nullable();       // in_stock | preparing | on_check | defective
            $table->bigInteger('cost_kzt')->nullable();
            $table->timestampsTz();
            $table->index('document_id');
        });

        Schema::create('customer_return_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32);                              // a money method or 'debt'
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE customer_return_payments ADD CONSTRAINT customer_return_payments_check CHECK (amount > 0)');

        // ---------------- Purchase ----------------
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('KZT');
            $table->bigInteger('total')->default(0);                   // minor units of currency
            $table->bigInteger('credit_amount')->default(0);           // part left as supplier debt
            $table->date('due_date')->nullable();
            $table->string('invoice_no', 64)->nullable();
            $table->timestampsTz();
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('line_type', 16);                           // unit | accessory
            $this->unitIntakeColumns($table);
            $table->foreignId('accessory_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('qty')->default(1);
            $table->bigInteger('price')->nullable();                   // per unit in purchase currency; NULL = cost unknown
            $table->bigInteger('cost_kzt')->nullable();                // per unit in tiyn, set when posted
            $table->timestampsTz();
            $table->index('document_id');
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('amount');                              // account currency
            $table->bigInteger('covered_amount')->nullable();          // purchase currency, set when posted
            $table->timestampsTz();
        });

        // ---------------- Expense ----------------
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('money_articles')->restrictOnDelete();
            $table->string('counterparty_type', 16)->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->bigInteger('credit_amount')->default(0);           // tiyn left as debt to the counterparty
            $table->date('due_date')->nullable();
            $table->string('description')->nullable();
            $table->bigInteger('total_kzt')->default(0);
            $table->timestampsTz();
        });

        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('amount');                              // account currency
            $table->timestampsTz();
        });

        // ---------------- Debt payment in / out ----------------
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('direction', 3);                            // in | out
            $table->string('counterparty_type', 16);
            $table->unsignedBigInteger('counterparty_id');
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('amount');                              // account currency
            $table->char('debt_currency', 3)->default('KZT');
            $table->bigInteger('debt_amount')->nullable();             // debt currency; NULL = convert amount by document rate
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->bigInteger('commission_amount')->default(0);
            $table->bigInteger('fx_diff_kzt')->default(0);
            $table->timestampsTz();
        });

        // ---------------- FX conversion ----------------
        Schema::create('fx_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('from_amount');
            $table->foreignId('to_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->bigInteger('to_amount');
            $table->bigInteger('fx_diff_kzt')->default(0);             // Σ amount_kzt of the document's money entries
            $table->timestampsTz();
        });

        // ---------------- Opening balance (day X) ----------------
        Schema::create('opening_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('line_type', 16);                           // unit | accessory | money | debt | bonus
            $this->unitIntakeColumns($table);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('cost_currency', 3)->nullable();
            $table->bigInteger('cost_amount_orig')->nullable();
            $table->decimal('cost_rate', 14, 4)->nullable();
            $table->bigInteger('unit_cost_kzt')->nullable();
            $table->boolean('cost_unknown')->default(false);
            $table->foreignId('accessory_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('qty')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('amount')->nullable();                  // money: account currency; debt: signed per TZ 02 §6.1
            $table->decimal('rate', 14, 4)->nullable();                // debt valuation rate (USD)
            $table->string('counterparty_type', 16)->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('bonus_amount')->nullable();
            $table->timestampTz('available_from')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        foreach (['opening_balance_lines', 'fx_conversions', 'debt_payments', 'expense_payments', 'expenses', 'purchase_payments', 'purchase_lines', 'purchases', 'customer_return_payments', 'customer_return_lines', 'customer_returns', 'sale_payments', 'sale_lines', 'sales'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
