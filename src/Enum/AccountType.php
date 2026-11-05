<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountType: string
{
    case Personal = 'personal';
    case Joint = 'joint';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personnel',
            self::Joint => 'Commun',
        };
    }
}
