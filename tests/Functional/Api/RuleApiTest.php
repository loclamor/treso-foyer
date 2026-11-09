<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\CategorizationRule;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use Symfony\Component\HttpFoundation\Response;

final class RuleApiTest extends ApiTestCase
{
    public function testListCreatePatchDeleteWithMatchCount(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $op = $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP 05.01.26', 'MONSHOP');
        $rule = new CategorizationRule($this->household, $courses, RuleMatchField::Merchant, RuleMatchType::Exact, 'MONSHOP');
        $rule->setPriority(50);
        $this->em()->persist($rule);
        $op->categorize($courses, CategorizationSource::Rule, $rule);
        $this->em()->flush();

        $client = $this->client();

        // Liste avec matchCount.
        $data = $this->decode($this->json($client, 'GET', '/api/rules'));
        self::assertCount(1, $data);
        self::assertSame(1, $data[0]['matchCount']);
        self::assertSame('MONSHOP', $data[0]['pattern']);

        // Création 201.
        $response = $this->json($client, 'POST', '/api/rules', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'label',
            'matchType' => 'contains',
            'pattern' => 'SUPERMARCHE',
            'direction' => 'debit',
            'amountMin' => 100,
            'amountMax' => 50000,
            'priority' => 10,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertSame('contains', $created['matchType']);
        self::assertSame(0, $created['matchCount']);
        self::assertSame('debit', $created['direction']);

        // Regex invalide ⇒ 422.
        $response = $this->json($client, 'POST', '/api/rules', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'label',
            'matchType' => 'regex',
            'pattern' => '(invalide',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('validation_failed', $data['error']);
        self::assertSame('pattern', $data['violations'][0]['field']);

        // PATCH partiel.
        $response = $this->json($client, 'PATCH', '/api/rules/'.(int) $created['id'], ['enabled' => false, 'priority' => 5]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertFalse($data['enabled']);
        self::assertSame(5, $data['priority']);

        // DELETE : les opérations de la règle sont décatégorisées puis recatégorisées (ici par la règle MONSHOP restante).
        $response = $this->json($client, 'DELETE', '/api/rules/'.(int) $rule->getId());
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->em()->clear();
        $reloaded = $this->em()->find(Operation::class, $op->getId());
        // La règle supprimée ne s'applique plus ; sans autre correspondance l'opération retombe non catégorisée
        // (la règle créée ci-dessus est sur 'label contains SUPERMARCHE', inactive pour MONSHOP).
        self::assertNull($reloaded?->getAppliedRule());
    }

    public function testNegativeAmountBoundsAreRejected(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $response = $this->json($this->client(), 'POST', '/api/rules', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'label',
            'matchType' => 'contains',
            'pattern' => 'MONSHOP',
            'amountMin' => -500,
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertContains('amountMin', array_column($this->decode($response)['violations'], 'field'));
    }

    public function testPreview(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $boulangerie = $this->category('Alimentation › Boulangerie & snacks');
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP 05.01.26', 'MONSHOP', $boulangerie, CategorizationSource::Rule);
        $this->addOperation($this->account, '2026-01-06', -900, 'ACHAT CB MONSHOP 06.01.26', 'MONSHOP', $courses, CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-01-07', -100, 'ACHAT CB AUTRE 07.01.26', 'AUTRE');

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/rules/preview', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'merchant',
            'matchType' => 'exact',
            'pattern' => 'MONSHOP',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(2, $data['matchCount']);
        self::assertSame(1, $data['conflicts']); // l'opération rule/boulangerie, catégorisée autrement
        self::assertSame(1, $data['manualCount']); // l'opération manuelle, comptée à part
        self::assertCount(2, $data['sample']);
        self::assertLessThanOrEqual(10, count($data['sample']));

        // Aperçu avec regex invalide ⇒ 422.
        $response = $this->json($client, 'POST', '/api/rules/preview', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'label',
            'matchType' => 'regex',
            'pattern' => '[a-',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testCategorizationRun(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $rule = new CategorizationRule($this->household, $courses, RuleMatchField::Merchant, RuleMatchType::Exact, 'MONSHOP');
        $this->em()->persist($rule);
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP 05.01.26', 'MONSHOP');
        $this->addOperation($this->account, '2026-01-06', -100, 'ACHAT CB RIEN 06.01.26', 'RIEN');
        $this->em()->flush();

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/categorization/run', ['all' => false]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(2, $data['processed']);
        self::assertSame(1, $data['byRule']);
        self::assertSame(1, $data['stillUncategorized']);
        self::assertSame(50, $data['categorizedPercent']);
    }

    public function testRuleVehicleIdCreatePatchAndSync(): void
    {
        $courses = $this->category('Alimentation › Courses');
        // Véhicule créé via l'API.
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => 'Clio', 'type' => 'car']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $vehicleId = (int) $this->decode($response)['id'];
        $vehicle = $this->em()->find(\App\Entity\Vehicle::class, $vehicleId);

        // Opération catégorisée par une règle (sans véhicule pour l'instant).
        $household = $this->em()->find(\App\Entity\Household::class, (int) $this->household->getId());
        $rule = new CategorizationRule($household, $courses, RuleMatchField::Merchant, RuleMatchType::Exact, 'SHOPV');
        $this->em()->persist($rule);
        $op = $this->addOperation($this->account, '2026-03-01', -800, 'ACHAT CB SHOPV 01.03.26', 'SHOPV');
        $op->categorize($courses, CategorizationSource::Rule, $rule);
        $this->em()->flush();

        $client = $this->client();

        // Création avec vehicleId ⇒ 201, vehicle exposé.
        $response = $this->json($client, 'POST', '/api/rules', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'label',
            'matchType' => 'contains',
            'pattern' => 'SHOPV',
            'vehicleId' => $vehicleId,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertSame($vehicleId, $created['vehicle']['id']);

        // PATCH avec vehicleId null explicite (clé présente) ⇒ retire le véhicule.
        $response = $this->json($client, 'PATCH', '/api/rules/'.(int) $created['id'], ['vehicleId' => null]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNull($this->decode($response)['vehicle']);

        // PATCH sans la clé vehicleId ⇒ inchangé (on le remet d'abord).
        $response = $this->json($client, 'PATCH', '/api/rules/'.(int) $created['id'], ['vehicleId' => $vehicleId]);
        self::assertSame($vehicleId, $this->decode($response)['vehicle']['id']);
        $response = $this->json($client, 'PATCH', '/api/rules/'.(int) $created['id'], ['priority' => 3]);
        self::assertSame($vehicleId, $this->decode($response)['vehicle']['id']);

        // Véhicule inconnu ⇒ 422 sur vehicleId.
        $response = $this->json($client, 'POST', '/api/rules', [
            'categoryId' => (int) $courses->getId(),
            'matchField' => 'label',
            'matchType' => 'contains',
            'pattern' => 'AUTRE',
            'vehicleId' => 999999,
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('vehicleId', $this->decode($response)['violations'][0]['field']);

        // La règle existante (SHOPV) reçoit le véhicule via PATCH ⇒ sync ⇒ l'opération
        // catégorisée par cette règle est affectée au véhicule (source « rule »).
        self::assertNotNull($vehicle);
        $response = $this->json($client, 'PATCH', '/api/rules/'.(int) $rule->getId(), ['vehicleId' => $vehicleId]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertSame($vehicleId, $data['vehicle']['id']);
        self::assertSame('rule', $data['vehicleSource']);
    }
}
