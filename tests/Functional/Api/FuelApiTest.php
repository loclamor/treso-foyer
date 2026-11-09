<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\OdometerReading;
use App\Entity\Vehicle;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use App\Enum\VehicleSource;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pleins de carburant (ADR 0015) : PUT/DELETE /api/operations/{id}/fuel, champs plein de
 * POST /api/operations, présentation `fuel`, GET /api/vehicles/{id}/fuel (pleins + synthèse),
 * point de compteur du plein dans `lastOdometer`, copie du plein au merge de doublon,
 * flag `fuel` des catégories. Données fictives uniquement.
 */
final class FuelApiTest extends ApiTestCase
{
    /** @return array<string, mixed> véhicule créé via l'API (201 attendu) */
    private function createVehicle(string $name = 'Clio'): array
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => $name, 'type' => 'car', 'acquiredAt' => '2026-01-01']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return $this->decode($response);
    }

    /** Affecte manuellement le véhicule à l'opération (même entity manager que addOperation). */
    private function assignVehicle(\App\Entity\Operation $operation, array $vehicle): void
    {
        $managed = $this->em()->find(Vehicle::class, (int) $vehicle['id']);
        self::assertNotNull($managed);
        $operation->assignVehicle($managed, VehicleSource::Manual);
        $this->em()->flush();
    }

    public function testPutFuelReturnsOperationWithFuelAndReplaces(): void
    {
        $vehicle = $this->createVehicle();
        $op = $this->addOperation($this->account, '2026-03-10', -6795, 'ACHAT CB STATION 10.03.26', 'STATION');
        $this->assignVehicle($op, $vehicle);
        $id = (int) $op->getId();

        $client = $this->client();
        // Sans plein : fuel null dans la présentation.
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.$id));
        self::assertNull($data['fuel']);

        // PUT avec volume + km (fullTank défaut true).
        $response = $this->json($client, 'PUT', "/api/operations/$id/fuel", ['volumeMl' => 45000, 'odometerKm' => 48200]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame($id, $data['operation']['id']);
        self::assertSame([
            'volumeMl' => 45000,
            'fullTank' => true,
            'odometerKm' => 48200,
            'pricePerLiter' => 1.51, // 6795 c × 10 / 45000 mL
        ], $data['operation']['fuel']);
        self::assertArrayNotHasKey('warning', $data); // pas de points voisins ⇒ pas d'avertissement

        // PUT remplace : plein partiel, km effacé.
        $response = $this->json($client, 'PUT', "/api/operations/$id/fuel", ['volumeMl' => 30000, 'fullTank' => false]);
        $data = $this->decode($response);
        self::assertSame(30000, $data['operation']['fuel']['volumeMl']);
        self::assertFalse($data['operation']['fuel']['fullTank']);
        self::assertNull($data['operation']['fuel']['odometerKm']);
        self::assertEqualsWithDelta(2.265, $data['operation']['fuel']['pricePerLiter'], 1e-9); // 6795 × 10 / 30000
    }

    public function testPutFuelOnCreditReturns400NotADebit(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', 5000, 'REMBOURSEMENT STATION', 'STATION');
        $response = $this->json($this->client(), 'PUT', '/api/operations/'.(int) $op->getId().'/fuel', ['volumeMl' => 40000]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('not_a_debit', $this->decode($response)['error']);
    }

    public function testPutFuelValidation422(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE', 'STATION');
        $url = '/api/operations/'.(int) $op->getId().'/fuel';
        $client = $this->client();

        // volumeMl absent ⇒ 422 sur volumeMl.
        $response = $this->json($client, 'PUT', $url, ['odometerKm' => 1000]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('volumeMl', $this->decode($response)['violations'][0]['field']);

        // volumeMl ≤ 0 ⇒ 422 sur volumeMl.
        $response = $this->json($client, 'PUT', $url, ['volumeMl' => 0]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('volumeMl', $this->decode($response)['violations'][0]['field']);

        // odometerKm < 0 ⇒ 422 sur odometerKm.
        $response = $this->json($client, 'PUT', $url, ['volumeMl' => 40000, 'odometerKm' => -5]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('odometerKm', $this->decode($response)['violations'][0]['field']);
    }

    public function testDeleteFuelReturnsNullFuel(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE', 'STATION');
        $id = (int) $op->getId();
        $client = $this->client();
        $this->json($client, 'PUT', "/api/operations/$id/fuel", ['volumeMl' => 40000, 'odometerKm' => 1000]);

        $response = $this->json($client, 'DELETE', "/api/operations/$id/fuel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertNull($data['operation']['fuel']);

        // Persisté : le GET renvoie aussi fuel null.
        $data = $this->decode($this->json($client, 'GET', "/api/operations/$id"));
        self::assertNull($data['fuel']);
    }

    public function testPatchManualEntryToCreditClearsFuel(): void
    {
        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -6000,
            'label' => 'ESSENCE',
            'fuelVolumeMl' => 40000,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $id = $this->decode($response)['operation']['id'];

        $data = $this->decode($this->json($client, 'PATCH', "/api/operations/$id", ['amount' => 6000]));
        self::assertSame(6000, $data['amount']);
        self::assertNull($data['fuel']);
    }

    public function testPutFuelWarnsWhenOdometerIncoherentWithNeighbors(): void
    {
        $vehicle = $this->createVehicle();
        $vid = (int) $vehicle['id'];
        $client = $this->client();
        $this->json($client, 'POST', "/api/vehicles/$vid/odometer", ['date' => '2026-01-10', 'km' => 45000]);
        $this->json($client, 'POST', "/api/vehicles/$vid/odometer", ['date' => '2026-03-15', 'km' => 48200]);

        $op = $this->addOperation($this->account, '2026-02-01', -5000, 'ESSENCE', 'STATION');
        $this->assignVehicle($op, $vehicle);
        $url = '/api/operations/'.(int) $op->getId().'/fuel';

        // km < relevé précédent ⇒ warning (point de l'opération exclu de la comparaison).
        $response = $this->json($client, 'PUT', $url, ['volumeMl' => 40000, 'odometerKm' => 44000]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertArrayHasKey('warning', $data);
        self::assertStringContainsString('incohérent', $data['warning']);
        self::assertSame(44000, $data['operation']['fuel']['odometerKm']); // le plein est quand même enregistré

        // km > relevé suivant ⇒ warning.
        $response = $this->json($client, 'PUT', $url, ['volumeMl' => 40000, 'odometerKm' => 49000]);
        self::assertArrayHasKey('warning', $this->decode($response));

        // km cohérent (entre les voisins) ⇒ pas de warning.
        $response = $this->json($client, 'PUT', $url, ['volumeMl' => 40000, 'odometerKm' => 46000]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertArrayNotHasKey('warning', $this->decode($response));
    }

    public function testPutFuelWithoutVehicleHasNoWarning(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE', 'STATION');
        $response = $this->json($this->client(), 'PUT', '/api/operations/'.(int) $op->getId().'/fuel', ['volumeMl' => 40000, 'odometerKm' => 44000]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(44000, $data['operation']['fuel']['odometerKm']);
        self::assertArrayNotHasKey('warning', $data); // pas de véhicule ⇒ pas de contrôle de cohérence
    }

    public function testPostOperationWithFuel(): void
    {
        $vehicle = $this->createVehicle();
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -5000,
            'label' => 'ESSENCE STATION 10.03.26',
            'vehicleId' => (int) $vehicle['id'],
            'fuelVolumeMl' => 40000,
            'odometerKm' => 1000,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertFalse($data['reconciled']);
        self::assertSame([
            'volumeMl' => 40000,
            'fullTank' => true, // défaut quand un volume est fourni
            'odometerKm' => 1000,
            'pricePerLiter' => 1.25, // 5000 c × 10 / 40000 mL
        ], $data['operation']['fuel']);
    }

    public function testPostOperationOdometerWithoutVolumeReturns422(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -5000,
            'label' => 'ESSENCE',
            'odometerKm' => 1000,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('odometerKm', $data['violations'][0]['field']);
    }

    public function testPostOperationFuelOnCreditReturns422OnFuelVolumeMl(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => 5000,
            'label' => 'REMBOURSEMENT',
            'fuelVolumeMl' => 40000,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('fuelVolumeMl', $this->decode($response)['violations'][0]['field']);
    }

    public function testPostOperationFuelVolumeNotPositiveReturns422(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -5000,
            'label' => 'ESSENCE',
            'fuelVolumeMl' => -10,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('fuelVolumeMl', $this->decode($response)['violations'][0]['field']);
    }

    public function testGetVehicleFuelReturnsFillsAndSummary(): void
    {
        $vehicle = $this->createVehicle();
        $vid = (int) $vehicle['id'];

        $op1 = $this->addOperation($this->account, '2026-01-10', -6000, 'ESSENCE 1', 'STATION');
        $this->assignVehicle($op1, $vehicle);
        $op2 = $this->addOperation($this->account, '2026-02-10', -6800, 'ESSENCE 2', 'STATION');
        $this->assignVehicle($op2, $vehicle);

        $client = $this->client();
        $this->json($client, 'PUT', '/api/operations/'.(int) $op1->getId().'/fuel', ['volumeMl' => 40000, 'odometerKm' => 10000]);
        $this->json($client, 'PUT', '/api/operations/'.(int) $op2->getId().'/fuel', ['volumeMl' => 45000, 'odometerKm' => 10800]);

        $response = $this->json($client, 'GET', "/api/vehicles/$vid/fuel");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);

        // Pleins triés date desc.
        self::assertCount(2, $data['fills']);
        $fill2 = $data['fills'][0];
        self::assertSame((int) $op2->getId(), $fill2['operationId']);
        self::assertSame('2026-02-10', $fill2['date']);
        self::assertSame(-6800, $fill2['amount']);
        self::assertSame(45000, $fill2['volumeMl']);
        self::assertTrue($fill2['fullTank']);
        self::assertSame(10800, $fill2['odometerKm']);
        self::assertEqualsWithDelta(1.511, $fill2['pricePerLiter'], 1e-9);
        self::assertEqualsWithDelta(5.63, $fill2['consumption'], 1e-9); // 45 L / 800 km × 100
        self::assertEqualsWithDelta(800.0, $fill2['segmentKm'], 1e-9);
        self::assertSame('ESSENCE 2', $fill2['label']);
        self::assertFalse($fill2['awaitingStatement']);

        // Premier plein complet : pas de segment.
        $fill1 = $data['fills'][1];
        self::assertSame((int) $op1->getId(), $fill1['operationId']);
        self::assertNull($fill1['consumption']);
        self::assertNull($fill1['segmentKm']);
        self::assertEqualsWithDelta(1.5, $fill1['pricePerLiter'], 1e-9);

        $summary = $data['summary'];
        self::assertSame(2, $summary['count']);
        self::assertSame(85000, $summary['volumeMl']);
        self::assertSame(-12800, $summary['amount']);
        self::assertEqualsWithDelta(1.506, $summary['avgPricePerLiter'], 1e-9); // 128 € / 85 L
        self::assertEqualsWithDelta(5.63, $summary['consumption'], 1e-9);
        self::assertSame(800, $summary['distanceKm']);

        // Période : seul le plein de février est affiché, mais le segment (plein précédent hors
        // période) est quand même compté dans la synthèse.
        $data = $this->decode($this->json($client, 'GET', "/api/vehicles/$vid/fuel?from=2026-02-01"));
        self::assertCount(1, $data['fills']);
        self::assertSame((int) $op2->getId(), $data['fills'][0]['operationId']);
        self::assertSame(1, $data['summary']['count']);
        self::assertSame(45000, $data['summary']['volumeMl']);
        self::assertSame(-6800, $data['summary']['amount']);
        self::assertEqualsWithDelta(1.511, $data['summary']['avgPricePerLiter'], 1e-9);
        self::assertEqualsWithDelta(5.63, $data['summary']['consumption'], 1e-9);
        self::assertSame(800, $data['summary']['distanceKm']);

        // Paramètres invalides ⇒ 400.
        $response = $this->json($client, 'GET', "/api/vehicles/$vid/fuel?from=2026-13-01");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_query', $this->decode($response)['error']);
        $response = $this->json($client, 'GET', "/api/vehicles/$vid/fuel?from=2026-03-01&to=2026-01-01");
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testLastOdometerIncludesFuelPoint(): void
    {
        $vehicle = $this->createVehicle();
        $vid = (int) $vehicle['id'];

        // Relevé saisi en janvier.
        $managed = $this->em()->find(Vehicle::class, $vid);
        self::assertNotNull($managed);
        $this->em()->persist(new OdometerReading($managed, new \DateTimeImmutable('2026-01-10'), 45000));
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE', 'STATION');
        $this->assignVehicle($op, $vehicle);
        $this->em()->flush();

        // Plein avec km en mars ⇒ point de compteur le plus récent.
        $this->json($this->client(), 'PUT', '/api/operations/'.(int) $op->getId().'/fuel', ['volumeMl' => 40000, 'odometerKm' => 50000]);

        $data = $this->decode($this->json($this->client(), 'GET', '/api/vehicles'));
        self::assertSame(['date' => '2026-03-10', 'km' => 50000], $data[0]['lastOdometer']);
    }

    public function testDuplicateMergeCopiesFuelToKeptOperation(): void
    {
        $csv = $this->addOperation($this->account, '2026-03-01', -6795, 'ACHAT CB STATION 01.03.26', 'STATION');
        $csv->setLabelSource(ImportFormat::Csv);
        $csv->recordFuel(45000, true, 48200); // le plein est sur l'opération supprimée
        $pdf = $this->addOperation($this->account, '2026-03-01', -6795, 'ACHAT CB STATION DETAIL 01.03.26 CARTE 123', 'STATION');
        $pdf->setLabelSource(ImportFormat::Pdf);
        $pdf->setDuplicateStatus(DuplicateStatus::Probable);
        $pdf->setDuplicateOf($csv);
        $this->em()->flush();

        // merge : l'opération PDF (référence) est conservée, le plein du CSV lui est reporté.
        $response = $this->json($this->client(), 'POST', '/api/operations/'.(int) $pdf->getId().'/duplicate', ['action' => 'merge']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame((int) $pdf->getId(), $data['kept']['id']);
        self::assertSame(45000, $data['kept']['fuel']['volumeMl']);
        self::assertTrue($data['kept']['fuel']['fullTank']);
        self::assertSame(48200, $data['kept']['fuel']['odometerKm']);
    }

    public function testCategoryFuelIsExposedAndEditable(): void
    {
        $client = $this->client();

        // « Transport › Carburant » porte fuel=true par défaut, les autres non.
        $tree = $this->decode($this->json($client, 'GET', '/api/categories'));
        $carburant = null;
        $coursesId = null;
        foreach ($tree as $root) {
            foreach ($root['children'] as $child) {
                if ('Transport' === $root['name'] && 'Carburant' === $child['name']) {
                    $carburant = $child;
                }
                if ('Alimentation' === $root['name'] && 'Courses' === $child['name']) {
                    $coursesId = (int) $child['id'];
                }
            }
        }
        self::assertTrue($carburant['fuel']);
        self::assertFalse($tree[1]['children'][0]['fuel']); // Alimentation › Courses
        self::assertNotNull($coursesId);

        // Création avec fuel=true.
        $transport = $this->category('Transport');
        $response = $this->json($client, 'POST', '/api/categories', ['name' => 'Carburant pro', 'kind' => 'expense', 'parentId' => (int) $transport->getId(), 'fuel' => true]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertTrue($this->decode($response)['fuel']);

        // PATCH true puis false (sans resynchro véhicule).
        $response = $this->json($client, 'PATCH', '/api/categories/'.$coursesId, ['fuel' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($this->decode($response)['fuel']);
        $response = $this->json($client, 'PATCH', '/api/categories/'.$coursesId, ['fuel' => false]);
        self::assertFalse($this->decode($response)['fuel']);
    }
}
