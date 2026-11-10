<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four append-only journals and their balance copies (TZ 02 §6, §11.2, §11.3, §11.6, §11.7), shifts.
 * Every journal row: document, store, user, business date, record date, reversal link, hash chain.
 */
return new class extends Migration
{
    private function journalColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('organization_id')->constrained()->restrictOnDelete();
        $table->foreignId('document_id')->constrained()->restrictOnDelete();
        $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
        $table->timestampTz('occurred_at');
        $table->timestampTz('recorded_at');
    }

    private function chainColumns(Blueprint $table, string $name): void
    {
        $table->unsignedBigInteger('chain_seq')->nullable();   // filled by trigger pm_journal_chain()
        $table->char('hash', 64)->nullable();
        $table->unique(['organization_id', 'chain_seq'], $name.'_chain_unique');
    }

    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $this->journalColumns($table);
            $table->string('kind', 24);
            $table->foreignId('stock_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('accessory_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('location_type', 16);
            $table->unsignedBigInteger('location_id');
            $table->integer('qty');
            $table->bigInteger('unit_cost_kzt')->default(0);
            $table->bigInteger('total_cost_kzt')->default(0);
            $table->char('cost_currency', 3)->nullable();
            $table->bigInteger('cost_amount_orig')->nullable();
            $table->decimal('exchange_rate', 14, 4)->nullable();
            $table->string('status_before', 24)->nullable();
            $table->string('status_after', 24)->nullable();
            $table->string('location_before_type', 16)->nullable();
            $table->unsignedBigInteger('location_before_id')->nullable();
            $table->bigInteger('avg_cost_before')->nullable();
            $table->bigInteger('avg_cost_after')->nullable();
            $table->foreignId('reversal_of_entry_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
            $this->chainColumns($table, 'inventory_movements');
            $table->index(['organization_id', 'stock_unit_id', 'id']);
            $table->index(['organization_id', 'accessory_id', 'location_type', 'location_id']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_subject_check CHECK (num_nonnulls(stock_unit_id, accessory_id) = 1)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_unit_qty_check CHECK (stock_unit_id IS NULL OR qty IN (-1, 0, 1))');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_acc_qty_check CHECK (accessory_id IS NULL OR qty <> 0)');

        Schema::create('money_entries', function (Blueprint $table) {
            $this->journalColumns($table);
            $table->unsignedBigInteger('account_id');
            $table->char('currency', 3);
            $table->bigInteger('amount');                            // signed, minor units of account currency
            $table->decimal('exchange_rate', 14, 4)->default(1);
            $table->bigInteger('amount_kzt');                        // signed, tiyn
            $table->foreignId('article_id')->constrained('money_articles')->restrictOnDelete();
            $table->string('counterparty_type', 16)->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->unsignedBigInteger('sale_payment_id')->nullable();
            $table->unsignedBigInteger('expense_payment_id')->nullable();
            $table->string('comment')->nullable();
            $table->foreignId('reversal_of_entry_id')->nullable()->unique()->constrained('money_entries')->restrictOnDelete();
            $this->chainColumns($table, 'money_entries');
            $table->foreign(['account_id', 'currency'])->references(['id', 'currency'])->on('money_accounts')->restrictOnDelete();
            $table->index(['organization_id', 'account_id', 'occurred_at']);
            $table->index(['organization_id', 'occurred_at']);
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE money_entries ADD CONSTRAINT money_entries_amount_check CHECK (amount <> 0 AND sign(amount_kzt) = sign(amount))');
        DB::statement("ALTER TABLE money_entries ADD CONSTRAINT money_entries_kzt_check CHECK (currency <> 'KZT' OR (exchange_rate = 1 AND amount_kzt = amount))");

        Schema::create('debt_entries', function (Blueprint $table) {
            $this->journalColumns($table);
            $table->string('counterparty_type', 16);                 // customer | supplier
            $table->unsignedBigInteger('counterparty_id');
            $table->char('currency', 3);
            $table->bigInteger('amount');                            // + counterparty owes us more / − we owe more
            $table->decimal('exchange_rate', 14, 4)->default(1);     // rate used for amount_kzt (average rate when settling)
            $table->bigInteger('amount_kzt');
            $table->string('kind', 24);
            $table->date('due_date')->nullable();
            $table->bigInteger('fx_diff_kzt')->default(0);           // + gain / − loss (TZ 02 §3.5)
            $table->string('comment')->nullable();
            $table->foreignId('reversal_of_entry_id')->nullable()->unique()->constrained('debt_entries')->restrictOnDelete();
            $this->chainColumns($table, 'debt_entries');
            $table->index(['organization_id', 'counterparty_type', 'counterparty_id', 'currency'], 'debt_entries_cpty_index');
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE debt_entries ADD CONSTRAINT debt_entries_amount_check CHECK (amount <> 0)');
        DB::statement("ALTER TABLE debt_entries ADD CONSTRAINT debt_entries_due_check CHECK (kind NOT IN ('sale_credit','purchase_credit') OR due_date IS NOT NULL)");
        DB::statement("ALTER TABLE debt_entries ADD CONSTRAINT debt_entries_type_check CHECK (counterparty_type IN ('customer','supplier'))");

        Schema::create('bonus_entries', function (Blueprint $table) {
            $this->journalColumns($table);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');                            // whole bonuses (1 bonus = 1 ₸)
            $table->string('kind', 24);
            $table->boolean('is_source')->default(false);            // positive entry that can be spent (FIFO)
            $table->timestampTz('available_from')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedBigInteger('sale_line_id')->nullable();
            $table->string('comment')->nullable();
            $table->foreignId('reversal_of_entry_id')->nullable()->unique()->constrained('bonus_entries')->restrictOnDelete();
            $this->chainColumns($table, 'bonus_entries');
            $table->index(['organization_id', 'customer_id']);
            $table->index('document_id');
        });
        DB::statement('ALTER TABLE bonus_entries ADD CONSTRAINT bonus_entries_amount_check CHECK (amount <> 0 AND (NOT is_source OR amount > 0))');

        // Which sources (accruals) covered a consumer entry; negative rows restore («те же бонусы с их сроком»).
        Schema::create('bonus_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('spend_entry_id')->constrained('bonus_entries')->restrictOnDelete();
            $table->foreignId('accrual_entry_id')->constrained('bonus_entries')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('bonus_entries')->restrictOnDelete(); // entry that wrote this row
            $table->bigInteger('amount');
            $table->timestampTz('created_at')->nullable();
            $table->index('accrual_entry_id');
            $table->index('spend_entry_id');
            $table->index('entry_id');
        });
        DB::statement('ALTER TABLE bonus_allocations ADD CONSTRAINT bonus_allocations_amount_check CHECK (amount <> 0)');

        // ---- Balance copies (one row per key, updated only inside posting) ----
        Schema::create('account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('account_id')->unique();
            $table->char('currency', 3);
            $table->boolean('allow_negative')->default(false);       // copy of money_accounts.allow_negative for the CHECK
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('balance_kzt_hist')->default(0);      // Σ amount_kzt (historical valuation)
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->foreign(['account_id', 'currency'])->references(['id', 'currency'])->on('money_accounts')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE account_balances ADD CONSTRAINT account_balances_non_negative CHECK (allow_negative OR balance >= 0)');

        Schema::create('counterparty_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('counterparty_type', 16);
            $table->unsignedBigInteger('counterparty_id');
            $table->char('currency', 3);
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('balance_kzt')->default(0);
            $table->decimal('avg_rate', 14, 4)->default(0);
            $table->date('nearest_due_date')->nullable();
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['organization_id', 'counterparty_type', 'counterparty_id', 'currency'], 'counterparty_balances_key');
        });

        Schema::create('customer_bonus_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('available')->default(0);
            $table->bigInteger('pending')->default(0);
            $table->timestampTz('next_expire_at')->nullable();
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement('ALTER TABLE customer_bonus_balances ADD CONSTRAINT customer_bonus_balances_check CHECK (available >= 0 AND pending >= 0)');

        // One shift per STORE covering all its cash registers (₸ and $); not an accounting document (audit №57)
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 32);                            // СМН-2026-000012
            $table->unsignedSmallInteger('number_year');
            $table->string('status', 16)->default('open');           // open | closed | auto_closed
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'number']);
        });
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status_check CHECK (status IN ('open','closed','auto_closed') AND ((status = 'open') = (closed_at IS NULL)))");
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_store ON shifts (store_id) WHERE status = 'open'");

        // counted cash per register at opening and closing; a difference is a document РКС (audit №57)
        Schema::create('shift_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->string('phase', 8);                              // open | close
            $table->bigInteger('expected');
            $table->bigInteger('counted');
            $table->bigInteger('diff');
            $table->jsonb('denominations')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['shift_id', 'account_id', 'phase']);
        });
    }

    public function down(): void
    {
        foreach (['shift_counts', 'shifts', 'customer_bonus_balances', 'counterparty_balances', 'account_balances', 'bonus_allocations', 'bonus_entries', 'debt_entries', 'money_entries', 'inventory_movements'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
