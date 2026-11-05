<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/rules (les champs non fournis prennent les valeurs par défaut de la règle). */
final class RuleCreateInput
{
    public function __construct(
        #[Assert\NotNull(message: 'categoryId est obligatoire.')]
        #[Assert\Positive(message: 'categoryId doit être un identifiant positif.')]
        public readonly ?int $categoryId = null,
        #[Assert\NotNull(message: 'matchField est obligatoire.')]
        #[Assert\Choice(choices: ['merchant', 'label'], message: 'matchField doit être « merchant » ou « label ».')]
        public readonly ?string $matchField = null,
        #[Assert\NotNull(message: 'matchType est obligatoire.')]
        #[Assert\Choice(choices: ['contains', 'starts_with', 'exact', 'regex'], message: 'matchType invalide.')]
        public readonly ?string $matchType = null,
        #[Assert\NotBlank(message: 'pattern est obligatoire.')]
        #[Assert\Length(max: 255, maxMessage: 'pattern ne doit pas dépasser 255 caractères.')]
        public readonly ?string $pattern = null,
        #[Assert\Choice(choices: ['any', 'debit', 'credit'], message: 'direction doit être « any », « debit » ou « credit ».')]
        public readonly string $direction = 'any',
        #[Assert\PositiveOrNull(message: 'amountMin doit être positif (centimes).')]
        public readonly ?int $amountMin = null,
        #[Assert\PositiveOrNull(message: 'amountMax doit être positif (centimes).')]
        public readonly ?int $amountMax = null,
        #[Assert\Positive(message: 'accountId doit être un identifiant positif.')]
        public readonly ?int $accountId = null,
        #[Assert\Type(type: 'int', message: 'priority doit être un entier.')]
        public readonly ?int $priority = null,
        #[Assert\Type(type: 'bool', message: 'enabled doit être un booléen.')]
        public readonly ?bool $enabled = null,
    ) {
    }
}
