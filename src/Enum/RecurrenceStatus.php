<?php

declare(strict_types=1);

namespace App\Enum;

enum RecurrenceStatus: string
{
    case Detected = 'detected';
    case Confirmed = 'confirmed';
    case Ignored = 'ignored';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Détectée',
            self::Confirmed => 'Confirmée',
            self::Ignored => 'Ignorée',
            self::Ended => 'Terminée',
        };
    }
}
