<?php

declare(strict_types=1);

namespace App\Enum;

enum VehicleEnergy: string
{
    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Electric = 'electric';
    case Hybrid = 'hybrid';
    case PluginHybrid = 'plugin_hybrid';
    case Lpg = 'lpg';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Essence',
            self::Diesel => 'Diesel',
            self::Electric => 'Électrique',
            self::Hybrid => 'Hybride',
            self::PluginHybrid => 'Hybride rechargeable',
            self::Lpg => 'GPL',
            self::Other => 'Autre',
        };
    }
}
