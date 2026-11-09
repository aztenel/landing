<?php

namespace App\Domain\Ledger\Enums;

/** Reversal reasons (TZ 02 §2.1 p.5). «other» requires a text of at least 5 characters. */
enum ReversalReason: string
{
    case InputError = 'input_error';
    case WrongItem = 'wrong_item';
    case WrongAmount = 'wrong_amount';
    case Duplicate = 'duplicate';
    case SystemError = 'system_error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InputError => 'Ошибка ввода',
            self::WrongItem => 'Не тот товар',
            self::WrongAmount => 'Не та сумма',
            self::Duplicate => 'Дубль',
            self::SystemError => 'Исправление ошибки системы',
            self::Other => 'Другое',
        };
    }
}
