<?php

use App\Domain\Catalog\Models\VariantPrice;
use App\Domain\Counterparties\Models\Supplier;
use App\Domain\Inventory\Models\PriceChange;
use App\Filament\Stock\Pages\Catalog;
use App\Filament\Stock\Pages\Prices;
use App\Filament\Stock\Pages\Suppliers;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('changes the price of a variant in the price list with a journal and updates the phones in stock (Э13)', function () {
    $w = world();
    $unit = $w->phone(); // own price 549 000
    $this->actingAs($w->owner);

    Livewire::test(Prices::class)->set("changes.{$w->iphone->id}", '529 000')->set('reason', 'акция')->call('apply')->assertSet('error', null);
    expect(VariantPrice::query()->where('variant_id', $w->iphone->id)->value('price_kzt'))->toBe(Money::kzt(529000)->amount)
        ->and($unit->refresh()->sale_price)->toBe(Money::kzt(529000)->amount)
        ->and(PriceChange::query()->count())->toBe(2);

    // the seller looks only; the admin cannot change many prices at once without the owner
    $this->actingAs($w->seller);
    Livewire::test(Prices::class)->assertSee('529')->assertDontSeeHtml('wire:model.live.debounce.400ms="changes.');
    $this->actingAs($w->admin);
    Livewire::test(Prices::class)->set("changes.{$w->iphone->id}", '530 000')->set("changes.{$w->usedIphone->id}", '200 000')->call('apply')
        ->assertSet('error', fn ($e) => $e !== null);
});

it('keeps suppliers away from sellers and checks the name is unique (Э23)', function () {
    $w = world();
    $this->actingAs($w->seller);
    $this->get(Suppliers::getUrl())->assertForbidden();

    $this->actingAs($w->owner);
    Livewire::test(Suppliers::class)->assertSee('Dubai Mobile')
        ->call('edit')->set('form.name', 'Dubai Mobile')->call('save')->assertHasErrors('form.name')
        ->set('form.name', 'ТехноОпт')->set('form.currency', 'USD')->set('form.payment_days', 14)->call('save')->assertHasNoErrors();
    expect(Supplier::query()->where('name', 'ТехноОпт')->value('currency'))->toBe('USD');
});

it('lets only the owner rename a shared model, adds a variant and fills Kazakh names (Э24)', function () {
    $w = world();
    $model = $w->iphone->productModel;
    $this->actingAs($w->admin);
    Livewire::test(Catalog::class)->call('openModel', $model->id)->set('model.name_ru', 'iPhone 15 Pro (ошибка)')->call('saveModel')->assertHasErrors('model.name_ru');
    expect($model->refresh()->name_ru)->toBe('iPhone 15 Pro');

    Livewire::test(Catalog::class)->set('tabName', 'kk')->set('kk.model:'.$model->id, 'iPhone 15 Pro')->call('saveKk', 'model:'.$model->id);
    expect($model->refresh()->name_kk)->toBe('iPhone 15 Pro');

    Livewire::test(Catalog::class)->set('tabName', 'accessories')->call('editAccessory')->set('accessory.name_ru', 'Кабель USB-C')->set('accessory.sku', 'CAB-1')
        ->set('accessory.barcode', $w->case->barcode)->call('saveAccessory')->assertHasErrors('accessory.barcode')
        ->call('generateBarcode')->call('saveAccessory')->assertHasNoErrors();
});
