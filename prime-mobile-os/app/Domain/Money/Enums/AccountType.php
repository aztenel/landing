<?php

namespace App\Domain\Money\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case InstallmentBank = 'installment_bank';
    case Safe = 'safe';
    case Technical = 'technical';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Касса',
            self::Bank => 'Безнал',
            self::InstallmentBank => 'Рассрочка банка',
            self::Safe => 'Сейф',
            self::Technical => 'Служебный',
        };
    }

    public function isCash(): bool
    {
        return $this === self::Cash || $this === self::Safe;
    }
}
