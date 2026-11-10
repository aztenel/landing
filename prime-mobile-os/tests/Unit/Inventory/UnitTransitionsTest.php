<?php

use App\Domain\Inventory\Enums\UnitAction;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Exceptions\InvalidUnitTransition;
use App\Domain\Inventory\UnitTransitions;

/** TZ 02 §10.3, written down independently of the code: (from, to). «—» = not in stock. */
const TZ_TRANSITIONS = [
    ['—', 'in_stock'], ['—', 'preparing'],
    ['preparing', 'in_stock'], ['in_stock', 'preparing'],
    ['in_stock', 'reserved'], ['reserved', 'in_stock'],
    ['in_stock', 'ordered'], ['ordered', 'in_stock'],
    ['in_stock', 'sold'], ['reserved', 'sold'], ['ordered', 'sold'],
    ['sold', 'in_stock'], ['sold', 'preparing'], ['sold', 'on_check'], ['sold', 'defective'],
    ['on_check', 'in_stock'], ['on_check', 'preparing'], ['on_check', 'defective'],
    ['in_stock', 'in_transit'], ['in_transit', 'in_stock'],
    ['in_stock', 'written_off'], ['preparing', 'written_off'], ['defective', 'written_off'],
    ['in_stock', 'returned_to_supplier'], ['on_check', 'returned_to_supplier'], ['defective', 'returned_to_supplier'],
    ['defective', 'preparing'],
    // audit decisions А6 (docs/audit/decisions.md)
    ['—', 'on_check'], ['—', 'defective'],
    ['written_off', 'in_stock'], ['written_off', 'preparing'],
    ['in_transit', 'written_off'], ['on_check', 'written_off'],
    ['preparing', 'defective'], ['preparing', 'returned_to_supplier'],
    ['returned_to_supplier', 'in_stock'], ['returned_to_supplier', 'preparing'],
];

it('contains exactly the transitions of TZ 02 §10.3', function () {
    $actual = array_map(fn ($p) => ($p[0] ?? '—').'>'.$p[1], UnitTransitions::pairs());
    $expected = array_map(fn ($p) => $p[0].'>'.$p[1], TZ_TRANSITIONS);
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

it('uses only statuses of the single TZ list', function () {
    expect(array_map(fn ($s) => $s->value, UnitStatus::cases()))->toBe([
        'in_stock', 'reserved', 'ordered', 'in_transit', 'preparing', 'on_check', 'defective', 'sold', 'returned_to_supplier', 'written_off',
    ]);
});

it('binds transitions to the business action', function () {
    expect(UnitTransitions::allowed(UnitStatus::InStock, UnitStatus::Sold, UnitAction::Sell))->toBeTrue()
        ->and(UnitTransitions::allowed(UnitStatus::Reserved, UnitStatus::Sold, UnitAction::Sell))->toBeTrue()
        ->and(UnitTransitions::allowed(null, UnitStatus::InStock, UnitAction::Receive))->toBeTrue()
        ->and(UnitTransitions::allowed(null, UnitStatus::Preparing, UnitAction::ReceiveUsed))->toBeTrue()
        ->and(UnitTransitions::allowed(UnitStatus::Sold, UnitStatus::Preparing, UnitAction::TradeInKnown))->toBeTrue()
        // pair exists in the table but not for this action
        ->and(UnitTransitions::allowed(UnitStatus::InStock, UnitStatus::Preparing, UnitAction::CustomerReturn))->toBeFalse()
        ->and(UnitTransitions::allowed(null, UnitStatus::Sold, UnitAction::Sell))->toBeFalse();
});

it('throws on transitions not in the table', function (?UnitStatus $from, UnitStatus $to, UnitAction $action) {
    expect(fn () => UnitTransitions::assert($from, $to, $action))->toThrow(InvalidUnitTransition::class);
})->with([
    'sold twice' => [UnitStatus::Sold, UnitStatus::Sold, UnitAction::Sell],
    'sell in transit' => [UnitStatus::InTransit, UnitStatus::Sold, UnitAction::Sell],
    'sell preparing' => [UnitStatus::Preparing, UnitStatus::Sold, UnitAction::Sell],
    'revive written off by a purchase' => [UnitStatus::WrittenOff, UnitStatus::InStock, UnitAction::Receive],
    'reserve sold' => [UnitStatus::Sold, UnitStatus::Reserved, UnitAction::Reserve],
]);

it('lets a reversal restore any previous status unless the unit is held', function () {
    UnitTransitions::assertReversal(UnitStatus::Sold, UnitStatus::InStock);
    UnitTransitions::assertReversal(UnitStatus::InStock, null);
    expect(fn () => UnitTransitions::assertReversal(UnitStatus::Reserved, null))->toThrow(InvalidUnitTransition::class);
});
