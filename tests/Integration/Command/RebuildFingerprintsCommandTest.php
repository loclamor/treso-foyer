<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\RebuildFingerprintsCommand;
use App\Entity\Account;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\AccountType;
use App\Import\LabelNormalizer;
use App\Import\OperationFingerprinter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:operations:rebuild-fingerprints` : recalcule fingerprint et occurrenceIndex depuis rawLabel
 * (rang par id croissant au sein de (compte, date, montant, clé)), idempotent, --renormalize en option.
 */
final class RebuildFingerprintsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        if (null === $this->entityManager->getRepository(Household::class)->findOneBy([])) {
            $this->entityManager->persist(new Household('Foyer test'));
            $this->entityManager->flush();
        }
    }

    private function createAccount(): Account
    {
        $household = $this->entityManager->getRepository(Household::class)->findOneBy([]);
        \assert($household instanceof Household);
        $account = new Account(
            $household,
            'perso',
            'Compte perso',
            AccountType::Personal,
            hash_hmac('sha256', '1234567R999', 'test-pepper'),
            '****R999',
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function createOperation(Account $account, string $date, int $amount, string $rawLabel, string $fingerprint, int $occurrenceIndex): Operation
    {
        $operation = new Operation($account, new \DateTimeImmutable($date), $amount, $rawLabel, 'ANCIEN LIBELLE NORMALISE', $fingerprint);
        $operation->setOccurrenceIndex($occurrenceIndex);
        $this->entityManager->persist($operation);

        return $operation;
    }

    public function testRebuildFingerprintsEtRangs(): void
    {
        $account = $this->createAccount();
        $label = 'ACHAT CB BOULANGERIE CHEZ MICHELE 26.08.26 CARTE NUMERO 456';
        // Empreintes arbitraires (ancienne clé 40) et rangs faux : tout doit être recalculé.
        $this->createOperation($account, '2026-08-26', -1200, $label, 'old-fingerprint-1', 7);
        $this->createOperation($account, '2026-08-26', -1200, $label, 'old-fingerprint-2', 7);
        $this->createOperation($account, '2026-08-27', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO 123', 'old-fingerprint-3', 0);
        $this->entityManager->flush();

        $command = self::getContainer()->get(RebuildFingerprintsCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('3 opération(s) analysée(s), 3 modifiée(s)', $tester->getDisplay());

        $normalizer = new LabelNormalizer();
        $fingerprinter = new OperationFingerprinter();
        $key = $normalizer->key($label);

        $this->entityManager->clear();
        $operations = $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account], ['id' => 'ASC']);
        self::assertCount(3, $operations);
        // Rangs par id croissant au sein de (compte, date, montant, clé).
        self::assertSame([0, 1, 0], array_map(static fn (Operation $op): int => $op->getOccurrenceIndex(), $operations));
        self::assertSame(
            $fingerprinter->fingerprint($account, $operations[0]->getDate(), -1200, $key, 0),
            $operations[0]->getFingerprint(),
        );
        self::assertSame(
            $fingerprinter->fingerprint($account, $operations[1]->getDate(), -1200, $key, 1),
            $operations[1]->getFingerprint(),
        );
        self::assertSame(
            $fingerprinter->fingerprint($account, $operations[2]->getDate(), -2756, $normalizer->key($operations[2]->getRawLabel()), 0),
            $operations[2]->getFingerprint(),
        );
        // Sans --renormalize, le libellé normalisé n'est pas touché.
        self::assertSame('ANCIEN LIBELLE NORMALISE', $operations[0]->getNormalizedLabel());

        // Idempotent : second passage, 0 modifiée, mêmes empreintes.
        $tester2 = new CommandTester($command);
        $tester2->execute([]);
        self::assertStringContainsString('0 modifiée(s)', $tester2->getDisplay());
        $this->entityManager->clear();
        $again = $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account], ['id' => 'ASC']);
        self::assertSame($operations[0]->getFingerprint(), $again[0]->getFingerprint());
        self::assertSame($operations[1]->getFingerprint(), $again[1]->getFingerprint());
    }

    public function testRenormalizeRecalculeAussiLibelleEtCommercant(): void
    {
        $account = $this->createAccount();
        $label = 'ACHAT CB BOULANGERIE CHEZ MICHELE 26.08.26 CARTE NUMERO 456';
        $this->createOperation($account, '2026-08-26', -1200, $label, 'old-fingerprint-1', 0);
        $this->entityManager->flush();

        $command = self::getContainer()->get(RebuildFingerprintsCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--renormalize' => true]);

        $expected = (new LabelNormalizer())->normalize($label);
        $this->entityManager->clear();
        $operation = $this->entityManager->getRepository(Operation::class)->findOneBy(['account' => $account]);
        self::assertNotNull($operation);
        self::assertSame($expected->normalized, $operation->getNormalizedLabel());
        self::assertSame($expected->merchant, $operation->getMerchant());
        self::assertSame($expected->key, substr((new LabelNormalizer())->key($label), 0, LabelNormalizer::KEY_LENGTH));
    }

    public function testDryRunNeRienEcrit(): void
    {
        $account = $this->createAccount();
        $this->createOperation($account, '2026-08-26', -1200, 'ACHAT CB BOULANGERIE 26.08.26 CARTE NUMERO 456', 'old-fingerprint-1', 3);
        $this->entityManager->flush();

        $command = self::getContainer()->get(RebuildFingerprintsCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 modifiée(s)', $tester->getDisplay());
        self::assertStringContainsString('dry-run', $tester->getDisplay());

        $this->entityManager->clear();
        $operation = $this->entityManager->getRepository(Operation::class)->findOneBy(['account' => $account]);
        self::assertSame('old-fingerprint-1', $operation?->getFingerprint());
        self::assertSame(3, $operation?->getOccurrenceIndex());
    }
}
