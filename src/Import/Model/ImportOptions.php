<?php

declare(strict_types=1);

namespace App\Import\Model;

use App\Entity\Member;

/**
 * Options d'un import de relevé.
 */
final readonly class ImportOptions
{
    public function __construct(
        public bool $dryRun = false,
        public bool $autoCreateAccount = false,
        public ?Member $importedBy = null,
        public ?string $originalFilename = null,
        /** Seuil de similarité pg_trgm pour la détection de doublons probables. */
        public float $similarityThreshold = 0.45,
    ) {
    }
}
