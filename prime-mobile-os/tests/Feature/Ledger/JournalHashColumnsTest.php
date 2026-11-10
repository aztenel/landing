<?php

use Illuminate\Support\Facades\DB;


/**
 * Audit №15: the hash of a journal row covers every non-null column (jsonb_strip_nulls). A NEW column of a journal
 * must therefore be nullable and without a default: old rows stay NULL in it and keep their hash. A column with a
 * default would change every old row and break the chain — this test stops such a migration.
 */
const JOURNAL_BASELINE = [
    'inventory_movements' => ['id', 'organization_id', 'document_id', 'store_id', 'user_id', 'occurred_at', 'recorded_at', 'kind', 'stock_unit_id', 'accessory_id', 'location_type', 'location_id', 'qty', 'unit_cost_kzt', 'total_cost_kzt', 'cost_currency', 'cost_amount_orig', 'exchange_rate', 'status_before', 'status_after', 'location_before_type', 'location_before_id', 'avg_cost_before', 'avg_cost_after', 'reversal_of_entry_id', 'chain_seq', 'hash'],
    'money_entries' => ['id', 'organization_id', 'document_id', 'store_id', 'user_id', 'occurred_at', 'recorded_at', 'account_id', 'currency', 'amount', 'exchange_rate', 'amount_kzt', 'article_id', 'counterparty_type', 'counterparty_id', 'sale_payment_id', 'expense_payment_id', 'comment', 'reversal_of_entry_id', 'chain_seq', 'hash'],
    'debt_entries' => ['id', 'organization_id', 'document_id', 'store_id', 'user_id', 'occurred_at', 'recorded_at', 'counterparty_type', 'counterparty_id', 'currency', 'amount', 'exchange_rate', 'amount_kzt', 'kind', 'due_date', 'fx_diff_kzt', 'comment', 'reversal_of_entry_id', 'chain_seq', 'hash'],
    'bonus_entries' => ['id', 'organization_id', 'document_id', 'store_id', 'user_id', 'occurred_at', 'recorded_at', 'customer_id', 'amount', 'kind', 'is_source', 'available_from', 'expires_at', 'sale_line_id', 'comment', 'reversal_of_entry_id', 'chain_seq', 'hash'],
];

it('allows only nullable columns without a default to be added to a journal (audit №15)', function () {
    foreach (JOURNAL_BASELINE as $table => $baseline) {
        $columns = DB::select('SELECT column_name, is_nullable, column_default FROM information_schema.columns WHERE table_name = ?', [$table]);
        expect(array_column($columns, 'column_name'))->toContain(...$baseline);
        foreach ($columns as $c) {
            if (in_array($c->column_name, $baseline, true)) {
                continue;
            }
            expect($c->is_nullable === 'YES' && $c->column_default === null)
                ->toBeTrue("{$table}.{$c->column_name}: a new journal column must be nullable without a default, or the hash chain breaks");
        }
    }
});
