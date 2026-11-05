<?php

declare(strict_types=1);

namespace App\Enum;

enum TransferStatus: string
{
    case Auto = 'auto';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatique',
            self::Confirmed => 'Confirmé',
        };
    }
}
