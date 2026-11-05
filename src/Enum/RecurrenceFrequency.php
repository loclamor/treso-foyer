<?php

declare(strict_types=1);

namespace App\Enum;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Hebdomadaire',
            self::Monthly => 'Mensuelle',
            self::Quarterly => 'Trimestrielle',
            self::Semiannual => 'Semestrielle',
            self::Yearly => 'Annuelle',
        };
    }
}
