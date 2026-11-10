<?php

use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\StockUnit;
use App\Domain\Ledger\Enums\ReversalReason;
use App\Domain\Ledger\Posting\Reverser;
use App\Domain\Ledger\Services\AccountingPeriods;
use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Domain\Sales\Drafts\CustomerReturnDraft;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Services\Exchange;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Reconciliation;
use Tests\Support\World;

// ТЗ 07 §6, разделы C, D, E, F, H: сценарии 28, 29, 31, 34, 37, 43, 45, 50.

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function dayPeriod($w, $day): Period
{
    $date = $day->copy()->setTimezone($w->org->timezone)->toDateString();   // the firm's day, not the UTC one

    return Period::make('custom', $w->org->timezone, $date, $date);
}

it('28: a repeated trade-in of a phone we sold — the same unit, the passport shows the sale and the trade-in act', function () {
    $w = world();
    $unit = $w->phone(variant: $w->usedIphone, cost: Money::kzt(150000));
    SaleDraft::make($w->store, $w->owner)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $act = \App\Domain\TradeIn\Drafts\TradeInDraft::make($w->store, $w->customer, $w->usedIphone, $unit->imei1, Money::kzt(200000))->grade('A')->battery(90)
        ->checklist(['icloud' => 'yes'])->kit(['box'])->imeiCheck('registered')->idDocument('900101300123')->payout('cash', Money::kzt(200000))->post($w->owner);

    expect(StockUnit::query()->where('imei1', $unit->imei1)->count())->toBe(1);
    $this->actingAs($w->owner)->get(route('erp.stock.units.show', $unit->public_code))->assertOk()
        ->assertSee('ПРД-')->assertSee($act->number)->assertSee('Продан')->assertSee('На подготовке');
});

it('29: a return after the rate changed 515 → 530 — 300 000 ₸ back, the phone at its cost 257 500, the sale day profit stays, the return day −42 500', function () {
    $w = world();
    $w->rate('515');
    $imei = World::imei();
    PurchaseDraft::make($w->store, $w->supplier, Currency::USD)->unit($w->iphone, $imei, Money::usd(500), ['sale_price' => Money::kzt(300000)])
        ->onCredit(now()->addDays(30))->post($w->admin);
    $unit = StockUnit::findByIdentifier($imei);
    \App\Domain\Inventory\Models\ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $imei, 'result' => 'registered', 'checked_by' => $w->owner->id, 'checked_at' => now()]);
    $saleDay = now();
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($unit, Money::kzt(300000))->cash(Money::kzt(300000))->post($w->owner);

    $this->travel(2)->days();
    $w->rate('530', now()->subMinute());
    $this->travel(1)->days();
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();
    $return = CustomerReturnDraft::make($sale)->line($line, 1, UnitStatus::Defective, reason: 'defect')->refund('cash', Money::kzt(300000))->post($w->owner);

    expect($unit->refresh()->cost_kzt)->toBe(Money::kzt(257500)->amount)
        ->and($unit->status)->toBe(UnitStatus::Defective)
        ->and($return->exchange_rate)->toBe('530.0000')
        ->and($w->balance('cash_kzt'))->toBe(0);
    $metrics = app(Metrics::class);
    expect($metrics->totals(dayPeriod($w, $saleDay), Scope::all([$w->store->id]))['gross'])->toBe(Money::kzt(42500)->amount)
        ->and($metrics->totals(dayPeriod($w, now()), Scope::all([$w->store->id]))['gross'])->toBe(-Money::kzt(42500)->amount)
        ->and(Reconciliation::problems())->toBe([]);
});

it('31: a full return of the split with debt (nothing paid) — the debt −129 000 first, then cash −100 000; the phone is «брак»', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(180000), attributes: ['sale_price' => Money::kzt(229000)]);
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($unit, Money::kzt(229000))
        ->cash(Money::kzt(100000))->debt(Money::kzt(129000), now()->addMonth())->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    // only cash is refused: the debt part must go back to the debt
    expect(fn () => CustomerReturnDraft::make($sale)->line($line, 1, UnitStatus::Defective, reason: 'defect')->refund('cash', Money::kzt(229000))->post($w->owner))
        ->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class);
    CustomerReturnDraft::make($sale)->line($line, 1, UnitStatus::Defective, reason: 'defect')
        ->refundToDebt(Money::kzt(129000))->refund('cash', Money::kzt(100000))->post($w->owner);

    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(0)
        ->and($w->balance('cash_kzt'))->toBe(0)
        ->and($unit->refresh()->status)->toBe(UnitStatus::Defective)
        ->and(Reconciliation::problems())->toBe([]);
});

it('34: an exchange to a cheaper phone (A55 229 000 → A35 179 000) — one operation, 50 000 ₸ back to the client', function () {
    $w = world();
    $w->cash(Money::kzt(100000));
    $old = $w->phone(cost: Money::kzt(180000), attributes: ['sale_price' => Money::kzt(229000)]);
    $new = $w->phone(cost: Money::kzt(140000), attributes: ['sale_price' => Money::kzt(179000)]);
    $sale = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($old, Money::kzt(229000))->cash(Money::kzt(229000))->post($w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    $return = CustomerReturnDraft::make($sale)->line($line, 1, UnitStatus::InStock, reason: 'defect')
        ->refundToExchange(Money::kzt(179000))->refund('cash', Money::kzt(50000))->save();
    $buy = SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($new, Money::kzt(179000))->pay('exchange_offset', Money::kzt(179000))->save();
    [$r, $s] = app(Exchange::class)->post($return, $buy, $w->owner);

    expect($r->exchange_group_id)->toBe($s->exchange_group_id)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(100000 + 229000 - 50000)->amount)
        ->and($w->balance('exchange_offset'))->toBe(0)
        ->and($new->refresh()->status)->toBe(UnitStatus::Sold)
        ->and(Reconciliation::problems())->toBe([]);
});

it('37: the rate 515 → 520 at 15:00 — the 14:00 sale keeps 515, the 16:00 sale has 520; the day is not recomputed', function () {
    $w = world();
    $this->travelTo(now()->setTimezone($w->org->timezone)->setTime(14, 0));
    $w->rate('515', now()->subHours(2));
    $a = SaleDraft::make($w->store, $w->owner)->unit($w->phone(cost: Money::kzt(400000)), Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $this->travelTo(now()->setTime(15, 0));
    app(\App\Domain\Money\Services\ExchangeRates::class)->set('520', $w->owner, confirmed: true);
    $this->travelTo(now()->setTime(16, 0));
    $b = SaleDraft::make($w->store, $w->owner)->unit($w->phone(cost: Money::kzt(400000)), Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);

    expect($a->refresh()->exchange_rate)->toBe('515.0000')->and($b->exchange_rate)->toBe('520.0000');
});

it('43: the admin reverses the sale «пробили не тот телефон» — mirror rows, the phone back in stock, the check «отменён», the activity log has it', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    app(Reverser::class)->reverse($sale, ReversalReason::WrongItem, 'пробили не тот телефон', $w->admin);

    expect($sale->refresh()->status->value)->toBe('reversed')
        ->and($unit->refresh()->status)->toBe(UnitStatus::InStock)
        ->and($w->balance('cash_kzt'))->toBe(0)
        ->and(Activity::query()->where('log_name', 'documents')->where('description', 'reversed')->where('subject_id', $sale->id)
            ->where('causer_id', $w->admin->id)->value('properties')['reason_text'] ?? null)->toBe('пробили не тот телефон')
        ->and(Reconciliation::problems())->toBe([]);
});

it('45: October is closed — the admin cannot post a sale on 30.10; the reversal of an October check is dated today', function () {
    $w = world();
    $this->travelTo(\Carbon\Carbon::parse('2026-10-30 12:00', $w->org->timezone));
    $old = SaleDraft::make($w->store, $w->owner)->unit($w->phone(cost: Money::kzt(400000)), Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $this->travelBack();
    app(AccountingPeriods::class)->close(\Carbon\Carbon::parse('2026-10-01'), $w->owner);

    expect(fn () => SaleDraft::make($w->store, $w->admin)->reason('Забыли провести')->occurredAt(\Carbon\Carbon::parse('2026-10-30 12:00', $w->org->timezone))
        ->unit($w->phone(cost: Money::kzt(400000)), Money::kzt(549000))->cash(Money::kzt(549000))->post($w->admin))
        ->toThrow(\App\Domain\Ledger\Exceptions\PeriodClosed::class, 'Период закрыт по 31.10.2026');
    $rev = app(Reverser::class)->reverse($old, ReversalReason::InputError, null, $w->owner);
    expect($rev->occurred_at->copy()->setTimezone($w->org->timezone)->toDateString())->toBe(now()->setTimezone($w->org->timezone)->toDateString());
});

it('50: an IMEI and a customer of the firm «Тест» are not found in Prime Mobile; the intake only warns «встречается в другой фирме», without the name', function () {
    $test = world(name: 'Тест');
    $foreign = $test->phone();
    \App\Domain\Counterparties\Models\Customer::query()->create(['name' => 'Чужой клиент', 'phone' => '+77779998877']);

    $pm = world();
    $this->actingAs($pm->owner);
    $found = fn (string $q) => collect(app(\App\Support\Ui\ErpSearch::class)->getResults($q)?->getCategories() ?? [])->flatten()->count();
    expect($found($foreign->imei1))->toBe(0)->and($found('77779998877'))->toBe(0);

    \Livewire\Livewire::test(\App\Filament\Intake\Pages\NewIntake::class)->set('head.supplier_id', $pm->supplier->id)
        ->call('pickVariant', 0, $pm->iphone->id)->call('scan', $foreign->imei1)
        ->assertSet('scanError', null)->assertSet('scanWarning', fn ($m) => str_contains((string) $m, 'встречается в другой фирме'))
        ->assertDontSee('Тест');
});
