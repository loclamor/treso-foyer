<?php

declare(strict_types=1);

namespace App\Import\Model;

/**
 * Rapport d'import d'un fichier. Ne contient jamais de numéro de compte en clair
 * ni de libellé d'opération.
 */
final readonly class ImportReport
{
    /**
     * @param string $status imported | already_imported | dry_run | failed
     */
    public function __construct(
        public string $filename,
        public string $status,
        public ?string $accountAlias,
        public string $accountMasked,
        public ?\DateTimeImmutable $periodStart,
        public ?\DateTimeImmutable $periodEnd,
        public int $added,
        public int $skipped,
        public int $probableDuplicates,
        public ?string $error = null,
        public int $enriched = 0,
    ) {
    }
}
