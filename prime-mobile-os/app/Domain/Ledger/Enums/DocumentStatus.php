<?php

namespace App\Domain\Ledger\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::Posted => 'Проведён',
            self::Reversed => 'Сторнирован',
        };
    }
}
