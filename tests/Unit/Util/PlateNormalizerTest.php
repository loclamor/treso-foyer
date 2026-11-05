<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\PlateNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlateNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sivProvider(): array
    {
        return [
            'déjà au format SIV' => ['AB-123-CD', 'AB-123-CD'],
            'sans séparateurs' => ['AB123CD', 'AB-123-CD'],
            'minuscules et espaces' => ['ab 123 cd', 'AB-123-CD'],
            'minuscules et tirets' => ['ab-123-cd', 'AB-123-CD'],
            'points' => ['AB.123.CD', 'AB-123-CD'],
        ];
    }

    #[DataProvider('sivProvider')]
    public function testNormalizeSiv(string $input, string $expected): void
    {
        self::assertSame($expected, PlateNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fniProvider(): array
    {
        return [
            'FNI sans séparateurs' => ['1234AB75', '1234AB75'],
            'FNI avec espaces' => ['123 AB 75', '123AB75'],
            'FNI court' => ['1A75', '1A75'],
            'FNI minuscule' => ['999 z 99', '999Z99'],
        ];
    }

    #[DataProvider('fniProvider')]
    public function testNormalizeFniKeepsNoSeparator(string $input, string $expected): void
    {
        self::assertSame($expected, PlateNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'chaîne quelconque' => ['XYZ'],
            'trop longue' => ['AA-123-AAA'],
            'que des chiffres' => ['123456'],
        ];
    }

    public function testNormalizeEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PlateNormalizer::normalize('');
    }

    #[DataProvider('invalidProvider')]
    public function testNormalizeInvalidThrowsWithoutLeakingPlate(string $input): void
    {
        try {
            PlateNormalizer::normalize($input);
            self::fail('Une InvalidArgumentException aurait dû être levée.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($input, $e->getMessage());
        }
    }

    public function testIsValid(): void
    {
        self::assertTrue(PlateNormalizer::isValid('AB-123-CD'));
        self::assertTrue(PlateNormalizer::isValid('1234AB75'));
        self::assertFalse(PlateNormalizer::isValid('plaque bidon'));
    }
}
