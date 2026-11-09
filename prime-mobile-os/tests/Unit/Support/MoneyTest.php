<?php

use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Money\Rate;
use App\Support\Money\Rounding;

it('stores whole tenge in tiyn and dollars in cents', function () {
    expect(Money::kzt(489000)->amount)->toBe(48900000)
        ->and(Money::kzt('2433.33')->amount)->toBe(243333)
        ->and(Money::usd('1.20')->amount)->toBe(120)
        ->and(Money::usd(950)->currency)->toBe(Currency::USD);
});

it('converts USD to KZT exactly (TZ 02 §3.2: $950 × 515 = 489 250,00 ₸)', function () {
    expect(Money::usd(950)->convert(Currency::KZT, Rate::of('515'))->equals(Money::kzt(489250)))->toBeTrue()
        ->and(Money::usd('1.20')->convert(Currency::KZT, Rate::of('515.0000'))->equals(Money::kzt(618)))->toBeTrue()
        ->and(Money::usd(1140)->convert(Currency::KZT, Rate::of('512,40'))->amount)->toBe(58413600);
});

it('rounds conversions half-up to one tiyn, symmetric for negatives', function () {
    // 1 cent × 512.5 ₸/$ = 512.5 tiyn → 513
    expect(Money::usdMinor(1)->convert(Currency::KZT, Rate::of('512.5'))->amount)->toBe(513)
        ->and(Money::usdMinor(-1)->convert(Currency::KZT, Rate::of('512.5'))->amount)->toBe(-513)
        // 1 cent × 512.4999 = 512.4999 tiyn → 512
        ->and(Money::usdMinor(1)->convert(Currency::KZT, Rate::of('512.4999'))->amount)->toBe(512)
        // KZT → USD: 100 ₸ / 515 = 0.19417… $ → 19 cents
        ->and(Money::kzt(100)->convert(Currency::USD, Rate::of('515'))->amount)->toBe(19)
        ->and(Rounding::HalfUp->divide(5, 2))->toBe(3)
        ->and(Rounding::HalfUp->divide(-5, 2))->toBe(-3)
        ->and(Rounding::Down->divide(7, 2))->toBe(3)
        ->and(Rounding::Up->divide(7, 2))->toBe(4);
});

it('computes bank commission to whole tenge half-up and bonuses down (TZ 02 §3.2)', function () {
    expect(Money::kzt(600000)->percent('12', Rounding::HalfUp, Money::TENGE)->equals(Money::kzt(72000)))->toBeTrue()
        ->and(Money::kzt(13990)->percent('0.95', Rounding::HalfUp, Money::TENGE)->equals(Money::kzt(133)))->toBeTrue()
        ->and(Money::kzt(546048)->percent('3', Rounding::Down, Money::TENGE)->equals(Money::kzt(16381)))->toBeTrue()
        ->and(Money::kzt(8952)->percent('5.00', Rounding::Down, Money::TENGE)->equals(Money::kzt(447)))->toBeTrue();
});

it('spreads a check discount by the largest remainder method (TZ 02 §3.2 example)', function () {
    $shares = Money::kzt(3000)->allocate([Money::kzt(549000)->amount, Money::kzt(9000)->amount], Money::TENGE);

    expect($shares[0]->equals(Money::kzt(2952)))->toBeTrue()
        ->and($shares[1]->equals(Money::kzt(48)))->toBeTrue()
        ->and(Money::kzt(549000)->minus($shares[0])->equals(Money::kzt(546048)))->toBeTrue()
        ->and(Money::kzt(9000)->minus($shares[1])->equals(Money::kzt(8952)))->toBeTrue();
});

it('allocates exactly the total for many lines and keeps keys', function () {
    $shares = Money::kzt(1000)->allocate(['a' => 1, 'b' => 1, 'c' => 1], Money::TENGE);

    expect(array_keys($shares))->toBe(['a', 'b', 'c'])
        ->and(array_sum(array_map(fn ($m) => $m->amount, $shares)))->toBe(100000)
        ->and($shares['a']->equals(Money::kzt(334)))->toBeTrue(); // ties go to the first line
});

it('formats amounts like the design: «489 000 ₸», «$1 140»', function () {
    $nbsp = "\u{00A0}";
    expect(Money::kzt(489000)->format())->toBe("489{$nbsp}000{$nbsp}₸")
        ->and(Money::kzt('2433.50')->format())->toBe("2{$nbsp}434{$nbsp}₸")
        ->and(Money::kzt(-2952)->format())->toBe("\u{2212}2{$nbsp}952{$nbsp}₸")
        ->and(Money::usd(1140)->format())->toBe("\$1{$nbsp}140")
        ->and(Money::usd('950.5')->format())->toBe('$950.50')
        ->and(Rate::of('512.4')->format())->toBe('512,40');
});

it('refuses to mix currencies and floats', function () {
    expect(fn () => Money::kzt(1)->plus(Money::usd(1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Rate::of('512.12345'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::kzt('12.345'))->toThrow(InvalidArgumentException::class);
});
