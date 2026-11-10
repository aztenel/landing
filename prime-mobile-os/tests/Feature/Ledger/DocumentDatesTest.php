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

it('closes months only in order (audit №46)', function () {
    $w = world();
    $periods = app(\App\Domain\Ledger\Services\AccountingPeriods::class);
    $tz = $w->org->timezone;
    $periods->close(now($tz)->subMonthsNoOverflow(3), $w->owner);

    expect(fn () => $periods->close(now($tz)->subMonthsNoOverflow(1), $w->owner))
        ->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class, 'по порядку');
    expect($periods->close(now($tz)->subMonthsNoOverflow(2), $w->owner)->status)->toBe('closed');
});

it('treats a limit of 0 as «always an approval» (audit №48)', function () {
    $w = world();
    \App\Domain\Tenancy\Models\RoleLimit::query()->where('code', 'L1')
        ->whereIn('role_id', \Spatie\Permission\Models\Role::query()->where('organization_id', $w->org->id)->where('name', 'seller')->pluck('id'))
        ->update(['value' => 0]);
    $decision = app(\App\Domain\Tenancy\Access\AccessChecker::class)->decide($w->seller, 'sale.discount', \App\Domain\Tenancy\Access\Limit::DiscountPerCheck, 0);
    expect($decision->needsApproval())->toBeTrue();
});

it('applies a changed «may go negative» setting of an account at once (audit №50)', function () {
    $w = world();
    $w->cash(Money::kzt(1000));
    $account = $w->account('cash_kzt');
    $account->forceFill(['allow_negative' => true])->save();
    expect((bool) \Illuminate\Support\Facades\DB::table('account_balances')->where('account_id', $account->id)->value('allow_negative'))->toBeTrue();
});
