<?php

use App\Domain\Shell\Models\Device;
use App\Domain\Shell\Services\Pins;
use App\Domain\Shell\Services\TwoFactor;
use App\Support\Exceptions\UserFacingException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => config(['prime.two_factor' => true]));

it('computes TOTP by RFC 6238 (test vector) and accepts ±30 s', function () {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';                // «12345678901234567890»
    expect(TwoFactor::code($secret, 59))->toBe('287082')
        ->and(TwoFactor::code($secret, 1111111109))->toBe('081804')
        ->and(TwoFactor::verifyCode($secret, '287082', 59 + 25))->toBeTrue()
        ->and(TwoFactor::verifyCode($secret, '287082', 59 + 95))->toBeFalse();
});

it('makes the owner set up 2FA, shows 10 recovery codes once, then asks for the code on every new session', function () {
    $w = world();
    $this->actingAs($w->owner);
    $this->get('http://erp.localhost/')->assertRedirect(route('erp.two-factor.setup'));
    $this->get(route('erp.two-factor.setup'))->assertOk()->assertSee('Включите вход по коду')->assertSee('<svg', false);
    $secret = Crypt::decryptString(session('shell.2fa_setup'));
    $this->post(route('erp.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post(route('erp.two-factor.confirm'), ['code' => TwoFactor::code($secret)])->assertRedirect(route('erp.two-factor.setup'));
    $page = $this->get(route('erp.two-factor.setup'))->assertOk()->assertSee('Сохраните резервные коды')->getContent();
    preg_match_all('/[A-Z0-9]{4}-[A-Z0-9]{4}/', $page, $m);
    expect(array_unique($m[0]))->toHaveCount(10)->and($w->owner->refresh()->two_factor_secret)->not->toContain($secret);

    // a new session: the code is asked again
    session()->flush();
    $this->actingAs($w->owner->refresh());
    $this->get('http://erp.localhost/')->assertRedirect(route('erp.two-factor'));
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('erp.two-factor.verify'), ['code' => '111111'])->assertSessionHasErrors('code');
    }
    $this->post(route('erp.two-factor.verify'), ['code' => TwoFactor::code($secret)])->assertSessionHasErrors(['code' => __('Код не подходит 5 раз. Попробуйте через 15 мин.')]);
    RateLimiter::clear('2fa:'.$w->owner->id);
    $this->post(route('erp.two-factor.verify'), ['code' => $m[0][0]])->assertRedirect();     // a recovery code — once
    expect(count(json_decode($w->owner->refresh()->two_factor_recovery_codes, true)))->toBe(9);
});

it('does not ask a seller; the owner resets 2FA of an employee', function () {
    $w = world();
    $this->actingAs($w->seller)->get('http://erp.localhost/sale')->assertOk();
    expect(TwoFactor::required($w->seller))->toBeFalse()->and(TwoFactor::required($w->accountant))->toBeTrue();

    $w->admin->forceFill(['two_factor_secret' => Crypt::encryptString(TwoFactor::newSecret()), 'two_factor_confirmed_at' => now()])->save();
    app(TwoFactor::class)->reset($w->admin, $w->owner);
    expect(TwoFactor::enabled($w->admin->refresh()))->toBeFalse();
});

it('sets a PIN per user and device: no weak PINs, only after the password, unlock checks the hash', function () {
    $w = world();
    $device = Device::query()->forceCreate(['organization_id' => $w->org->id, 'key_hash' => hash('sha256', 'k1'), 'name' => 'iPhone', 'type' => 'personal', 'status' => 'allowed']);
    $pins = app(Pins::class);
    expect(fn () => $pins->set($w->seller, $device, '1234', 'password'))->toThrow(UserFacingException::class, 'простой')
        ->and(fn () => $pins->set($w->seller, $device, '5821', 'wrong'))->toThrow(UserFacingException::class, 'Пароль');
    $pins->set($w->seller, $device, '5821', 'password');
    expect($pins->check($w->seller, $device, '5821'))->toBeTrue()->and($pins->check($w->seller, $device, '5822'))->toBeFalse()
        ->and($pins->check($w->seller2, $device, '5821'))->toBeFalse()
        ->and(DB::table('device_pins')->value('pin_hash'))->not->toBe('5821');
});
