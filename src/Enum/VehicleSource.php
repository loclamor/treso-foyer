<?php

declare(strict_types=1);

namespace App\Enum;

/** Origine de l'affectation d'une opération à un véhicule. */
enum VehicleSource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
    case Auto = 'auto';
    /** Véhicule par défaut d'une période labellisée couvrant la date de l'opération (ADR 0018). */
    case Period = 'period';
    /** Opération volontairement laissée sans véhicule : sort du compteur « à affecter », jamais resynchronisée. */
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle',
            self::Rule => 'Règle',
            self::Auto => 'Automatique',
            self::Period => 'Période',
            self::Ignored => 'Ignorée',
        };
    }
}
