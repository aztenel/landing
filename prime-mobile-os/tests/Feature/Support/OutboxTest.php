<?php

use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxDispatcher;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('writes the site announcement in the same transaction as the sale and the reversal (audit №5)', function () {
    $w = world();
    $unit = $w->phone();

    $doc = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(500000))->cash(Money::kzt(500000))->post($w->owner);
    $posted = DB::table('outbox_events')->where('topic', 'document.posted')->where('subject_id', $doc->id)->first();
    expect($posted)->not->toBeNull()
        ->and(json_decode($posted->payload, true)['stock_unit_ids'])->toBe([$unit->id])
        ->and($posted->processed_at)->toBeNull();

    app(Reverser::class)->reverse($doc, 'wrong_item', 'пробили не тот телефон', $w->owner);
    expect(DB::table('outbox_events')->where('topic', 'document.reversed')->where('subject_id', $doc->id)->exists())->toBeTrue();
});

it('writes nothing when the posting fails, and announces cart changes', function () {
    $w = world();
    $unit = $w->phone();
    $before = DB::table('outbox_events')->count();

    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(500000))->cash(Money::kzt(1))->post($w->owner))
        ->toThrow(PostingException::class);
    expect(DB::table('outbox_events')->count())->toBe($before);

    $reservation = app(ReservationService::class)->reserve($unit, $w->seller);
    app(ReservationService::class)->release($reservation, $w->seller);
    expect(DB::table('outbox_events')->where('topic', 'unit.changed')->where('subject_id', $unit->id)->pluck('payload')
        ->map(fn ($p) => json_decode($p, true)['action'])->all())->toBe(['reserve', 'release']);
});

it('delivers pending events in order, retries a failing listener later and does not lose the event', function () {
    $w = world();
    $unit = $w->phone();
    DB::table('outbox_events')->update(['processed_at' => now()]);
    SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(500000))->cash(Money::kzt(500000))->post($w->owner);

    $fail = true;
    $seen = [];
    Event::listen(OutboxMessage::class, function (OutboxMessage $m) use (&$fail, &$seen) {
        if ($fail) {
            throw new RuntimeException('site is down');
        }
        $seen[] = $m->topic;
    });

    expect(app(OutboxDispatcher::class)->run())->toBe(['processed' => 0, 'failed' => 1]);
    $row = DB::table('outbox_events')->whereNull('processed_at')->first();
    expect($row->attempts)->toBe(1)->and($row->last_error)->toContain('site is down');

    // not yet: the next attempt waits; then it is delivered
    expect(app(OutboxDispatcher::class)->run()['processed'])->toBe(0);
    DB::table('outbox_events')->where('id', $row->id)->update(['available_at' => now()->subSecond()]);
    $fail = false;
    expect(app(OutboxDispatcher::class)->run())->toBe(['processed' => 1, 'failed' => 0])
        ->and($seen)->toBe(['document.posted'])
        ->and(DB::table('outbox_events')->whereNull('processed_at')->count())->toBe(0);
});

it('runs the nightly ledger checks in production and stores the result (audit №16)', function () {
    $w = world();
    SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(500000))->cash(Money::kzt(500000))->post($w->owner);

    $this->artisan('prime:reconcile', ['--organization' => $w->org->id])->assertSuccessful();
    $run = DB::table('reconciliation_runs')->where('organization_id', $w->org->id)->latest('id')->first();
    expect($run->ok)->toBeTrue()->and($run->problem_count)->toBe(0);

    // a broken balance copy is reported
    DB::table('account_balances')->where('organization_id', $w->org->id)->limit(1)->update(['balance' => DB::raw('balance + 100')]);
    $this->artisan('prime:reconcile', ['--organization' => $w->org->id])->assertFailed();
    expect(DB::table('reconciliation_runs')->where('organization_id', $w->org->id)->latest('id')->value('ok'))->toBeFalse();
});
