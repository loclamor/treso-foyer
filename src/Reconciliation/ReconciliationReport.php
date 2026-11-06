<?php

declare(strict_types=1);

namespace App\Reconciliation;

/**
 * Compteurs du rapprochement des saisies manuelles avec les lignes de relevé
 * (voir ADR 0014, jamais de libellé affiché).
 */
final readonly class ReconciliationReport
{
    /**
     * @param array<int, int> $matchedLines id de la saisie absorbée => id de la ligne de relevé conservée
     */
    public function __construct(
        /** Saisies appariées automatiquement (paire univoque) et absorbées. */
        public int $matched = 0,
        /** Saisies avec au moins un candidat mais paire non univoque : restent en attente. */
        public int $ambiguous = 0,
        /** Saisies sans aucun candidat : restent en attente. */
        public int $pending = 0,
        public array $matchedLines = [],
    ) {
    }

    /** @return array{matched: int, ambiguous: int, pending: int} */
    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'ambiguous' => $this->ambiguous,
            'pending' => $this->pending,
        ];
    }
}
