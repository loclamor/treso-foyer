<?php

declare(strict_types=1);

namespace App\Enum;

/** Origine d'un remplissage d'AdBlue (ADR 0016). */
enum AdblueSource: string
{
    /** Pompe en station : payé à part, une ligne de relevé (opération liée). */
    case Station = 'station';
    /** Bidon acheté (souvent dans les courses) et versé, en tout ou partie, plus tard. */
    case Can = 'can';

    public function label(): string
    {
        return match ($this) {
            self::Station => 'Station',
            self::Can => 'Bidon',
        };
    }
}
