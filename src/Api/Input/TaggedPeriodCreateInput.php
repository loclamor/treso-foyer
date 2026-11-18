<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/tagged-periods. Les dates sont au format AAA-MM-JJ. */
final class TaggedPeriodCreateInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'type est obligatoire.')]
        #[Assert\Choice(choices: ['vacation', 'weekend', 'outing', 'other'], message: 'type doit être « vacation », « weekend », « outing » ou « other ».')]
        public readonly ?string $type = null,
        #[Assert\NotBlank(message: 'label est obligatoire.')]
        #[Assert\Length(max: 100, maxMessage: 'Le libellé ne doit pas dépasser {{ limit }} caractères.')]
        public readonly ?string $label = null,
        #[Assert\NotBlank(message: 'startDate est obligatoire.')]
        #[Assert\Date(message: 'startDate doit être une date AAA-MM-JJ.')]
        public readonly ?string $startDate = null,
        #[Assert\NotBlank(message: 'endDate est obligatoire.')]
        #[Assert\Date(message: 'endDate doit être une date AAA-MM-JJ.')]
        public readonly ?string $endDate = null,
        #[Assert\Positive(message: 'vehicleId doit être un identifiant positif.')]
        public readonly ?int $vehicleId = null,
        public readonly ?bool $vehicleOverridesRules = null,
    ) {
    }
}
