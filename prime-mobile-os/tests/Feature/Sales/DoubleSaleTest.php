<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\UnitReservation;
use App\Domain\Inventory\Services\ReservationService;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Posting\Poster;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Tenancy\Models\Store;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * TZ 02 §12.4 / §14 №3: two cash registers press «Оплатить» for one IMEI. PostgreSQL row locks serialize them:
 * the second transaction re-reads the unit under FOR UPDATE and sees `sold`. Emulated here as two drafts posted
 * one after another; the database guard (layer 4) is checked separately.
 */
it('lets only one of two sales of the same IMEI pass, with a clear message', function () {
    $w = world();
    $unit = $w->phone();

    $draftA = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->save();
    $draftB = SaleDraft::make($w->store, $w->seller2)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->save();

    $a = app(Poster::class)->post($draftA, $w->seller);
    expect($a->isPosted())->toBeTrue();

    expect(fn () => app(Poster::class)->post($draftB, $w->seller2))
        ->toThrow(PostingException::class, "Телефон уже продан ({$a->number}");

    expect($draftB->refresh()->isDraft())->toBeTrue()
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(549000)->amount)
        ->and(SaleLine::query()->where('stock_unit_id', $unit->id)->where('is_active', true)->count())->toBe(1);
});

it('rejects a second active sale line of a unit in the database even if the code is wrong (layer 4)', function () {
    $w = world();
    $unit = $w->phone();
    $first = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    $second = SaleDraft::make($w->store, $w->seller2)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->save();

    try {
        DB::transaction(fn () => DB::update('UPDATE sale_lines SET is_active = true WHERE document_id = ?', [$second->id]));
        $this->fail('The partial unique index must reject the second active sale');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('sale_lines_one_active_sale');
    }
});

it('locks the unit row FOR UPDATE while posting (layer 3)', function () {
    $w = world();
    $unit = $w->phone();
    $draft = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->save();
    DB::enableQueryLog();
    app(Poster::class)->post($draft, $w->seller);
    $locks = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, '"stock_units"') && str_contains($q, 'for update'));
    expect($locks)->not->toBeEmpty();
});

it('reserves a unit for one seller only (layer 2) and releases it on sale', function () {
    $w = world();
    $unit = $w->phone();
    $reservations = app(ReservationService::class);

    $reservations->reserve($unit, $w->seller);
    expect($unit->refresh()->status)->toBe(UnitStatus::Reserved)
        ->and(fn () => $reservations->reserve($unit, $w->seller2))->toThrow(PostingException::class, 'Уже в корзине у');

    expect(fn () => SaleDraft::make($w->store, $w->seller2)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller2))
        ->toThrow(PostingException::class, 'в корзине у Айдар');

    SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    expect($unit->refresh()->status)->toBe(UnitStatus::Sold)
        ->and(UnitReservation::query()->whereNull('released_at')->count())->toBe(0)
        ->and(UnitReservation::query()->value('release_reason'))->toBe('sold');
});

it('releases expired reservations back to stock', function () {
    $w = world();
    $unit = $w->phone();
    $reservation = app(ReservationService::class)->reserve($unit, $w->seller);
    $reservation->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(app(ReservationService::class)->releaseExpired())->toBe(1)
        ->and($unit->refresh()->status)->toBe(UnitStatus::InStock);
});

it('refuses to sell a phone of another store', function () {
    $w = world();
    $unit = $w->phone();
    $other = Store::query()->create(['name' => 'Магазин 2']);

    expect(fn () => SaleDraft::make($other, $w->owner)->unit($unit, Money::kzt(549000))->pay('kaspi_qr', Money::kzt(549000))->post($w->owner))
        ->toThrow(PostingException::class, 'другом магазине');
});
