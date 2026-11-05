<?php

declare(strict_types=1);

namespace App\Import\Model;

use App\Enum\AccountType;
use App\Enum\ImportFormat;

/**
 * Relevé parsé : en-tête (compte, soldes) + opérations en ordre chronologique croissant
 * (l'ordre relatif des opérations de même date est conservé, inversé de façon stable
 * par rapport au fichier qui les liste de la plus récente à la plus ancienne).
 */
final readonly class ParsedStatement
{
    /**
     * @param list<ParsedOperation> $operations ordre chronologique croissant
     * @param list<ParsedStatement> $related    autres comptes du même document (livrets d'un relevé
     *                                          La Banque Postale), importés chacun sur leur compte
     * @param ?string               $accountLabel libellé du compte s'il est créé (ex. « Livret A »)
     * @param ?AccountType          $accountType  type du compte s'il est créé (Personnel par défaut)
     */
    public function __construct(
        public string $accountNumber,
        public ImportFormat $format,
        public ?\DateTimeImmutable $openingDate,
        public ?int $openingBalance,
        public ?\DateTimeImmutable $closingDate,
        public ?int $closingBalance,
        public array $operations,
        public array $related = [],
        public ?string $accountLabel = null,
        public ?AccountType $accountType = null,
    ) {
    }
}
