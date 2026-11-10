<?php

use App\Domain\Shell\Models\Device;
use App\Domain\Shell\Services\DeviceRegistry;
use Filament\Facades\Filament;

// ТЗ 07 §6, сценарий 52: the owner disables a stolen laptop — its session ends at the next request, login from it is refused.

it('52: a disabled device is logged out at its next request and cannot log in again', function () {
    $w = world();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $key = str_repeat('a', 48);
    $this->withCookie(DeviceRegistry::COOKIE, $key)->actingAs($w->seller)->get('http://erp.localhost/sale')->assertOk();
    $device = Device::query()->where('key_hash', hash('sha256', $key))->firstOrFail();

    app(DeviceRegistry::class)->disable($device, $w->owner);

    $this->withCookie(DeviceRegistry::COOKIE, $key)->get('http://erp.localhost/sale')->assertRedirect();
    $this->assertGuest();
    // the next login from the same laptop is refused again by the same check
    $this->withCookie(DeviceRegistry::COOKIE, $key)->actingAs($w->seller)->get('http://erp.localhost/')->assertRedirect();
    $this->assertGuest();
});
