<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\Household;
use App\Entity\InternalTransfer;
use App\Entity\Operation;
use App\Enum\AccountType;
use App\Enum\TransferStatus;
use PHPUnit\Framework\TestCase;

final class InternalTransferTest extends TestCase
{
    private function makeAccount(Household $household, string $alias): Account
    {
        return new Account(
            $household,
            $alias,
            'Compte '.$alias,
            AccountType::Personal,
            hash('sha256', 'FIXTURE-'.$alias),
            '****'.substr($alias, -4),
        );
    }

    private function makeOperation(Account $account, int $amount): Operation
    {
        return new Operation(
            $account,
            new \DateTimeImmutable('2026-02-01'),
            $amount,
            'VIR INTERNE',
            'VIR INTERNE',
            hash('sha256', uniqid('fixture', true)),
        );
    }

    public function testBalancedTransfer(): void
    {
        $household = new Household('Foyer test');
        $perso = $this->makeAccount($household, 'perso');
        $commun = $this->makeAccount($household, 'commun');

        $transfer = new InternalTransfer(TransferStatus::Auto);
        $transfer->addOperation($this->makeOperation($perso, -30000));
        $transfer->addOperation($this->makeOperation($commun, 30000));

        self::assertTrue($transfer->isBalanced());
    }

    public function testUnbalancedWhenSameAccount(): void
    {
        $household = new Household('Foyer test');
        $perso = $this->makeAccount($household, 'perso');

        $transfer = new InternalTransfer();
        $transfer->addOperation($this->makeOperation($perso, -30000));
        $transfer->addOperation($this->makeOperation($perso, 30000));

        self::assertFalse($transfer->isBalanced());
    }

    public function testUnbalancedWhenAmountsDoNotSumToZero(): void
    {
        $household = new Household('Foyer test');
        $perso = $this->makeAccount($household, 'perso');
        $commun = $this->makeAccount($household, 'commun');

        $transfer = new InternalTransfer();
        $transfer->addOperation($this->makeOperation($perso, -30000));
        $transfer->addOperation($this->makeOperation($commun, 29000));

        self::assertFalse($transfer->isBalanced());
    }

    public function testUnbalancedWhenNotExactlyTwoOperations(): void
    {
        $household = new Household('Foyer test');
        $perso = $this->makeAccount($household, 'perso');
        $commun = $this->makeAccount($household, 'commun');

        $one = new InternalTransfer();
        $one->addOperation($this->makeOperation($perso, -30000));
        self::assertFalse($one->isBalanced());

        $three = new InternalTransfer();
        $three->addOperation($this->makeOperation($perso, -30000));
        $three->addOperation($this->makeOperation($commun, 30000));
        $three->addOperation($this->makeOperation($commun, 100));
        self::assertFalse($three->isBalanced());
    }
}
