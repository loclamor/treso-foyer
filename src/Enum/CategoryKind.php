<?php

declare(strict_types=1);

namespace App\Enum;

enum CategoryKind: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Dépense',
            self::Income => 'Revenu',
            // transfer = mouvements internes / épargne, exclus des dépenses.
            self::Transfer => 'Mouvement interne',
        };
    }
}
