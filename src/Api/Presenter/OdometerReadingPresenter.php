<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\OdometerReading;

/** Présentation des relevés kilométriques (contrat docs/api.md, lot 4). */
final class OdometerReadingPresenter
{
    /** @return array{id: int, date: string, km: int, note: string|null} date au format AAAA-MM-JJ */
    public function present(OdometerReading $reading): array
    {
        return [
            'id' => (int) $reading->getId(),
            'date' => $reading->getDate()->format('Y-m-d'),
            'km' => $reading->getKm(),
            'note' => $reading->getNote(),
        ];
    }
}
