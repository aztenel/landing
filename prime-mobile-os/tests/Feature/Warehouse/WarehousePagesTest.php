<?php

use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Sales\Drafts\SaleDraft;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Services\OrganizationProvisioner;
use App\Domain\Warehouse\Models\WarrantyCase;
use App\Filament\Warehouse\Pages\Revisions;
use App\Filament\Warehouse\Pages\Transfers;
use App\Filament\Warehouse\Pages\WarrantyCases;
use App\Filament\Warehouse\Pages\WriteOffs;
use App\Livewire\Stock\UnitPassport;
use App\Support\Imei\Imei;
use App\Support\Money\Money;
use App\Support\Ui\WorkingStore;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Reconciliation;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('sends a transfer by scanning and accepts it in the other store (Э14)', function () {
    $w = world();
    $b = Store::query()->create(['name' => 'Хан Шатыр']);
    app(OrganizationProvisioner::class)->installStarterAccounts($b);
    $unit = $w->phone();
    $this->actingAs($w->owner);
    WorkingStore::set($w->owner, $w->store->id);
    Livewire::test(Transfers::class)->set('toStore', $b->id)->call('addCode', $unit->imei1)->call('send')->assertSet('error', null);
    expect($unit->refresh()->status)->toBe(UnitStatus::InTransit);

    WorkingStore::set($w->owner, $b->id);
    $page = Livewire::test(Transfers::class);
    $out = \App\Domain\Ledger\Models\Document::query()->where('type', 'transfer_out')->first();
    $page->call('open', $out->id)->call('accept')->assertSet('error', null);
    expect($unit->refresh()->isInStore($b->id))->toBeTrue()->and(Reconciliation::problems())->toBe([]);
});

it('runs a revision: the seller scans, the admin posts; a missing phone is written off (Э15)', function () {
    $w = world();
    $here = $w->phone();
    $missing = $w->phone();
    $this->actingAs($w->seller);
    Livewire::test(Revisions::class)->call('start')->call('scan', $here->imei1)->assertSee('совпало')->assertDontSee('Провести итог');
    $this->actingAs($w->admin);
    Livewire::test(Revisions::class)->call('post')->assertSet('error', null);
    expect($missing->refresh()->status)->toBe(UnitStatus::WrittenOff)->and($here->refresh()->status)->toBe(UnitStatus::InStock)
        ->and(Reconciliation::problems())->toBe([]);
});

it('writes off from the page and accepts a warranty case by IMEI (Э16, Э17)', function () {
    $w = world();
    $unit = $w->phone(cost: Money::kzt(100000));
    $this->actingAs($w->owner);
    Livewire::test(WriteOffs::class)->call('addCode', $unit->public_code)->set('reason', 'разбит')->call('post')->assertSet('error', null);
    expect($unit->refresh()->status)->toBe(UnitStatus::WrittenOff);

    $sold = $w->phone();
    SaleDraft::make($w->store, $w->owner)->unit($sold, Money::kzt(549000))->cash(Money::kzt(549000))->post($w->owner);
    $this->actingAs($w->seller);
    Livewire::test(WarrantyCases::class)->set('code', $sold->imei1)->set('complaint', 'не заряжается')->call('accept')->assertSet('error', null);
    expect(WarrantyCase::query()->count())->toBe(1)->and($sold->refresh()->status)->toBe(UnitStatus::Sold);
});

it('corrects an IMEI typo from the passport (ИЗХ) — the seller only by request', function () {
    $w = world();
    $unit = $w->phone();
    $new = Imei::withCheckDigit('35693812888888');
    $this->actingAs($w->seller);
    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->set('edit.imei1', $new)->set('edit.reason', 'опечатка')->call('saveEdit')
        ->assertSet('error', fn ($e) => $e !== null);
    $this->actingAs($w->admin);
    Livewire::test(UnitPassport::class, ['code' => $unit->public_code])->set('edit.imei1', $new)->set('edit.reason', 'опечатка')->call('saveEdit')->assertSet('error', null);
    expect($unit->refresh()->imei1)->toBe($new);
});
