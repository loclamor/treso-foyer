<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/operations/bulk-categorize (manuel, sans règle). */
final class BulkCategorizeInput
{
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'ids ne doit pas être vide.')]
        #[Assert\Type(type: 'array', message: 'ids doit être un tableau.')]
        #[Assert\All([new Assert\Positive(message: 'Chaque id doit être positif.')])]
        public readonly array $ids = [],
        #[Assert\NotNull(message: 'categoryId est obligatoire.')]
        #[Assert\Positive(message: 'categoryId doit être un identifiant positif.')]
        public readonly ?int $categoryId = null,
    ) {
    }
}
