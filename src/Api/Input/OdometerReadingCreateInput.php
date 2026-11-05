<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/vehicles/{id}/odometer. */
final class OdometerReadingCreateInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'date est obligatoire.')]
        #[Assert\Date(message: 'date doit être une date au format AAAA-MM-JJ.')]
        public readonly ?string $date = null,
        #[Assert\NotNull(message: 'km est obligatoire.')]
        #[Assert\PositiveOrZero(message: 'Le kilométrage doit être positif ou nul.')]
        public readonly ?int $km = null,
        #[Assert\Length(max: 255, maxMessage: 'note ne doit pas dépasser 255 caractères.')]
        public readonly ?string $note = null,
    ) {
    }
}
