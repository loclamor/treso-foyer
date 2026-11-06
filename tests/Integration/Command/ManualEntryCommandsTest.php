<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\EntriesReconcileCommand;
use App\Command\RebuildFingerprintsCommand;
use App\Command\SpentAtCommand;
use App\Entity\Account;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\ImportFormat;
use App\Import\LabelNormalizer;
use App\Import\Model\ImportOptions;
use App\Import\StatementImporter;
use App\Reconciliation\ManualEntryReconciler;
use App\Security\AccountNumberHasher;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Commandes liées aux saisies manuelles (ADR 0014) : `app:operations:spent-at` (recalcule
 * spent_at des opérations importées, jamais les saisies ni les lignes rapprochées),
 * `app:entries:reconcile` (rapprochement automatique), `app:operations:rebuild-fingerprints`
 * (ignore les saisies en attente).
 */
final class ManualEntryCommandsTest extends KernelTestCase
{
    private const FIXTURE_CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';

    private EntityManagerInterface $entityManager;
    private Household $household;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->household = new Household('Foyer test');
        $this->entityManager->persist($this->household);
        // Même empreinte HMAC que le n° 1234567R999 des fixtures CSV : l'import résout ce compte.
        $hasher = $container->get(AccountNumberHasher::class);
        $this->account = new Account(
            $this->household,
            'ccp-perso',
            'Compte '.$hasher->mask('1234567R999'),
            \App\Enum\AccountType::Personal,
            $hasher->hash('1234567R999'),
            $hasher->mask('1234567R999'),
        );
        $this->entityManager->persist($this->account);
        CategorizationFixtures::seedCategories($this->entityManager, $this->household);
        $this->entityManager->flush();
    }

    private function managedAccount(): Account
    {
        return $this->entityManager->find(Account::class, $this->account->getId()) ?? $this->account;
    }

    private function makeEntry(string $spentAt, int $amount, string $label): Operation
    {
        $normalized = self::getContainer()->get(LabelNormalizer::class)->normalize($label);
        $entry = Operation::manualEntry(
            $this->managedAccount(),
            new \DateTimeImmutable($spentAt),
            $amount,
            $label,
            $normalized->normalized,
            $normalized->merchant,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function makeStatementLine(string $date, int $amount, string $rawLabel): Operation
    {
        $line = CategorizationFixtures::makeOperation($this->managedAccount(), $date, $amount, $rawLabel, 'SUPERMARCHE');
        $line->setLabelSource(ImportFormat::Csv);
        $this->entityManager->persist($line);
        $this->entityManager->flush();

        return $line;
    }

    public function testSpentAtExtractionALImport(): void
    {
        $importer = self::getContainer()->get(StatementImporter::class);
        $importer->import(self::FIXTURE_CSV, new ImportOptions(autoCreateAccount: true));

        $this->entityManager->clear();
        // « ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 … » au 26/08 → spent_at = 2026-08-25 (écart 1 j ≤ 31).
        $line = $this->entityManager->getRepository(Operation::class)->findOneBy(['amount' => -2756]);
        self::assertNotNull($line);
        self::assertSame('2026-08-25', $line->getSpentAt()?->format('Y-m-d'));
        // Prélèvement sans date de carte : spent_at = date du relevé (backfill) ou null selon le libellé.
        $prelevement = $this->entityManager->getRepository(Operation::class)->findOneBy(['amount' => -8510]);
        self::assertNotNull($prelevement);
        self::assertNull($prelevement->getSpentAt());
    }

    public function testSpentAtCommandIdempotentEtProtections(): void
    {
        $importer = self::getContainer()->get(StatementImporter::class);
        $importer->import(self::FIXTURE_CSV, new ImportOptions(autoCreateAccount: true));

        // Saisie manuelle + ligne rapprochée : leurs spent_at ne doivent jamais être touchés.
        $entry = $this->makeEntry('2026-08-20', -999, 'ACHAT CB SUPERMARCHE 20.08.26');
        $entryId = (int) $entry->getId();
        $line = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 25.08.26');
        $lineId = (int) $line->getId();
        self::getContainer()->get(ManualEntryReconciler::class)->merge($entry, $line);

        // On remet à null le spent_at d'une ligne importée pour forcer une modification.
        $this->entityManager->getConnection()->executeStatement('UPDATE operation SET spent_at = NULL WHERE amount = -1200');
        $this->entityManager->clear();

        $command = self::getContainer()->get(SpentAtCommand::class);
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $exit);
        // Les 2 lignes « BOULANGERIE » à -1200 (occurrences) sont à recalculer.
        self::assertStringContainsString('2 modifiée(s) (dry-run', $tester->getDisplay());
        // Dry-run : rien n'est écrit.
        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Operation::class)->findOneBy(['amount' => -1200])?->getSpentAt());

        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Operation::class)->findBy(['amount' => -1200]) as $fixed) {
            self::assertSame('2026-08-26', $fixed->getSpentAt()?->format('Y-m-d'));
        }
        // Ligne rapprochée : spent_at conservé (celui de la saisie, pas recalculé depuis le libellé).
        $reconciled = $this->entityManager->find(Operation::class, $lineId);
        self::assertSame('2026-08-20', $reconciled?->getSpentAt()?->format('Y-m-d'));
        self::assertNotNull($reconciled?->getReconciledAt());
        // Saisie supprimée par le merge ; recrée-en une pour vérifier la protection awaiting_statement.
        $entry2 = $this->makeEntry('2026-08-21', -777, 'ACHAT CB MAGASIN 21.08.26');
        $entry2Id = (int) $entry2->getId();
        $tester->execute([]);
        $this->entityManager->clear();
        $kept = $this->entityManager->find(Operation::class, $entry2Id);
        self::assertSame('2026-08-21', $kept?->getSpentAt()?->format('Y-m-d'));
        // La première saisie a été absorbée par le merge : supprimée.
        self::assertNull($this->entityManager->find(Operation::class, $entryId));
    }

    public function testEntriesReconcileCommand(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $entryId = (int) $entry->getId();
        $line = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $lineId = (int) $line->getId();

        $command = self::getContainer()->get(EntriesReconcileCommand::class);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Saisies rapprochées : 1', $tester->getDisplay());
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Operation::class, $entryId));

        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $entryId));
        self::assertNotNull($this->entityManager->find(Operation::class, $lineId)?->getReconciledAt());
    }

    public function testRebuildFingerprintsIgnoreLesSaisiesEnAttente(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $entryId = (int) $entry->getId();
        $fingerprint = $entry->getFingerprint();
        $this->makeStatementLine('2026-08-26', -1200, 'ACHAT CB BOULANGERIE 26.08.26');

        $command = self::getContainer()->get(RebuildFingerprintsCommand::class);
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exit);

        $this->entityManager->clear();
        $kept = $this->entityManager->find(Operation::class, $entryId);
        // L'empreinte « manual:… » de la saisie en attente est conservée telle quelle.
        self::assertSame($fingerprint, $kept?->getFingerprint());
        self::assertTrue($kept?->isAwaitingStatement());
    }
}
