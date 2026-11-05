<?php

declare(strict_types=1);

namespace App\Enum;

enum RuleMatchType: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Exact = 'exact';
    case Regex = 'regex';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'Contient',
            self::StartsWith => 'Commence par',
            self::Exact => 'Égal à',
            self::Regex => 'Expression régulière',
        };
    }
}
