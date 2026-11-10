<?php

use App\Filament\Shell\Pages\Settings;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('saves firm settings for the owner in user units (Э26)', function () {
    $w = world();
    $this->actingAs($w->owner);
    Livewire::test(Settings::class)
        ->set('firm.accent', 'graphite')->set('firm.debt_ceiling', '250000')->set('firm.bonus_phone', '1.5')
        ->call('saveFirm')->assertHasNoErrors();

    $s = $w->org->fresh()->settings();
    expect($s->accent())->toBe('graphite')
        ->and($s->customerDebtCeiling())->toBe(25000000)
        ->and($s->bonusPhonePercent())->toBe('1.50');
});

it('lets the admin change only store data, a seller nothing (Э26, TZ 04 §1)', function () {
    $w = world();
    $this->actingAs($w->admin);
    // a forged call of the owner's action is refused on the server
    expect(fn () => Livewire::test(Settings::class)->call('saveFirm'))->toThrow(\App\Domain\Tenancy\Access\Exceptions\AccessDenied::class);
    Livewire::test(Settings::class)->set("stores.{$w->store->id}.address", 'ул. Достык, 9')->call('saveStores')->assertHasNoErrors();
    expect($w->store->fresh()->address)->toBe('ул. Достык, 9');

    $this->actingAs($w->seller);
    $this->get('http://'.config('prime.erp_domain').'/settings')->assertForbidden();
});

it('refuses to change the code prefix after the first phone (audit №43)', function () {
    $w = world();
    $w->phone();
    $this->actingAs($w->owner);
    expect(fn () => Livewire::test(Settings::class)->set('firm.code_prefix', 'XX')->call('saveFirm'))
        ->toThrow(\App\Support\Exceptions\UserFacingException::class);
});
