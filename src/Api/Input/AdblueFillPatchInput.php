<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/adblue-fills/{id} (ADR 0016) : absent = inchangé ; odometerKm, operationId, canPrice/canVolumeMl, note : clé présente (même null) = appliquée. */
final class AdblueFillPatchInput
{
    public function __construct(
        #[Assert\Date(message: 'date doit être une date au format AAAA-MM-JJ.')]
        public readonly ?string $date = null,
        #[Assert\Positive(message: 'Le volume doit être strictement positif.')]
        public readonly ?int $volumeMl = null,
        #[Assert\Choice(choices: ['station', 'can'], message: 'source doit être « station » ou « can ».')]
        public readonly ?string $source = null,
        #[Assert\PositiveOrZero(message: 'Le kilométrage doit être positif ou nul.')]
        public readonly ?int $odometerKm = null,
        /** Station uniquement : opération (débit) du paiement. */
        #[Assert\Positive(message: 'operationId doit être un identifiant.')]
        public readonly ?int $operationId = null,
        /** Bidon uniquement : prix du bidon en centimes, avec canVolumeMl. */
        #[Assert\Positive(message: 'Le prix du bidon doit être strictement positif.')]
        public readonly ?int $canPrice = null,
        #[Assert\Positive(message: 'La contenance du bidon doit être strictement positive.')]
        public readonly ?int $canVolumeMl = null,
        #[Assert\Length(max: 255, maxMessage: 'note ne doit pas dépasser 255 caractères.')]
        public readonly ?string $note = null,
    ) {
    }
}
