<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Entity\Household;
use App\Entity\ImportBatch;
use App\Entity\Operation;
use App\Entity\Statement;
use App\Enum\ImportFormat;
use App\Enum\ImportStatus;
use App\Import\AccountResolver;
use App\Import\Exception\NotAnAccountStatementException;
use App\Import\Exception\ParseException;
use App\Import\LabelNormalizer;
use App\Import\Model\ImportOptions;
use App\Import\OperationFingerprinter;
use App\Import\Parser\CsvStatementParser;
use App\Import\Parser\PdfStatementParser;
use App\Import\StatementImporter;
use App\Tests\Support\FakePdfTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Import d'un « PDF » via le vrai parseur PDF : le texte vient d'un extracteur de test
 * (fixtures de tests/Fixtures/pdf-text/), le fichier temporaire ne contient que l'en-tête %PDF.
 */
final class PdfStatementImportTest extends KernelTestCase
{
    private const PDF_TEXT = __DIR__.'/../../Fixtures/pdf-text';
    private const CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';

    /**
     * Relevé PDF fictif dont l'opération a la même empreinte que le salaire de releve-a.csv,
     * avec un libellé plus complet (motif) : teste la consolidation PDF > CSV avec le vrai parseur.
     */
    private const PDF_ENRICHI = <<<'TEXT'
  Situation de votre CCP n° 12 345 67 R 999
   IBAN : FR00 2004 1000 0112 3456 7R99 999 | BIC : PSSTFRPPXXX

  Vos opérations
   Date  Opération  Débit (¤)  Crédit (¤)

   Ancien solde au 20/08/2026   + 0,00 ¤
   24/08  VIREMENT DE EMPLOYEUR FICTIF SA SALAIRE AOUT REFERENCE : 0000000000000000                    2 350,00
          MOTIF : PAIE AOUT 2026

                                                                Total des opérations   0,00           2 350,00
                                                   Nouveau solde au 27/08/2026                    + 2 350,00 ¤
TEXT;

    private EntityManagerInterface $entityManager;

    /** @var list<string> fichiers temporaires supprimés en tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        if (null === $this->entityManager->getRepository(Household::class)->findOneBy([])) {
            $this->entityManager->persist(new Household('Foyer test'));
            $this->entityManager->flush();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * Importeur construit à la main : vrai parseur PDF + extracteur de test, vrai parseur CSV.
     *
     * @param array<string, string> $textsByBasename contenu texte des « PDF » par nom de fichier
     */
    private function makeImporter(array $textsByBasename): StatementImporter
    {
        $container = self::getContainer();
        $csvParser = $container->get(CsvStatementParser::class);
        $pdfParser = new PdfStatementParser(new FakePdfTextExtractor($textsByBasename));

        return new StatementImporter(
            $container->get('doctrine'),
            [$pdfParser, $csvParser],
            $container->get(AccountResolver::class),
            $container->get(LabelNormalizer::class),
            $container->get(OperationFingerprinter::class),
        );
    }

    /** Fichier temporaire commençant par %PDF (reçu par supports()), texte fourni par l'extracteur de test. */
    private function makePdfFile(string $name): string
    {
        $path = sys_get_temp_dir().'/'.$name;
        file_put_contents($path, "%PDF-1.4\n% fixture texte fournie par FakePdfTextExtractor\n");
        $this->tempFiles[] = $path;

        return $path;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::PDF_TEXT.'/'.$name);
    }

    public function testImportPdfComplet(): void
    {
        $path = $this->makePdfFile('releve-janvier-2027.pdf');
        $importer = $this->makeImporter(['releve-janvier-2027.pdf' => $this->fixture('releve-janvier-2027.txt')]);

        $report = $importer->import($path, new ImportOptions(autoCreateAccount: true));

        self::assertSame('imported', $report->status);
        self::assertSame(10, $report->added);
        self::assertSame(0, $report->skipped);
        self::assertSame('ccp-perso', $report->accountAlias);
        self::assertSame('****R999', $report->accountMasked);
        self::assertSame('2026-12-28', $report->periodStart?->format('Y-m-d'));
        self::assertSame('2027-01-12', $report->periodEnd?->format('Y-m-d'));

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(\App\Entity\Account::class)->findOneBy(['alias' => 'ccp-perso']);
        self::assertNotNull($account);

        // Un relevé (Statement) avec soldes d'ouverture et de clôture.
        $statement = $this->entityManager->getRepository(Statement::class)->findOneBy(['account' => $account]);
        self::assertNotNull($statement);
        self::assertSame('2026-12-12', $statement->getOpeningDate()?->format('Y-m-d'));
        self::assertSame(-7391, $statement->getOpeningBalance());
        self::assertSame('2027-01-12', $statement->getClosingDate()->format('Y-m-d'));
        self::assertSame(207102, $statement->getClosingBalance());

        $operations = $this->entityManager->getRepository(Operation::class)->findBy(['account' => $account], ['date' => 'ASC', 'id' => 'ASC']);
        self::assertCount(10, $operations);
        foreach ($operations as $operation) {
            self::assertSame(ImportFormat::Pdf, $operation->getLabelSource());
            self::assertStringNotContainsString('S.A.', $operation->getRawLabel());
            self::assertStringNotContainsString('RCS', $operation->getRawLabel());
            self::assertStringNotContainsString('Page', $operation->getRawLabel());
        }

        $salaire = $this->findByLabelPrefix($operations, 'VIREMENT DE EMPLOYEUR');
        self::assertSame('2027-01-05', $salaire->getDate()->format('Y-m-d'));
        self::assertSame(235000, $salaire->getAmount());

        $avoir = $this->findByLabelPrefix($operations, 'AVOIR CARTE');
        self::assertSame(999, $avoir->getAmount());

        $prelevement = $this->findByLabelPrefix($operations, 'PRELEVEMENT DE FOURNISSEUR');
        self::assertSame('PRELEVEMENT DE FOURNISSEUR ENERGIE SA REF : ABC-0000000001 IDENT : FR00ZZZ000000 MANDAT : MDT-FICTIF-42', $prelevement->getRawLabel());

        $frais = $this->findByLabelPrefix($operations, 'FRAIS TENUE DE COMPTE');
        self::assertSame('FRAIS TENUE DE COMPTE', $frais->getRawLabel());
        self::assertSame(-300, $frais->getAmount());

        $librairie = $this->findByLabelPrefix($operations, 'ACHAT CB LIBRAIRIE');
        self::assertSame('2026-12-31', $librairie->getDate()->format('Y-m-d'));
        self::assertStringContainsString('USD 12,34 CARTE NUMERO 123', $librairie->getRawLabel());

        // Les deux achats identiques du 02/01 sont bien deux opérations (rangs 0 et 1).
        $boulangeries = array_values(array_filter(
            $operations,
            static fn (Operation $op): bool => str_contains($op->getRawLabel(), 'BOULANGERIE'),
        ));
        self::assertCount(2, $boulangeries);
        self::assertSame('2027-01-02', $boulangeries[0]->getDate()->format('Y-m-d'));
        self::assertSame(-1200, $boulangeries[0]->getAmount());
        self::assertSame([0, 1], array_map(static fn (Operation $op): int => $op->getOccurrenceIndex(), $boulangeries));
    }

    public function testReimportDuMemePdf(): void
    {
        $path = $this->makePdfFile('releve-janvier-2027.pdf');
        $importer = $this->makeImporter(['releve-janvier-2027.pdf' => $this->fixture('releve-janvier-2027.txt')]);

        $importer->import($path, new ImportOptions(autoCreateAccount: true));
        $report = $importer->import($path, new ImportOptions());

        self::assertSame('already_imported', $report->status);
        $this->entityManager->clear();
        self::assertCount(10, $this->entityManager->getRepository(Operation::class)->findAll());
        self::assertCount(1, $this->entityManager->getRepository(ImportBatch::class)->findAll());
    }

    public function testReleveIncoherentEchoueAvecLotFailed(): void
    {
        $path = $this->makePdfFile('releve-incoherent.pdf');
        $importer = $this->makeImporter(['releve-incoherent.pdf' => $this->fixture('releve-incoherent.txt')]);

        try {
            $importer->import($path, new ImportOptions(autoCreateAccount: true));
            self::fail('Une ParseException était attendue.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Relevé incohérent', $exception->getMessage());
            self::assertStringNotContainsString('1234567', $exception->getMessage());
            self::assertStringNotContainsString('SUPERMARCHE', $exception->getMessage());
        }

        // Le lot « failed » est enregistré (avec le message d'erreur), et l'import suivant repart.
        $this->entityManager->clear();
        $sha256 = hash('sha256', (string) file_get_contents($path));
        $batch = $this->entityManager->getRepository(ImportBatch::class)->findOneBy(['sha256' => $sha256]);
        self::assertNotNull($batch);
        self::assertSame(ImportStatus::Failed, $batch->getStatus());
        self::assertSame(ImportFormat::Pdf, $batch->getFormat());
        self::assertStringContainsString('incohérent', (string) $batch->getErrorMessage());
        self::assertCount(0, $this->entityManager->getRepository(Operation::class)->findAll());
    }

    public function testReleveAnnuelDeFraisSansLot(): void
    {
        $path = $this->makePdfFile('releve-annuel-frais.pdf');
        $importer = $this->makeImporter(['releve-annuel-frais.pdf' => $this->fixture('releve-annuel-frais.txt')]);

        try {
            $importer->import($path, new ImportOptions(autoCreateAccount: true));
            self::fail('Une NotAnAccountStatementException était attendue.');
        } catch (NotAnAccountStatementException) {
        }

        // Aucun lot enregistré, même pas « failed ».
        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(ImportBatch::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(Operation::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(Statement::class)->findAll());
    }

    public function testReleveIbanSeul(): void
    {
        $path = $this->makePdfFile('releve-iban-seul.pdf');
        $importer = $this->makeImporter(['releve-iban-seul.pdf' => $this->fixture('releve-iban-seul.txt')]);

        $report = $importer->import($path, new ImportOptions(autoCreateAccount: true));

        self::assertSame('imported', $report->status);
        self::assertSame(3, $report->added);
        self::assertSame('ccp-perso', $report->accountAlias);
        self::assertSame('****R999', $report->accountMasked);
    }

    public function testPdfApresCsvEnrichitAvecLeVraiParseurPdf(): void
    {
        $path = $this->makePdfFile('releve-detaille.pdf');
        $importer = $this->makeImporter(['releve-detaille.pdf' => self::PDF_ENRICHI]);

        $importer->import(self::CSV, new ImportOptions(autoCreateAccount: true));
        $this->entityManager->clear();
        $salary = $this->findByLabelPrefix($this->entityManager->getRepository(Operation::class)->findAll(), 'VIREMENT DE EMPLOYEUR');
        self::assertSame(ImportFormat::Csv, $salary->getLabelSource());
        $salaryId = $salary->getId();
        $fingerprint = $salary->getFingerprint();

        $report = $importer->import($path, new ImportOptions());

        self::assertSame('imported', $report->status);
        self::assertSame(0, $report->added);
        self::assertSame(1, $report->enriched);
        self::assertSame(0, $report->skipped);

        $this->entityManager->clear();
        $salary = $this->entityManager->find(Operation::class, $salaryId);
        self::assertStringContainsString('MOTIF : PAIE AOUT 2026', $salary->getRawLabel());
        self::assertSame(ImportFormat::Pdf, $salary->getLabelSource());
        self::assertNotNull($salary->getEnrichedAt());
        self::assertSame($fingerprint, $salary->getFingerprint());
    }

    /**
     * @param list<Operation> $operations
     */
    private function findByLabelPrefix(array $operations, string $prefix): Operation
    {
        foreach ($operations as $operation) {
            if (str_starts_with($operation->getRawLabel(), $prefix)) {
                return $operation;
            }
        }
        self::fail('Opération introuvable : '.$prefix);
    }
}
