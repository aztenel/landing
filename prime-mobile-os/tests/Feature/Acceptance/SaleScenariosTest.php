<?php

use App\Domain\Ledger\Models\Document;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Pos\Services\CartService;
use App\Domain\Reports\Services\Metrics;
use App\Domain\Reports\Services\Period;
use App\Domain\Reports\Services\Scope;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

// ТЗ 07 §6, раздел B: сценарии 9, 10, 13, 16, 19.

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('8: iPhone 489 000 + case 4 990 + glass 2 990, 500 000 cash given — 496 980, change 3 020, bonuses 5 128 pending, the receipt has the IMEI in kk/ru', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000), attributes: ['sale_price' => Money::kzt(489000)]);
    $w->accessories($w->case, 5, Money::kzt(2000));
    $w->accessories($w->glass, 5, Money::kzt(900));
    $doc = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(489000))
        ->accessory($w->case, 1, Money::kzt(4990))->accessory($w->glass, 1, Money::kzt(2990))
        ->cash(Money::kzt(496980), tendered: Money::kzt(500000))->post($w->seller);

    $pay = \App\Domain\Sales\Models\SalePayment::query()->where('document_id', $doc->id)->firstOrFail();
    expect((int) $pay->change)->toBe(Money::kzt(3020)->amount)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(496980)->amount)
        ->and(\App\Domain\Ledger\Models\BonusEntry::query()->where('document_id', $doc->id)->pluck('amount')->sort()->values()->all())->toBe([89, 149, 4890])
        ->and((int) $w->bonuses($w->customer)->pending)->toBe(5128)
        ->and((int) $w->bonuses($w->customer)->available)->toBe(0);
    $this->actingAs($w->seller)->get(route('erp.print.sale', $doc))->assertOk()
        ->assertSee($unit->imei1)->assertSee('Сдача')->assertSee('Қайтарым', false);
    expect(Reconciliation::problems())->toBe([]);
});

it('9: split 300 000 ₸ — Kaspi QR 200 000 (1 %) + cash 100 000: QR +200 000 −2 000, cash +100 000, profit −2 000, the seller does not see the fee', function () {
    $w = world();
    PaymentMethod::query()->where('code', 'kaspi_qr')->update(['commission_percent' => '1.00']);
    $unit = $w->phone(cost: Money::kzt(250000), attributes: ['sale_price' => Money::kzt(300000)]);
    $doc = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(300000))
        ->pay('kaspi_qr', Money::kzt(200000))->cash(Money::kzt(100000))->post($w->seller);

    expect($w->balance('kaspi_qr'))->toBe(Money::kzt(198000)->amount)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(100000)->amount)
        ->and(\App\Domain\Ledger\Models\MoneyEntry::query()->where('document_id', $doc->id)->where('amount', -Money::kzt(2000)->amount)->exists())->toBeTrue();
    $t = app(Metrics::class)->totals(Period::make('today', $w->org->timezone), Scope::all([$w->store->id]));
    expect($t['commissions'])->toBe(Money::kzt(2000)->amount)
        ->and($t['profit_sales'])->toBe(Money::kzt(300000 - 250000 - 2000)->amount);

    $this->actingAs($w->seller);
    Livewire::test(\App\Filament\Sales\Pages\Sales::class)->call('open', $doc->id)->assertDontSee('омиссия')->assertDontSee('прибыль');
    expect(Reconciliation::problems())->toBe([]);
});

it('10: Samsung 229 000 — cash 100 000 + debt 129 000 till 09.12.2026 for Ерлан: cash, debt, the receipt says when to pay', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(180000), attributes: ['sale_price' => Money::kzt(229000)]);
    $doc = SaleDraft::make($w->store, $w->admin)->customer($w->customer)->unit($unit, Money::kzt(229000))
        ->cash(Money::kzt(100000))->debt(Money::kzt(129000), \Carbon\Carbon::parse('2026-12-09'))->post($w->admin);

    expect($w->balance('cash_kzt'))->toBe(Money::kzt(100000)->amount)
        ->and((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(129000)->amount);
    $this->actingAs($w->admin)->get(route('erp.print.sale', $doc))->assertOk()->assertSee('вернуть до 09.12.2026')->assertSee('129');
    $this->get('http://erp.localhost/debts')->assertOk()->assertSee('09.12.2026');
});

it('13: two cash desks post the same phone — exactly one check; the other gets «только что продан», no journal rows, the rest of the cart stays', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $w->accessories($w->case, 3, Money::kzt(2000));
    \App\Domain\Catalog\Models\AccessoryPrice::query()->create(['accessory_id' => $w->case->id, 'price_kzt' => Money::kzt(4990)->amount]);
    $carts = app(CartService::class);
    $a = $carts->cart($w->seller, $w->store);
    $d = $carts->cart($w->seller2, $w->store);
    $carts->scan($a, $w->seller, $unit->imei1);
    // the reservation of Айдар expired; Дана took the phone as well (the moment both press «Провести»)
    \App\Domain\Inventory\Models\UnitReservation::query()->update(['expires_at' => now()->subMinute()]);
    app(\App\Domain\Inventory\Services\ReservationService::class)->releaseExpired();
    $carts->scan($d, $w->seller2, $unit->imei1);
    $carts->scan($d, $w->seller2, $w->case->barcode);
    $carts->payAllWith($a->refresh(), 'cash');
    $carts->payAllWith($d->refresh(), 'cash');

    $first = $carts->checkout($d->refresh(), $w->seller2);
    $before = \App\Domain\Ledger\Models\MoneyEntry::query()->count();
    expect(fn () => $carts->checkout($a->refresh(), $w->seller))
        ->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class, "Телефон только что продан (Дана Продавец, {$first['document']->number}");
    expect($first['status'])->toBe('posted')
        ->and(Document::query()->where('type', 'sale')->where('status', 'posted')->count())->toBe(1)
        ->and(\App\Domain\Ledger\Models\MoneyEntry::query()->count())->toBe($before)
        ->and(Reconciliation::problems())->toBe([]);
});

it('16: Айдар left a phone in the cart; 31 min later Дана scans it — hers; Айдар «Провести» — «Телефон в корзине у Даны»', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $carts = app(CartService::class);
    $a = $carts->cart($w->seller, $w->store);
    $carts->scan($a, $w->seller, $unit->imei1);

    $this->travel(31)->minutes();
    $d = $carts->cart($w->seller2, $w->store);
    $carts->scan($d, $w->seller2, $unit->imei1);
    expect($carts->totals($d->refresh())['lines'])->toHaveCount(1);

    $carts->payAllWith($a->refresh(), 'cash');
    expect(fn () => $carts->checkout($a->refresh(), $w->seller))->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class, 'в корзине у Дана');
});

it('19: a seller sells below the cost (463 500 → 460 000): «Цена ниже допустимой. Нужна заявка владельцу», the cost is not in the text', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(463500), attributes: ['sale_price' => Money::kzt(460000)]);
    try {
        SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(460000))->cash(Money::kzt(460000))->post($w->seller);
        $this->fail('no approval was asked');
    } catch (ApprovalRequired $e) {
        expect($e->getMessage())->toContain('Цена ниже допустимой. Нужна заявка владельцу')
            ->and($e->getMessage())->not->toContain('463')->and($e->getMessage())->not->toContain('себестоим');
    }
});

it('11–12: debt without a customer — «выберите клиента [F4]»; with a customer but without a date — «Укажите дату возврата долга»', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(180000), attributes: ['sale_price' => Money::kzt(229000)]);
    $carts = app(CartService::class);
    $cart = $carts->cart($w->seller, $w->store);
    $carts->scan($cart, $w->seller, $unit->imei1);
    $pays = [['method' => 'cash', 'amount' => Money::kzt(100000)->amount], ['method' => 'debt', 'amount' => Money::kzt(129000)->amount, 'due_date' => '']];
    expect(fn () => $carts->setPayments($cart->refresh(), $pays))->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class, 'выберите клиента [F4]');

    $carts->setCustomer($cart->refresh(), $w->customer->id);
    $carts->setPayments($cart->refresh(), $pays);
    expect(fn () => $carts->checkout($cart->refresh(), $w->seller))->toThrow(\App\Domain\Ledger\Exceptions\PostingException::class, 'Укажите дату возврата долга');
    expect(\App\Domain\Ledger\Models\Document::query()->where('type', 'sale')->where('status', 'posted')->count())->toBe(0);
});
