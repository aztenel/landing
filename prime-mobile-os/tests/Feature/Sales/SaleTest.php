<?php

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\ImeiCheck;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\BonusEntry;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleLine;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Money\Money;

/** TZ 02 §6.3 with a card instead of the trade-in offset (the offset needs the act): iPhone 15 Pro 549 000 + case 9 000, check discount 3 000. */
function splitSaleWorld(): array
{
    $w = world(['bonus' => ['phone_percent' => '3.00', 'accessory_percent' => '5.00']]);
    PaymentMethod::query()->where('code', 'kaspi_qr')->update(['commission_percent' => '1.00']);
    $unit = $w->phone(cost: Money::kzt(489250));
    $w->accessories($w->case, 10, Money::kzt(2400));

    return [$w, $unit];
}

it('posts the split sale of TZ 02 §6.3: discount spread, change, commission, costs, bonuses', function () {
    [$w, $unit] = splitSaleWorld();

    $doc = SaleDraft::make($w->store, $w->seller)->customer($w->customer)
        ->unit($unit, Money::kzt(549000))
        ->accessory($w->case, 1, Money::kzt(9000))
        ->discount(Money::kzt(3000))
        ->pay('card', Money::kzt(150000))
        ->pay('kaspi_qr', Money::kzt(200000))
        ->cash(Money::kzt(205000), tendered: Money::kzt(210000))
        ->post($w->owner);

    $lines = SaleLine::query()->where('document_id', $doc->id)->orderBy('line_no')->get();
    expect($lines[0]->amount)->toBe(Money::kzt(546048)->amount)
        ->and($lines[0]->check_discount_share)->toBe(Money::kzt(2952)->amount)
        ->and($lines[1]->amount)->toBe(Money::kzt(8952)->amount)
        ->and($lines[0]->cost_kzt)->toBe(Money::kzt(489250)->amount)
        ->and($lines[1]->cost_kzt)->toBe(Money::kzt(2400)->amount)
        ->and(Sale::query()->where('document_id', $doc->id)->value('total'))->toBe(Money::kzt(555000)->amount);

    // gross profit 63 350, minus commission 2 000 = 61 350 (TZ 02 §6.3)
    $gross = $lines->sum('amount') - $lines->sum('cost_kzt');
    $commission = -MoneyEntry::query()->where('document_id', $doc->id)->where('amount', '<', 0)->sum('amount');
    expect($gross)->toBe(Money::kzt(63350)->amount)->and($gross - $commission)->toBe(Money::kzt(61350)->amount);

    expect($w->balance('kaspi_qr'))->toBe(Money::kzt(198000)->amount)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(205000)->amount)
        ->and($w->balance('halyk_terminal'))->toBe(Money::kzt(150000)->amount)
        ->and(SalePayment::query()->where('document_id', $doc->id)->where('method', 'cash')->value('change'))->toBe(Money::kzt(5000)->amount);

    expect($unit->refresh()->status)->toBe(UnitStatus::Sold)
        ->and($unit->location())->toBeNull()
        ->and($w->accessoryQty($w->case))->toBe(9)
        ->and($w->accessoryAvg($w->case))->toBe(Money::kzt(2400)->amount);

    // bonuses 16 381 + 447 = 16 828 pending for 14 days
    expect((int) $w->bonuses($w->customer)->pending)->toBe(16828)
        ->and((int) $w->bonuses($w->customer)->available)->toBe(0)
        ->and(BonusEntry::query()->where('document_id', $doc->id)->pluck('amount')->sort()->values()->all())->toBe([447, 16381]);
});

it('does not post when payments do not match the total', function () {
    [$w, $unit] = splitSaleWorld();

    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(500000))->post($w->seller))
        ->toThrow(PostingException::class, 'не равна итогу');
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock)
        ->and(Document::query()->where('type', 'sale')->first()->isDraft())->toBeTrue()
        ->and(Document::query()->where('type', 'sale')->first()->number)->toBeNull();
});

it('splits an installment sale into revenue and bank commission (TZ 02 §6.4)', function () {
    $w = world();
    PaymentMethod::query()->where('code', 'kaspi_installment')->update(['commission_percent' => '12.00']);
    $unit = $w->phone();

    SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(600000))->pay('kaspi_installment', Money::kzt(600000))->post($w->owner);

    expect($w->balance('kaspi_pay'))->toBe(Money::kzt(528000)->amount)
        ->and(MoneyEntry::query()->where('account_id', $w->account('kaspi_pay')->id)->pluck('amount')->all())
        ->toBe([Money::kzt(600000)->amount, -Money::kzt(72000)->amount])
        ->and($w->debt('customer', $w->customer->id))->toBeNull();
});

it('sells on credit with a due date and accrues bonuses only on the paid part', function () {
    $w = world();
    $unit = $w->phone();

    expect(fn () => SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($unit, Money::kzt(500000))
        ->cash(Money::kzt(400000))->pay('debt', Money::kzt(100000))->post($w->owner))
        ->toThrow(PostingException::class, 'срок');

    SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($unit, Money::kzt(500000))
        ->cash(Money::kzt(400000))->debt(Money::kzt(100000), now()->addDays(10))->post($w->owner);

    $debt = $w->debt(CounterpartyType::Customer->value, $w->customer->id);
    expect((int) $debt->balance)->toBe(Money::kzt(100000)->amount)
        ->and($debt->nearest_due_date->format('Y-m-d'))->toBe(now()->addDays(10)->format('Y-m-d'))
        ->and((int) $w->bonuses($w->customer)->pending)->toBe(4000); // 1 % of 400 000
});

it('asks for approval when the seller exceeds limits; the draft stays without a number', function () {
    [$w, $unit] = splitSaleWorld();

    try {
        SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(300000))->discount(Money::kzt(6000))->cash(Money::kzt(294000))->post($w->seller);
        $this->fail('Approval expected');
    } catch (ApprovalRequired $e) {
        expect($e->approvalType)->toBe('discount')->and($e->context['limit'])->toBe('L1');
    }
    expect($unit->refresh()->status)->toBe(UnitStatus::InStock);

    // the new customer cannot get a debt from a seller without the owner (L6)
    expect(fn () => SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))
        ->cash(Money::kzt(500000))->debt(Money::kzt(49000), now()->addDays(5))->post($w->seller))->toThrow(ApprovalRequired::class);

    // selling below cost needs the owner even within discount limits (the list price itself is below cost here)
    $unit->forceFill(['sale_price' => Money::kzt(400000)->amount])->save();
    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(400000))->cash(Money::kzt(400000))->post($w->seller))
        ->toThrow(ApprovalRequired::class, 'ниже допустимой');
});

it('counts a price below the list price and a gift as a discount: the limit cannot be bypassed (audit №2)', function () {
    [$w, $unit] = splitSaleWorld();
    $unit->forceFill(['sale_price' => Money::kzt(549000)->amount])->save();

    // the seller lowers the price by 6 000 instead of giving a discount → limit L1 (5 000 ₸)
    try {
        SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(543000), listPrice: Money::kzt(543000))->cash(Money::kzt(543000))->post($w->seller);
        $this->fail('Approval expected');
    } catch (ApprovalRequired $e) {
        expect($e->approvalType)->toBe('discount')->and($e->context['limit'])->toBe('L1');
    }

    // a «gift» without an owner promotion is a 100 % discount: 2 cases at 9 000 = 18 000 ₸ > 5 000 ₸
    \App\Domain\Catalog\Models\AccessoryPrice::query()->updateOrCreate(['accessory_id' => $w->case->id], ['price_kzt' => Money::kzt(9000)->amount]);
    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->gift($w->case, 2)->cash(Money::kzt(549000))->post($w->seller))
        ->toThrow(ApprovalRequired::class);

    // within the limit it posts; the list price on the line is the database price
    $doc = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(546000), listPrice: Money::kzt(1))->cash(Money::kzt(546000))->post($w->owner);
    expect(SaleLine::query()->where('document_id', $doc->id)->value('list_price_kzt'))->toBe(Money::kzt(549000)->amount)
        ->and(Sale::query()->where('document_id', $doc->id)->value('discount_total'))->toBe(Money::kzt(3000)->amount);
});

it('takes the payment account and bank commission from the firm settings, not from the request (audit №6)', function () {
    $w = world();
    PaymentMethod::query()->where('code', 'kaspi_qr')->update(['commission_percent' => '1.00']);
    $unit = $w->phone();

    $doc = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(500000))
        ->pay('kaspi_qr', Money::kzt(500000), ['account_id' => $w->account('cash_kzt')->id, 'commission_percent' => '0.00'])
        ->post($w->owner);

    $payment = SalePayment::query()->where('document_id', $doc->id)->first();
    expect($payment->account_id)->toBe($w->account('kaspi_qr')->id)
        ->and($w->balance('kaspi_qr'))->toBe(Money::kzt(495000)->amount)
        ->and($w->balance('cash_kzt'))->toBe(0);

    // the trade-in offset needs the act
    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($w->phone(), Money::kzt(500000))->pay('tradein', Money::kzt(500000))->post($w->owner))
        ->toThrow(PostingException::class, 'актом trade-in');
});

it('requires a fresh IMEI registry check for sellers (TZ 02 §10.4 p.3)', function () {
    $w = world();
    $unit = $w->phone(imeiCheck: false);

    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller))
        ->toThrow(ApprovalRequired::class, 'госбазы');

    ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $unit->imei1, 'result' => 'registered', 'checked_by' => $w->seller->id, 'checked_at' => now()->subDays(8)]);
    expect(fn () => SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller))
        ->toThrow(ApprovalRequired::class, 'старше 7');

    ImeiCheck::query()->create(['stock_unit_id' => $unit->id, 'imei' => $unit->imei1, 'result' => 'registered', 'checked_by' => $w->seller->id, 'checked_at' => now()]);
    $doc = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    expect($doc->isPosted())->toBeTrue();
});

it('never sells more accessories than in stock', function () {
    $w = world();
    $w->accessories($w->case, 1, Money::kzt(2000));

    expect(fn () => SaleDraft::make($w->store, $w->seller)->accessory($w->case, 2, Money::kzt(9000))->cash(Money::kzt(18000))->post($w->seller))
        ->toThrow(InsufficientStock::class, 'Нет на остатке');
    expect($w->accessoryQty($w->case))->toBe(1);
});

it('pays with bonuses FIFO within 20 % of the check', function () {
    $w = world();
    OpeningBalanceDraft::make($w->store)
        ->bonus($w->customer, 3000, now()->subDays(30), now()->addDays(100))
        ->bonus($w->customer, 5000, now()->subDays(30), now()->addDays(10))
        ->post($w->owner);
    $w->accessories($w->case, 5, Money::kzt(2000));

    expect(fn () => SaleDraft::make($w->store, $w->seller)->customer($w->customer)->accessory($w->case, 1, Money::kzt(9000))
        ->bonus(Money::kzt(5000))->cash(Money::kzt(4000))->post($w->seller))->toThrow(PostingException::class, '20');

    SaleDraft::make($w->store, $w->seller)->customer($w->customer)->accessory($w->case, 4, Money::kzt(9000))
        ->bonus(Money::kzt(7000))->cash(Money::kzt(29000))->post($w->seller);
    // the 5 000 expiring first are spent first, then 2 000 of the 3 000
    $balance = $w->bonuses($w->customer);
    expect((int) $balance->available)->toBe(1000)
        ->and((int) $balance->pending)->toBe(870); // 3 % of (36 000 − 7 000)
});

it('keeps one customer under the firm debt ceiling of 300 000 ₸ unless the owner allows (audit №17)', function () {
    $w = world();
    SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($w->phone(), Money::kzt(900000))
        ->cash(Money::kzt(650000))->debt(Money::kzt(250000), now()->addDays(10))->post($w->owner);

    // the admin sells 80 000 more on credit: 330 000 > 300 000 → approval of the owner, even with a higher role limit L4
    \App\Domain\Tenancy\Models\RoleLimit::query()->where('code', 'L4')
        ->whereIn('role_id', \Spatie\Permission\Models\Role::query()->where('organization_id', $w->org->id)->where('name', 'admin')->pluck('id'))
        ->update(['value' => Money::kzt(1000000)->amount]);
    expect(fn () => SaleDraft::make($w->store, $w->admin)->customer($w->customer)->unit($w->phone(), Money::kzt(900000))
        ->cash(Money::kzt(820000))->debt(Money::kzt(80000), now()->addDays(10))->post($w->admin))
        ->toThrow(ApprovalRequired::class, 'потолка фирмы');

    // the owner may
    expect(SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($w->phone(), Money::kzt(900000))
        ->cash(Money::kzt(820000))->debt(Money::kzt(80000), now()->addDays(10))->post($w->owner)->isPosted())->toBeTrue();
});
