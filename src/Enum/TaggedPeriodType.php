<?php

declare(strict_types=1);

namespace App\Enum;

enum TaggedPeriodType: string
{
    case Vacation = 'vacation';
    case Weekend = 'weekend';
    case Outing = 'outing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Vacances',
            self::Weekend => 'Week-end',
            self::Outing => 'Sortie',
            self::Other => 'Autre',
        };
    }
}
