<?php

use App\Support\Imei\Imei;

it('accepts a valid IMEI (15 digits, Luhn)', function () {
    expect(Imei::isValid('490154203237518'))->toBeTrue()
        ->and(Imei::isValid('35 693812 4471 206'))->toBe(Imei::luhnValid('356938124471206'));
});

it('rejects a wrong check digit, wrong length and letters', function () {
    expect(Imei::isValid('490154203237517'))->toBeFalse()
        ->and(Imei::isValid('49015420323751'))->toBeFalse()
        ->and(Imei::isValid('4901542032375180'))->toBeFalse()
        ->and(Imei::isValid('49015420323751A'))->toBeFalse()
        ->and(fn () => Imei::assertValid('490154203237517'))->toThrow(InvalidArgumentException::class, 'Луну');
});

it('computes the Luhn check digit and normalizes input', function () {
    expect(Imei::luhnCheckDigit('49015420323751'))->toBe(8)
        ->and(Imei::withCheckDigit('35693812447120'))->toHaveLength(15)
        ->and(Imei::isValid(Imei::withCheckDigit('35693812447120')))->toBeTrue()
        ->and(Imei::normalize(' 49-0154 2032.3751/8 '))->toBe('490154203237518')
        ->and(Imei::format('490154203237518'))->toBe('49 015420 3237 518');
});

it('distinguishes serial numbers from IMEIs', function () {
    expect(Imei::isSerial('DMPXK2LQ7F'))->toBeTrue()
        ->and(Imei::isSerial('490154203237518'))->toBeFalse()
        ->and(Imei::isSerial('AB1'))->toBeFalse();
});
