<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup;

use App\Backup\BackupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;

final class BackupManagerTest extends TestCase
{
    public function testParseDatabaseUrl(): void
    {
        $db = BackupManager::parseDatabaseUrl('postgresql://banque:secret@database:5432/banque?serverVersion=17&charset=utf8');

        self::assertSame('database', $db['host']);
        self::assertSame(5432, $db['port']);
        self::assertSame('banque', $db['user']);
        self::assertSame('secret', $db['password']);
        self::assertSame('banque', $db['dbname']);
    }

    public function testParseDatabaseUrlDefaultsAndEncoding(): void
    {
        $db = BackupManager::parseDatabaseUrl('postgresql://user:p%40ss%3Aword@db.internal/app%20name');

        self::assertSame(5432, $db['port']);
        self::assertSame('p@ss:word', $db['password']);
        self::assertSame('app name', $db['dbname']);
    }

    #[DataProvider('invalidUrls')]
    public function testParseDatabaseUrlRejectsInvalidUrls(string $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupManager::parseDatabaseUrl($invalid);
    }

    /** @return list<array{string}> */
    public static function invalidUrls(): array
    {
        return [['sqlite:///tmp/x.db'], ['postgresql://hostonly'], ['not a url']];
    }

    public function testPruneKeepsNewestAndRemovesStaleTmpFiles(): void
    {
        $dir = sys_get_temp_dir() . '/backup-manager-test-' . uniqid();
        mkdir($dir);
        foreach (['banque-20260101-000000.sql.gz', 'banque-20260102-000000.sql.gz', 'banque-20260103-000000.sql.gz'] as $f) {
            file_put_contents($dir . '/' . $f, 'x');
        }
        file_put_contents($dir . '/banque-20260104-000000.sql.gz.tmp', 'x');
        file_put_contents($dir . '/autre-fichier.txt', 'x');

        $manager = new BackupManager('postgresql://u:p@h:5432/db', $dir, 2, Clock::get(), new NullLogger());
        $pruned = $manager->prune(2);

        self::assertSame(1, $pruned);
        self::assertFileDoesNotExist($dir . '/banque-20260101-000000.sql.gz');
        self::assertFileExists($dir . '/banque-20260102-000000.sql.gz');
        self::assertFileExists($dir . '/banque-20260103-000000.sql.gz');
        self::assertFileDoesNotExist($dir . '/banque-20260104-000000.sql.gz.tmp');
        self::assertFileExists($dir . '/autre-fichier.txt');

        (new \Symfony\Component\Filesystem\Filesystem())->remove($dir);
    }
}
