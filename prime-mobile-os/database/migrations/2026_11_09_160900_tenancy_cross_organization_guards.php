<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organization isolation in the database itself (TZ 03 §12, §18 p.4) while RLS is not enabled yet (CORE.md §10 p.1):
 *
 *  - composite foreign keys (organization_id, <ref>_id) → <ref>(organization_id, id): a document header, a journal
 *    row, a balance copy or a stock unit can never point to a store / document / unit / account / customer /
 *    supplier of ANOTHER organization, whatever the PHP code does. Draft detail rows (sale_lines, purchases, …) are
 *    validated by the handlers at posting; what is posted is guaranteed here;
 *  - polymorphic counterparty (counterparty_type + counterparty_id) of journals and balance copies is checked by a
 *    trigger (customers / suppliers of the same organization);
 *  - activity_log gets organization_id (the action journal is per organization, TZ 03 §12 p.1, p.9).
 *
 * The single-column FKs of the earlier migrations stay; these constraints are added next to them.
 */
return new class extends Migration
{
    /** referenced table => list of [referencing table, column, on delete] */
    public const REFS = [
        'stores' => [
            ['documents', 'store_id', 'restrict'],
            ['money_accounts', 'store_id', 'restrict'],
            ['payment_methods', 'store_id', 'restrict'],
            ['shifts', 'store_id', 'restrict'],
            ['inventory_movements', 'store_id', 'restrict'],
            ['money_entries', 'store_id', 'restrict'],
            ['debt_entries', 'store_id', 'restrict'],
            ['bonus_entries', 'store_id', 'restrict'],
        ],
        'documents' => [
            ['documents', 'reversal_of_document_id', 'restrict'],
            ['documents', 'reversed_by_document_id', 'restrict'],
            ['stock_units', 'purchase_document_id', 'restrict'],
            ['shifts', 'discrepancy_document_id', 'restrict'],
            ['inventory_movements', 'document_id', 'restrict'],
            ['money_entries', 'document_id', 'restrict'],
            ['debt_entries', 'document_id', 'restrict'],
            ['bonus_entries', 'document_id', 'restrict'],
        ],
        'stock_units' => [
            ['unit_identifiers', 'stock_unit_id', 'cascade'],
            ['unit_reservations', 'stock_unit_id', 'restrict'],
            ['imei_checks', 'stock_unit_id', 'restrict'],
            ['inventory_movements', 'stock_unit_id', 'restrict'],
        ],
        'suppliers' => [
            ['stock_units', 'supplier_id', 'restrict'],
        ],
        'customers' => [
            ['bonus_entries', 'customer_id', 'restrict'],
            ['customer_bonus_balances', 'customer_id', 'restrict'],
        ],
        'money_accounts' => [
            ['account_balances', 'account_id', 'restrict'],
            ['money_entries', 'account_id', 'restrict'],
            ['payment_methods', 'account_id', 'restrict'],
            ['shifts', 'account_id', 'restrict'],
        ],
        'money_articles' => [
            ['money_entries', 'article_id', 'restrict'],
        ],
        'bonus_entries' => [
            ['bonus_allocations', 'spend_entry_id', 'restrict'],
            ['bonus_allocations', 'accrual_entry_id', 'restrict'],
            ['bonus_allocations', 'entry_id', 'restrict'],
        ],
        'accounting_periods' => [
            ['period_snapshots', 'accounting_period_id', 'restrict'],
        ],
    ];

    /** Journals and copies whose counterparty_type/counterparty_id must be a customer/supplier of the same organization. */
    public const COUNTERPARTY_TABLES = ['counterparty_balances', 'debt_entries', 'money_entries'];

    public function up(): void
    {
        foreach (self::REFS as $referenced => $refs) {
            DB::statement("CREATE UNIQUE INDEX {$referenced}_org_id_unique ON {$referenced} (organization_id, id)");
            foreach ($refs as [$table, $column, $onDelete]) {
                $action = $onDelete === 'cascade' ? 'CASCADE' : 'RESTRICT';
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_same_org_fk
                    FOREIGN KEY (organization_id, {$column}) REFERENCES {$referenced} (organization_id, id) ON DELETE {$action}");
            }
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pm_counterparty_same_org() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.counterparty_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.counterparty_type = 'customer' THEN
        PERFORM 1 FROM customers WHERE id = NEW.counterparty_id AND organization_id = NEW.organization_id FOR KEY SHARE;
    ELSIF NEW.counterparty_type = 'supplier' THEN
        PERFORM 1 FROM suppliers WHERE id = NEW.counterparty_id AND organization_id = NEW.organization_id FOR KEY SHARE;
    ELSE
        RETURN NEW; -- other counterparty kinds (modules) are checked by their own constraints
    END IF;
    IF NOT FOUND THEN
        RAISE EXCEPTION '%: контрагент % #% не найден в фирме %', TG_TABLE_NAME, NEW.counterparty_type, NEW.counterparty_id, NEW.organization_id
            USING ERRCODE = '23503';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        foreach (self::COUNTERPARTY_TABLES as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_counterparty_same_org BEFORE INSERT OR UPDATE OF organization_id, counterparty_type, counterparty_id ON {$table}
                FOR EACH ROW EXECUTE FUNCTION pm_counterparty_same_org()");
        }

        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['organization_id', 'log_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'log_name', 'created_at']);
            $table->dropConstrainedForeignId('organization_id');
        });
        foreach (self::COUNTERPARTY_TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_counterparty_same_org ON {$table}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS pm_counterparty_same_org()');
        foreach (array_reverse(self::REFS, true) as $referenced => $refs) {
            foreach ($refs as [$table, $column]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_same_org_fk");
            }
            DB::statement("DROP INDEX IF EXISTS {$referenced}_org_id_unique");
        }
    }
};
