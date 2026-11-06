<?php

declare(strict_types=1);

namespace App\Enum;

/** Sens d'une série récurrente (le signe du montant est porté par la direction). */
enum RecurrenceDirection: string
{
    case Expense = 'expense';
    case Income = 'income';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Dépense',
            self::Income => 'Revenu',
        };
    }
}
