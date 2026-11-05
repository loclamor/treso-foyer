<?php

declare(strict_types=1);

namespace App\Vehicle;

/**
 * Distance parcourue entre deux bornes, calculée par OdometerCalculator :
 * km aux bornes interpolés linéairement entre les relevés encadrants.
 */
final class OdometerDistance
{
    public function __construct(
        /** Kilomètres parcourus entre coveredFrom et coveredTo (float : interpolation). */
        public readonly float $km,
        public readonly \DateTimeImmutable $coveredFrom,
        public readonly \DateTimeImmutable $coveredTo,
    ) {
    }

    /** @return array{km: float, coveredFrom: string, coveredTo: string} dates au format AAAA-MM-JJ */
    public function toArray(): array
    {
        return [
            'km' => $this->km,
            'coveredFrom' => $this->coveredFrom->format('Y-m-d'),
            'coveredTo' => $this->coveredTo->format('Y-m-d'),
        ];
    }
}
