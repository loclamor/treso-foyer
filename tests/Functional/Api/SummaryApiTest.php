<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\ImportBatch;
use App\Entity\Statement;
use App\Enum\CategorizationSource;
use App\Enum\ImportFormat;
use App\Enum\ImportStatus;
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

    public function testMissingStatements(): void
    {
        // Compte perso : relevés de janvier et mars 2025 → février manquant.
        $this->addStatement($this->account, '2025-01-10', withOpening: true);
        $this->addStatement($this->account, '2025-03-12', withOpening: true);
        // Compte joint : uniquement un export CSV (sans solde d'ouverture) → non évalué.
        $this->addStatement($this->jointAccount, '2025-02-27', withOpening: false);
        $this->em()->flush();

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/summary'));
        $gaps = array_values(array_filter($data['missingStatements'], static fn (array $m): bool => 'gap' === $m['reason']));
        self::assertCount(1, $gaps);
        self::assertSame('2025-02', $gaps[0]['month']);
        self::assertSame('ccp-perso', $gaps[0]['account']['alias']);
        self::assertArrayNotHasKey('numberHash', $gaps[0]['account']);
        // Après le dernier relevé (mars 2025), les mois échus sont en retard.
        $overdue = array_values(array_filter($data['missingStatements'], static fn (array $m): bool => 'overdue' === $m['reason']));
        self::assertNotEmpty($overdue);
        self::assertSame('2025-04', $overdue[0]['month']);
        foreach ($data['missingStatements'] as $missing) {
            self::assertSame('ccp-perso', $missing['account']['alias']);
        }

        // Périmètre limité au compte joint : rien à signaler.
        $data = $this->decode($this->json($client, 'GET', '/api/summary?accountId[]='.(int) $this->jointAccount->getId()));
        self::assertSame([], $data['missingStatements']);
    }

    private function addStatement(Account $account, string $closingDate, bool $withOpening): void
    {
        $batch = new ImportBatch('releve-'.$closingDate.'.pdf', hash('sha256', $account->getAlias().$closingDate), ImportFormat::Pdf, ImportStatus::Completed);
        $batch->setAccount($account);
        $this->em()->persist($batch);
        $statement = new Statement($account, $batch, new \DateTimeImmutable($closingDate), 10000);
        if ($withOpening) {
            $statement->setOpeningDate((new \DateTimeImmutable($closingDate))->modify('-1 month'));
            $statement->setOpeningBalance(10000);
        }
        $this->em()->persist($statement);
    }
}
