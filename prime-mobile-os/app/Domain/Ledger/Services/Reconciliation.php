<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Posting\Ledgers\BonusLedger;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * The nightly checks of TZ 02 §13.2 (production: command prime:reconcile, audit №16; tests: Tests\Support\Reconciliation): every balance copy must equal its journal. Returns a list of
 * human-readable discrepancies for the current organization (empty = everything reconciles).
 *
 *   expect(Reconciliation::problems())->toBe([]);
 */
final class Reconciliation
{
    /** @return list<string> */
    public static function problems(): array
    {
        $org = CurrentOrganization::instance()->requireId();
        $problems = [];
        $add = function (string $check, array $rows) use (&$problems) {
            foreach ($rows as $row) {
                $problems[] = $check.': '.json_encode($row, JSON_UNESCAPED_UNICODE);
            }
        };

        // 1. accounts: balance = Σ amount, balance_kzt_hist = Σ amount_kzt
        $add('1 account', DB::select(
            'SELECT b.account_id, b.balance, COALESCE(e.s, 0) AS journal, b.balance_kzt_hist, COALESCE(e.k, 0) AS journal_kzt
               FROM account_balances b
               LEFT JOIN (SELECT account_id, SUM(amount) s, SUM(amount_kzt) k FROM money_entries GROUP BY account_id) e ON e.account_id = b.account_id
              WHERE b.organization_id = ? AND (b.balance <> COALESCE(e.s, 0) OR b.balance_kzt_hist <> COALESCE(e.k, 0))', [$org]));
        $add('1 account without copy', DB::select(
            'SELECT e.account_id FROM money_entries e WHERE e.organization_id = ?
              AND NOT EXISTS (SELECT 1 FROM account_balances b WHERE b.account_id = e.account_id) GROUP BY e.account_id', [$org]));

        // 2. accessory per place: qty = Σ qty
        $add('2 accessory place', DB::select(
            'SELECT b.accessory_id, b.location_type, b.location_id, b.qty, COALESCE(m.s, 0) AS journal
               FROM accessory_balances b
               LEFT JOIN (SELECT accessory_id, location_type, location_id, SUM(qty) s FROM inventory_movements
                           WHERE accessory_id IS NOT NULL AND organization_id = ? GROUP BY 1, 2, 3) m
                      ON m.accessory_id = b.accessory_id AND m.location_type = b.location_type AND m.location_id = b.location_id
              WHERE b.organization_id = ? AND b.qty <> COALESCE(m.s, 0)', [$org, $org]));

        // 3. accessory average: total_qty = Σ qty; Σ total_cost ≈ total_qty × avg (±1 ₸ per piece of rounding)
        $add('3 accessory cost', DB::select(
            'SELECT c.accessory_id, c.total_qty, c.avg_cost_kzt, COALESCE(m.q, 0) AS journal_qty, COALESCE(m.t, 0) AS journal_cost
               FROM accessory_costs c
               LEFT JOIN (SELECT accessory_id, SUM(qty) q, SUM(total_cost_kzt) t FROM inventory_movements
                           WHERE accessory_id IS NOT NULL AND organization_id = ? GROUP BY 1) m ON m.accessory_id = c.accessory_id
              WHERE c.organization_id = ? AND (c.total_qty <> COALESCE(m.q, 0)
                    OR abs(COALESCE(m.t, 0) - c.total_qty * c.avg_cost_kzt) > 100 * GREATEST(c.total_qty, 1))', [$org, $org]));

        // 4. units: Σ qty ∈ {0, 1}; place = place of the journal; cost = Σ total_cost while on hand
        $add('4 unit qty', DB::select(
            'SELECT stock_unit_id, SUM(qty) q FROM inventory_movements WHERE organization_id = ? AND stock_unit_id IS NOT NULL
              GROUP BY 1 HAVING SUM(qty) NOT IN (0, 1)', [$org]));
        $add('4 unit place/cost', DB::select(
            'SELECT u.id, u.status, u.location_type, u.location_id, u.cost_kzt, m.q, m.t
               FROM stock_units u
               JOIN (SELECT stock_unit_id, SUM(qty) q, SUM(total_cost_kzt) t FROM inventory_movements
                      WHERE organization_id = ? AND stock_unit_id IS NOT NULL GROUP BY 1) m ON m.stock_unit_id = u.id
              WHERE u.organization_id = ? AND (
                    (m.q = 1 AND (u.location_type IS NULL OR u.cost_kzt <> m.t))
                 OR (m.q = 0 AND u.location_type IS NOT NULL)
                 OR (m.q = 0 AND m.t <> 0)
                 OR (u.status IS NULL AND u.cost_kzt <> 0))', [$org, $org]));
        $add('4 unit place = last movement', DB::select(
            'SELECT u.id, u.location_type, u.location_id, l.location_type AS j_type, l.location_id AS j_id, l.qty
               FROM stock_units u
               JOIN LATERAL (SELECT * FROM inventory_movements m WHERE m.stock_unit_id = u.id AND m.qty <> 0 ORDER BY m.id DESC LIMIT 1) l ON true
              WHERE u.organization_id = ? AND (
                    (l.qty = 1 AND (u.location_type IS DISTINCT FROM l.location_type OR u.location_id IS DISTINCT FROM l.location_id))
                 OR (l.qty = -1 AND u.location_type IS NOT NULL))', [$org]));

        // 5. sold ⇔ exactly one active sale line
        $add('5 sold', DB::select(
            "SELECT u.id FROM stock_units u WHERE u.organization_id = ?
                AND COALESCE(u.status = 'sold', false) <> ((SELECT count(*) FROM sale_lines l WHERE l.stock_unit_id = u.id AND l.is_active) = 1)", [$org]));

        // 6. counterparties: balance = Σ amount, balance_kzt = Σ amount_kzt
        $add('6 counterparty', DB::select(
            'SELECT b.counterparty_type, b.counterparty_id, b.currency, b.balance, COALESCE(e.s, 0) AS journal, b.balance_kzt, COALESCE(e.k, 0) AS journal_kzt
               FROM counterparty_balances b
               LEFT JOIN (SELECT counterparty_type, counterparty_id, currency, SUM(amount) s, SUM(amount_kzt) k FROM debt_entries
                           WHERE organization_id = ? GROUP BY 1, 2, 3) e USING (counterparty_type, counterparty_id, currency)
              WHERE b.organization_id = ? AND (b.balance <> COALESCE(e.s, 0) OR b.balance_kzt <> COALESCE(e.k, 0))', [$org, $org]));

        // 7. bonuses: copy = journal (Σ entries = Σ remaining of sources; available/pending recomputed)
        $add('7 bonus sum', DB::select(
            'SELECT c.customer_id, COALESCE(e.s, 0) AS journal, COALESCE(r.s, 0) AS sources
               FROM customer_bonus_balances c
               LEFT JOIN (SELECT customer_id, SUM(amount) s FROM bonus_entries WHERE organization_id = ? GROUP BY 1) e ON e.customer_id = c.customer_id
               LEFT JOIN (SELECT s.customer_id, SUM(s.amount - COALESCE((SELECT SUM(a.amount) FROM bonus_allocations a WHERE a.accrual_entry_id = s.id), 0)) s
                            FROM bonus_entries s WHERE s.is_source AND s.organization_id = ? GROUP BY 1) r ON r.customer_id = c.customer_id
              WHERE c.organization_id = ? AND COALESCE(e.s, 0) <> COALESCE(r.s, 0)', [$org, $org, $org]));
        $ledger = app(BonusLedger::class);
        foreach (DB::select('SELECT customer_id, available, pending FROM customer_bonus_balances WHERE organization_id = ?', [$org]) as $row) {
            $available = 0;
            $pending = 0;
            foreach ($ledger->sources((int) $row->customer_id) as $s) {
                if ($s->expires_at !== null && $s->expires_at <= now()) {
                    continue;
                }
                $s->available_from !== null && $s->available_from > now() ? $pending += $s->remaining : $available += $s->remaining;
            }
            if ($available !== (int) $row->available || $pending !== (int) $row->pending) {
                $problems[] = '7 bonus copy: '.json_encode(['customer_id' => $row->customer_id, 'copy' => [$row->available, $row->pending], 'journal' => [$available, $pending]]);
            }
        }

        // 8. posted sales: Σ payments = Σ lines = total
        $add('8 sale', DB::select(
            "SELECT s.document_id FROM sales s JOIN documents d ON d.id = s.document_id
              WHERE s.organization_id = ? AND d.status <> 'draft' AND (
                    s.total <> (SELECT COALESCE(SUM(amount), 0) FROM sale_payments p WHERE p.document_id = s.document_id)
                 OR s.total <> (SELECT COALESCE(SUM(amount), 0) FROM sale_lines l WHERE l.document_id = s.document_id))", [$org]));

        // 10. reversed document + its reversal net to zero in every journal
        foreach (['inventory_movements' => ['qty', 'total_cost_kzt'], 'money_entries' => ['amount', 'amount_kzt'], 'debt_entries' => ['amount', 'amount_kzt', 'fx_diff_kzt'], 'bonus_entries' => ['amount']] as $journal => $columns) {
            $sums = implode(' OR ', array_map(fn ($c) => "SUM(j.{$c}) <> 0", $columns));
            $add("10 reversal {$journal}", DB::select(
                "SELECT d.id FROM documents d JOIN documents r ON r.reversal_of_document_id = d.id
                   JOIN {$journal} j ON j.document_id IN (d.id, r.id)
                  WHERE d.organization_id = ? GROUP BY d.id HAVING {$sums}", [$org]));
        }

        // 11. no negative stock / cash (CHECK constraints make this impossible; kept as the TZ list)
        $add('11 negative', DB::select(
            'SELECT account_id FROM account_balances WHERE organization_id = ? AND NOT allow_negative AND balance < 0', [$org]));

        // 12. hash chains
        foreach (ChainVerifier::JOURNALS as $journal) {
            if (($broken = app(ChainVerifier::class)->firstBrokenLink($journal)) !== null) {
                $problems[] = "12 chain {$journal}: broken at {$broken}";
            }
        }

        return $problems;
    }
}
