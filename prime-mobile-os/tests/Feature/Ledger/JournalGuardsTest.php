<?php

use App\Domain\Inventory\Models\UnitIdentifier;
use App\Domain\Ledger\Models\InventoryMovement;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Services\ChainVerifier;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Run a statement that must fail inside a savepoint so the test transaction stays usable. */
function expectDbError(callable $statement, string $message): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($message);

        return;
    }
    test()->fail('The database accepted a forbidden statement.');
}

it('forbids UPDATE and DELETE of journal rows at the database level (TZ 02 §2.5)', function () {
    $w = world();
    $w->cash(Money::kzt(100000));
    $entry = MoneyEntry::query()->firstOrFail();

    expectDbError(fn () => DB::update('UPDATE money_entries SET amount = 1 WHERE id = ?', [$entry->id]), 'только для добавления');
    expectDbError(fn () => DB::delete('DELETE FROM money_entries WHERE id = ?', [$entry->id]), 'только для добавления');
    expectDbError(fn () => DB::statement('TRUNCATE inventory_movements CASCADE'), 'только для добавления');

    $w->phone();
    $movement = InventoryMovement::query()->firstOrFail();
    expectDbError(fn () => DB::update('UPDATE inventory_movements SET qty = 0 WHERE id = ?', [$movement->id]), 'только для добавления');
    expect(fn () => $movement->forceFill(['qty' => 0])->save())->toThrow(LogicException::class);

    expect(MoneyEntry::query()->firstOrFail()->amount)->toBe(Money::kzt(100000)->amount);
});

it('keeps posted documents immutable except comment and fiscal number', function () {
    $w = world();
    $doc = $w->cash(Money::kzt(5000));

    $doc->forceFill(['comment' => 'пересчитали', 'fiscal_receipt_no' => 'F-1'])->save();
    expect($doc->refresh()->comment)->toBe('пересчитали');

    expectDbError(fn () => DB::update('UPDATE documents SET occurred_at = now() - interval \'1 day\' WHERE id = ?', [$doc->id]), 'менять нельзя');
    expectDbError(fn () => DB::update("UPDATE documents SET status = 'draft', number = NULL WHERE id = ?", [$doc->id]), 'нельзя');
    expectDbError(fn () => DB::delete('DELETE FROM documents WHERE id = ?', [$doc->id]), 'удалить нельзя');
});

it('locks the detail rows of a posted document', function () {
    $w = world();
    $unit = $w->phone();
    $sale = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    expectDbError(fn () => DB::update('UPDATE sale_lines SET price = 1 WHERE id = ?', [$line->id]), 'менять нельзя');
    expectDbError(fn () => DB::delete('DELETE FROM sale_payments WHERE document_id = ?', [$sale->id]), 'менять нельзя');
    // whitelisted columns (returns / reversal) may change
    DB::update('UPDATE sale_lines SET is_active = is_active WHERE id = ?', [$line->id]);
    expect(true)->toBeTrue();
});

it('chains journal rows with SHA-256 per organization and detects tampering', function () {
    $w = world();
    $w->cash(Money::kzt(1000));
    $w->cash(Money::kzt(2000));
    $w->phone();

    $verifier = app(ChainVerifier::class);
    expect(MoneyEntry::query()->orderBy('chain_seq')->pluck('chain_seq')->all())->toBe([1, 2])
        ->and($verifier->firstBrokenLink('money_entries'))->toBeNull()
        ->and($verifier->firstBrokenLink('inventory_movements'))->toBeNull();

    // someone with server rights disables the guard and edits a row: the chain breaks at that row
    DB::statement('ALTER TABLE money_entries DISABLE TRIGGER money_entries_append_only');
    DB::update('UPDATE money_entries SET amount = amount + 100, amount_kzt = amount_kzt + 100 WHERE chain_seq = 2');
    DB::statement('ALTER TABLE money_entries ENABLE TRIGGER money_entries_append_only');

    expect($verifier->firstBrokenLink('money_entries'))->toBe(2);
});

it('keeps IMEI unique within the organization at the database level (layer 1)', function () {
    $w = world();
    $unit = $w->phone();
    $other = $w->phone();

    expectDbError(fn () => DB::insert(
        'INSERT INTO unit_identifiers (organization_id, stock_unit_id, kind, value, created_at) VALUES (?, ?, ?, ?, now())',
        [$w->org->id, $other->id, 'imei2', $unit->imei1],
    ), 'unit_identifiers_organization_id_value_unique');
    expect(UnitIdentifier::query()->where('value', $unit->imei1)->count())->toBe(1);
});
