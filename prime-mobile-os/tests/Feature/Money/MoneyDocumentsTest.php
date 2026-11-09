<?php

use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Debts\Drafts\DebtPaymentDraft;
use App\Domain\Debts\Models\DebtPayment;
use App\Domain\Inventory\Drafts\PurchaseDraft;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Ledger\Exceptions\InsufficientFunds;
use App\Domain\Ledger\Exceptions\PostingException;
use App\Domain\Ledger\Models\DebtEntry;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Drafts\FxConvertDraft;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Models\FxConversion;
use App\Domain\Money\Services\ExchangeRates;
use App\Domain\Tenancy\Access\Exceptions\AccessDenied;
use App\Domain\Tenancy\Access\Exceptions\ApprovalRequired;
use App\Support\Exceptions\UserFacingException;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use Tests\Support\World;

it('does not let an expense take the cash below zero (TZ 02 §14 №9)', function () {
    $w = world();
    $w->cash(Money::kzt(30000));

    expect(fn () => ExpenseDraft::make(ArticleCode::Rent, $w->store)->pay($w->account('cash_kzt'), Money::kzt(50000))->post($w->owner))
        ->toThrow(InsufficientFunds::class, "В «Касса ₸ — ТРЦ «Керуен»» 30\u{00A0}000\u{00A0}₸");

    ExpenseDraft::make(ArticleCode::Courier, $w->store)->pay($w->account('cash_kzt'), Money::kzt(3000))->description('Такси')->post($w->seller);
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(27000)->amount);

    // seller limit L10: 10 000 ₸ per operation
    expect(fn () => ExpenseDraft::make(ArticleCode::Household, $w->store)->pay($w->account('cash_kzt'), Money::kzt(15000))->post($w->seller))
        ->toThrow(ApprovalRequired::class);
});

it('pays a USD supplier debt in tenge with the exchange difference of TZ 02 §3.5 / §14 №5', function () {
    $w = world();
    $w->rate('515', now()->subDays(40));
    OpeningBalanceDraft::make($w->store)->money($w->account('safe_kzt'), Money::kzt(5000000))->post($w->owner);
    PurchaseDraft::make($w->store, $w->supplier, Currency::USD)
        ->unit($w->iphone, World::imei(), Money::usd(10000))->onCredit(now()->addDays(30))->post($w->owner);
    $debt = $w->debt('supplier', $w->supplier->id, 'USD');
    expect((int) $debt->balance)->toBe(-Money::usd(10000)->amount)->and((int) $debt->balance_kzt)->toBe(-Money::kzt(5150000)->amount);

    $w->rate('520');
    $doc = DebtPaymentDraft::toSupplier($w->supplier, $w->account('safe_kzt'), Money::kzt(2080000), Currency::USD, Money::usd(4000))->post($w->owner);

    $entry = DebtEntry::query()->where('document_id', $doc->id)->firstOrFail();
    expect($entry->amount)->toBe(Money::usd(4000)->amount)
        ->and($entry->amount_kzt)->toBe(Money::kzt(2060000)->amount)        // at the average 515
        ->and($entry->fx_diff_kzt)->toBe(-Money::kzt(20000)->amount)        // loss 20 000 ₸
        ->and(DebtPayment::query()->where('document_id', $doc->id)->value('fx_diff_kzt'))->toBe(-Money::kzt(20000)->amount)
        ->and($w->balance('safe_kzt'))->toBe(Money::kzt(5000000 - 2080000)->amount);

    $debt->refresh();
    expect((int) $debt->balance)->toBe(-Money::usd(6000)->amount)
        ->and($debt->avg_rate)->toBe('515.0000')
        ->and((int) $debt->balance_kzt)->toBe(-Money::kzt(3090000)->amount);
});

it('converts dollars to tenge and records the exchange difference (TZ 02 §3.4)', function () {
    $w = world();
    $w->rate('515');
    $w->cash(Money::usd(1500), 'safe_usd');

    $doc = FxConvertDraft::make($w->account('safe_usd'), Money::usd(1000), $w->account('safe_kzt'), Money::kzt(512000))->post($w->admin);

    expect($w->balance('safe_usd'))->toBe(Money::usd(500)->amount)
        ->and($w->balance('safe_kzt'))->toBe(Money::kzt(512000)->amount)
        ->and(FxConversion::query()->where('document_id', $doc->id)->value('fx_diff_kzt'))->toBe(-Money::kzt(3000)->amount);

    expect(fn () => FxConvertDraft::make($w->account('safe_kzt'), Money::kzt(1), $w->account('cash_kzt'), Money::kzt(1))->post($w->admin))
        ->toThrow(PostingException::class, 'разных валют');
});

it('accepts a customer debt payment with a bank commission', function () {
    $w = world();
    OpeningBalanceDraft::make($w->store)->customerDebt($w->customer, Money::kzt(100000), now()->addDays(5))->post($w->owner);

    DebtPaymentDraft::fromCustomer($w->customer, $w->account('kaspi_qr'), Money::kzt(40000), $w->store, '1.00')->post($w->seller);

    expect((int) $w->debt(CounterpartyType::Customer->value, $w->customer->id)->balance)->toBe(Money::kzt(60000)->amount)
        ->and($w->balance('kaspi_qr'))->toBe(Money::kzt(39600)->amount);
});

it('sets the exchange rate with protection from typos', function () {
    $w = world();
    $rates = app(ExchangeRates::class);
    $rates->set('515', $w->admin);

    expect(fn () => $rates->set('5150', $w->admin))->toThrow(UserFacingException::class, 'Вы уверены')
        ->and(fn () => $rates->set('516', $w->seller))->toThrow(AccessDenied::class);

    $rates->set('518', $w->admin);
    expect($rates->current()->equals(Rate::of('518')))->toBeTrue()
        ->and($rates->current(now()->subMinutes(5)))->toBeNull();
});
