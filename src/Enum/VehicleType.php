<?php

declare(strict_types=1);

namespace App\Enum;

enum VehicleType: string
{
    case Car = 'car';
    case Motorcycle = 'motorcycle';
    case Bike = 'bike';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Car => 'Voiture',
            self::Motorcycle => 'Moto / scooter',
            self::Bike => 'Vélo',
            self::Other => 'Autre',
        };
    }
}
