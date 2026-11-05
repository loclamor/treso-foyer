<?php

declare(strict_types=1);

namespace App\Enum;

enum ImportStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Terminé',
            self::Failed => 'Échoué',
        };
    }
}
