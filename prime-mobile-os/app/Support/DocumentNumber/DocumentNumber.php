<?php

namespace App\Support\DocumentNumber;

use InvalidArgumentException;

/**
 * Document numbers «<префикс>-<год>-<6 цифр>» (TZ 02 §2.3): ПРД-2026-004812.
 * The counter key is the prefix («series»): transfer_out and transfer_in share ПРМ and one counter.
 * Allocation itself (FOR UPDATE on document_counters) lives in App\Domain\Ledger\Services\DocumentNumberAllocator.
 */
final class DocumentNumber
{
    /** document type => prefix (TZ 02 §2.3) */
    public const PREFIXES = [
        'purchase' => 'ПРХ',
        'sale' => 'ПРД',
        'customer_return' => 'ВЗК',
        'supplier_return' => 'ВЗП',
        'tradein' => 'ТРД',
        'order' => 'ЗАК',
        'debt_adjustment' => 'КДЛ',
        'fx_convert' => 'КНВ',
        'bonus_manual' => 'БНС',
        'reversal' => 'СТН',
        'cost_adjustment' => 'КСБ',
        'transfer_out' => 'ПРМ',
        'transfer_in' => 'ПРМ',
        'write_off' => 'СПС',
        'revision' => 'РЕВ',
        'prep_cost' => 'ЗПД',
        'expense' => 'РСХ',
        'debt_payment_in' => 'ОДК',
        'debt_payment_out' => 'ОДП',
        'cash_move' => 'ДДС',
        'shift_close' => 'СМН',
        'opening_balance' => 'ВХО',
        'unit_edit' => 'ИЗХ',
    ];

    /** Numbered entities that are not accounting documents (no journals). */
    public const EXTRA_SERIES = [
        'warranty_case' => 'ГРО',
    ];

    public static function prefixFor(string $type): string
    {
        return self::PREFIXES[$type] ?? self::EXTRA_SERIES[$type]
            ?? throw new InvalidArgumentException("No number prefix for document type {$type}");
    }

    public static function format(string $prefix, int $year, int $sequence): string
    {
        if ($sequence < 1 || $sequence > 999999) {
            throw new InvalidArgumentException("Document sequence out of range: {$sequence}");
        }

        return sprintf('%s-%04d-%06d', $prefix, $year, $sequence);
    }

    /** @return array{prefix: string, year: int, sequence: int} */
    public static function parse(string $number): array
    {
        if (! preg_match('/^(\p{Lu}{3})-(\d{4})-(\d{6})$/u', $number, $m)) {
            throw new InvalidArgumentException("Invalid document number: {$number}");
        }

        return ['prefix' => $m[1], 'year' => (int) $m[2], 'sequence' => (int) $m[3]];
    }
}
