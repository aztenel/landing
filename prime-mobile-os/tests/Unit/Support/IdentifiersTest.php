<?php

use App\Domain\Ledger\Enums\DocumentType;
use App\Support\DocumentNumber\DocumentNumber;
use App\Support\Idempotency\Idempotency;
use App\Support\Iin\Iin;
use App\Support\Phone\Phone;

it('formats and parses document numbers with TZ 02 §2.3 prefixes', function () {
    expect(DocumentNumber::format('ПРД', 2026, 456))->toBe('ПРД-2026-000456')
        ->and(DocumentNumber::parse('ПРД-2026-004812'))->toBe(['prefix' => 'ПРД', 'year' => 2026, 'sequence' => 4812])
        ->and(DocumentType::Purchase->prefix())->toBe('ПРХ')
        ->and(DocumentType::CustomerReturn->prefix())->toBe('ВЗК')
        ->and(DocumentType::DebtPaymentIn->prefix())->toBe('ОДК')
        ->and(DocumentType::DebtPaymentOut->prefix())->toBe('ОДП')
        ->and(DocumentType::OpeningBalance->prefix())->toBe('ВХО')
        ->and(DocumentType::Reversal->prefix())->toBe('СТН')
        ->and(DocumentType::TransferOut->prefix())->toBe(DocumentType::TransferIn->prefix())
        ->and(DocumentNumber::prefixFor('warranty_case'))->toBe('ГРО');

    foreach (DocumentType::cases() as $type) {
        expect(mb_strlen($type->prefix()))->toBe(3);
    }
});

it('validates IIN control digits and phones, generates idempotency keys', function () {
    expect(Iin::isValid('123'))->toBeFalse()
        ->and(Iin::mask('1234'))->toBe('•••• •••• 1234')
        ->and(Phone::normalize('8 (701) 000-00-01'))->toBe('+77010000001')
        ->and(Phone::format('+77010000001'))->toBe('+7 701 000 00 01')
        ->and(Idempotency::isValid(Idempotency::newKey()))->toBeTrue()
        ->and(Idempotency::derived('reversal', 42))->toBe('reversal:42');
});

it('computes a known valid IIN', function () {
    // build a valid IIN from 11 digits by brute force over the control digit
    $valid = collect(range(0, 9))->map(fn ($d) => '90010130001'.$d)->first(fn ($iin) => Iin::isValid($iin));
    expect($valid)->not->toBeNull()
        ->and(Iin::isValid(substr($valid, 0, 11).(((int) substr($valid, -1) + 1) % 10)))->toBeFalse();
});
