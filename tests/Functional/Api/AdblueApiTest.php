<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Household;
use App\Entity\OdometerReading;
use App\Entity\Vehicle;
use App\Enum\VehicleSource;
use App\Enum\VehicleType;
use App\Tests\Support\CategorizationFixtures;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remplissages d'AdBlue (ADR 0016) : création station (opération liée, coût réel, affectation
 * véhicule) et bidon (coût estimé), validations, synthèse et consommation, point de compteur,
 * opérations candidates, modification / suppression, isolement du foyer, report du lien à la
 * fusion d'un doublon. Données fictives uniquement.
 */
final class AdblueApiTest extends ApiTestCase
{
    /** @return array<string, mixed> véhicule créé via l'API */
    private function createVehicle(string $name = 'Monospace'): array
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => $name, 'type' => 'car', 'acquiredAt' => '2026-01-01']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return $this->decode($response);
    }

    public function testStationFillLinkedToOperationTakesRealCostAndAssignsVehicle(): void
    {
        $vehicle = $this->createVehicle();
        $op = $this->addOperation($this->account, '2026-03-10', -1890, 'ACHAT CB STATION ADBLUE 10.03.26', 'STATION');
        $client = $this->client();

        $response = $this->json($client, 'POST', '/api/vehicles/'.$vehicle['id'].'/adblue', [
            'date' => '2026-03-10', 'volumeMl' => 10000, 'source' => 'station', 'operationId' => (int) $op->getId(), 'odometerKm' => 50000,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $fill = $this->decode($response);
        self::assertSame('station', $fill['source']);
        self::assertSame((int) $op->getId(), $fill['operation']['id']);
        self::assertSame(1890, $fill['cost']);
        self::assertFalse($fill['costEstimated']);
        self::assertSame(1.89, $fill['pricePerLiter']);
        self::assertArrayNotHasKey('warning', $fill);

        // L'opération est affectée au véhicule (manuelle).
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertSame($vehicle['id'], $data['vehicle']['id']);
        self::assertSame('manual', $data['vehicleSource']);

        // Le kilométrage du remplissage est un point de compteur (dernier km connu du véhicule).
        $list = $this->decode($this->json($client, 'GET', '/api/vehicles'));
        $row = array_values(array_filter($list, static fn (array $v): bool => $v['id'] === $vehicle['id']))[0];
        self::assertSame(50000, $row['lastOdometer']['km']);

        // Même opération pour un second remplissage ⇒ 409.
        $response = $this->json($client, 'POST', '/api/vehicles/'.$vehicle['id'].'/adblue', [
            'date' => '2026-03-10', 'volumeMl' => 2000, 'source' => 'station', 'operationId' => (int) $op->getId(),
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('operation_already_linked', $this->decode($response)['error']);
    }

    public function testCanFillEstimatesCostAndValidations(): void
    {
        $vehicle = $this->createVehicle();
        $client = $this->client();
        $uri = '/api/vehicles/'.$vehicle['id'].'/adblue';

        // Bidon de 10 L à 12,99 €, 4 L versés ⇒ 519,6 c arrondi à 520.
        $response = $this->json($client, 'POST', $uri, ['date' => '2026-04-02', 'volumeMl' => 4000, 'source' => 'can', 'canPrice' => 1299, 'canVolumeMl' => 10000]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $fill = $this->decode($response);
        self::assertSame(520, $fill['cost']);
        self::assertTrue($fill['costEstimated']);
        self::assertNull($fill['operation']);

        // Bidon sans prix : coût inconnu.
        $response = $this->json($client, 'POST', $uri, ['date' => '2026-04-20', 'volumeMl' => 6000, 'source' => 'can']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertNull($this->decode($response)['cost']);

        $op = $this->addOperation($this->account, '2026-04-02', -1299, 'ACHAT CB HYPER 02.04.26', 'HYPER');
        $credit = $this->addOperation($this->account, '2026-04-02', 1299, 'REMBOURSEMENT', 'HYPER');
        $client = $this->client();
        $cases = [
            [['date' => '2026-04-02', 'volumeMl' => 4000, 'source' => 'can', 'operationId' => (int) $op->getId()], 'operationId'],
            [['date' => '2026-04-02', 'volumeMl' => 4000, 'source' => 'station', 'canPrice' => 1299, 'canVolumeMl' => 10000], 'canPrice'],
            [['date' => '2026-04-02', 'volumeMl' => 4000, 'source' => 'can', 'canPrice' => 1299], 'canVolumeMl'],
            [['date' => '2026-04-02', 'volumeMl' => 0, 'source' => 'can'], 'volumeMl'],
            [['date' => '2026-04-02', 'volumeMl' => 4000, 'source' => 'pompe'], 'source'],
            [['date' => '2026-04-02', 'volumeMl' => 4000, 'source' => 'station', 'operationId' => (int) $credit->getId()], 'operationId'],
        ];
        foreach ($cases as [$body, $field]) {
            $response = $this->json($client, 'POST', $uri, $body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), json_encode($body));
            self::assertContains($field, array_column($this->decode($response)['violations'], 'field'), json_encode($body));
        }
    }

    public function testListSummaryAndConsumption(): void
    {
        $vehicle = $this->createVehicle();
        $managed = $this->em()->find(Vehicle::class, (int) $vehicle['id']);
        $this->em()->persist(new OdometerReading($managed, new \DateTimeImmutable('2026-01-01'), 10000));
        $this->em()->persist(new OdometerReading($managed, new \DateTimeImmutable('2026-07-01'), 20000));
        $this->em()->flush();
        $op = $this->addOperation($this->account, '2026-02-10', -2000, 'ACHAT CB STATION 10.02.26', 'STATION');
        $client = $this->client();
        $uri = '/api/vehicles/'.$vehicle['id'].'/adblue';

        $this->json($client, 'POST', $uri, ['date' => '2026-02-10', 'volumeMl' => 10000, 'source' => 'station', 'operationId' => (int) $op->getId()]);
        $this->json($client, 'POST', $uri, ['date' => '2026-05-05', 'volumeMl' => 5000, 'source' => 'can', 'canPrice' => 1000, 'canVolumeMl' => 10000]);
        $this->json($client, 'POST', $uri, ['date' => '2026-09-01', 'volumeMl' => 3000, 'source' => 'can']);

        $data = $this->decode($this->json($client, 'GET', $uri));
        self::assertCount(3, $data['fills']);
        self::assertSame(['2026-09-01', '2026-05-05', '2026-02-10'], array_column($data['fills'], 'date'));
        self::assertSame(3, $data['summary']['count']);
        self::assertSame(18000, $data['summary']['volumeMl']);
        self::assertSame(2000, $data['summary']['cost']);
        self::assertSame(500, $data['summary']['estimatedCost']);
        // Prix moyen sur les remplissages au coût connu : 2500 c / 15 L.
        self::assertSame(round(2500 * 10 / 15000, 3), $data['summary']['avgPricePerLiter']);
        // Fenêtre couverte 01/01 → 01/07 (10 000 km) : 15 L ⇒ 1,5 L/1000 km (le 01/09 est hors fenêtre).
        self::assertSame(1.5, $data['summary']['consumption']);
        self::assertSame(10000, $data['summary']['distanceKm']);

        // Période : seuls les remplissages datés dedans.
        $data = $this->decode($this->json($client, 'GET', $uri.'?from=2026-04-01&to=2026-12-31'));
        self::assertSame(['2026-09-01', '2026-05-05'], array_column($data['fills'], 'date'));
        self::assertSame(0, $data['summary']['cost']);

        $response = $this->json($client, 'GET', $uri.'?from=2026-05-01&to=2026-04-01');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testPatchSwitchSourceAndDelete(): void
    {
        $vehicle = $this->createVehicle();
        $op = $this->addOperation($this->account, '2026-03-10', -1500, 'ACHAT CB STATION 10.03.26', 'STATION');
        $client = $this->client();
        $fill = $this->decode($this->json($client, 'POST', '/api/vehicles/'.$vehicle['id'].'/adblue', [
            'date' => '2026-03-10', 'volumeMl' => 8000, 'source' => 'station', 'operationId' => (int) $op->getId(),
        ]));
        $uri = '/api/adblue-fills/'.$fill['id'];

        // Passage en bidon : l'opération est déliée, prix du bidon appliqué.
        $response = $this->json($client, 'PATCH', $uri, ['source' => 'can', 'canPrice' => 1200, 'canVolumeMl' => 10000, 'odometerKm' => 51000]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('can', $data['source']);
        self::assertNull($data['operation']);
        self::assertSame(960, $data['cost']);
        self::assertSame(51000, $data['odometerKm']);

        // Retour en station avec l'opération : prix du bidon effacé.
        $data = $this->decode($this->json($client, 'PATCH', $uri, ['source' => 'station', 'operationId' => (int) $op->getId(), 'odometerKm' => null]));
        self::assertSame('station', $data['source']);
        self::assertNull($data['canPrice']);
        self::assertSame(1500, $data['cost']);
        self::assertNull($data['odometerKm']);

        // Suppression : l'opération reste affectée au véhicule.
        self::assertSame(Response::HTTP_NO_CONTENT, $this->json($client, 'DELETE', $uri)->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->json($client, 'DELETE', $uri)->getStatusCode());
        $opData = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertSame($vehicle['id'], $opData['vehicle']['id']);
    }

    public function testCandidatesNearDateExcludeLinkedFuelAndCredits(): void
    {
        $vehicle = $this->createVehicle();
        $near = $this->addOperation($this->account, '2026-03-11', -1890, 'ACHAT CB STATION 11.03.26', 'STATION');
        $far = $this->addOperation($this->account, '2026-03-30', -2500, 'ACHAT CB LOIN', 'LOIN');
        $this->addOperation($this->account, '2026-03-10', 5000, 'VIREMENT RECU', 'X');
        $fuel = $this->addOperation($this->account, '2026-03-10', -6000, 'ACHAT CB GASOIL', 'STATION');
        $fuel->recordFuel(40000, true, null);
        $linked = $this->addOperation($this->account, '2026-03-12', -1000, 'ACHAT CB STATION 12.03.26', 'STATION');
        $this->em()->flush();
        $client = $this->client();
        $fill = $this->decode($this->json($client, 'POST', '/api/vehicles/'.$vehicle['id'].'/adblue', [
            'date' => '2026-03-12', 'volumeMl' => 5000, 'source' => 'station', 'operationId' => (int) $linked->getId(),
        ]));

        $uri = '/api/vehicles/'.$vehicle['id'].'/adblue/candidates?date=2026-03-10';
        $ids = array_column($this->decode($this->json($client, 'GET', $uri)), 'id');
        self::assertSame([(int) $near->getId()], $ids);
        self::assertNotContains((int) $far->getId(), $ids);

        // En modification, l'opération déjà liée au remplissage reste proposée.
        $ids = array_column($this->decode($this->json($client, 'GET', $uri.'&fillId='.$fill['id'])), 'id');
        self::assertSame([(int) $near->getId(), (int) $linked->getId()], $ids);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->json($client, 'GET', '/api/vehicles/'.$vehicle['id'].'/adblue/candidates')->getStatusCode());
    }

    public function testOtherHouseholdIsolation(): void
    {
        $em = $this->em();
        $other = new Household('Autre foyer');
        $em->persist($other);
        $otherVehicle = new Vehicle($other, 'Autre', VehicleType::Car);
        $em->persist($otherVehicle);
        $otherAccount = CategorizationFixtures::makeAccount($other, 'autre-compte');
        $em->persist($otherAccount);
        $em->flush();
        $foreignOp = $this->addOperation($otherAccount, '2026-03-10', -1000, 'ACHAT CB STATION', 'STATION');
        $vehicle = $this->createVehicle();
        $client = $this->client();

        self::assertSame(Response::HTTP_NOT_FOUND, $this->json($client, 'GET', '/api/vehicles/'.(int) $otherVehicle->getId().'/adblue')->getStatusCode());
        $response = $this->json($client, 'POST', '/api/vehicles/'.$vehicle['id'].'/adblue', [
            'date' => '2026-03-10', 'volumeMl' => 5000, 'source' => 'station', 'operationId' => (int) $foreignOp->getId(),
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testDuplicateMergeMovesLinkToKeptOperation(): void
    {
        $vehicle = $this->createVehicle();
        $kept = $this->addOperation($this->account, '2026-03-10', -1890, 'ACHAT CB STATION 10.03.26', 'STATION');
        $removed = $this->addOperation($this->account, '2026-03-10', -1890, 'ACHAT CB STATION 10/03', 'STATION');
        $removed->setDuplicateOf($kept);
        $removed->setDuplicateStatus(\App\Enum\DuplicateStatus::Probable);
        $removed->assignVehicle($this->em()->find(Vehicle::class, (int) $vehicle['id']), VehicleSource::Manual);
        $this->em()->flush();
        $client = $this->client();
        $fill = $this->decode($this->json($client, 'POST', '/api/vehicles/'.$vehicle['id'].'/adblue', [
            'date' => '2026-03-10', 'volumeMl' => 10000, 'source' => 'station', 'operationId' => (int) $removed->getId(),
        ]));

        $response = $this->json($client, 'POST', '/api/operations/'.(int) $removed->getId().'/duplicate', ['action' => 'merge']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $keptId = $this->decode($response)['kept']['id'];

        $data = $this->decode($this->json($client, 'GET', '/api/vehicles/'.$vehicle['id'].'/adblue'));
        self::assertSame($fill['id'], $data['fills'][0]['id']);
        self::assertSame($keptId, $data['fills'][0]['operation']['id']);
    }
}
