<?php

use App\Domain\Inventory\Services\PriceService;
use App\Domain\Notifications\Models\AppNotification;
use App\Domain\Reports\Services\Summaries;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Storefront\Services\Promotions;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function promo($w, array $over = []): int
{
    return DB::table('promotions')->insertGetId($over + ['organization_id' => $w->org->id, 'name_ru' => 'Чёрная пятница', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
        'filter' => json_encode(['model_ids' => [$w->iphone->model_id]]), 'discount_type' => 'percent', 'discount_value' => 5, 'rounding' => 1000, 'show_old' => true,
        'status' => 'planned', 'created_by' => $w->owner->id, 'created_at' => now(), 'updated_at' => now()]);
}

it('rounds the promo price down as in TZ 05 §6.3: 489 000 −5% → 464 000', function () {
    expect(Promotions::price(Money::kzt(489000)->amount, 'percent', 5, 1000))->toBe(Money::kzt(464000)->amount)
        ->and(Promotions::price(Money::kzt(489000)->amount, 'amount', 20000, 1))->toBe(Money::kzt(469000)->amount)
        ->and(Promotions::price(Money::kzt(489000)->amount, 'fixed', 449990, 100))->toBe(Money::kzt(449900)->amount);
});

it('starts by the clock with price documents, the cash register sells at the promo price, and puts prices back', function () {
    $w = world();
    $a = $w->phone();                     // 549 000
    $b = $w->phone();
    $id = promo($w);
    app(Promotions::class)->tick();

    expect($a->refresh()->sale_price)->toBe(Money::kzt(521000)->amount)       // 549 000 × 0.95 = 521 550 → 521 000
        ->and($a->compare_at_price)->toBe(Money::kzt(549000)->amount)
        ->and(DB::table('price_changes')->where('reason', 'promo:'.$id)->count())->toBe(2);

    app(PriceService::class)->setUnitPrice($b->refresh(), Money::kzt(500000), $w->owner);   // changed by hand during the promo
    DB::table('promotions')->where('id', $id)->update(['ends_at' => now()->subMinute()]);
    app(Promotions::class)->tick();

    expect($a->refresh()->sale_price)->toBe(Money::kzt(549000)->amount)->and($a->compare_at_price)->toBeNull()
        ->and($b->refresh()->sale_price)->toBe(Money::kzt(500000)->amount)
        ->and(DB::table('promotion_items')->where('stock_unit_id', $b->id)->value('result'))->toBe('manual')
        ->and(AppNotification::query()->where('title', 'like', '%решите сами%')->exists())->toBeTrue();
});

it('sends the owner «Итог дня» from the report formula, without customer data', function () {
    $w = world();
    $unit = $w->phone();
    SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $msg = app(Summaries::class)->day(CarbonImmutable::now());
    expect($msg['title'])->toContain("549\u{00A0}000")->and($msg['body'])->toContain('Чеков 1')->toContain('Айдар')
        ->not->toContain($w->customer->name)->not->toContain($w->customer->phone)->not->toContain($unit->imei1);

    $this->artisan('prime:summaries', ['--force' => 'day'])->assertSuccessful();
    expect(AppNotification::query()->where('user_id', $w->owner->id)->where('type', 'daily_summary')->exists())->toBeTrue();
    expect(app(Summaries::class)->day(CarbonImmutable::now()->subDays(3))['body'])->toContain('Продаж не было');
    expect(app(Summaries::class)->week(CarbonImmutable::now())['title'])->toContain('Итог недели');
    expect(app(Summaries::class)->month(CarbonImmutable::now())['body'])->toContain('чистая прибыль');
});
