<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use Symfony\Component\HttpFoundation\Response;

final class OperationApiTest extends ApiTestCase
{
    public function testListPaginationAndDefaultSort(): void
    {
        for ($i = 1; $i <= 60; ++$i) {
            $this->addOperation($this->account, sprintf('2026-01-%02d', ($i % 28) + 1), -100 * $i, 'ACHAT CB MAGASIN '.$i, 'MAGASIN'.$i);
        }
        $client = $this->client();
        $response = $this->json($client, 'GET', '/api/operations');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(60, $data['total']);
        self::assertSame(1, $data['page']);
        self::assertSame(50, $data['perPage']);
        self::assertCount(50, $data['items']);
        self::assertArrayHasKey('sums', $data);
        self::assertSame(-6000 * 61 / 2, $data['sums']['debit']); // -(100+200+...+6000)
        self::assertSame(0, $data['sums']['credit']);

        $response = $this->json($client, 'GET', '/api/operations?page=2&perPage=50');
        self::assertCount(10, $this->decode($response)['items']);

        // perPage plafonné à 200.
        $response = $this->json($client, 'GET', '/api/operations?perPage=500');
        self::assertSame(200, $this->decode($response)['perPage']);
    }

    public function testListFilters(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $boulangerie = $this->category('Alimentation › Boulangerie & snacks');
        $this->addOperation($this->account, '2026-02-01', -2756, 'ACHAT CB BOULANGERIE CHEZ MICHÈLE 01.02.26', 'BOULANGERIE CHEZ MICHELE', $boulangerie, CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-02-02', -500, 'ACHAT CB SUPERMARCHE EXEMPLE 02.02.26', 'SUPERMARCHE', $courses, CategorizationSource::Rule);
        $this->addOperation($this->account, '2026-02-03', 2350, 'VIREMENT DE EMPLOYEUR FICTIF', 'EMPLOYEUR');
        $this->addOperation($this->jointAccount, '2026-02-04', -1200, 'ACHAT CB BOULANGERIE CHEZ MICHÈLE 04.02.26', 'BOULANGERIE CHEZ MICHELE');

        $client = $this->client();

        // q insensible casse/accents (libellé normalisé replié).
        $data = $this->decode($this->json($client, 'GET', '/api/operations?q=michele'));
        self::assertSame(2, $data['total']);
        // q sur le commerçant.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?q=supermarche'));
        self::assertSame(1, $data['total']);
        // q sur raw_label (casse seule).
        $data = $this->decode($this->json($client, 'GET', '/api/operations?q=chez+mich'));
        self::assertSame(2, $data['total']);

        // categoryId parent inclut les enfants.
        $alimentations = $this->category('Alimentation');
        $data = $this->decode($this->json($client, 'GET', '/api/operations?categoryId='.(int) $alimentations->getId()));
        self::assertSame(2, $data['total']);

        // uncategorized.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?uncategorized=1'));
        self::assertSame(2, $data['total']);

        // direction + montants (valeur absolue).
        $data = $this->decode($this->json($client, 'GET', '/api/operations?direction=credit'));
        self::assertSame(1, $data['total']);
        self::assertSame(2350, $data['sums']['credit']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations?minAmount=1000&maxAmount=3000'));
        self::assertSame(3, $data['total']);

        // merchant exact.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?merchant=SUPERMARCHE'));
        self::assertSame(1, $data['total']);

        // source.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?source=manual'));
        self::assertSame(1, $data['total']);

        // périmètre accountId[] et memberId.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?accountId[]='.(int) $this->jointAccount->getId()));
        self::assertSame(1, $data['total']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations?memberId='.(int) $this->otherMember->getId()));
        self::assertSame(1, $data['total']);

        // from / to.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?from=2026-02-02&to=2026-02-03'));
        self::assertSame(2, $data['total']);

        // tri montant asc.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?sort=amount&order=asc'));
        self::assertSame(-2756, $data['items'][0]['amount']);
    }

    public function testMerchantFilterUsesGroupKey(): void
    {
        // Avec commerçant : égalité exacte sur le commerçant.
        $this->addOperation($this->account, '2026-03-01', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 01.03.26', 'SUPERMARCHE');
        // Sans commerçant : la clé de groupe est le libellé normalisé (cf. /api/merchants).
        $sansCommercant = $this->addOperation($this->account, '2026-03-02', -1000, 'OPERATION SANS COMMERCANT');
        $client = $this->client();

        self::assertSame(1, $this->decode($this->json($client, 'GET', '/api/operations?merchant=SUPERMARCHE'))['total']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations?merchant='.rawurlencode($sansCommercant->getNormalizedLabel())));
        self::assertSame(1, $data['total']);
        self::assertSame($sansCommercant->getId(), $data['items'][0]['id']);
    }

    public function testShowAndPatchNote(): void
    {
        $operation = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB TEST', 'TEST');
        $client = $this->client();

        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $operation->getId()));
        self::assertSame((int) $operation->getId(), $data['id']);
        self::assertSame(-100, $data['amount']);
        self::assertSame('none', $data['duplicateStatus']);
        self::assertNull($data['category']);
        self::assertSame(['id' => (int) $this->account->getId(), 'alias' => 'ccp-perso', 'label' => 'Compte ****R999', 'numberMasked' => '****R999'], $data['account']);

        $response = $this->json($client, 'PATCH', '/api/operations/'.(int) $operation->getId(), ['note' => 'Une note']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('Une note', $this->decode($response)['note']);

        $response = $this->json($client, 'PATCH', '/api/operations/'.(int) $operation->getId(), ['note' => null]);
        self::assertNull($this->decode($response)['note']);
    }

    public function testCategorizeOperationScopeDoesNotCreateRule(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $op1 = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB SHOP A', 'SHOPA');
        $op2 = $this->addOperation($this->account, '2026-03-02', -200, 'ACHAT CB SHOP A', 'SHOPA');
        $client = $this->client();

        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op1->getId().'/categorize', ['categoryId' => (int) $courses->getId(), 'scope' => 'operation']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('manual', $data['operation']['categorizationSource']);
        self::assertSame(0, $data['updatedCount']);
        self::assertNull($data['rule']);

        // L'autre opération du même commerçant n'est pas touchée.
        $this->em()->clear();
        $reloaded = $this->em()->find(Operation::class, $op2->getId());
        self::assertNull($reloaded?->getCategory());
    }

    public function testCategorizeMerchantScopeCreatesRuleAndPropagates(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $op1 = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB SHOP B', 'SHOPB');
        $op2 = $this->addOperation($this->account, '2026-03-02', -200, 'ACHAT CB SHOP B', 'SHOPB');
        $manual = $this->addOperation($this->account, '2026-03-03', -300, 'ACHAT CB SHOP B', 'SHOPB', $this->category('Alimentation › Boulangerie & snacks'), CategorizationSource::Manual);
        $client = $this->client();

        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op1->getId().'/categorize', ['categoryId' => (int) $courses->getId(), 'scope' => 'merchant']);
        $data = $this->decode($response);
        self::assertSame(1, $data['updatedCount']); // op2 mise à jour, l'opération manuelle intacte
        self::assertNotNull($data['rule']);
        self::assertSame('merchant', $data['rule']['matchField']);
        self::assertSame('exact', $data['rule']['matchType']);
        self::assertSame('SHOPB', $data['rule']['pattern']);
        self::assertSame(100, $data['rule']['priority']);
        self::assertSame(1, $data['rule']['matchCount']);

        $this->em()->clear();
        self::assertSame('rule', $this->em()->find(Operation::class, $op2->getId())?->getCategorizationSource()?->value);
        self::assertSame('manual', $this->em()->find(Operation::class, $manual->getId())?->getCategorizationSource()?->value);
    }

    public function testCategorizeNullClearsThenRecategorizes(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $op = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB SHOP C', 'SHOPC', $courses, CategorizationSource::Manual);
        // Règle qui recatégorisera l'opération après le clear.
        $rule = new \App\Entity\CategorizationRule($this->household, $this->category('Alimentation › Boulangerie & snacks'), \App\Enum\RuleMatchField::Merchant, \App\Enum\RuleMatchType::Exact, 'SHOPC');
        $this->em()->persist($rule);
        $this->em()->flush();

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op->getId().'/categorize', ['categoryId' => null, 'scope' => 'operation']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('rule', $data['operation']['categorizationSource']);
        self::assertSame('Alimentation › Boulangerie & snacks', $data['operation']['category']['fullName']);
    }

    public function testBulkCategorize(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $op1 = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB X1', 'X1');
        $op2 = $this->addOperation($this->account, '2026-03-02', -200, 'ACHAT CB X2', 'X2');
        $client = $this->client();

        $response = $this->json($client, 'POST', '/api/operations/bulk-categorize', ['ids' => [(int) $op1->getId(), (int) $op2->getId()], 'categoryId' => (int) $courses->getId()]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $this->decode($response)['updatedCount']);

        $this->em()->clear();
        foreach ([$op1, $op2] as $op) {
            $reloaded = $this->em()->find(Operation::class, $op->getId());
            self::assertSame('manual', $reloaded?->getCategorizationSource()?->value);
            self::assertSame((int) $courses->getId(), (int) $reloaded?->getCategory()?->getId());
        }
    }

    public function testDuplicateMergeAndDistinct(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $csv = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB SHOP D 01.03.26', 'SHOPD');
        $csv->setLabelSource(ImportFormat::Csv);
        $pdf = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB SHOP D DETAIL 01.03.26 CARTE 123', 'SHOPD');
        $pdf->setLabelSource(ImportFormat::Pdf);
        $pdf->setDuplicateStatus(DuplicateStatus::Probable);
        $pdf->setDuplicateOf($csv);
        $csv->categorize($courses, CategorizationSource::Manual);
        $csv->setNote('note conservée');
        $this->em()->flush();

        $client = $this->client();

        // distinct : le statut passe à confirmed_distinct, duplicateOf null.
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $pdf->getId().'/duplicate', ['action' => 'distinct']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('confirmed_distinct', $data['kept']['duplicateStatus']);
        self::assertNull($data['kept']['duplicateOfId']);

        // merge : l'opération PDF (référence) est conservée, catégorie manuelle et note reportées.
        $pdf->setDuplicateStatus(DuplicateStatus::Probable);
        $pdf->setDuplicateOf($csv);
        $this->em()->flush();
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $pdf->getId().'/duplicate', ['action' => 'merge']);
        $data = $this->decode($response);
        self::assertSame((int) $pdf->getId(), $data['kept']['id']);
        self::assertSame('manual', $data['kept']['categorizationSource']);
        self::assertSame('note conservée', $data['kept']['note']);
        $this->em()->clear();
        self::assertNull($this->em()->find(Operation::class, $csv->getId()));
    }

    public function testBulkVehicleWithoutIdsIsRejected(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/operations/bulk-vehicle', ['ids' => [], 'vehicleId' => null]);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('ids', array_column($this->decode($response)['violations'], 'field'));
    }

    public function testValidationErrorReturns422WithViolations(): void
    {
        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/operations/bulk-categorize', ['ids' => [], 'categoryId' => 1]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('validation_failed', $data['error']);
        self::assertNotEmpty($data['violations']);
        self::assertArrayHasKey('field', $data['violations'][0]);
    }

    private function createVehicleViaApi(string $name = 'Clio'): array
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => $name, 'type' => 'car', 'acquiredAt' => '2026-01-01']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return $this->decode($response);
    }

    public function testAssignVehicleManualThenUnassignResyncs(): void
    {
        $vehicle = $this->createVehicleViaApi();
        $op = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB SHOP V', 'SHOPV');
        $client = $this->client();

        // Affectation manuelle.
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op->getId().'/vehicle', ['vehicleId' => (int) $vehicle['id']]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame((int) $vehicle['id'], $data['vehicle']['id']);
        self::assertSame('manual', $data['vehicleSource']);

        // vehicleId null ⇒ retire l'affectation manuelle puis sync (catégorie non vehicleRelated ⇒ rien).
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op->getId().'/vehicle', ['vehicleId' => null]);
        $data = $this->decode($response);
        self::assertNull($data['vehicle']);
        self::assertNull($data['vehicleSource']);

        // Catégorie vehicleRelated + un seul véhicule actif ⇒ null remet l'affectation auto.
        $carburant = $this->category('Transport › Carburant');
        $op2 = $this->addOperation($this->account, '2026-03-02', -5000, 'ESSENCE STATION V', 'STATIONV', $carburant, CategorizationSource::Manual);
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op2->getId().'/vehicle', ['vehicleId' => (int) $vehicle['id']]);
        self::assertSame('manual', $this->decode($response)['vehicleSource']);
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op2->getId().'/vehicle', ['vehicleId' => null]);
        $data = $this->decode($response);
        self::assertSame((int) $vehicle['id'], $data['vehicle']['id']);
        self::assertSame('auto', $data['vehicleSource']);

        // Véhicule inconnu ⇒ 422 sur vehicleId.
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op->getId().'/vehicle', ['vehicleId' => 999999]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('vehicleId', $this->decode($response)['violations'][0]['field']);
    }

    public function testBulkVehicle(): void
    {
        $vehicle = $this->createVehicleViaApi();
        $op1 = $this->addOperation($this->account, '2026-03-01', -100, 'ACHAT CB B1', 'B1');
        $op2 = $this->addOperation($this->account, '2026-03-02', -200, 'ACHAT CB B2', 'B2');
        $client = $this->client();

        $response = $this->json($client, 'POST', '/api/operations/bulk-vehicle', ['ids' => [(int) $op1->getId(), (int) $op2->getId()], 'vehicleId' => (int) $vehicle['id']]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $this->decode($response)['updatedCount']);

        $this->em()->clear();
        foreach ([$op1, $op2] as $op) {
            $reloaded = $this->em()->find(Operation::class, $op->getId());
            self::assertSame((int) $vehicle['id'], (int) $reloaded?->getVehicle()?->getId());
            self::assertSame('manual', $reloaded?->getVehicleSource()?->value);
        }

        // vehicleId null ⇒ affectations manuelles retirées puis sync.
        $response = $this->json($client, 'POST', '/api/operations/bulk-vehicle', ['ids' => [(int) $op1->getId(), (int) $op2->getId()], 'vehicleId' => null]);
        self::assertSame(2, $this->decode($response)['updatedCount']);
        $this->em()->clear();
        foreach ([$op1, $op2] as $op) {
            $reloaded = $this->em()->find(Operation::class, $op->getId());
            self::assertNull($reloaded?->getVehicle());
            self::assertNull($reloaded?->getVehicleSource());
        }
    }

    public function testIgnoreVehicleThenReactivate(): void
    {
        $vehicle = $this->createVehicleViaApi();
        $carburant = $this->category('Transport › Carburant');
        $op = $this->addOperation($this->account, '2026-03-02', -5000, 'ESSENCE STATION I', 'STATIONI', $carburant, CategorizationSource::Manual);
        $client = $this->client();
        $url = '/api/operations/'.(int) $op->getId().'/vehicle';

        // ignore ⇒ sans véhicule, source « ignored », même avec un seul véhicule actif (pas de resync auto).
        $response = $this->json($client, 'POST', $url, ['vehicleId' => null, 'ignore' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertNull($data['vehicle']);
        self::assertSame('ignored', $data['vehicleSource']);

        // Une resynchronisation (ex. modification d'un véhicule) ne la touche pas.
        $this->json($client, 'PATCH', '/api/vehicles/'.(int) $vehicle['id'], ['name' => 'Clio 2']);
        self::assertSame('ignored', $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()))['vehicleSource']);

        // Exclue de « à affecter » et du compteur, listée par vehicleIgnored=1.
        self::assertSame(0, $this->decode($this->json($client, 'GET', '/api/operations?vehicleUnassigned=1'))['total']);
        self::assertSame(0, $this->decode($this->json($client, 'GET', '/api/summary'))['vehicleUnassignedCount']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations?vehicleIgnored=1'));
        self::assertSame(1, $data['total']);
        self::assertSame((int) $op->getId(), $data['items'][0]['id']);

        // ignore + vehicleId ⇒ 422.
        $response = $this->json($client, 'POST', $url, ['vehicleId' => (int) $vehicle['id'], 'ignore' => true]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // vehicleId null sans ignore ⇒ réactivée : l'affectation auto reprend.
        $data = $this->decode($this->json($client, 'POST', $url, ['vehicleId' => null]));
        self::assertSame((int) $vehicle['id'], $data['vehicle']['id']);
        self::assertSame('auto', $data['vehicleSource']);
    }

    public function testBulkIgnoreVehicle(): void
    {
        $this->createVehicleViaApi('Clio');
        $this->createVehicleViaApi('Zoe'); // deux véhicules actifs ⇒ pas d'affectation auto
        $carburant = $this->category('Transport › Carburant');
        $op1 = $this->addOperation($this->account, '2026-03-02', -5000, 'ESSENCE B1', 'STATIONB', $carburant, CategorizationSource::Manual);
        $op2 = $this->addOperation($this->account, '2026-03-03', -4000, 'ESSENCE B2', 'STATIONB', $carburant, CategorizationSource::Manual);
        $client = $this->client();
        self::assertSame(2, $this->decode($this->json($client, 'GET', '/api/summary'))['vehicleUnassignedCount']);

        $response = $this->json($client, 'POST', '/api/operations/bulk-vehicle', ['ids' => [(int) $op1->getId(), (int) $op2->getId()], 'ignore' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $this->decode($response)['updatedCount']);
        self::assertSame(0, $this->decode($this->json($client, 'GET', '/api/summary'))['vehicleUnassignedCount']);
        self::assertSame(2, $this->decode($this->json($client, 'GET', '/api/operations?vehicleIgnored=1'))['total']);

        // Réactivation en masse : de nouveau « à affecter ».
        $this->json($client, 'POST', '/api/operations/bulk-vehicle', ['ids' => [(int) $op1->getId(), (int) $op2->getId()], 'vehicleId' => null]);
        self::assertSame(2, $this->decode($this->json($client, 'GET', '/api/summary'))['vehicleUnassignedCount']);
    }

    public function testListFiltersVehicleIdAndVehicleUnassigned(): void
    {
        $vehicle = $this->createVehicleViaApi();
        $carburant = $this->category('Transport › Carburant');
        $assigned = $this->addOperation($this->account, '2026-03-01', -5000, 'ESSENCE STATION F', 'STATIONF', $carburant, CategorizationSource::Manual);
        $unassigned = $this->addOperation($this->account, '2026-03-02', -3000, 'PEAGE F', 'AUTOROUTEF', $carburant, CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-03-03', -100, 'ACHAT CB F', 'SHOPF'); // non catégorisé
        $managed = $this->em()->find(\App\Entity\Vehicle::class, (int) $vehicle['id']);
        $assigned->assignVehicle($managed, \App\Enum\VehicleSource::Manual);
        $this->em()->flush();

        $client = $this->client();

        // vehicleId : l'opération affectée, et les sommes suivent le filtre.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?vehicleId='.(int) $vehicle['id']));
        self::assertSame(1, $data['total']);
        self::assertSame((int) $assigned->getId(), $data['items'][0]['id']);
        self::assertSame(-5000, $data['sums']['debit']);

        // vehicleUnassigned=1 : catégories vehicleRelated sans véhicule (le non catégorisé exclu).
        $data = $this->decode($this->json($client, 'GET', '/api/operations?vehicleUnassigned=1'));
        self::assertSame(1, $data['total']);
        self::assertSame((int) $unassigned->getId(), $data['items'][0]['id']);
        self::assertSame(-3000, $data['sums']['debit']);

        // Véhicule inconnu ⇒ 400.
        $response = $this->json($client, 'GET', '/api/operations?vehicleId=999999');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_query', $this->decode($response)['error']);
    }

    public function testCategorizeManualSetsAutoVehicleAfterSync(): void
    {
        // Un seul véhicule actif à la date de l'opération.
        $vehicle = $this->createVehicleViaApi();
        $carburant = $this->category('Transport › Carburant');
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE STATION C', 'STATIONC');
        $client = $this->client();

        // Catégorisation manuelle en « Transport › Carburant » : la réponse doit contenir
        // l'affectation auto (sync() écrit hors UnitOfWork ⇒ refresh avant présentation).
        $response = $this->json($client, 'POST', '/api/operations/'.(int) $op->getId().'/categorize', ['categoryId' => (int) $carburant->getId(), 'scope' => 'operation']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('Transport › Carburant', $data['operation']['category']['fullName']);
        self::assertSame('manual', $data['operation']['categorizationSource']);
        self::assertSame((int) $vehicle['id'], $data['operation']['vehicle']['id']);
        self::assertSame('auto', $data['operation']['vehicleSource']);
    }
}
