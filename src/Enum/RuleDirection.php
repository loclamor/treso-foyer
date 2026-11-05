<?php

declare(strict_types=1);

namespace App\Enum;

enum RuleDirection: string
{
    case Any = 'any';
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Tous',
            self::Debit => 'Débit',
            self::Credit => 'Crédit',
        };
    }
}
