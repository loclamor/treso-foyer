<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Entity\Account;
use App\Entity\Household;
use App\Entity\ImportBatch;
use App\Entity\Operation;
use App\Entity\Statement;
use App\Enum\DuplicateStatus;
use App\Enum\ImportStatus;
use App\Import\Exception\UnknownAccountException;
use App\Import\DuplicateMerger;
use App\Import\Model\ImportOptions;
use App\Import\StatementImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatementImporterTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/csv';

    private EntityManagerInterface $entityManager;
    private StatementImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->importer = $container->get(StatementImporter::class);
        if (null === $this->entityManager->getRepository(Household::class)->findOneBy([])) {
            $this->entityManager->persist(new Household('Foyer test'));
            $this->entityManager->flush();
        }
    }

    private function importA(bool $autoCreate = true): \App\Import\Model\ImportReport
    {
        return $this->importer->import(self::FIXTURES.'/releve-a.csv', new ImportOptions(autoCreateAccount: $autoCreate));
    }

    public function testCompteInconnuSansAutoCreation(): void
    {
        $path = self::FIXTURES.'/releve-compte-inconnu.csv';
        try {
            $this->importer->import($path, new ImportOptions(autoCreateAccount: false));
            self::fail('UnknownAccountException attendue.');
        } catch (UnknownAccountException $exception) {
            // Le message ne contient que le masque, jamais le numéro en clair.
            self::assertStringContainsString('****', $exception->getMessage());
            self::assertStringNotContainsString('9999999X000', $exception->getMessage());
        }

        // Un lot « failed » est enregistré avec le message d'erreur.
        $this->entityManager->clear();
        $sha256 = hash('sha256', (string) file_get_contents($path));
        $batch = $this->entityManager->getRepository(ImportBatch::class)->findOneBy(['sha256' => $sha256]);
        self::assertNotNull($batch);
        self::assertSame(ImportStatus::Failed, $batch->getStatus());
        self::assertNotNull($batch->getErrorMessage());
        self::assertStringNotContainsString('9999999X000', $batch->getErrorMessage());
        self::assertNull($batch->getAccount());
    }

    public function testDryRunKeepsThePreviousFailedBatch(): void
    {
        $path = self::FIXTURES.'/releve-compte-inconnu.csv';
        $sha256 = hash('sha256', (string) file_get_contents($path));
        $batches = fn (): array => $this->entityManager->getRepository(ImportBatch::class)->findBy(['sha256' => $sha256]);
        try {
            $this->importer->import($path, new ImportOptions(autoCreateAccount: false, originalFilename: 'mon-releve.csv'));
            self::fail('UnknownAccountException attendue.');
        } catch (UnknownAccountException) {
        }

        // Le dry-run ne doit pas effacer le lot en échec existant.
        $report = $this->importer->import($path, new ImportOptions(dryRun: true, autoCreateAccount: true));
        self::assertSame('dry_run', $report->status);
        $this->entityManager->clear();
        self::assertCount(1, $batches());
        self::assertSame(ImportStatus::Failed, $batches()[0]->getStatus());
        // Nom d'origine du fichier, pas « <sha256>.csv ».
        self::assertSame('mon-releve.csv', $batches()[0]->getOriginalFilename());

        // Nouvel échec : un seul lot en échec, remplacé.
        try {
            $this->importer->import($path, new ImportOptions(autoCreateAccount: false));
        } catch (UnknownAccountException) {
        }
        $this->entityManager->clear();
        self::assertCount(1, $batches());

        // Import réel ensuite : le lot en échec laisse la place au lot terminé.
        self::assertSame('imported', $this->importer->import($path, new ImportOptions(autoCreateAccount: true))->status);
        $this->entityManager->clear();
        self::assertCount(1, $batches());
        self::assertSame(ImportStatus::Completed, $batches()[0]->getStatus());
    }

    public function testImportAvecAutoCreationCompte(): void
    {
        $report = $this->importA();

        self::assertSame('imported', $report->status);
        self::assertSame(7, $report->added);
        self::assertSame(0, $report->skipped);
        self::assertSame('ccp-perso', $report->accountAlias);
        self::assertSame('****R999', $report->accountMasked);
        self::assertSame('2026-08-24', $report->periodStart?->format('Y-m-d'));
        self::assertSame('2026-08-26', $report->periodEnd?->format('Y-m-d'));

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        self::assertNotNull($account);
        self::assertSame('****R999', $account->getNumberMasked());
        self::assertSame('Compte ****R999', $account->getLabel());

        $statement = $this->entityManager->getRepository(Statement::class)->findOneBy(['account' => $account]);
        self::assertNotNull($statement);
        self::assertSame('2026-08-27', $statement->getClosingDate()->format('Y-m-d'));
        self::assertSame(-7391, $statement->getClosingBalance());

        self::assertCount(7, $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account]));
    }

    public function testReimportDuMemeFichier(): void
    {
        $this->importA();
        $report = $this->importA();

        self::assertSame('already_imported', $report->status);
        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        self::assertCount(7, $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account]));
        self::assertCount(1, $this->entityManager->getRepository(ImportBatch::class)->findAll());
    }

    public function testFichierChevauchant(): void
    {
        $this->importA();
        $report = $this->importer->import(self::FIXTURES.'/releve-b.csv', new ImportOptions(autoCreateAccount: true));

        self::assertSame('imported', $report->status);
        self::assertSame(1, $report->added);
        self::assertSame(2, $report->skipped);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        self::assertCount(8, $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account]));
    }

    public function testOccurrencesMultiples(): void
    {
        $this->importA();
        // releve-a.csv contient 2x la même boulangerie : 2 opérations distinctes en base.
        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        $boulangerie = array_filter(
            $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account]),
            static fn (Operation $op): bool => str_contains($op->getRawLabel(), 'BOULANGERIE'),
        );
        self::assertCount(2, $boulangerie);
        self::assertSame([0, 1], array_map(static fn (Operation $op): int => $op->getOccurrenceIndex(), array_values($boulangerie)));

        // releve-c.csv contient 3x cette opération : seule la 3e (rang 2) est ajoutée.
        $report = $this->importer->import(self::FIXTURES.'/releve-c.csv', new ImportOptions(autoCreateAccount: true));
        self::assertSame(1, $report->added);
        self::assertSame(2, $report->skipped);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        $boulangerie = array_filter(
            $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account]),
            static fn (Operation $op): bool => str_contains($op->getRawLabel(), 'BOULANGERIE'),
        );
        self::assertCount(3, $boulangerie);
    }

    public function testDoublonProbableFlou(): void
    {
        $this->importA();
        $report = $this->importer->import(self::FIXTURES.'/releve-d.csv', new ImportOptions(autoCreateAccount: true));

        self::assertSame('imported', $report->status);
        self::assertSame(1, $report->added);
        self::assertSame(1, $report->probableDuplicates);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        $operations = $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account]);
        $probable = array_filter($operations, static fn (Operation $op): bool => DuplicateStatus::Probable === $op->getDuplicateStatus());
        self::assertCount(1, $probable);
        $op = array_values($probable)[0];
        self::assertSame('2026-08-27', $op->getDate()->format('Y-m-d'));
        self::assertSame(-2756, $op->getAmount());
        self::assertNotNull($op->getDuplicateOf());
        self::assertSame('2026-08-26', $op->getDuplicateOf()->getDate()->format('Y-m-d'));
    }

    public function testMergedDuplicateIsNotRecreatedByALaterExport(): void
    {
        $this->importA();
        $this->importer->import(self::FIXTURES.'/releve-d.csv', new ImportOptions(autoCreateAccount: true));
        $probable = $this->entityManager->getRepository(Operation::class)->findOneBy(['duplicateStatus' => DuplicateStatus::Probable]);
        self::assertNotNull($probable);
        $kept = self::getContainer()->get(DuplicateMerger::class)->merge($probable);
        self::assertNotNull($kept);
        $countAfterMerge = \count($this->entityManager->getRepository(Operation::class)->findAll());

        // Export suivant (autre fichier) contenant encore la ligne fusionnée.
        $report = $this->importer->import(self::FIXTURES.'/releve-d-reexport.csv', new ImportOptions(autoCreateAccount: true));

        self::assertSame('imported', $report->status);
        self::assertSame(0, $report->added);
        self::assertSame(0, $report->probableDuplicates);
        $this->entityManager->clear();
        self::assertCount($countAfterMerge, $this->entityManager->getRepository(Operation::class)->findAll());
        self::assertNull($this->entityManager->getRepository(Operation::class)->findOneBy(['duplicateStatus' => DuplicateStatus::Probable]));
    }

    public function testDryRunNAucuneEcriture(): void
    {
        $report = $this->importer->import(self::FIXTURES.'/releve-a.csv', new ImportOptions(dryRun: true, autoCreateAccount: true));

        self::assertSame('dry_run', $report->status);
        self::assertSame(7, $report->added);
        self::assertSame('ccp-perso', $report->accountAlias);

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(Account::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(ImportBatch::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(Operation::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(Statement::class)->findAll());
    }

    public function testImportReelApresDryRun(): void
    {
        // Régression : le rollback du dry-run fermait l'EntityManager et faisait échouer l'import suivant.
        $this->importer->import(self::FIXTURES.'/releve-a.csv', new ImportOptions(dryRun: true, autoCreateAccount: true));
        $report = $this->importer->import(self::FIXTURES.'/releve-a.csv', new ImportOptions(autoCreateAccount: true));

        self::assertSame('imported', $report->status);
        self::assertSame(7, $report->added);

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entityManager->clear();
        self::assertCount(7, $entityManager->getRepository(Operation::class)->findAll());
    }

    public function testEchecPuisImportSuivant(): void
    {
        try {
            $this->importer->import(self::FIXTURES.'/releve-invalide.csv', new ImportOptions(autoCreateAccount: true));
            self::fail('Une ParseException était attendue.');
        } catch (\App\Import\Exception\ParseException) {
        }

        $report = $this->importer->import(self::FIXTURES.'/releve-a.csv', new ImportOptions(autoCreateAccount: true));
        self::assertSame('imported', $report->status);
    }
}
