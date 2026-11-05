<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Entity\Category;
use App\Entity\Household;
use App\Entity\ImportBatch;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\CategoryKind;
use App\Enum\ImportFormat;
use App\Import\AccountResolver;
use App\Import\LabelNormalizer;
use App\Import\Model\ImportOptions;
use App\Import\OperationFingerprinter;
use App\Import\Parser\CsvStatementParser;
use App\Import\StatementImporter;
use App\Tests\Support\FakePdfStatementParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Consolidation : une opération importée depuis un CSV voit son libellé remplacé par celui du PDF
 * (source plus complète), sans perdre son identité ni sa catégorie ; l'inverse n'arrive jamais.
 */
final class LabelEnrichmentTest extends KernelTestCase
{
    private const CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';
    private const PDF = __DIR__.'/../../Fixtures/pdf/releve-a-detaille.fakepdf';

    private EntityManagerInterface $entityManager;
    private StatementImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $csvParser = $container->get(CsvStatementParser::class);
        $this->importer = new StatementImporter(
            $container->get('doctrine'),
            [new FakePdfStatementParser($csvParser), $csvParser],
            $container->get(AccountResolver::class),
            $container->get(LabelNormalizer::class),
            $container->get(OperationFingerprinter::class),
        );
        if (null === $this->entityManager->getRepository(Household::class)->findOneBy([])) {
            $this->entityManager->persist(new Household('Foyer test'));
            $this->entityManager->flush();
        }
    }

    public function testPdfApresCsvEnrichitLeLibelleEtConserveLaCategorie(): void
    {
        $this->importer->import(self::CSV, new ImportOptions(autoCreateAccount: true));

        $salary = $this->findByLabelPrefix('VIREMENT DE EMPLOYEUR');
        self::assertSame(ImportFormat::Csv, $salary->getLabelSource());
        $household = $this->entityManager->getRepository(Household::class)->findOneBy([]);
        $category = new Category($household, 'Salaires test', CategoryKind::Income);
        $this->entityManager->persist($category);
        $salary->categorize($category, CategorizationSource::Manual);
        $salary->setNote('note perso');
        $this->entityManager->flush();
        $salaryId = $salary->getId();
        $fingerprint = $salary->getFingerprint();

        $report = $this->importer->import(self::PDF, new ImportOptions());

        self::assertSame('imported', $report->status);
        self::assertSame(0, $report->added);
        self::assertSame(7, $report->enriched);
        self::assertSame(0, $report->skipped);

        $this->entityManager->clear();
        $salary = $this->entityManager->find(Operation::class, $salaryId);
        self::assertStringContainsString('MOTIF : PAIE AOUT 2026', $salary->getRawLabel());
        self::assertSame(ImportFormat::Pdf, $salary->getLabelSource());
        self::assertNotNull($salary->getEnrichedAt());
        self::assertSame($fingerprint, $salary->getFingerprint());
        self::assertSame('Salaires test', $salary->getCategory()?->getName());
        self::assertSame(CategorizationSource::Manual, $salary->getCategorizationSource());
        self::assertSame('note perso', $salary->getNote());
        self::assertSame(ImportFormat::Pdf, $salary->getImportBatch()?->getFormat());
        self::assertCount(7, $this->entityManager->getRepository(Operation::class)->findAll());

        $pdfBatch = $this->entityManager->getRepository(ImportBatch::class)->findOneBy(['format' => ImportFormat::Pdf]);
        self::assertSame(7, $pdfBatch?->getEnrichedCount());
    }

    public function testCsvApresPdfNEcrasePasLeLibelle(): void
    {
        $this->importer->import(self::PDF, new ImportOptions(autoCreateAccount: true));
        $report = $this->importer->import(self::CSV, new ImportOptions());

        self::assertSame(0, $report->added);
        self::assertSame(0, $report->enriched);
        self::assertSame(7, $report->skipped);

        $this->entityManager->clear();
        $salary = $this->findByLabelPrefix('VIREMENT DE EMPLOYEUR');
        self::assertStringContainsString('MOTIF : PAIE AOUT 2026', $salary->getRawLabel());
        self::assertSame(ImportFormat::Pdf, $salary->getLabelSource());
        self::assertNull($salary->getEnrichedAt());
    }

    private function findByLabelPrefix(string $prefix): Operation
    {
        foreach ($this->entityManager->getRepository(Operation::class)->findAll() as $operation) {
            if (str_starts_with($operation->getRawLabel(), $prefix)) {
                return $operation;
            }
        }
        self::fail('Opération introuvable : '.$prefix);
    }
}
