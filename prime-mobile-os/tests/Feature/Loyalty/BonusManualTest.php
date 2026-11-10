<?php

use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Exceptions\InsufficientBonuses;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Exceptions\ReversalBlocked;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\CustomerBonusBalance;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Loyalty\Drafts\BonusManualDraft;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use Tests\Support\Reconciliation;

function bonusesOf($w): int
{
    return (int) (CustomerBonusBalance::query()->where('customer_id', $w->customer->id)->value('available') ?? 0);
}

it('gives and takes bonuses by hand with a reason; admin within L17, seller — no (TZ 02 №42)', function () {
    $w = world();

    expect(fn () => BonusManualDraft::make()->give($w->customer, 2000, '')->post($w->owner))->toThrow(PostingException::class, 'причину');
    expect(fn () => BonusManualDraft::make()->give($w->customer, 2000, 'ждал заказ')->post($w->seller))->toThrow(AccessDenied::class);
    expect(fn () => BonusManualDraft::make()->give($w->customer, 10001, 'ждал заказ')->post($w->admin))->toThrow(ApprovalRequired::class);

    $doc = BonusManualDraft::make()->give($w->customer, 2000, 'долго ждал заказ')->post($w->admin);
    expect($doc->number)->toStartWith('БНС-')->and(bonusesOf($w))->toBe(2000); // available at once

    BonusManualDraft::make()->take($w->customer, 500, 'начислили по ошибке')->post($w->admin);
    expect(bonusesOf($w))->toBe(1500);
    expect(fn () => BonusManualDraft::make()->take($w->customer, 1501, 'x')->post($w->owner))->toThrow(InsufficientBonuses::class);

    expect(Reconciliation::problems())->toBe([]);
});

it('burns expired bonuses at night with one system document БСГ per firm (audit №54)', function () {
    $w = world();
    // bonuses brought from sfon that expired yesterday, and a fresh gift
    OpeningBalanceDraft::make($w->store)->bonus($w->customer, 5000, now()->subDays(30), now()->subDay())->post($w->owner);
    BonusManualDraft::make()->give($w->customer, 300, 'подарок')->post($w->owner);

    // nobody can post БСГ by hand
    expect(fn () => app(Poster::class)->postAsSystem(Document::query()->where('type', 'bonus_manual')->first()))
        ->toThrow(PostingException::class, 'не проводит');

    $this->artisan('prime:bonus-expire')->assertSuccessful();
    $this->artisan('prime:bonus-expire')->assertSuccessful(); // the same night — no second document
    $docs = Document::query()->where('type', 'bonus_expire')->get();
    expect($docs)->toHaveCount(1)
        ->and($docs[0]->number)->toStartWith('БСГ-')
        ->and((int) BonusEntry::query()->where('document_id', $docs[0]->id)->sum('amount'))->toBe(-5000)
        ->and(bonusesOf($w))->toBe(300)
        ->and(Reconciliation::problems())->toBe([]);

    expect(fn () => app(Reverser::class)->reverse($docs[0], 'other', 'вернуть бонусы', $w->owner))->toThrow(ReversalBlocked::class);
});
