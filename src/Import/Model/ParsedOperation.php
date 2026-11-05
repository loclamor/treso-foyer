<?php

declare(strict_types=1);

namespace App\Import\Model;

/**
 * Une ligne d'opération parsée depuis un relevé (CSV ou PDF).
 */
final readonly class ParsedOperation
{
    public function __construct(
        public \DateTimeImmutable $date,
        /** Montant en centimes, négatif = débit. */
        public int $amount,
        /** Libellé brut : espaces internes conservés, chaîne trimée. */
        public string $rawLabel,
    ) {
    }
}
