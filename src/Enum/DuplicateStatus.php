<?php

declare(strict_types=1);

namespace App\Enum;

enum DuplicateStatus: string
{
    case None = 'none';
    case Probable = 'probable';
    case ConfirmedDistinct = 'confirmed_distinct';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Aucun',
            self::Probable => 'Doublon probable',
            self::ConfirmedDistinct => 'Distinct confirmé',
        };
    }
}
