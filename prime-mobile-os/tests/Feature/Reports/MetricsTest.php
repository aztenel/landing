<?php

use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

function periodToday($w): Period
{
    return Period::make('today', $w->org->timezone);
}

it('counts revenue, cost, margin, commissions, bonuses, expenses and net profit by one formula (TZ 06 §2.1)', function () {
    $w = world();
    PaymentMethod::query()->where('code', 'kaspi_qr')->update(['commission_percent' => '1.00']);
    $unit = $w->phone(cost: Money::kzt(489250));
    $w->accessories($w->case, 10, Money::kzt(2400));

    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)
        ->unit($unit, Money::kzt(549000))->accessory($w->case, 1, Money::kzt(9000))->discount(Money::kzt(3000))
        ->pay('kaspi_qr', Money::kzt(200000))->cash(Money::kzt(355000))->post($w->seller);
    ExpenseDraft::make('rent', $w->store)->pay($w->account('cash_kzt'), Money::kzt(35000))->description('Аренда')->post($w->owner);

    $t = app(Metrics::class)->totals(periodToday($w), Scope::all([$w->store->id]));
    expect($t['sales'])->toBe(Money::kzt(555000)->amount)
        ->and($t['revenue'])->toBe(Money::kzt(555000)->amount)
        ->and($t['cost'])->toBe(Money::kzt(489250 + 2400)->amount)
        ->and($t['gross'])->toBe(Money::kzt(555000 - 491650)->amount)
        ->and($t['commissions'])->toBe(Money::kzt(2000)->amount)
        ->and($t['expenses'])->toBe(Money::kzt(35000)->amount)
        ->and($t['net'])->toBe(Money::kzt(555000 - 491650 - 2000 - 35000)->amount)
        ->and($t['checks'])->toBe(1)->and($t['phones'])->toBe(1)->and($t['accessories'])->toBe(1)
        ->and($t['discounts'])->toBe(Money::kzt(3000)->amount)
        ->and($t['margin'])->toBe(round(63350 * 100 / 555000, 2));

    // return of the case: on the return date, the seller of the original check
    $line = SaleLine::query()->where('document_id', $sale->id)->where('line_type', 'accessory')->first();
    $refund = (int) round($line->amount / 100);
    CustomerReturnDraft::make($sale)->line($line, 1)->refund('cash', Money::kztMinor($line->amount))->reason('не подошёл')->post($w->admin);
    $t = app(Metrics::class)->totals(periodToday($w), Scope::all([$w->store->id]));
    expect($t['returns'])->toBe($line->amount)->and($t['revenue'])->toBe(Money::kzt(555000)->amount - $line->amount)
        ->and($t['accessories'])->toBe(0)->and($t['checks'])->toBe(1);

    // seller filter: the return still belongs to the seller of the check
    $bySeller = app(Metrics::class)->totals(periodToday($w), new Scope([$w->store->id], sellerId: $w->seller->id));
    expect($bySeller['revenue'])->toBe($t['revenue'])->and($bySeller['expenses'])->toBe(0);
});

it('a reversed sale disappears from the same period; another store is not counted', function () {
    $w = world();
    $unit = $w->phone();
    $sale = SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    app(Reverser::class)->reverse($sale->refresh(), ReversalReason::InputError, null, $w->owner);

    $t = app(Metrics::class)->totals(periodToday($w), Scope::all([$w->store->id]));
    expect($t['revenue'])->toBe(0)->and($t['cost'])->toBe(0)->and($t['checks'])->toBe(0)->and($t['phones'])->toBe(0);
    expect(app(Metrics::class)->totals(periodToday($w), Scope::all([999]))['sales'])->toBe(0);
});

it('builds periods in the firm time zone and compares with the previous one', function () {
    $now = CarbonImmutable::parse('2026-11-10 20:30:00', 'UTC');   // 01:30 next day in Almaty (+5)
    $p = Period::make('today', 'Asia/Almaty', now: $now);
    expect($p->from->format('Y-m-d H:i'))->toBe('2026-11-11 00:00')
        ->and($p->startUtc())->toBe('2026-11-10 19:00:00')
        ->and($p->previous()->from->format('Y-m-d'))->toBe('2026-11-10');
    $m = Period::make('month', 'Asia/Almaty', now: $now);
    expect($m->previous()->from->format('Y-m-d'))->toBe('2026-10-01')->and($m->previous()->to->format('Y-m-d'))->toBe('2026-10-11');
    expect(Period::make('year', 'Asia/Almaty', now: $now)->bucket())->toBe('week');
});
