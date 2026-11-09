<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\CategoryKind;
use App\Enum\CategorizationSource;
use Symfony\Component\HttpFoundation\Response;

final class CategoryApiTest extends ApiTestCase
{
    public function testTreeWithCounts(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP 05.01.26', 'MONSHOP', $courses, CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-01-06', -100, 'ACHAT CB AUTRE 06.01.26', 'AUTRE', $courses, CategorizationSource::Manual);

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/categories'));
        self::assertNotEmpty($data);
        $alimentation = null;
        foreach ($data as $root) {
            if ('Alimentation' === $root['name']) {
                $alimentation = $root;
            }
        }
        self::assertNotNull($alimentation);
        self::assertSame('expense', $alimentation['kind']);
        self::assertNull($alimentation['parentId']);
        $coursesChild = null;
        foreach ($alimentation['children'] as $child) {
            if ('Courses' === $child['name']) {
                $coursesChild = $child;
            }
        }
        self::assertSame(2, $coursesChild['operationsCount']);
        self::assertSame('Alimentation › Courses', $coursesChild['fullName']);
        self::assertSame((int) $alimentation['id'], $coursesChild['parentId']);
    }

    public function testCreateDuplicatePatchDelete(): void
    {
        $client = $this->client();

        // Création 201.
        $response = $this->json($client, 'POST', '/api/categories', ['name' => 'Loisirs', 'kind' => 'expense', 'color' => '#ff0000', 'icon' => 'pi pi-star']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertSame('Loisirs', $created['name']);
        self::assertSame(0, $created['operationsCount']);

        // Doublon ⇒ 409.
        $response = $this->json($client, 'POST', '/api/categories', ['name' => 'Loisirs', 'kind' => 'expense']);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('category_exists', $this->decode($response)['error']);

        // PATCH.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $created['id'], ['archived' => true, 'position' => 42]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertTrue($data['archived']);
        self::assertSame(42, $data['position']);

        // DELETE d'une catégorie utilisée ⇒ 409.
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP', 'MONSHOP', $courses, CategorizationSource::Manual);
        $response = $this->json($client, 'DELETE', '/api/categories/'.(int) $courses->getId());
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('category_in_use', $this->decode($response)['error']);

        // DELETE d'une catégorie libre ⇒ 204.
        $response = $this->json($client, 'DELETE', '/api/categories/'.(int) $created['id']);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function testVehicleRelatedFlagAndSync(): void
    {
        $transport = $this->category('Transport');
        // Un seul véhicule actif.
        $response = $this->json($this->client(), 'POST', '/api/vehicles', ['name' => 'Clio', 'type' => 'car', 'acquiredAt' => '2026-01-01']);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $vehicleId = (int) $this->decode($response)['id'];

        $client = $this->client();
        // Création sans le flag.
        $response = $this->json($client, 'POST', '/api/categories', ['name' => 'Parking perso', 'kind' => 'expense', 'parentId' => (int) $transport->getId()]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertFalse($created['vehicleRelated']);

        // Opération dans cette catégorie : non affectée tant que le flag est à false.
        $op = $this->addOperation($this->account, '2026-03-05', -1200, 'PARKING CENTRE', 'PARKING', $this->category('Transport › Parking perso'), CategorizationSource::Manual);
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertNull($data['vehicle']);

        // PATCH vehicleRelated=true ⇒ flag exposé + sync ⇒ affectation auto.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $created['id'], ['vehicleRelated' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($this->decode($response)['vehicleRelated']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertSame($vehicleId, $data['vehicle']['id']);
        self::assertSame('auto', $data['vehicleSource']);

        // PATCH false ⇒ sync ⇒ affectation retirée.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $created['id'], ['vehicleRelated' => false]);
        self::assertFalse($this->decode($response)['vehicleRelated']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.(int) $op->getId()));
        self::assertNull($data['vehicle']);
        self::assertNull($data['vehicleSource']);

        // Le flag est exposé dans l'arbre.
        $tree = $this->decode($this->json($client, 'GET', '/api/categories'));
        $found = null;
        foreach ($tree as $root) {
            if ('Transport' === $root['name']) {
                foreach ($root['children'] as $child) {
                    if ('Carburant' === $child['name']) {
                        $found = $child;
                    }
                }
            }
        }
        self::assertNotNull($found);
        self::assertTrue($found['vehicleRelated']); // Carburant est vehicleRelated par défaut
    }

    public function testFinancingFlagCreateAndPatch(): void
    {
        $transport = $this->category('Transport');
        $client = $this->client();

        // Création avec le flag (catégorie de LOA personnalisée).
        $response = $this->json($client, 'POST', '/api/categories', ['name' => 'LOA', 'kind' => 'expense', 'parentId' => (int) $transport->getId(), 'vehicleRelated' => true, 'financing' => true]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertTrue($created['financing']);

        // PATCH false ⇒ flag retiré ; type invalide ⇒ 422.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $created['id'], ['financing' => false]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse($this->decode($response)['financing']);
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $created['id'], ['financing' => 'oui']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // « Transport › Crédit véhicule » est un poste de financement par défaut.
        $tree = $this->decode($this->json($client, 'GET', '/api/categories'));
        $found = null;
        foreach ($tree as $root) {
            if ('Transport' === $root['name']) {
                foreach ($root['children'] as $child) {
                    if ('Crédit véhicule' === $child['name']) {
                        $found = $child;
                    }
                }
            }
        }
        self::assertNotNull($found);
        self::assertTrue($found['financing']);
        self::assertTrue($found['vehicleRelated']);
    }
}
