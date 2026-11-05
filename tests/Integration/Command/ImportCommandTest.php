<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Categorization\Categorizer;
use App\Categorization\InternalTransferMatcher;
use App\Command\ImportCommand;
use App\Entity\Household;
use App\Import\AccountResolver;
use App\Import\LabelNormalizer;
use App\Import\OperationFingerprinter;
use App\Import\Parser\CsvStatementParser;
use App\Import\Parser\PdfStatementParser;
use App\Import\PostImportProcessor;
use App\Import\StatementImporter;
use App\Tests\Support\FakePdfTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCommandTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures';

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        if (null === $entityManager->getRepository(Household::class)->findOneBy([])) {
            $entityManager->persist(new Household('Foyer test'));
            $entityManager->flush();
        }
    }

    public function testImportDossierComplet(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES],
            '--auto-create-account' => true,
        ]);

        $display = $tester->getDisplay();

        // Jamais de numéro de compte en clair dans la sortie.
        self::assertStringNotContainsString('1234567R999', $display);
        self::assertStringNotContainsString('9999999X000', $display);
        // Jamais de libellé d'opération.
        self::assertStringNotContainsString('SUPERMARCHE', $display);

        self::assertStringContainsString('releve-a.csv', $display);
        self::assertStringContainsString('ccp-perso ****R999', $display);
        self::assertStringContainsString('format non pris en charge', $display); // PDF fictif
        self::assertStringContainsString('échoué', $display); // CSV invalide
        self::assertStringContainsString('imported', $display);
        // Au moins un fichier en échec (PDF non pris en charge + CSV invalide) → code retour FAILURE.
        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testImportUnSeulFichierValide(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES.'/csv/releve-a.csv'],
            '--auto-create-account' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('imported', $display);
        self::assertStringContainsString('7', $display); // 7 opérations ajoutées
        self::assertStringNotContainsString('1234567R999', $display);
    }

    public function testDryRun(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURES.'/csv/releve-a.csv'],
            '--dry-run' => true,
            '--auto-create-account' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('dry_run', $tester->getDisplay());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertCount(0, $entityManager->getRepository(\App\Entity\Operation::class)->findAll());
        self::assertCount(0, $entityManager->getRepository(\App\Entity\ImportBatch::class)->findAll());
    }

    public function testReleveAnnuelDeFraisIgnoreSansEchec(): void
    {
        // Vrai parseur PDF + extracteur de test (fixture texte du relevé annuel de frais).
        $container = self::getContainer();
        $csvParser = $container->get(CsvStatementParser::class);
        $pdfParser = new PdfStatementParser(new FakePdfTextExtractor([
            'releve-annuel-frais.pdf' => (string) file_get_contents(self::FIXTURES.'/pdf-text/releve-annuel-frais.txt'),
        ]));
        $importer = new StatementImporter(
            $container->get('doctrine'),
            [$pdfParser, $csvParser],
            $container->get(AccountResolver::class),
            $container->get(LabelNormalizer::class),
            $container->get(OperationFingerprinter::class),
        );
        $command = new ImportCommand(
            $importer,
            $container->get(EntityManagerInterface::class),
            $container->get(PostImportProcessor::class),
        );

        $dir = sys_get_temp_dir().'/'.uniqid('import-frais-', true);
        mkdir($dir);
        $pdf = $dir.'/releve-annuel-frais.pdf';
        file_put_contents($pdf, "%PDF-1.4\n% fixture texte fournie par FakePdfTextExtractor\n");

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['paths' => [$dir]]);

        @unlink($pdf);
        @rmdir($dir);

        // Ignoré, mais pas un échec : code retour SUCCESS.
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('ignoré (pas un relevé d\'opérations)', $tester->getDisplay());

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertCount(0, $entityManager->getRepository(\App\Entity\ImportBatch::class)->findAll());
    }
}
