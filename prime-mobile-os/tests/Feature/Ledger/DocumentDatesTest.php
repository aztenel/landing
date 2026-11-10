<?php

use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

it('posts a draft saved long ago with the posting moment when nobody set the date (audit №19)', function () {
    $w = world();
    $this->travelTo(now()->setTime(12, 0));
    $doc = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(900000))->cash(Money::kzt(900000))->save();

    $this->travel(40)->minutes();  // the seller posts later: no «задним числом», no reason needed
    $posted = app(\App\Domain\Ledger\Posting\Poster::class)->post($doc, $w->seller);

    expect($posted->occurred_at->equalTo(now()))->toBeTrue()
        ->and($posted->occurred_at_manual)->toBeFalse();
});

it('gives a reversal its own date: yesterday stays as it was, today gets the minus (audit №27)', function () {
    $w = world();
    $this->travelTo(now()->setTime(15, 0)->subDay());
    $sale = SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(4990))->cash(Money::kzt(4990))->post($w->owner);
    $yesterday = $sale->occurred_at->copy();

    $this->travel(1)->day();
    $reversal = app(Reverser::class)->reverse($sale, 'wrong_item', 'пробили не тот телефон', $w->owner);

    expect($reversal->occurred_at->isSameDay(now()))->toBeTrue()
        ->and(MoneyEntry::query()->where('document_id', $reversal->id)->pluck('occurred_at')->every(fn ($d) => \Carbon\CarbonImmutable::parse($d)->isSameDay(now())))->toBeTrue()
        ->and((int) MoneyEntry::query()->whereDate('occurred_at', $yesterday->toDateString())->sum('amount'))->toBe(Money::kzt(4990)->amount)
        ->and(Reconciliation::problems())->toBe([]);
});
