<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Household;
use App\Entity\Operation;
use App\Entity\TaggedPeriod;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\TaggedPeriodType;
use App\Enum\VehicleSource;
use Symfony\Component\HttpFoundation\Response;

final class TaggedPeriodApiTest extends ApiTestCase
{
    public function testCrud(): void
    {
        $client = $this->client();

        // Création 201.
        $response = $this->json($client, 'POST', '/api/tagged-periods', [
            'type' => 'vacation',
            'label' => 'Été 2026',
            'startDate' => '2026-08-01',
            'endDate' => '2026-08-15',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertSame('vacation', $created['type']);
        self::assertSame('Été 2026', $created['label']);
        self::assertSame('2026-08-01', $created['startDate']);
        self::assertSame('2026-08-15', $created['endDate']);
        self::assertGreaterThan(0, $created['id']);

        // Liste (tri startDate puis id).
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods'));
        self::assertCount(1, $data);
        self::assertSame($created['id'], array_values($data)[0]['id']);

        // PATCH partiel : les champs absents ne sont pas modifiés.
        $response = $this->json($client, 'PATCH', '/api/tagged-periods/'.(int) $created['id'], [
            'label' => 'Été 2026 (Corse)',
            'type' => 'outing',
            'endDate' => '2026-08-20',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('outing', $data['type']);
        self::assertSame('Été 2026 (Corse)', $data['label']);
        self::assertSame('2026-08-01', $data['startDate']);
        self::assertSame('2026-08-20', $data['endDate']);

        // DELETE 204.
        $response = $this->json($client, 'DELETE', '/api/tagged-periods/'.(int) $created['id']);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods'));
        self::assertCount(0, $data);
    }

    public function testSingleDayPeriodIsValid(): void
    {
        $client = $this->client();

        $response = $this->json($client, 'POST', '/api/tagged-periods', [
            'type' => 'weekend',
            'label' => 'Journée',
            'startDate' => '2026-05-09',
            'endDate' => '2026-05-09',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertSame('2026-05-09', $created['startDate']);
        self::assertSame('2026-05-09', $created['endDate']);
    }

    public function testValidationErrors(): void
    {
        $client = $this->client();
        $base = ['type' => 'other', 'label' => 'Test', 'startDate' => '2026-06-01', 'endDate' => '2026-06-10'];

        // endDate < startDate ⇒ 422 (callback de l'entité, champ endDate).
        $response = $this->json($client, 'POST', '/api/tagged-periods', array_merge($base, ['endDate' => '2026-05-31']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('validation_failed', $data['error']);
        $fields = array_column($data['violations'], 'field');
        self::assertContains('endDate', $fields);

        // type invalide ⇒ 422 (contrainte du DTO).
        $response = $this->json($client, 'POST', '/api/tagged-periods', array_merge($base, ['type' => 'nope']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // label vide ⇒ 422.
        $response = $this->json($client, 'POST', '/api/tagged-periods', array_merge($base, ['label' => '']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // label > 100 caractères ⇒ 422.
        $response = $this->json($client, 'POST', '/api/tagged-periods', array_merge($base, ['label' => str_repeat('a', 101)]));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertContains('label', array_column($data['violations'], 'field'));

        // startDate invalide ⇒ 422.
        $response = $this->json($client, 'POST', '/api/tagged-periods', array_merge($base, ['startDate' => '2026-02-30']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // PATCH qui casse la cohérence ⇒ 422.
        $response = $this->json($client, 'POST', '/api/tagged-periods', $base);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $id = (int) $this->decode($response)['id'];
        $response = $this->json($client, 'PATCH', '/api/tagged-periods/'.$id, ['endDate' => '2026-05-01']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testOverlapFilterFromTo(): void
    {
        $client = $this->client();
        $create = function (string $label, string $from, string $to) use ($client): int {
            $response = $this->json($client, 'POST', '/api/tagged-periods', [
                'type' => 'other', 'label' => $label, 'startDate' => $from, 'endDate' => $to,
            ]);
            self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

            return (int) $this->decode($response)['id'];
        };
        $a = $create('A', '2026-01-10', '2026-01-20');
        $b = $create('B', '2026-02-01', '2026-02-05');
        $c = $create('C', '2026-01-25', '2026-02-02');

        // Sans filtre : les 3, tri startDate.
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods'));
        self::assertSame([$a, $c, $b], array_column($data, 'id'));

        // from/to : chevauchement [start_date <= to AND end_date >= from] ⇒ A et C, pas B.
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods?from=2026-01-15&to=2026-01-31'));
        self::assertSame([$a, $c], array_column($data, 'id'));

        // from seul : périodes finissant le 2026-01-31 ou après ⇒ C (02-02) et B (02-05), pas A (finit 01-20).
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods?from=2026-01-31'));
        self::assertSame([$c, $b], array_column($data, 'id'));

        // to seul : périodes commençant le 2026-01-31 ou avant ⇒ A et C.
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods?to=2026-01-31'));
        self::assertSame([$a, $c], array_column($data, 'id'));

        // Fenêtre sans chevauchement ⇒ vide.
        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods?from=2026-01-21&to=2026-01-24'));
        self::assertCount(0, $data);

        // Date invalide ⇒ 400 invalid_query.
        $response = $this->json($client, 'GET', '/api/tagged-periods?from=01-01-2026');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('invalid_query', $this->decode($response)['error']);
    }

    public function testUnknownOrForeignIdIs404(): void
    {
        $client = $this->client();

        // Période d'un autre foyer ⇒ invisible (404), même avec un id valide.
        $otherHousehold = new Household('Autre foyer');
        $this->em()->persist($otherHousehold);
        $foreign = new TaggedPeriod(
            $otherHousehold,
            TaggedPeriodType::Weekend,
            'Week-end ailleurs',
            new \DateTimeImmutable('2026-05-09'),
            new \DateTimeImmutable('2026-05-10'),
        );
        $this->em()->persist($foreign);
        $this->em()->flush();
        $foreignId = (int) $foreign->getId();

        $data = $this->decode($this->json($client, 'GET', '/api/tagged-periods'));
        self::assertCount(0, $data);

        $response = $this->json($client, 'PATCH', '/api/tagged-periods/'.$foreignId, ['label' => 'X']);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $response = $this->json($client, 'DELETE', '/api/tagged-periods/'.$foreignId);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        // Id inexistant ⇒ 404.
        $response = $this->json($client, 'PATCH', '/api/tagged-periods/999999', ['label' => 'X']);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $response = $this->json($client, 'DELETE', '/api/tagged-periods/999999');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testPeriodVehicleAssignsTransportExpenses(): void
    {
        $client = $this->client();
        // Deux véhicules actifs : aucune affectation automatique possible.
        $clio = $this->createVehicle('Clio');
        $kangoo = $this->createVehicle('Kangoo');

        $carburant = $this->category('Transport › Carburant');
        $credit = $this->category('Transport › Crédit véhicule');
        $inside = $this->addOperation($this->account, '2026-08-05', -6000, 'PLEIN AUTOROUTE', 'AUTOROUTE', $carburant, CategorizationSource::Manual);
        $lastDay = $this->addOperation($this->account, '2026-08-15', -4000, 'PLEIN RETOUR', 'STATION', $carburant, CategorizationSource::Manual);
        $outside = $this->addOperation($this->account, '2026-08-20', -5000, 'PLEIN APRES', 'STATION', $carburant, CategorizationSource::Manual);
        $loan = $this->addOperation($this->account, '2026-08-10', -25000, 'ECHEANCE PRET AUTO', 'BANQUE', $credit, CategorizationSource::Manual);
        $manual = $this->addOperation($this->account, '2026-08-06', -3000, 'PLEIN CLIO', 'STATION', $carburant, CategorizationSource::Manual);
        $managed = $this->em()->find(Operation::class, $manual->getId());
        self::assertNotNull($managed);
        $managed->assignVehicle($this->em()->find(Vehicle::class, $clio), VehicleSource::Manual);
        $this->em()->flush();

        // Création avec véhicule ⇒ resynchronisation immédiate.
        $response = $this->json($client, 'POST', '/api/tagged-periods', [
            'type' => 'vacation',
            'label' => 'Vacances',
            'startDate' => '2026-08-01',
            'endDate' => '2026-08-15',
            'vehicleId' => $kangoo,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $period = $this->decode($response);
        self::assertSame($kangoo, $period['vehicle']['id']);
        self::assertSame('Kangoo', $period['vehicle']['name']);
        self::assertFalse($period['vehicleOverridesRules']);

        self::assertSame([$kangoo, 'period'], $this->vehicleOf($inside));
        self::assertSame([$kangoo, 'period'], $this->vehicleOf($lastDay));   // borne incluse
        self::assertSame([null, null], $this->vehicleOf($outside));          // hors période
        self::assertSame([null, null], $this->vehicleOf($loan));             // financement exclu
        self::assertSame([$clio, 'manual'], $this->vehicleOf($manual));      // manuel intouché

        // Retrait du véhicule ⇒ retour à l'état sans période.
        $response = $this->json($client, 'PATCH', '/api/tagged-periods/'.(int) $period['id'], ['clearVehicle' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNull($this->decode($response)['vehicle']);
        self::assertSame([null, null], $this->vehicleOf($inside));

        // Remise du véhicule (prioritaire sur les règles), puis suppression de la période.
        $response = $this->json($client, 'PATCH', '/api/tagged-periods/'.(int) $period['id'], ['vehicleId' => $clio, 'vehicleOverridesRules' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($this->decode($response)['vehicleOverridesRules']);
        self::assertSame([$clio, 'period'], $this->vehicleOf($inside));

        $response = $this->json($client, 'DELETE', '/api/tagged-periods/'.(int) $period['id']);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame([null, null], $this->vehicleOf($inside));
        self::assertSame([$clio, 'manual'], $this->vehicleOf($manual));
    }

    public function testPeriodVehicleValidation(): void
    {
        $client = $this->client();
        $base = ['type' => 'other', 'label' => 'X', 'startDate' => '2026-05-01', 'endDate' => '2026-05-02'];

        // Véhicule d'un autre foyer ⇒ 422 sur vehicleId.
        $otherHousehold = new Household('Autre foyer');
        $this->em()->persist($otherHousehold);
        $foreign = new Vehicle($otherHousehold, 'Ailleurs');
        $this->em()->persist($foreign);
        $this->em()->flush();
        $response = $this->json($client, 'POST', '/api/tagged-periods', $base + ['vehicleId' => (int) $foreign->getId()]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('vehicleId', $this->decode($response)['violations'][0]['field']);

        // clearVehicle + vehicleId ⇒ 422.
        $id = (int) $this->decode($this->json($client, 'POST', '/api/tagged-periods', $base))['id'];
        $response = $this->json($client, 'PATCH', '/api/tagged-periods/'.$id, ['clearVehicle' => true, 'vehicleId' => 1]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    private function createVehicle(string $name): int
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => $name, 'type' => 'car']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return (int) $this->decode($response)['id'];
    }

    /** @return array{0: ?int, 1: ?string} [id du véhicule, source] lus via l'API */
    private function vehicleOf(Operation $operation): array
    {
        $data = $this->decode($this->json($this->client(), 'GET', '/api/operations/'.(int) $operation->getId()));

        return [$data['vehicle']['id'] ?? null, $data['vehicleSource']];
    }
}
