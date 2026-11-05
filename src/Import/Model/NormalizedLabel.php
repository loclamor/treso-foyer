<?php

declare(strict_types=1);

namespace App\Import\Model;

/**
 * Libellé normalisé : texte replié, commerçant extrait, clé de dédoublonnage inter-formats.
 */
final readonly class NormalizedLabel
{
    public function __construct(
        public string $normalized,
        public ?string $merchant,
        /** Clé stable inter-formats : premiers caractères alphanumériques du libellé replié (LabelNormalizer::KEY_LENGTH). */
        public string $key,
    ) {
    }
}
