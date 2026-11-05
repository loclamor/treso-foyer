<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\CategorizationRule;
use App\Entity\Vehicle;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Enum\VehicleSource;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/categorization/run : la réponse contient la clé « vehicles » (rapport
 * d'affectation des véhicules, compteurs uniquement).
 */
final class CategorizationRunApiTest extends ApiTestCase
{
    public function testRunReturnsVehiclesReportAndAssignsVehicle(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $vehicle = new Vehicle($this->household, 'Clio');
        $this->em()->persist($vehicle);
        $rule = new CategorizationRule($this->household, $carburant, RuleMatchField::Merchant, RuleMatchType::Exact, 'TOTAL');
        $this->em()->persist($rule);
        $operation = $this->addOperation($this->account, '2026-03-10', -4590, 'ACHAT CB TOTAL 10.03.26', 'TOTAL');
        $this->em()->flush();

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/categorization/run', ['all' => false]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertArrayHasKey('vehicles', $data);
        self::assertSame(
            ['processed', 'byRule', 'auto', 'cleared', 'unchanged'],
            array_keys($data['vehicles']),
        );
        // La règle a catégorisé l'opération (Carburant, vehicleRelated) : un seul véhicule
        // actif ⇒ affectation auto.
        self::assertSame(1, $data['byRule']);
        self::assertSame(1, $data['vehicles']['auto']);
        self::assertSame(0, $data['vehicles']['cleared']);

        // L'affectation est bien écrite en base (source auto).
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT vehicle_id, vehicle_source FROM operation WHERE id = :id',
            ['id' => $operation->getId()],
        );
        self::assertSame($vehicle->getId(), (int) $row['vehicle_id']);
        self::assertSame(VehicleSource::Auto->value, $row['vehicle_source']);
    }

    public function testRunWithoutCandidateStillReturnsVehiclesKey(): void
    {
        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/categorization/run', ['all' => true]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertArrayHasKey('vehicles', $data);
        self::assertSame(0, $data['vehicles']['processed']);
        self::assertSame(0, $data['vehicles']['byRule']);
        self::assertSame(0, $data['vehicles']['auto']);
        self::assertSame(0, $data['vehicles']['cleared']);
        self::assertSame(0, $data['vehicles']['unchanged']);
    }
}
