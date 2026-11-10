<?php

use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Models\MoneyEntry;
use App\Domain\Money\Drafts\ExpenseDraft;
use App\Domain\Money\Enums\ArticleCode;
use App\Domain\Money\Enums\PaymentMethodCode;
use App\Domain\Money\Models\PaymentMethod;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Sales\Models\SaleLine;
use App\Filament\Pos\Pages\Sale;
use App\Filament\Sales\Pages\Returns;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

// ТЗ 07 §6, раздел D и E: сценарии 33, 39, 42.

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('33: a phone of proper quality «не подошёл» after 20 days — law hint, only a request to the owner', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $sale = SaleDraft::make($w->store, $w->seller)->customer($w->customer)->unit($unit, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->seller);
    $this->travel(20)->days();
    app(\App\Domain\Ledger\Services\Shifts::class)->current($w->store) ?? app(\App\Domain\Ledger\Services\Shifts::class)->open($w->store, $w->owner);
    $line = SaleLine::query()->where('document_id', $sale->id)->firstOrFail();

    $this->actingAs($w->admin); // the admin may post returns himself — but not this one
    $page = Livewire::test(Returns::class)->call('chooseSale', $sale->id)
        ->set("pick.{$line->id}.on", true)->set("pick.{$line->id}.reason", 'not_fit')->set("pick.{$line->id}.icloud", true)
        ->call('toMoney')->assertSet('step', 3)
        ->assertSee('по закону возвращать не обязаны')->assertDontSee('Провести возврат')->assertSee('Отправить заявку');
    $page->call('post')->assertSet('error', fn ($e) => str_contains((string) $e, 'только по решению владельца'));
    expect(Document::query()->where('type', 'customer_return')->where('status', 'posted')->count())->toBe(0);

    $page->call('sendRequest')->assertSet('error', null)->assertSee('Ждём ответа');
    $request = \App\Domain\Approvals\Models\ApprovalRequest::query()->latest('id')->firstOrFail();
    expect($request->reason_text)->toContain('не подошёл');
    expect(\App\Domain\Sales\Models\CustomerReturnLine::query()->latest('id')->value('reason'))->toBe('not_fit');

    // a defect is a different story: the admin posts it himself
    Livewire::test(Returns::class)->call('chooseSale', $sale->id)
        ->set("pick.{$line->id}.on", true)->set("pick.{$line->id}.reason", 'defect')->set("pick.{$line->id}.icloud", true)
        ->call('toMoney')->assertDontSee('по закону возвращать не обязаны')->assertSee('Провести возврат');

    // the core refuses it too, whatever the screen: a direct post by the admin needs the owner; the owner decides himself
    $draft = fn () => \App\Domain\Sales\Drafts\CustomerReturnDraft::make($sale)->line($line, 1, 'in_stock', reason: 'not_fit')->refund('cash', Money::kzt(549000));
    expect(fn () => $draft()->post($w->admin))->toThrow(\App\Domain\Tenancy\Access\Exceptions\ApprovalRequired::class, 'надлежащего качества');
    $w->cash(Money::kzt(600000));
    expect($draft()->post($w->owner)->status->value)->toBe('posted');
});

it('39: one expense «Аренда» 300 000 ₸ paid 100 000 cash + 200 000 Kaspi Gold — one РСХ, two money rows, one article', function () {
    $w = world();
    $w->cash(Money::kzt(150000));
    $w->cash(Money::kzt(250000), 'kaspi_gold');
    $doc = ExpenseDraft::make(ArticleCode::Rent, $w->store)
        ->pay($w->account('cash_kzt'), Money::kzt(100000))->pay($w->account('kaspi_gold'), Money::kzt(200000))
        ->description('Аренда ноябрь')->post($w->owner);

    $rows = MoneyEntry::query()->where('document_id', $doc->id)->get();
    expect($doc->number)->toStartWith('РСХ')
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('article_id')->unique())->toHaveCount(1)
        ->and($rows->sum('amount'))->toBe(-Money::kzt(300000)->amount)
        ->and($w->balance('cash_kzt'))->toBe(Money::kzt(50000)->amount)
        ->and($w->balance('kaspi_gold'))->toBe(Money::kzt(50000)->amount)
        ->and(Reconciliation::problems())->toBe([]);
});

it('42: the cash register has no «Наличные $» — the hint says to exchange dollars first', function () {
    $w = world();
    expect(collect(PaymentMethodCode::cases())->map->value)->not->toContain('cash_usd')
        ->and(PaymentMethod::query()->where('is_enabled', true)->get()->every(fn ($m) => $m->account_id === null || $m->account->currency->value === 'KZT'))->toBeTrue();

    $unit = $w->phone(cost: Money::kzt(400000));
    $this->actingAs($w->seller);
    Livewire::test(Sale::class)->call('scan', $unit->imei1)->call('f9')->assertSet('modal', 'payment')
        ->assertSee('Сначала обмен валюты')->assertDontSee('Наличные $');
});
