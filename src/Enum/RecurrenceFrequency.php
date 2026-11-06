<?php

declare(strict_types=1);

namespace App\Enum;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Hebdomadaire',
            self::Monthly => 'Mensuelle',
            self::Bimonthly => 'Bimestrielle',
            self::Quarterly => 'Trimestrielle',
            self::Semiannual => 'Semestrielle',
            self::Yearly => 'Annuelle',
        };
    }

    /** Durée nominale d'un cycle en jours (mois moyen = 30,44 j). */
    public function nominalDays(): float
    {
        return match ($this) {
            self::Weekly => 7.0,
            self::Monthly => 30.44,
            self::Bimonthly => 60.88,
            self::Quarterly => 91.31,
            self::Semiannual => 182.62,
            self::Yearly => 365.25,
        };
    }

    /** Tolérance sur les écarts de dates, en jours. */
    public function toleranceDays(): int
    {
        return match ($this) {
            self::Weekly => 2,
            self::Monthly => 6,
            self::Bimonthly => 10,
            self::Quarterly => 15,
            self::Semiannual => 25,
            self::Yearly => 30,
        };
    }

    /** Nombre de mois calendaires d'un cycle (null pour l'hebdomadaire). */
    public function months(): ?int
    {
        return match ($this) {
            self::Weekly => null,
            self::Monthly => 1,
            self::Bimonthly => 2,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Yearly => 12,
        };
    }
}
