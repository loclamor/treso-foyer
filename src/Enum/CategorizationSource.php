<?php

declare(strict_types=1);

namespace App\Enum;

enum CategorizationSource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
    case Similarity = 'similarity';
    case Llm = 'llm';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle',
            self::Rule => 'Règle',
            self::Similarity => 'Similarité',
            self::Llm => 'LLM',
            self::Transfer => 'Virement interne détecté',
        };
    }
}
