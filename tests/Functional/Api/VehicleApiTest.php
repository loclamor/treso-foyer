<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\CategorizationRule;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Enum\VehicleType;
use App\Tests\Support\FakeVehicleLookup;
use App\Vehicle\Lookup\VehicleLookupException;
use App\Vehicle\Lookup\VehicleLookupResult;
use Symfony\Component\HttpFoundation\Response;

/**
 * API véhicules (lot 3) : CRUD, conflits 409, plaque 422, lookup (faux service
 * substitué dans le conteneur de test), resynchronisation de l'axe véhicule.
 * Données fictives uniquement (plaque AB-123-CD).
 */
final class VehicleApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeVehicleLookup::reset();
    }

    /** @return array<string, mixed> véhicule créé via l'API (201 attendu) */
    private function createVehicle(array $body = []): array
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', $body + ['name' => 'Voiture test', 'type' => 'car']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        return $this->decode($response);
    }

    public function testListDefaultHidesArchived(): void
    {
        $v1 = $this->createVehicle(['name' => 'Voiture A']);
        $this->createVehicle(['name' => 'Voiture B', 'archived' => true]);

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/vehicles'));
        self::assertCount(1, $data);
        self::assertSame($v1['id'], $data[0]['id']);
        self::assertSame(0, $data[0]['operationsCount']);
        self::assertNull($data[0]['lastOdometer']);
        // lookupData (donnée privée) jamais exposé.
        self::assertArrayNotHasKey('lookupData', $data[0]);
        self::assertArrayHasKey('lookupAt', $data[0]);

        // ?archived=1 : les archivés sont inclus.
        $data = $this->decode($this->json($client, 'GET', '/api/vehicles?archived=1'));
        self::assertCount(2, $data);
    }

    public function testCreateNormalizesPlateAndPersistsFields(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', [
            'name' => 'Clio',
            'type' => 'car',
            'plate' => 'ab 123 cd',
            'make' => 'Renault',
            'model' => 'Clio 5',
            'energy' => 'diesel',
            'fiscalPower' => 5,
            'firstRegistrationAt' => '2020-05-10',
            'vin' => 'VF1AAAAAAA1234567',
            'acquiredAt' => '2020-06-01',
            'purchasePrice' => 1500000,
            'mainDriverId' => (int) $this->member->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('AB-123-CD', $data['plate']); // normalisée SIV
        self::assertSame('Renault', $data['make']);
        self::assertSame('diesel', $data['energy']);
        self::assertSame(5, $data['fiscalPower']);
        self::assertSame('2020-05-10', $data['firstRegistrationAt']);
        self::assertSame('VF1AAAAAAA1234567', $data['vin']);
        self::assertSame('2020-06-01', $data['acquiredAt']);
        self::assertNull($data['disposedAt']);
        self::assertSame(1500000, $data['purchasePrice']);
        self::assertSame((int) $this->member->getId(), $data['mainDriver']['id']);
        self::assertFalse($data['archived']);
        self::assertNull($data['lookupAt']);
        self::assertSame(0, $data['operationsCount']);
        self::assertNull($data['lastOdometer']);
    }

    public function testCreateDuplicateNameOrPlateConflicts(): void
    {
        $this->createVehicle(['name' => 'Clio', 'plate' => 'AB-123-CD']);
        $client = $this->client();

        // Nom en doublon ⇒ 409.
        $response = $this->json($client, 'POST', '/api/vehicles', ['name' => 'Clio', 'type' => 'car']);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('name_exists', $this->decode($response)['error']);

        // Plaque en doublon (même plaque saisie autrement) ⇒ 409.
        $response = $this->json($client, 'POST', '/api/vehicles', ['name' => 'Autre', 'type' => 'car', 'plate' => 'ab123cd']);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('plate_exists', $this->decode($response)['error']);
    }

    public function testCreateInvalidPlateReturns422WithoutPlateInMessage(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => 'Clio', 'plate' => 'PASUNEPLACE']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('validation_failed', $data['error']);
        self::assertSame('plate', $data['violations'][0]['field']);
        // Donnée privée : la plaque saisie ne doit jamais apparaître dans la sortie.
        self::assertStringNotContainsString('PASUNEPLACE', (string) $response->getContent());
    }

    public function testPatchEmptyStringResetsNullableFields(): void
    {
        $vehicle = $this->createVehicle([
            'name' => 'Clio',
            'plate' => 'AB-123-CD',
            'make' => 'Renault',
            'model' => 'Clio 4',
            'vin' => 'VF1AAAAAAA1234567',
            'acquiredAt' => '2020-06-01',
            'mainDriverId' => (int) $this->member->getId(),
        ]);

        $client = $this->client();
        // Chaîne vide = remise à null (dates et textes) ; mainDriverId null explicite = plus de conducteur ;
        // champs absents = inchangés.
        $response = $this->json($client, 'PATCH', '/api/vehicles/'.(int) $vehicle['id'], [
            'plate' => '',
            'make' => '',
            'vin' => '',
            'acquiredAt' => '',
            'mainDriverId' => null,
            'model' => 'Clio 5',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertNull($data['plate']);
        self::assertNull($data['make']);
        self::assertNull($data['vin']);
        self::assertNull($data['acquiredAt']);
        self::assertNull($data['mainDriver']);
        self::assertSame('Clio 5', $data['model']);
        self::assertSame('Clio', $data['name']); // absent = inchangé
    }

    public function testDelete204Or409WhenUsed(): void
    {
        $free = $this->createVehicle(['name' => 'Libre']);
        $used = $this->createVehicle(['name' => 'Utilisée', 'plate' => 'AB-123-CD']);

        // Règle rattachée au véhicule ⇒ 409 (message proposant l'archivage).
        $household = $this->em()->find(\App\Entity\Household::class, (int) $this->household->getId());
        $rule = new CategorizationRule($household, $this->category('Alimentation › Courses'), RuleMatchField::Merchant, RuleMatchType::Exact, 'SHOPV');
        $vehicle = $this->em()->find(Vehicle::class, (int) $used['id']);
        self::assertNotNull($vehicle);
        $rule->setVehicle($vehicle);
        $this->em()->persist($rule);
        $this->em()->flush();

        $client = $this->client();
        $response = $this->json($client, 'DELETE', '/api/vehicles/'.(int) $used['id']);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('vehicle_in_use', $data['error']);
        self::assertStringContainsString('archive', $data['message']);

        // Véhicule libre ⇒ 204.
        $response = $this->json($client, 'DELETE', '/api/vehicles/'.(int) $free['id']);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->em()->clear();
        self::assertNull($this->em()->find(Vehicle::class, (int) $free['id']));
    }

    public function testDelete409WhenOperationAssigned(): void
    {
        $vehicle = $this->createVehicle(['name' => 'Clio']);
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE STATION', 'STATION');
        $managed = $this->em()->find(Vehicle::class, (int) $vehicle['id']);
        $op->assignVehicle($managed, \App\Enum\VehicleSource::Manual);
        $this->em()->flush();

        $response = $this->json($this->client(), 'DELETE', '/api/vehicles/'.(int) $vehicle['id']);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('vehicle_in_use', $this->decode($response)['error']);
    }

    public function testLookupSuccessReturnsSuggestionWithoutRaw(): void
    {
        FakeVehicleLookup::$enabled = true;
        FakeVehicleLookup::$result = new VehicleLookupResult(
            'Renault', 'Clio 5', 'TCe 90', 'petrol', 5,
            new \DateTimeImmutable('2020-05-10'), 'VF1AAAAAAA1234567',
            ['marque' => 'RENAULT', 'modele' => 'CLIO 5'],
        );

        $response = $this->json($this->client(), 'POST', '/api/vehicles/lookup', ['plate' => 'ab-123-cd']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('AB-123-CD', $data['plate']);
        self::assertSame('Renault', $data['suggestion']['make']);
        self::assertSame('Clio 5', $data['suggestion']['model']);
        self::assertSame('petrol', $data['suggestion']['energy']);
        self::assertSame(5, $data['suggestion']['fiscalPower']);
        self::assertSame('2020-05-10', $data['suggestion']['firstRegistrationAt']);
        self::assertSame('VF1AAAAAAA1234567', $data['suggestion']['vin']);
        self::assertArrayNotHasKey('raw', $data['suggestion']);
        self::assertSame(1, FakeVehicleLookup::$calls);
    }

    public function testLookupDisabledReturns503(): void
    {
        FakeVehicleLookup::$enabled = false;
        $response = $this->json($this->client(), 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD']);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('lookup_disabled', $this->decode($response)['error']);
        self::assertSame(0, FakeVehicleLookup::$calls);
    }

    public function testLookupErrorsAreMapped(): void
    {
        FakeVehicleLookup::$enabled = true;
        $client = $this->client();

        FakeVehicleLookup::$exception = new VehicleLookupException('Aucun véhicule trouvé pour cette plaque.', VehicleLookupException::REASON_NOT_FOUND);
        $response = $this->json($client, 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD']);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('not_found', $this->decode($response)['error']);

        FakeVehicleLookup::$exception = new VehicleLookupException('Quota atteint.', VehicleLookupException::REASON_QUOTA);
        $response = $this->json($client, 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD']);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('quota', $this->decode($response)['error']);

        FakeVehicleLookup::$exception = new VehicleLookupException('Service indisponible.', VehicleLookupException::REASON_UNAVAILABLE);
        $response = $this->json($client, 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD']);
        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertSame('unavailable', $this->decode($response)['error']);

        FakeVehicleLookup::$exception = new VehicleLookupException('Réponse illisible.', VehicleLookupException::REASON_INVALID_RESPONSE);
        $response = $this->json($client, 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD']);
        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertSame('invalid_response', $this->decode($response)['error']);
    }

    public function testLookupInvalidPlateReturns422(): void
    {
        FakeVehicleLookup::$enabled = true;
        $response = $this->json($this->client(), 'POST', '/api/vehicles/lookup', ['plate' => '!!!']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('plate', $this->decode($response)['violations'][0]['field']);
        self::assertSame(0, FakeVehicleLookup::$calls); // jamais appelé
    }

    public function testLookupWithVehicleIdRecordsLookupData(): void
    {
        $vehicle = $this->createVehicle(['name' => 'Clio', 'make' => 'Renault']);
        FakeVehicleLookup::$enabled = true;
        FakeVehicleLookup::$result = new VehicleLookupResult(
            'Renault', 'Clio 5', null, 'diesel', 6, null, null,
            ['marque' => 'RENAULT', 'puissance_fiscale' => 6],
        );

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD', 'vehicleId' => (int) $vehicle['id']]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em()->clear();
        $reloaded = $this->em()->find(Vehicle::class, (int) $vehicle['id']);
        self::assertSame(['marque' => 'RENAULT', 'puissance_fiscale' => 6], $reloaded?->getLookupData());
        self::assertNotNull($reloaded?->getLookupAt());
        // Les autres champs du véhicule ne sont pas modifiés.
        self::assertSame('Clio', $reloaded?->getName());
        self::assertSame('Renault', $reloaded?->getMake());
        self::assertNull($reloaded?->getPlate());

        // lookupData n'est jamais exposé par l'API ; lookupAt oui.
        $data = $this->decode($this->json($client, 'GET', '/api/vehicles'));
        self::assertArrayNotHasKey('lookupData', $data[0]);
        self::assertNotNull($data[0]['lookupAt']);
    }

    public function testLookupWithUnknownVehicleIdReturns422(): void
    {
        FakeVehicleLookup::$enabled = true;
        FakeVehicleLookup::$result = new VehicleLookupResult('Renault', 'Clio', null, null, null, null, null, []);
        $response = $this->json($this->client(), 'POST', '/api/vehicles/lookup', ['plate' => 'AB-123-CD', 'vehicleId' => 999999]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('vehicleId', $this->decode($response)['violations'][0]['field']);
    }

    public function testLookupStatus(): void
    {
        $client = $this->client();
        self::assertSame(['enabled' => false], $this->decode($this->json($client, 'GET', '/api/vehicles/lookup/status')));
        FakeVehicleLookup::$enabled = true;
        self::assertSame(['enabled' => true], $this->decode($this->json($client, 'GET', '/api/vehicles/lookup/status')));
    }

    public function testVehicleCreationSyncsAutoAssignment(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $op = $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE STATION', 'STATION', $carburant, CategorizationSource::Manual);

        $client = $this->client();
        // Aucun véhicule ⇒ aucune affectation.
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertNull($data['vehicle']);
        self::assertNull($data['vehicleSource']);

        // Véhicule cédé avant la date de l'opération ⇒ toujours rien.
        $this->createVehicle(['name' => 'Ancienne', 'acquiredAt' => '2020-01-01', 'disposedAt' => '2026-03-01']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertNull($data['vehicle']);

        // Un seul véhicule actif à la date de l'opération ⇒ affectation auto après création.
        $vehicle = $this->createVehicle(['name' => 'Clio', 'acquiredAt' => '2026-01-01']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertSame((int) $vehicle['id'], $data['vehicle']['id']);
        self::assertSame('auto', $data['vehicleSource']);

        // Le compteur d'opérations du véhicule est exposé dans la liste.
        $vehicles = $this->decode($this->json($client, 'GET', '/api/vehicles'));
        $clio = null;
        foreach ($vehicles as $row) {
            if ((int) $vehicle['id'] === $row['id']) {
                $clio = $row;
            }
        }
        self::assertSame(1, $clio['operationsCount']);
    }

    public function testLastOdometerIsExposed(): void
    {
        $vehicle = $this->createVehicle(['name' => 'Clio']);
        $managed = $this->em()->find(Vehicle::class, (int) $vehicle['id']);
        self::assertNotNull($managed);
        $this->em()->persist(new \App\Entity\OdometerReading($managed, new \DateTimeImmutable('2026-01-10'), 45000));
        $this->em()->persist(new \App\Entity\OdometerReading($managed, new \DateTimeImmutable('2026-03-15'), 48200));
        $this->em()->flush();

        $data = $this->decode($this->json($this->client(), 'GET', '/api/vehicles'));
        self::assertSame(['date' => '2026-03-15', 'km' => 48200], $data[0]['lastOdometer']);
    }

    public function testSummaryExposesVehicleUnassignedCount(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $commun = $this->category('Transport › Transports en commun'); // pas vehicleRelated
        $this->addOperation($this->account, '2026-03-10', -5000, 'ESSENCE', 'STATION', $carburant, CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-03-11', -3000, 'BUS', 'BUS', $commun, CategorizationSource::Manual);

        $data = $this->decode($this->json($this->client(), 'GET', '/api/summary'));
        self::assertSame(1, $data['vehicleUnassignedCount']);
    }

    public function testVehicleRefShapeOnOperation(): void
    {
        $vehicle = $this->createVehicle(['name' => 'Scooter', 'type' => 'motorcycle']);
        $op = $this->addOperation($this->account, '2026-03-10', -500, 'ACHAT CB SCOOT', 'SCOOT');
        $managed = $this->em()->find(Vehicle::class, (int) $vehicle['id']);
        $op->assignVehicle($managed, \App\Enum\VehicleSource::Manual);
        $this->em()->flush();

        $data = $this->decode($this->json($this->client(), 'GET', '/api/operations/'.(int) $op->getId()));
        // VehicleRef = {id, name, type, archived}.
        self::assertSame(
            ['id' => (int) $vehicle['id'], 'name' => 'Scooter', 'type' => 'motorcycle', 'archived' => false],
            $data['vehicle'],
        );
        self::assertSame('manual', $data['vehicleSource']);
    }
}
