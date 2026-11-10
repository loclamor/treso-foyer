<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use Symfony\Component\HttpFoundation\Response;

/**
 * Achats de gaz en bouteille (ADR 0017) : PUT/DELETE /api/operations/{id}/gas, champ gasMassG
 * de POST /api/operations, présentation `gas`, copie au merge de doublon, PATCH vers crédit
 * qui retire l'achat, flag `gas` des catégories (« Logement › Gaz » par défaut).
 * Données fictives uniquement.
 */
final class GasApiTest extends ApiTestCase
{
    public function testPutGasReturnsOperationWithGasAndReplaces(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', -3990, 'ACHAT CB INTERMARCHE 10.03.26', 'INTERMARCHE');
        $id = (int) $op->getId();

        $client = $this->client();
        // Sans achat de gaz : gas null dans la présentation.
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.$id));
        self::assertNull($data['gas']);

        // PUT : bonbonne 13 kg ⇒ prix au kg = 3990 c × 10 / 13000 g = 3,069 €/kg.
        $response = $this->json($client, 'PUT', "/api/operations/$id/gas", ['massG' => 13000]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame($id, $data['operation']['id']);
        self::assertSame([
            'massG' => 13000,
            'pricePerKg' => 3.069,
        ], $data['operation']['gas']);

        // PUT remplace : bonbonne 6 kg.
        $response = $this->json($client, 'PUT', "/api/operations/$id/gas", ['massG' => 6000]);
        $data = $this->decode($response);
        self::assertSame(6000, $data['operation']['gas']['massG']);
        self::assertEqualsWithDelta(6.65, $data['operation']['gas']['pricePerKg'], 1e-9); // 3990 × 10 / 6000
    }

    public function testPutGasOnCreditReturns400NotADebit(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', 5000, 'REMBOURSEMENT INTERMARCHE', 'INTERMARCHE');
        $response = $this->json($this->client(), 'PUT', '/api/operations/'.(int) $op->getId().'/gas', ['massG' => 13000]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('not_a_debit', $this->decode($response)['error']);
    }

    public function testPutGasValidation422(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', -3990, 'ACHAT CB INTERMARCHE', 'INTERMARCHE');
        $url = '/api/operations/'.(int) $op->getId().'/gas';
        $client = $this->client();

        // massG absent ⇒ 422 sur massG.
        $response = $this->json($client, 'PUT', $url, []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('massG', $this->decode($response)['violations'][0]['field']);

        // massG ≤ 0 ⇒ 422 sur massG.
        $response = $this->json($client, 'PUT', $url, ['massG' => 0]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('massG', $this->decode($response)['violations'][0]['field']);
    }

    public function testDeleteGasReturnsNullGas(): void
    {
        $op = $this->addOperation($this->account, '2026-03-10', -3990, 'ACHAT CB INTERMARCHE', 'INTERMARCHE');
        $id = (int) $op->getId();
        $client = $this->client();
        $this->json($client, 'PUT', "/api/operations/$id/gas", ['massG' => 13000]);

        $response = $this->json($client, 'DELETE', "/api/operations/$id/gas");
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertNull($data['operation']['gas']);

        // Persisté : le GET renvoie aussi gas null.
        $data = $this->decode($this->json($client, 'GET', '/api/operations/'.$id));
        self::assertNull($data['gas']);
    }

    public function testPostOperationWithGas(): void
    {
        $gaz = $this->category('Logement › Gaz');
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -3990,
            'label' => 'BONBONNE GAZ 13KG INTERMARCHE',
            'categoryId' => (int) $gaz->getId(),
            'gasMassG' => 13000,
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertFalse($data['reconciled']);
        self::assertSame([
            'massG' => 13000,
            'pricePerKg' => 3.069,
        ], $data['operation']['gas']);
        self::assertSame('Logement › Gaz', $data['operation']['category']['fullName']);
    }

    public function testPostOperationGasOnCreditReturns422OnGasMassG(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => 3990,
            'label' => 'REMBOURSEMENT',
            'gasMassG' => 13000,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('gasMassG', $this->decode($response)['violations'][0]['field']);
    }

    public function testPostOperationGasMassNotPositiveReturns422(): void
    {
        $response = $this->json($this->client(), 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -3990,
            'label' => 'BONBONNE GAZ',
            'gasMassG' => -10,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('gasMassG', $this->decode($response)['violations'][0]['field']);
    }

    public function testPatchManualEntryToCreditClearsGas(): void
    {
        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/operations', [
            'accountId' => (int) $this->account->getId(),
            'spentAt' => '2026-03-10',
            'amount' => -3990,
            'label' => 'BONBONNE GAZ 13KG',
            'gasMassG' => 13000,
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $id = $this->decode($response)['operation']['id'];

        $data = $this->decode($this->json($client, 'PATCH', "/api/operations/$id", ['amount' => 3990]));
        self::assertSame(3990, $data['amount']);
        self::assertNull($data['gas']);
    }

    public function testDuplicateMergeCopiesGasToKeptOperation(): void
    {
        $csv = $this->addOperation($this->account, '2026-03-01', -3990, 'ACHAT CB INTERMARCHE 01.03.26', 'INTERMARCHE');
        $csv->setLabelSource(ImportFormat::Csv);
        $csv->recordGas(13000); // la masse est sur l'opération supprimée
        $pdf = $this->addOperation($this->account, '2026-03-01', -3990, 'ACHAT CB INTERMARCHE DETAIL 01.03.26 CARTE 123', 'INTERMARCHE');
        $pdf->setLabelSource(ImportFormat::Pdf);
        $pdf->setDuplicateStatus(DuplicateStatus::Probable);
        $pdf->setDuplicateOf($csv);
        $this->em()->flush();

        // merge : l'opération PDF (référence) est conservée, la masse du CSV lui est reportée.
        $response = $this->json($this->client(), 'POST', '/api/operations/'.(int) $pdf->getId().'/duplicate', ['action' => 'merge']);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame((int) $pdf->getId(), $data['kept']['id']);
        self::assertSame(13000, $data['kept']['gas']['massG']);
        self::assertEqualsWithDelta(3.069, $data['kept']['gas']['pricePerKg'], 1e-9);
    }

    public function testCategoryGasIsExposedAndEditable(): void
    {
        $client = $this->client();

        // « Logement › Gaz » porte gas=true par défaut, « Logement › Électricité » non.
        $tree = $this->decode($this->json($client, 'GET', '/api/categories'));
        $gaz = null;
        $electriciteId = null;
        foreach ($tree as $root) {
            if ('Logement' !== $root['name']) {
                continue;
            }
            foreach ($root['children'] as $child) {
                if ('Gaz' === $child['name']) {
                    $gaz = $child;
                }
                if ('Électricité' === $child['name']) {
                    $electriciteId = (int) $child['id'];
                }
            }
        }
        self::assertNotNull($gaz);
        self::assertTrue($gaz['gas']);
        self::assertNotNull($electriciteId);

        // PATCH true puis false sur « Logement › Électricité ».
        $response = $this->json($client, 'PATCH', '/api/categories/'.$electriciteId, ['gas' => true]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($this->decode($response)['gas']);
        $response = $this->json($client, 'PATCH', '/api/categories/'.$electriciteId, ['gas' => false]);
        self::assertFalse($this->decode($response)['gas']);

        // Création avec gas=true.
        $logement = $this->category('Logement');
        $response = $this->json($client, 'POST', '/api/categories', ['name' => 'Gaz bouteille pro', 'kind' => 'expense', 'parentId' => (int) $logement->getId(), 'gas' => true]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertTrue($this->decode($response)['gas']);
    }
}
