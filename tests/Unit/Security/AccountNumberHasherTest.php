<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\AccountNumberHasher;
use PHPUnit\Framework\TestCase;

final class AccountNumberHasherTest extends TestCase
{
    public function testNormalizeRemovesSpacesAndUppercases(): void
    {
        $hasher = new AccountNumberHasher('test-pepper');

        // Numéro FICTIF de test uniquement.
        self::assertSame('1234567R999', $hasher->normalize('12 345 67 r 999'));
    }

    public function testHashIsStableAndDependsOnNormalizedInputAndPepper(): void
    {
        $hasher = new AccountNumberHasher('test-pepper');
        $otherPepper = new AccountNumberHasher('autre-pepper');

        $hash = $hasher->hash('12 345 67 R 999');
        self::assertSame($hash, $hasher->hash('1234567R999'));
        self::assertSame(64, strlen($hash));
        self::assertNotSame($hash, $otherPepper->hash('12 345 67 R 999'));
        self::assertNotSame($hash, $hasher->hash('12 345 67 R 998'));
    }

    public function testMaskKeepsLastFourCharacters(): void
    {
        $hasher = new AccountNumberHasher('test-pepper');

        // Numéro FICTIF de test uniquement.
        self::assertSame('****R999', $hasher->mask('12 345 67 R 999'));
    }

    public function testEmptyPepperIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccountNumberHasher('');
    }
}
