<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Clock\ClockInterface;

/**
 * Horloge de test : freeze('2026-11-05') fige « maintenant » (état statique car le noyau
 * est reboote entre les requêtes, comme FakeVehicleLookup) ; reset() restaure le temps réel.
 * Liée à ClockInterface dans config/services_test.yaml.
 */
final class TestClock implements ClockInterface
{
    private static ?\DateTimeImmutable $frozen = null;

    public static function freeze(string $now): void
    {
        self::$frozen = new \DateTimeImmutable($now);
    }

    public static function reset(): void
    {
        self::$frozen = null;
    }

    public function now(): \DateTimeImmutable
    {
        return self::$frozen ?? new \DateTimeImmutable();
    }

    public function sleep(float|int $seconds): void
    {
        // Le temps ne s'écoule pas en test.
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this; // Le fuseau horaire n'a pas d'importance en test.
    }
}
