<?php

declare(strict_types=1);

namespace App\Enum;

enum BudgetScope: string
{
    case Household = 'household';
    case Member = 'member';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Household => 'Foyer',
            self::Member => 'Membre',
            self::Account => 'Compte',
        };
    }
}
