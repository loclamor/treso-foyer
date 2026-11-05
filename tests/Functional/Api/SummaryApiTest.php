<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\CategorizationSource;
use Symfony\Component\HttpFoundation\Response;

final class SummaryApiTest extends ApiTestCase
{
    public function testSummary(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP 05.01.26', 'MONSHOP', $courses, CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-01-06', -100, 'ACHAT CB AUTRE 06.01.26', 'AUTRE');
        $duplicate = $this->addOperation($this->account, '2026-01-07', -100, 'ACHAT CB AUTRE 07.01.26', 'AUTRE');
        $duplicate->setDuplicateStatus(\App\Enum\DuplicateStatus::Probable);
        $this->addOperation($this->jointAccount, '2026-01-08', 5000, 'VIREMENT REÇU', 'RECU');
        $this->em()->flush();

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/summary'));
        self::assertSame(4, $data['operationsCount']);
        self::assertSame(1, $data['categorizedCount']);
        self::assertSame(3, $data['uncategorizedCount']);
        self::assertSame(1, $data['probableDuplicatesCount']);
        self::assertSame(0, $data['pendingTransfersCount']);
        self::assertSame('2026-01-05', $data['firstDate']);
        self::assertSame('2026-01-08', $data['lastDate']);
        self::assertNull($data['lastImportAt']);
        self::assertCount(2, $data['accounts']);

        // Périmètre accountId[].
        $data = $this->decode($this->json($client, 'GET', '/api/summary?accountId[]='.(int) $this->jointAccount->getId()));
        self::assertSame(1, $data['operationsCount']);
        self::assertCount(1, $data['accounts']);
        self::assertSame('compte-joint', $data['accounts'][0]['alias']);
    }
}
