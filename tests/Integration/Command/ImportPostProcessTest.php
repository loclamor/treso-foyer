<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ImportCommand;
use App\Command\SetupCommand;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
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

/**
 * Post-traitement de app:import : appariement des virements internes puis
 * catégorisation automatique après un import réel (fixtures CSV fictives).
 */
final class ImportPostProcessTest extends KernelTestCase
{
    private const FIXTURE_CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';
    private const FIXTURE_FEES_TEXT = __DIR__.'/../../Fixtures/pdf-text/releve-annuel-frais.txt';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        // Foyer + catégories + règles par défaut.
        (new CommandTester($container->get(SetupCommand::class)))->execute([]);
    }

    public function testImportTriggersTransferMatchingAndCategorization(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURE_CSV],
            '--auto-create-account' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Post-traitement', $display);
        // Les 2 opérations « BOULANGERIE » de la fixture sont catégorisées par règle.
        self::assertStringContainsString('2 par règle', $display);
        // Jamais de libellé d'opération dans la sortie.
        self::assertStringNotContainsString('BOULANGERIE', $display);
        self::assertStringNotContainsString('1234567R999', $display);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $operations = $entityManager->getRepository(Operation::class)->findAll();
        self::assertCount(7, $operations);
        $byRule = array_filter(
            $operations,
            static fn (Operation $o): bool => CategorizationSource::Rule === $o->getCategorizationSource(),
        );
        self::assertCount(2, $byRule);
        foreach ($byRule as $operation) {
            self::assertSame('Alimentation › Boulangerie & snacks', $operation->getCategory()?->getFullName());
        }
    }

    public function testNoPostProcessOptionSkipsIt(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURE_CSV],
            '--auto-create-account' => true,
            '--no-post-process' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Post-traitement', $tester->getDisplay());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        foreach ($entityManager->getRepository(Operation::class)->findAll() as $operation) {
            self::assertNull($operation->getCategory());
        }
    }

    public function testDryRunImportSkipsPostProcess(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURE_CSV],
            '--auto-create-account' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Post-traitement', $tester->getDisplay());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertCount(0, $entityManager->getRepository(Operation::class)->findAll());
    }

    /**
     * Le relevé annuel de frais est rejeté dans une transaction annulée, ce qui ferme
     * l'EntityManager (ORM 3). S'il est traité en dernier, le post-traitement doit quand même
     * s'exécuter et catégoriser les opérations ajoutées par les fichiers précédents.
     */
    public function testPostProcessRunsWhenLastFileIsAnnualFeesStatement(): void
    {
        $dir = sys_get_temp_dir().'/'.uniqid('import-post-frais-', true);
        mkdir($dir);
        copy(self::FIXTURE_CSV, $dir.'/a-releve.csv');
        file_put_contents($dir.'/z-frais.pdf', "%PDF-1.4\n% fixture texte fournie par FakePdfTextExtractor\n");

        try {
            $tester = new CommandTester($this->commandWithFakePdfText(['z-frais.pdf' => (string) file_get_contents(self::FIXTURE_FEES_TEXT)]));
            $exitCode = $tester->execute(['paths' => [$dir], '--auto-create-account' => true]);
        } finally {
            @unlink($dir.'/a-releve.csv');
            @unlink($dir.'/z-frais.pdf');
            @rmdir($dir);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('ignoré (pas un relevé d\'opérations)', $display);
        self::assertStringNotContainsString('Post-traitement ignoré', $display);
        self::assertStringContainsString('2 par règle', $display);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $byRule = array_filter(
            $entityManager->getRepository(Operation::class)->findAll(),
            static fn (Operation $o): bool => CategorizationSource::Rule === $o->getCategorizationSource(),
        );
        self::assertCount(2, $byRule);
    }

    /**
     * Un fichier déjà importé ne doit rien ajouter au total (ses compteurs d'origine sont
     * affichés sur sa ligne, pas cumulés) et ne doit pas déclencher de post-traitement.
     */
    public function testAlreadyImportedFileCountsNothingAndSkipsPostProcess(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        (new CommandTester($command))->execute(['paths' => [self::FIXTURE_CSV], '--auto-create-account' => true]);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['paths' => [self::FIXTURE_CSV], '--auto-create-account' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('already_imported', $display);
        self::assertStringContainsString('Totaux : 1 fichier(s), 0 ajoutée(s), 0 déjà connue(s), 0 enrichie(s), 0 doublon(s) probable(s).', $display);
        self::assertStringNotContainsString('Post-traitement', $display);
    }

    /**
     * @param array<string, string> $pdfTexts
     */
    private function commandWithFakePdfText(array $pdfTexts): ImportCommand
    {
        $container = self::getContainer();
        $importer = new StatementImporter(
            $container->get('doctrine'),
            [new PdfStatementParser(new FakePdfTextExtractor($pdfTexts)), $container->get(CsvStatementParser::class)],
            $container->get(AccountResolver::class),
            $container->get(LabelNormalizer::class),
            $container->get(OperationFingerprinter::class),
        );

        return new ImportCommand(
            $importer,
            $container->get(EntityManagerInterface::class),
            $container->get(PostImportProcessor::class),
        );
    }
}
