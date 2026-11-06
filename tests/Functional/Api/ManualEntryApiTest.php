<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\ImportFormat;
use App\Import\Model\ImportOptions;
use App\Import\StatementImporter;
use App\Tests\Support\TestClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * API des saisies manuelles de dépenses et du rapprochement avec les relevés (ADR 0014) :
 * POST /api/operations, PATCH/DELETE sur saisie en attente, candidats, reconcile manuel,
 * filtre awaitingStatement, résumé (compteur + saisies en retard), stats à la date effective.
 */
final class ManualEntryApiTest extends ApiTestCase
{
    private const FIXTURE_CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';

    protected function tearDown(): void
    {
        TestClock::reset();
        parent::tearDown();
    }

    private function importFixture(): void
    {
        self::getContainer()->get(StatementImporter::class)->import(self::FIXTURE_CSV, new ImportOptions(autoCreateAccount: true));
        $this->em()->clear();
    }

    /** Ligne importée de la fixture (relevé-a.csv). */
    private function importedLine(int $amount): Operation
    {
        $line = $this->em()->getRepository(Operation::class)->findOneBy(['amount' => $amount, 'labelSource' => ImportFormat::Csv]);
        \assert($line instanceof Operation);

        return $line;
    }

    private function createEntry(array $overrides = []): array
    {
        $response = $this->json($this->client(), 'POST', '/api/operations', $overrides + [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-10-20',
            'amount' => -4599,
            'label' => 'ACHAT CB AMAZON 20.10.26',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return $this->decode($response);
    }

    public function testCreateManualEntry(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $data = $this->createEntry([
            'categoryId' => (int) $courses->getId(),
            'note' => 'Gros achat',
        ]);

        self::assertFalse($data['reconciled']);
        $op = $data['operation'];
        self::assertTrue($op['awaitingStatement']);
        self::assertSame('2026-10-20', $op['spentAt']);
        self::assertSame('2026-10-20', $op['date']);
        self::assertSame('2026-10-20', $op['effectiveDate']);
        self::assertSame(-4599, $op['amount']);
        self::assertSame('ACHAT CB AMAZON 20.10.26', $op['rawLabel']);
        self::assertSame('AMAZON', $op['merchant']);
        self::assertNull($op['labelSource']);
        self::assertNull($op['reconciledAt']);
        self::assertSame('Gros achat', $op['note']);
        self::assertSame((int) $courses->getId(), $op['category']['id']);
        self::assertSame('manual', $op['categorizationSource']);
        self::assertSame('ccp-perso', $op['account']['alias']);

        // Persistée en base avec une empreinte « manual:… ».
        $stored = $this->em()->find(Operation::class, $op['id']);
        self::assertNotNull($stored);
        self::assertTrue($stored->isAwaitingStatement());
        self::assertStringStartsWith('manual:', $stored->getFingerprint());
    }

    public function testCreateValidation422(): void
    {
        $client = $this->client();
        $base = ['accountId' => (int) $this->account->getId(), 'spentAt' => '2026-10-20', 'amount' => -100, 'label' => 'Test'];

        // amount nul.
        $response = $this->json($client, 'POST', '/api/operations', ['accountId' => $base['accountId'], 'spentAt' => '2026-10-20', 'amount' => 0, 'label' => 'Test']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertArrayHasKey('violations', $this->decode($response));

        // label vide.
        $response = $this->json($client, 'POST', '/api/operations', [...$base, 'label' => '']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // date invalide.
        $response = $this->json($client, 'POST', '/api/operations', [...$base, 'spentAt' => '2026-02-30']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // compte inconnu du foyer.
        $response = $this->json($client, 'POST', '/api/operations', [...$base, 'accountId' => 999999]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('accountId', $this->decode($response)['violations'][0]['field']);

        // catégorie hors foyer.
        $response = $this->json($client, 'POST', '/api/operations', [...$base, 'categoryId' => 999999]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // compte clôturé.
        $joint = $this->em()->find(\App\Entity\Account::class, $this->jointAccount->getId());
        $joint->setClosedAt(new \DateTimeImmutable('2026-01-01'));
        $this->em()->flush();
        $response = $this->json($client, 'POST', '/api/operations', [...$base, 'accountId' => (int) $joint->getId()]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testCreateAutoCategorizesBySimilarity(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-10', -3000, 'ACHAT CB AMAZON EU 10.01.26', 'AMAZON EU', $courses, CategorizationSource::Manual);

        $data = $this->createEntry(['amount' => -3000, 'label' => 'ACHAT CB AMAZON EU 20.10.26', 'spentAt' => '2026-10-20']);

        // Sans categoryId : catégorisation automatique de la saisie (similarité avec l'opération manuelle).
        self::assertSame((int) $courses->getId(), $data['operation']['category']['id']);
        self::assertSame('similarity', $data['operation']['categorizationSource']);
    }

    public function testCreateReconciledImmediatelyWhenStatementAlreadyImported(): void
    {
        $this->importFixture();
        $line = $this->importedLine(-500);
        $lineId = (int) $line->getId();

        // Saisie de la même dépense (ligne « SUPER; MARKET » -500 du 26/08/2026).
        $data = $this->createEntry(['amount' => -500, 'spentAt' => '2026-08-24', 'label' => 'SUPER MARKET']);

        self::assertTrue($data['reconciled']);
        self::assertSame($lineId, $data['operation']['id']);
        self::assertFalse($data['operation']['awaitingStatement']);
        self::assertNotNull($data['operation']['reconciledAt']);
        // spentAt de la ligne (extrait du libellé CB : 26.08.26) conservé.
        self::assertSame('2026-08-26', $data['operation']['spentAt']);
        self::assertSame('2026-08-26', $data['operation']['date']);

        // La saisie a disparu : plus rien en attente.
        $list = $this->decode($this->json($this->client(), 'GET', '/api/operations?awaitingStatement=1'));
        self::assertSame(0, $list['total']);
    }

    public function testPatchAwaitingEntry(): void
    {
        $data = $this->createEntry();
        $id = $data['operation']['id'];
        $client = $this->client();

        $response = $this->json($client, 'PATCH', '/api/operations/'.$id, [
            'amount' => -100,
            'spentAt' => '2026-10-21',
            'label' => 'Nouveau libellé test',
            'accountId' => (int) $this->jointAccount->getId(),
            'note' => 'modifiée',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $op = $this->decode($response);
        self::assertSame(-100, $op['amount']);
        self::assertSame('2026-10-21', $op['spentAt']);
        // spentAt modifie aussi la date.
        self::assertSame('2026-10-21', $op['date']);
        self::assertSame('Nouveau libellé test', $op['rawLabel']);
        self::assertSame('NOUVEAU LIBELLE TEST', $op['merchant']);
        self::assertSame((int) $this->jointAccount->getId(), $op['account']['id']);
        self::assertSame('modifiée', $op['note']);
        self::assertTrue($op['awaitingStatement']);

        // note: null efface la note (comme avant).
        $response = $this->json($client, 'PATCH', '/api/operations/'.$id, ['note' => null]);
        self::assertNull($this->decode($response)['note']);
    }

    public function testPatchImportedOperationRejectsEntryFieldsButKeepsNote(): void
    {
        $operation = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB TEST 01.03.26', 'TEST');
        $client = $this->client();

        // amount sur une opération importée ⇒ 400 not_a_manual_entry.
        $response = $this->json($client, 'PATCH', '/api/operations/'.(int) $operation->getId(), ['amount' => -5]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('not_a_manual_entry', $this->decode($response)['error']);

        // spentAt idem.
        $response = $this->json($client, 'PATCH', '/api/operations/'.(int) $operation->getId(), ['spentAt' => '2026-03-02']);
        self::assertSame('not_a_manual_entry', $this->decode($response)['error']);

        // note seule : toujours autorisée.
        $response = $this->json($client, 'PATCH', '/api/operations/'.(int) $operation->getId(), ['note' => 'Une note']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Une note', $this->decode($response)['note']);
    }

    public function testDeleteOnlyAwaitingEntry(): void
    {
        $data = $this->createEntry();
        $id = $data['operation']['id'];
        $client = $this->client();

        $response = $this->json($client, 'DELETE', '/api/operations/'.$id);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->json($client, 'GET', '/api/operations/'.$id)->getStatusCode());

        // Opération importée : 400 not_a_manual_entry.
        $operation = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB TEST 01.03.26', 'TEST');
        $response = $this->json($client, 'DELETE', '/api/operations/'.(int) $operation->getId());
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('not_a_manual_entry', $this->decode($response)['error']);
    }

    public function testCandidatesAndManualReconcile(): void
    {
        $this->importFixture();
        $line = $this->importedLine(-500);
        $lineId = (int) $line->getId();

        // Saisie sans correspondance automatique (montant inconnu).
        $data = $this->createEntry(['amount' => -12345, 'spentAt' => '2026-08-26', 'label' => 'ACHAT CB SUPERMARCHE 26.08.26']);
        $entryId = $data['operation']['id'];
        self::assertFalse($data['reconciled']);

        $client = $this->client();
        // Candidats : lignes de relevé du foyer, même signe, fenêtre −5/+30 j.
        $response = $this->json($client, 'GET', '/api/operations/'.$entryId.'/reconciliation-candidates');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $items = $this->decode($response)['items'];
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertFalse($item['awaitingStatement']);
            self::assertNotNull($item['labelSource']);
        }
        // Le candidat au montant exact (−500) est proposé en premier ? non : −12345 n'a pas de montant exact.
        self::assertContains($lineId, array_map(static fn (array $i): int => (int) $i['id'], $items));

        // Candidats sur une opération importée ⇒ 400.
        $response = $this->json($client, 'GET', '/api/operations/'.$lineId.'/reconciliation-candidates');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('not_a_manual_entry', $this->decode($response)['error']);

        // Appariement manuel (montant différent autorisé : les valeurs du relevé font foi).
        $response = $this->json($client, 'POST', '/api/operations/'.$entryId.'/reconcile', ['operationId' => $lineId]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $merged = $this->decode($response)['operation'];
        self::assertSame($lineId, $merged['id']);
        self::assertSame(-500, $merged['amount']);
        self::assertSame('2026-08-26', $merged['date']);
        self::assertNotNull($merged['reconciledAt']);
        // Note : le libellé saisi diffère ⇒ « Saisie : … ».
        self::assertSame('Saisie : ACHAT CB SUPERMARCHE 26.08.26', $merged['note']);

        // La saisie a disparu ; reconcile dessus ⇒ 404 ; reconcile d'une ligne importée ⇒ 400.
        self::assertSame(Response::HTTP_NOT_FOUND, $this->json($client, 'POST', '/api/operations/'.$entryId.'/reconcile', ['operationId' => $lineId])->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->json($client, 'POST', '/api/operations/'.$lineId.'/reconcile', ['operationId' => $lineId])->getStatusCode());

        // Cible déjà rapprochée ⇒ 400 already_reconciled.
        $data2 = $this->createEntry(['amount' => -777, 'spentAt' => '2026-08-26', 'label' => 'AUTRE SAISIE']);
        $response = $this->json($client, 'POST', '/api/operations/'.$data2['operation']['id'].'/reconcile', ['operationId' => $lineId]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('already_reconciled', $this->decode($response)['error']);
    }

    public function testListAwaitingFilterAndEffectiveDate(): void
    {
        $this->importFixture();
        // La ligne -2756 du 26/08 a un spentAt extrait du libellé (25.08.26) différent de sa date.
        $line = $this->importedLine(-2756);
        self::assertSame('2026-08-25', $line->getSpentAt()?->format('Y-m-d'));
        self::assertSame('2026-08-26', $line->getDate()->format('Y-m-d'));
        $lineId = (int) $line->getId();

        // Deux lignes -2756 identiques dans la fixture ⇒ appariement ambigu, la saisie reste en attente.
        $data = $this->createEntry(['amount' => -2756, 'spentAt' => '2026-08-24', 'label' => 'SUPERMARCHE']);
        self::assertFalse($data['reconciled']);
        $entryId = $data['operation']['id'];

        $client = $this->client();
        // Filtre awaitingStatement=1.
        $list = $this->decode($this->json($client, 'GET', '/api/operations?awaitingStatement=1'));
        self::assertSame(1, $list['total']);
        self::assertSame($entryId, $list['items'][0]['id']);

        // Rapprochement manuel sur la ligne -2756 (spentAt 25.08 extrait du libellé CB).
        $response = $this->json($client, 'POST', '/api/operations/'.$entryId.'/reconcile', ['operationId' => $lineId]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('2026-08-25', $this->decode($response)['operation']['spentAt']);

        // from/to portent sur la date effective : la ligne rapprochée (date 26, effective 25)
        // apparaît au 25/08, pas au 26/08.
        $list = $this->decode($this->json($client, 'GET', '/api/operations?from=2026-08-25&to=2026-08-25'));
        self::assertContains($lineId, array_map(static fn (array $i): int => (int) $i['id'], $list['items']));
        $list = $this->decode($this->json($client, 'GET', '/api/operations?from=2026-08-26&to=2026-08-26'));
        self::assertNotContains($lineId, array_map(static fn (array $i): int => (int) $i['id'], $list['items']));

        // Plus de saisie en attente.
        $list = $this->decode($this->json($client, 'GET', '/api/operations?awaitingStatement=1'));
        self::assertSame(0, $list['total']);
    }

    public function testSummaryAwaitingCountAndStaleEntries(): void
    {
        TestClock::freeze('2026-11-06');
        $this->createEntry(['spentAt' => '2026-10-01', 'amount' => -100000, 'label' => 'Grosse dépense']);
        $this->createEntry(['spentAt' => '2026-11-01', 'amount' => -500, 'label' => 'Dépense récente']);

        $data = $this->decode($this->json($this->client(), 'GET', '/api/summary'));
        self::assertSame(2, $data['awaitingStatementCount']);
        // Seule la saisie du 01/10 est « en retard » (> 15 jours au 06/11) : âge 36 jours.
        self::assertCount(1, $data['staleManualEntries']);
        $stale = $data['staleManualEntries'][0];
        self::assertSame('2026-10-01', $stale['spentAt']);
        self::assertSame(36, $stale['ageDays']);
        self::assertSame(-100000, $stale['amount']);
        self::assertSame('Grosse dépense', $stale['label']);
        self::assertSame('ccp-perso', $stale['account']['alias']);
    }

    public function testStatsUseEffectiveDate(): void
    {
        // Saisie anticipée : date effective 2026-08-24 (rien au 25..31 août en date de relevé).
        $this->createEntry(['spentAt' => '2026-08-24', 'amount' => -1000, 'label' => 'Dépense anticipée']);

        $client = $this->client();
        $overview = $this->decode($this->json($client, 'GET', '/api/stats/overview?from=2026-08-01&to=2026-08-31'));
        self::assertSame(1000, $overview['expense']);
        self::assertSame(1, $overview['operationsCount']);

        $day = $this->decode($this->json($client, 'GET', '/api/stats/overview?from=2026-08-24&to=2026-08-24'));
        self::assertSame(1000, $day['expense']);

        $after = $this->decode($this->json($client, 'GET', '/api/stats/overview?from=2026-08-25&to=2026-08-31'));
        self::assertSame(0, $after['expense']);
        self::assertSame(0, $after['operationsCount']);

        // Calendrier : la dépense apparaît au 24/08 (date effective), pas au relevé.
        $calendar = $this->decode($this->json($client, 'GET', '/api/stats/calendar?from=2026-08-20&to=2026-08-31'));
        self::assertSame([['date' => '2026-08-24', 'expense' => 1000, 'count' => 1]], $calendar);
    }
}
