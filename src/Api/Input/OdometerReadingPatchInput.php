<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PATCH /api/odometer-readings/{id}. Champ absent (null) = inchangé ;
 * chaîne vide = remise à null pour note. Exception : note null explicite (clé présente
 * dans le corps) efface la note.
 */
final class OdometerReadingPatchInput
{
    public function __construct(
        #[Assert\Date(message: 'date doit être une date au format AAAA-MM-JJ.')]
        public readonly ?string $date = null,
        #[Assert\PositiveOrZero(message: 'Le kilométrage doit être positif ou nul.')]
        public readonly ?int $km = null,
        #[Assert\Length(max: 255, maxMessage: 'note ne doit pas dépasser 255 caractères.')]
        public readonly ?string $note = null,
    ) {
    }
}
