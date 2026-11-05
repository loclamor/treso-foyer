<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Entity\Account;
use App\Entity\Household;
use App\Enum\AccountType;
use App\Import\Model\ParsedOperation;
use App\Import\OperationFingerprinter;
use PHPUnit\Framework\TestCase;

final class OperationFingerprinterTest extends TestCase
{
    private function makeAccount(): Account
    {
        return new Account(
            new Household('Foyer test'),
            'perso',
            'Compte perso',
            AccountType::Personal,
            hash_hmac('sha256', 'FIXTURE-COMPTE', 'test-pepper'),
            '****R999',
        );
    }

    public function testFingerprintStable(): void
    {
        $fingerprinter = new OperationFingerprinter();
        $account = $this->makeAccount();
        $date = new \DateTimeImmutable('2026-08-26');
        $fp1 = $fingerprinter->fingerprint($account, $date, -2756, 'ACHATCBSUPERMARCHEEXEMPLE', 0);
        $fp2 = $fingerprinter->fingerprint($account, $date, -2756, 'ACHATCBSUPERMARCHEEXEMPLE', 0);
        $fpOther = $fingerprinter->fingerprint($account, $date, -2756, 'ACHATCBSUPERMARCHEEXEMPLE', 1);

        self::assertSame($fp1, $fp2);
        self::assertSame(64, strlen($fp1));
        self::assertNotSame($fp1, $fpOther);
    }

    public function testAssignOccurrences(): void
    {
        $fingerprinter = new OperationFingerprinter();
        $date = new \DateTimeImmutable('2026-08-26');
        $ops = [
            new ParsedOperation($date, -1200, 'ACHAT CB BOULANGERIE CHEZ MICHELE 26.08.26 CARTE NUMERO 456'),
            new ParsedOperation($date, -1200, 'ACHAT CB BOULANGERIE CHEZ MICHELE 26.08.26 CARTE NUMERO 456'),
            new ParsedOperation($date, -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO 123'),
            new ParsedOperation($date, -1200, 'ACHAT CB BOULANGERIE CHEZ MICHELE 26.08.26 CARTE NUMERO 456'),
        ];
        $keys = array_map(static fn (ParsedOperation $op): string => (new \App\Import\LabelNormalizer())->key($op->rawLabel), $ops);
        $index = 0;
        $occurrences = $fingerprinter->assignOccurrences($ops, static function () use (&$index, $keys): string {
            return $keys[$index++];
        });

        // 2 achats identiques le même jour : rangs 0 et 1 ; le 3e identical : rang 2.
        self::assertSame([0, 1, 0, 2], $occurrences);
    }
}
