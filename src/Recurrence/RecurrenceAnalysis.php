<?php

declare(strict_types=1);

namespace App\Recurrence;

use App\Enum\RecurrenceFrequency;

/**
 * Résultat de l'analyse d'un groupe d'opérations (classe pure, sans dépendance).
 */
final readonly class RecurrenceAnalysis
{
    /**
     * @param list<int> $linkedIds opérations retenues (liées à la série)
     */
    public function __construct(
        public RecurrenceFrequency $frequency,
        public int $expectedAmount,
        public int $amountTolerance,
        public \DateTimeImmutable $lastOccurrence,
        public \DateTimeImmutable $nextExpected,
        public ?array $activeMonths,
        public array $linkedIds,
    ) {
    }
}
