<?php

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Ledger\Services\Shifts;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

it('needs an open shift of the store for a sale; one open shift per store (audit №18, №57)', function () {
    $w = world();
    $shifts = app(Shifts::class);
    $shift = $shifts->current($w->store);
    expect($shift->number)->toStartWith('СМН-');

    expect(fn () => $shifts->open($w->store, $w->seller))->toThrow(PostingException::class, 'уже открыта');

    $sale = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->cash(Money::kzt(900000))->post($w->owner);
    expect((int) $sale->shift_id)->toBe($shift->id);

    $shifts->close($shift, $w->seller);
    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->pay('kaspi_qr', Money::kzt(900000))->post($w->owner))
        ->toThrow(PostingException::class, 'Смена не открыта');

    // a reversal of the cash sale also takes money out of today's cash: it needs a shift too
    expect(fn () => app(Reverser::class)->reverse($sale, 'wrong_item', 'пробили не тот телефон', $w->owner))
        ->toThrow(PostingException::class, 'Смена не открыта');
    expect(Reconciliation::problems())->toBe([]);
});

it('does not need a shift for a non-cash expense, but does for a cash one', function () {
    $w = world();
    $w->cash(Money::kzt(50000));
    $w->cash(Money::kzt(50000), 'kaspi_gold');
    app(Shifts::class)->close(app(Shifts::class)->current($w->store), $w->owner);

    expect(ExpenseDraft::make('rent', $w->store)->pay($w->account('kaspi_gold'), Money::kzt(10000))->post($w->owner)->isPosted())->toBeTrue();
    expect(fn () => ExpenseDraft::make('rent', $w->store)->pay($w->account('cash_kzt'), Money::kzt(10000))->post($w->owner))
        ->toThrow(PostingException::class, 'Смена не открыта');
});
