<?php

use App\Filament\Pages\Auth\PhoneLogin;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('logs into the ERP by phone and password and gets the organization context', function () {
    $w = world();
    CurrentOrganization::instance()->clear();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(PhoneLogin::class)
        ->set('data.phone', '8 (707) 000-00-00')
        ->set('data.password', 'wrong')
        ->call('authenticate')
        ->assertHasErrors(['data.phone']);

    Livewire::test(PhoneLogin::class)
        ->set('data.phone', $w->owner->phone)
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertHasNoErrors();
    $this->assertAuthenticatedAs($w->owner);

    $this->get('/admin')->assertOk();
    expect(CurrentOrganization::instance()->id())->toBe($w->org->id);
});
