<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\BackupCommand;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:backup contre la base de test (pg_dump embarqué dans l'image php) : dump gzipé
 * réellement produit dans BACKUP_DIR, puis rotation via --keep.
 */
final class BackupCommandTest extends KernelTestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/app-backup-test-' . uniqid();
        $_SERVER['BACKUP_DIR'] = $this->backupDir;
        $_ENV['BACKUP_DIR'] = $this->backupDir;
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['BACKUP_DIR'], $_ENV['BACKUP_DIR']);
        if (isset($this->backupDir) && is_dir($this->backupDir)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($this->backupDir);
        }
        parent::tearDown();
    }

    public function testBackupProducesGzipDumpAndRotates(): void
    {
        $tester = new CommandTester(self::getContainer()->get(BackupCommand::class));

        $exit = $tester->execute(['--keep' => '2']);
        self::assertSame(0, $exit);
        $files = glob($this->backupDir . '/banque-*.sql.gz') ?: [];
        self::assertCount(1, $files);

        $content = gzdecode((string) file_get_contents($files[0]));
        self::assertIsString($content);
        self::assertStringContainsString('PostgreSQL database dump', $content);

        // Deux autres dumps avec --keep=2 : seuls les 2 plus récents restent.
        $tester->execute(['--keep' => '2']);
        $tester->execute(['--keep' => '2']);
        $files = glob($this->backupDir . '/banque-*.sql.gz') ?: [];
        self::assertCount(2, $files);
        self::assertStringContainsString('1 dump(s) supprimé(s)', $tester->getDisplay());
    }
}
