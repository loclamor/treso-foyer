<?php

declare(strict_types=1);

namespace App\Enum;

enum ImportFormat: string
{
    case Pdf = 'pdf';
    case Csv = 'csv';

    /**
     * Priorité du libellé : à l'import, une opération déjà connue voit son libellé remplacé
     * par celui d'une source de priorité strictement supérieure (le PDF, plus complet, l'emporte sur le CSV).
     */
    public function labelPriority(): int
    {
        return match ($this) {
            self::Pdf => 2,
            self::Csv => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
        };
    }
}
