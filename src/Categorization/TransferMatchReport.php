<?php

declare(strict_types=1);

namespace App\Categorization;

/**
 * Compteurs de l'appariement des virements internes (jamais de libellé affiché).
 */
final readonly class TransferMatchReport
{
    public function __construct(
        /** Paires appariées (InternalTransfer créé). */
        public int $matched = 0,
        /** Opérations avec plusieurs candidats : non appariées. */
        public int $ambiguous = 0,
    ) {
    }
}
