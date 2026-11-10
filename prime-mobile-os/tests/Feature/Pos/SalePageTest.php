<?php

use App\Domain\Catalog\Models\AccessoryPrice;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Ledger\Models\Document;
use App\Domain\Ledger\Services\Shifts;
use App\Filament\Pos\Pages\Sale;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('sells from the page: scan, quick cash, F9, «Провести»; the receipt opens for the seller only (Э3)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $this->actingAs($w->seller);

    $page = Livewire::test(Sale::class)
        ->assertSee('Продажа')->assertSee('Чек пуст')
        ->call('scan', $unit->imei1)->assertSet('error', null)
        ->assertSee('549')
        ->call('quickPay', 'cash')
        ->call('f9')->assertSet('modal', 'payment')
        ->call('pay')->assertSet('error', null)->assertSet('modal', 'done');

    $doc = Document::query()->where('type', 'sale')->where('status', 'posted')->firstOrFail();
    expect($unit->refresh()->status)->toBe(UnitStatus::Sold);
    $this->get(route('erp.pos.receipt', $doc))->assertOk()->assertSee($doc->number)->assertSee('549');

    $this->actingAs($w->accountant)->get(route('erp.pos.receipt', $doc))->assertForbidden();
});

it('does not take the price from the browser and shows the core errors as they are', function () {
    $w = world();
    $unit = $w->phone();
    $this->actingAs($w->seller);
    $page = Livewire::test(Sale::class)->call('scan', $unit->imei1);

    // a forged payment window: 1 ₸ instead of 549 000 — the check does not balance on the server
    $page->call('openPayment')->set('payRows', [['method' => 'cash', 'amount' => '1', 'tendered' => '', 'due_date' => '']])
        ->call('pay')->assertSet('error', fn ($e) => str_contains((string) $e, 'не хватает'));

    $page->call('scan', '000000000000000')->assertSet('error', fn ($e) => str_contains((string) $e, 'IMEI с ошибкой') || str_contains((string) $e, 'Не нашли'));
});

it('asks to open the shift with a cash count when it is closed (Э3.6)', function () {
    $w = world();
    app(Shifts::class)->close(app(Shifts::class)->current($w->store), $w->owner);
    $this->actingAs($w->seller);

    $page = Livewire::test(Sale::class)->assertSet('modal', 'shift-open')->assertSee('Открыть смену');
    $counted = collect(app(\App\Domain\Money\Services\ShiftCash::class)->registers($w->store))->mapWithKeys(fn ($a) => [$a->id => '0'])->all();
    $page->set('counted', $counted)->call('doOpenShift')->assertSet('error', null)->assertSet('modal', null);
    expect(app(Shifts::class)->current($w->store))->not->toBeNull();
});

it('sends an over-limit discount request from the page and posts after the owner answers (Э28.2)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(400000));
    $this->actingAs($w->seller);

    $page = Livewire::test(Sale::class)->call('scan', $unit->imei1)
        ->call('openDiscount')->set('discountValue', '15000')->set('discountReason', 'торг')->call('saveDiscount')
        ->call('quickPay', 'cash')->call('f9')->call('pay')->assertSet('modal', 'approval')
        ->call('requestApproval')->assertSee('Ждём ответа');

    $request = \App\Domain\Approvals\Models\ApprovalRequest::query()->latest('id')->first();
    app(\App\Domain\Approvals\Services\ApprovalService::class)->approve($request, $w->owner);
    $page->call('pollApproval')->assertSet('modal', 'done');
    expect($unit->refresh()->status)->toBe(UnitStatus::Sold);
});
