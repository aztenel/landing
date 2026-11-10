<?php

use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\Shift;
use App\Domain\Ledger\Services\Shifts;
use App\Domain\Money\Drafts\CashAdjustmentDraft;
use App\Domain\Money\Services\ShiftCash;
use App\Domain\Notifications\Models\AppNotification;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Reconciliation;

function counts($w, int $kzt, int $usd = 0): array
{
    return [$w->account('cash_kzt')->id => $kzt, $w->account('cash_usd')->id => $usd];
}

it('closes a shift with a count: a difference in ₸ and in $ — two documents РКС, the big one goes to the owner (audit №57)', function () {
    $w = world();
    $w->rate('515');
    $w->cash(Money::kzt(1245000));
    $w->cash(Money::usd(100), 'cash_usd');
    $shift = app(Shifts::class)->current($w->store);

    // a difference without a comment is not accepted
    expect(fn () => app(ShiftCash::class)->close($shift, $w->seller, counts($w, Money::kzt(1244500)->amount, Money::usd(100)->amount)))
        ->toThrow(PostingException::class, 'напишите почему');

    app(ShiftCash::class)->close($shift, $w->seller, counts($w, Money::kzt(1242000)->amount, Money::usd(99)->amount), 'сдача', [
        $w->account('cash_kzt')->id => ['20000' => 62, '2000' => 1],
    ]);

    $docs = Document::query()->where('type', 'cash_adjustment')->orderBy('id')->get();
    expect($docs)->toHaveCount(2)
        ->and($docs[0]->number)->toStartWith('РКС-')
        ->and((int) $docs[0]->shift_id)->toBe($shift->id)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(1242000)->amount)
        ->and($w->balance('cash_usd'))->toBe(Money::usd(99)->amount)
        ->and(Shift::query()->find($shift->id)->status)->toBe('closed')
        ->and((int) DB::table('shift_counts')->where('shift_id', $shift->id)->where('phase', 'close')->sum('diff'))->toBe(Money::kzt(-3000)->amount + Money::usd(-1)->amount)
        ->and(Reconciliation::problems())->toBe([]);

    // −3 000 ₸ and −$1 (515 ₸) — only the first is over the 1 000 ₸ threshold
    $bell = AppNotification::query()->where('type', 'shift_discrepancy')->where('user_id', $w->owner->id)->get();
    expect($bell)->toHaveCount(1)->and($bell[0]->title)->toContain("3\u{00A0}000");

    // the shift is closed: no cash documents until the next opening
    expect(fn () => \App\Domain\Money\Drafts\CashMoveDraft::deposit($w->store, $w->account('cash_kzt'), Money::kzt(1000))->post($w->owner))
        ->toThrow(PostingException::class, 'Смена не открыта');
});

it('opens a shift with a count; an exact count makes no documents', function () {
    $w = world();
    $w->cash(Money::kzt(50000));
    app(ShiftCash::class)->close(app(Shifts::class)->current($w->store), $w->seller, counts($w, Money::kzt(50000)->amount));
    expect(Document::query()->where('type', 'cash_adjustment')->count())->toBe(0);

    // every register must be counted
    expect(fn () => app(ShiftCash::class)->open($w->store, $w->seller, [$w->account('cash_kzt')->id => 0]))->toThrow(PostingException::class, 'Введите');
    expect(Shift::query()->where('status', 'open')->count())->toBe(0); // the failed opening rolled back

    $shift = app(ShiftCash::class)->open($w->store, $w->seller, counts($w, Money::kzt(50500)->amount), 'нашли 500 ₸ под лотком');
    expect($shift->isOpen())->toBeTrue()
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(50500)->amount)
        ->and(AppNotification::query()->where('type', 'shift_discrepancy')->count())->toBe(0); // 500 ₸ is under the threshold

    // one open shift per store
    expect(fn () => app(ShiftCash::class)->open($w->store, $w->seller, counts($w, 0)))->toThrow(PostingException::class, 'уже открыта');
});

it('limits a manual cash correction by L16: seller — no, admin — up to 5 000 ₸, owner — any', function () {
    $w = world();
    $w->cash(Money::kzt(100000));
    $cash = $w->account('cash_kzt');

    expect(fn () => CashAdjustmentDraft::make($w->store, $cash, Money::kzt(-1000), 'ошибка сдачи')->post($w->seller))->toThrow(AccessDenied::class);
    CashAdjustmentDraft::make($w->store, $cash, Money::kzt(-4000), 'ошибка сдачи')->post($w->admin);
    expect(fn () => CashAdjustmentDraft::make($w->store, $cash, Money::kzt(-6000), 'ошибка сдачи')->post($w->admin))->toThrow(ApprovalRequired::class);
    expect(fn () => CashAdjustmentDraft::make($w->store, $cash, Money::kzt(-6000), '  ')->post($w->owner))->toThrow(PostingException::class, 'почему');
    CashAdjustmentDraft::make($w->store, $cash, Money::kzt(-6000), 'ошибка сдачи')->post($w->owner);

    expect($w->balance('cash_kzt'))->toBe(Money::kzt(90000)->amount)->and(Reconciliation::problems())->toBe([]);
    // a shift phase is not a way around the limit for a register of another kind
    expect(fn () => CashAdjustmentDraft::make($w->store, $w->account('safe_kzt'), Money::kzt(-1), 'x', 'close')->post($w->owner))
        ->toThrow(PostingException::class, 'наличной кассы');
});

it('reminds the owner at night and closes a forgotten shift at 03:00 «не сверена»', function () {
    $w = world();
    $shift = app(Shifts::class)->current($w->store);
    $tz = $w->org->timezone;

    $this->travelTo(now($tz)->setTime(23, 59, 30));
    Shift::query()->whereKey($shift->id)->update(['opened_at' => now()->subHours(14)]);
    $this->artisan('prime:shifts-tick')->assertSuccessful();
    $this->artisan('prime:shifts-tick')->assertSuccessful();
    expect(AppNotification::query()->where('type', 'cash_not_closed')->where('user_id', $w->owner->id)->count())->toBe(1)
        ->and(Shift::query()->find($shift->id)->status)->toBe('open');

    $this->travelTo(now($tz)->addDay()->setTime(3, 1));
    $this->artisan('prime:shifts-tick')->assertSuccessful();
    expect(Shift::query()->find($shift->id)->status)->toBe('auto_closed')
        ->and(Document::query()->where('type', 'cash_adjustment')->count())->toBe(0);
});
