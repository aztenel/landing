<?php

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Counterparties\Enums\CounterpartyType;
use App\Domain\Ledger\Drafts\OpeningBalanceDraft;
use App\Domain\Money\Models\MoneyArticle;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Filament\Approvals\Pages\Requests;
use App\Filament\Money\Pages\Balance;
use App\Filament\Money\Pages\Customers;
use App\Filament\Money\Pages\Debts;
use App\Filament\Money\Pages\Expenses;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('shows a customer card with purchases, debt and bonuses; the owner anonymizes (Э18)', function () {
    $w = world();
    $unit = $w->phone();
    SaleDraft::make($w->store, $w->owner)->customer($w->customer)->unit($unit, Money::kzt(549000))->cash(Money::kzt(449000))->debt(Money::kzt(100000), now()->addDays(10))->post($w->owner);
    $this->actingAs($w->seller);
    Livewire::test(Customers::class)->set('q', 'Ерлан')->assertSee('Ерлан')->call('open', $w->customer->id)->assertSee('100')->assertDontSee('Обезличить');
    $this->actingAs($w->owner);
    Livewire::test(Customers::class)->call('open', $w->customer->id)->set('tabName', 'data')->call('anonymize');
    expect($w->customer->refresh()->name)->toBe('Клиент №'.$w->customer->id)->and($w->customer->phone)->toBeNull();
});

it('takes a debt payment from the debts screen; the seller does not see suppliers (Э19)', function () {
    $w = world();
    OpeningBalanceDraft::make($w->store)->debt(CounterpartyType::Customer, $w->customer->id, Money::kzt(129000), now()->addDays(5))
        ->debt(CounterpartyType::Supplier, $w->supplier->id, Money::kzt(-500000), now()->addDays(5))->post($w->owner);
    $this->actingAs($w->seller);
    Livewire::test(Debts::class)->assertSee('Ерлан')->assertDontSee('Мы должны')
        ->call('payCustomer', $w->customer->id)->set('pay.amount', '50000')->set('pay.method', 'cash')->call('submit')->assertSet('error', null);
    expect((int) $w->debt('customer', $w->customer->id)->balance)->toBe(Money::kzt(79000)->amount);

    $w->cash(Money::kzt(600000));
    $this->actingAs($w->admin);
    Livewire::test(Debts::class)->set('tabName', 'suppliers')->assertSee('Dubai Mobile')
        ->call('paySupplier', $w->supplier->id, 'KZT')->set('pay.amount', '200000')->set('pay.account_id', $w->account('cash_kzt')->id)->call('submit')->assertSet('error', null);
    expect((int) $w->debt('supplier', $w->supplier->id)->balance)->toBe(Money::kzt(-300000)->amount)->and(Reconciliation::problems())->toBe([]);
});

it('sends a collection from the balance and the owner accepts it there (Э20)', function () {
    $w = world();
    $w->cash(Money::kzt(700000));
    $this->actingAs($w->seller);
    Livewire::test(Balance::class)->assertSee('Касса')->call('openModal', 'collection')->set('f.from', $w->account('cash_kzt')->id)->set('f.amount', '600000')->call('submit')->assertSet('error', null);
    $this->actingAs($w->owner);
    $sent = \App\Domain\Money\Models\CashMove::query()->where('kind', 'collection')->first();
    Livewire::test(Balance::class)->assertSee('Принять')->call('openModal', 'accept', $sent->document_id)->set('f.amount', '600000')->call('submit')->assertSet('error', null);
    expect($w->balance('safe_kzt'))->toBe(Money::kzt(600000)->amount)->and(Reconciliation::problems())->toBe([]);
});

it('records an expense from the cash register (Э21)', function () {
    $w = world();
    $w->cash(Money::kzt(50000));
    $this->actingAs($w->seller);
    $article = MoneyArticle::query()->where('kind', 'expense')->where('code', '<>', 'bank_fee')->first();
    Livewire::test(Expenses::class)->call('newExpense')->set('form.article_id', $article->id)->set('form.amount', '3000')
        ->set('form.account_id', $w->account('cash_kzt')->id)->set('form.description', 'такси')->call('save')->assertSet('error', null)->assertSee('такси');
    expect($w->balance('cash_kzt'))->toBe(Money::kzt(47000)->amount);
});

it('lets the owner decide a request on the requests page, also with another sum (Э28.3)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $draft = SaleDraft::make($w->store, $w->seller)->unit($unit, Money::kzt(549000))->discount(Money::kzt(15000))->cash(Money::kzt(534000))->save();
    $request = app(ApprovalService::class)->requestForDocument($draft, $w->seller, 'постоянный клиент');
    $this->actingAs($w->owner);
    Livewire::test(Requests::class)->assertSee($request->headline)->set("f.{$request->id}.value", '10000')->call('decide', $request->id, 'change')->assertSet('error', null);
    expect($request->refresh()->status)->toBe(ApprovalStatus::Approved);
    $this->actingAs($w->seller);
    Livewire::test(Requests::class)->assertSee('разрешено');
});
