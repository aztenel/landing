<?php

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Services\ApprovalQueries;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;
use Tests\Support\Reconciliation;

/** A seller gives 15 000 ₸ discount on 489 000 (limit 5 000) — TZ 07 scenario 18. */
function discountDraft($w, int $discount = 15000)
{
    $unit = $w->phone(cost: Money::kzt(400000)); // not below cost: only the discount needs the owner
    $unit->forceFill(['sale_price' => Money::kzt(489000)->amount])->save();

    return SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(489000))->discount(Money::kzt($discount))
        ->cash(Money::kzt(489000 - $discount))->save();
}

it('sends the over-limit discount to the owner, posts after «Разрешить», never twice (TZ 03 §6, scenario 18)', function () {
    $w = world();
    $draft = discountDraft($w);

    expect(fn () => app(Poster::class)->post($draft, $w->seller))->toThrow(ApprovalRequired::class);

    $request = app(ApprovalService::class)->requestForDocument($draft, $w->seller);
    expect($request->status)->toBe(ApprovalStatus::Pending)
        ->and(app(ApprovalQueries::class)->awaitingCount($w->owner))->toBe(1)
        ->and(app(ApprovalQueries::class)->awaitingCount($w->seller2))->toBe(0);

    app(ApprovalService::class)->approve($request, $w->owner);
    $posted = app(Poster::class)->post($draft->refresh(), $w->seller);
    expect($posted->isPosted())->toBeTrue()
        ->and($request->fresh()->status)->toBe(ApprovalStatus::Used)
        ->and(Reconciliation::problems())->toBe([]);
});

it('does not let an approval for 15 000 ₸ cover a bigger discount (fingerprint of the operation)', function () {
    $w = world();
    $draft = discountDraft($w);
    $request = app(ApprovalService::class)->requestForDocument($draft, $w->seller);
    app(ApprovalService::class)->approve($request, $w->owner);

    // the seller changes the draft after the approval: 25 000 instead of 15 000
    $draft->editDraft(function ($d) {
        \App\Domain\Sales\Models\Sale::query()->where('document_id', $d->id)->update(['check_discount' => Money::kzt(25000)->amount]);
        \App\Domain\Sales\Models\SalePayment::query()->where('document_id', $d->id)->update(['amount' => Money::kzt(464000)->amount]);
    });
    expect(fn () => app(Poster::class)->post($draft->refresh(), $w->seller))->toThrow(ApprovalRequired::class);
});

it('lets nobody but deciders approve, and a rejected request does not post', function () {
    $w = world();
    $draft = discountDraft($w);
    $request = app(ApprovalService::class)->requestForDocument($draft, $w->seller);

    expect(fn () => app(ApprovalService::class)->approve($request, $w->seller2))->toThrow(\App\Domain\Tenancy\Access\Exceptions\AccessDenied::class);
    app(ApprovalService::class)->reject($request, $w->owner, 'много');
    expect(fn () => app(Poster::class)->post($draft->refresh(), $w->seller))->toThrow(ApprovalRequired::class);
});

it('shows the request in the owner bell and approves it from there; the tick runs (Э28)', function () {
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    $w = world();
    $draft = discountDraft($w);
    $request = app(ApprovalService::class)->requestForDocument($draft, $w->seller);

    $this->actingAs($w->owner);
    \Livewire\Livewire::test(\App\Livewire\Approvals\Bell::class)->set('open', true)
        ->assertSee($request->headline)->call('decide', $request->id, 'approve');
    expect($request->fresh()->status)->toBe(ApprovalStatus::Approved);

    $this->actingAs($w->seller);
    \Livewire\Livewire::test(\App\Livewire\Approvals\Bell::class)->set('open', true)->assertDontSee('Разрешить');
    $this->artisan('prime:approvals-tick')->assertSuccessful();
});
