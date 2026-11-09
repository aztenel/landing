<?php

use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Services\ChainVerifier;
use App\Domain\Tenancy\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Reconciliation;

it('seeds the demo organization through real documents with consistent journals (nightly checks of TZ 02 §13.2)', function () {
    $this->seed(DatabaseSeeder::class);

    $org = Organization::query()->where('name', 'Prime Mobile')->firstOrFail();
    expect($org->settings()->accent())->toBe('blue');
    $owner = User::query()->where('phone', '+77010000001')->firstOrFail();
    expect(Hash::check('password', $owner->password))->toBeTrue();

    CurrentOrganization::instance()->set($org);
    expect($owner->can('stock.view_cost'))->toBeTrue()
        ->and(User::query()->where('phone', '+77010000002')->first()->can('stock.view_cost'))->toBeFalse()
        ->and(StockUnit::query()->count())->toBeGreaterThanOrEqual(30)
        ->and(Document::query()->where('type', 'sale')->where('status', 'posted')->count())->toBeGreaterThanOrEqual(20)
        ->and(Document::query()->where('type', 'sale')->where('occurred_at', '>=', now()->subDays(14))->count())->toBeGreaterThanOrEqual(20);

    // check 1: account copies = Σ journal
    expect(DB::select('SELECT b.account_id FROM account_balances b LEFT JOIN (SELECT account_id, SUM(amount) s FROM money_entries GROUP BY account_id) e ON e.account_id = b.account_id WHERE b.balance <> COALESCE(e.s, 0)'))->toBe([]);
    // check 2: accessory copies = Σ journal per place
    expect(DB::select('SELECT b.id FROM accessory_balances b LEFT JOIN (SELECT accessory_id, location_type, location_id, SUM(qty) s FROM inventory_movements WHERE accessory_id IS NOT NULL GROUP BY 1, 2, 3) m ON m.accessory_id = b.accessory_id AND m.location_type = b.location_type AND m.location_id = b.location_id WHERE b.qty <> COALESCE(m.s, 0)'))->toBe([]);
    // check 4/5: a sold unit has exactly one active sale line, unit Σ qty ∈ {0, 1}
    expect(DB::select("SELECT u.id FROM stock_units u WHERE (u.status = 'sold') <> EXISTS (SELECT 1 FROM sale_lines l WHERE l.stock_unit_id = u.id AND l.is_active)"))->toBe([])
        ->and(DB::select('SELECT stock_unit_id FROM inventory_movements WHERE stock_unit_id IS NOT NULL GROUP BY 1 HAVING SUM(qty) NOT IN (0, 1)'))->toBe([]);
    // check 6: debt copies = Σ journal
    expect(DB::select('SELECT b.id FROM counterparty_balances b LEFT JOIN (SELECT counterparty_type, counterparty_id, currency, SUM(amount) s FROM debt_entries GROUP BY 1, 2, 3) e USING (counterparty_type, counterparty_id, currency) WHERE b.balance <> COALESCE(e.s, 0)'))->toBe([]);
    // check 8: every sale — Σ payments = Σ lines
    expect(DB::select('SELECT s.document_id FROM sales s WHERE s.total <> (SELECT SUM(amount) FROM sale_payments p WHERE p.document_id = s.document_id) OR s.total <> (SELECT SUM(amount) FROM sale_lines l WHERE l.document_id = s.document_id)'))->toBe([]);
    // check 12: hash chains intact
    foreach (ChainVerifier::JOURNALS as $journal) {
        expect(app(ChainVerifier::class)->firstBrokenLink($journal))->toBeNull();
    }
    // the full list of nightly checks (costs, averages, bonuses, reversal pairs, …): tests/Support/Reconciliation
    expect(Reconciliation::problems())->toBe([]);
});
