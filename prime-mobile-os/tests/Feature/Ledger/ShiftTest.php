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

it('posts and reverses several documents as one operation: all or nothing (audit №20)', function () {
    $w = world();
    $poster = app(\App\Domain\Ledger\Posting\Poster::class);
    $a = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->cash(Money::kzt(900000))->save();
    $broken = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->cash(Money::kzt(1))->save();

    expect(fn () => $poster->postMany([$a, $broken], $w->owner))->toThrow(PostingException::class);
    expect($a->refresh()->isDraft())->toBeTrue()->and($broken->refresh()->isDraft())->toBeTrue();

    $b = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->cash(Money::kzt(900000))->save();
    [$pa, $pb] = $poster->postMany([$a, $b], $w->owner);
    expect($pa->isPosted())->toBeTrue()->and($pb->isPosted())->toBeTrue();

    app(Reverser::class)->reverseMany([$pb, $pa], 'duplicate', null, $w->owner);
    expect($pa->refresh()->status->value)->toBe('reversed')->and($pb->refresh()->status->value)->toBe('reversed')
        ->and(Reconciliation::problems())->toBe([]);
});

it('edits the lines of a draft only under the posting lock and never after posting (audit №45)', function () {
    $w = world();
    $draft = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->cash(Money::kzt(900000))->save();
    $draft->editDraft(fn ($d) => \App\Domain\Sales\Models\SalePayment::query()->where('document_id', $d->id)->update(['tendered' => Money::kzt(1000000)->amount]));

    app(\App\Domain\Ledger\Posting\Poster::class)->post($draft, $w->owner);
    expect(fn () => $draft->editDraft(fn () => null))->toThrow(\App\Domain\Ledger\Exceptions\DocumentNotDraft::class);
});
