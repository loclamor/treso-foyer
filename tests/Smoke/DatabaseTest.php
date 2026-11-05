<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseTest extends KernelTestCase
{
    public function testPostgresWithPgTrgmIsAvailable(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $version = (string) $connection->fetchOne('SHOW server_version');
        self::assertStringStartsWith('17', $version);

        self::assertSame(
            'pg_trgm',
            $connection->fetchOne("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'"),
        );
        self::assertGreaterThan(0.3, (float) $connection->fetchOne("SELECT similarity('ACHAT CB INTERMARCHE', 'INTERMARCHE')"));
    }
}
