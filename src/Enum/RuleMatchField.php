<?php

declare(strict_types=1);

namespace App\Enum;

enum RuleMatchField: string
{
    case Merchant = 'merchant';
    case Label = 'label';

    public function label(): string
    {
        return match ($this) {
            self::Merchant => 'Commerçant',
            self::Label => 'Libellé',
        };
    }
}
