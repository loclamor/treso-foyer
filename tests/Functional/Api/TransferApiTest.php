<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\InternalTransfer;
use Symfony\Component\HttpFoundation\Response;

final class TransferApiTest extends ApiTestCase
{
    public function testMatchListAndUnlink(): void
    {
        // Deux opérations de montant opposé, comptes différents, dates proches.
        $this->addOperation($this->account, '2026-01-10', -50000, 'VIREMENT DE COMPTE JOINT', null);
        $this->addOperation($this->jointAccount, '2026-01-12', 50000, 'VIREMENT REÇU DE CCP', null);

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/transfers/match');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(1, $data['matched']);

        // Liste paginée.
        $response = $this->json($client, 'GET', '/api/transfers');
        $data = $this->decode($response);
        self::assertSame(1, $data['total']);
        self::assertSame('auto', $data['items'][0]['status']);
        self::assertCount(2, $data['items'][0]['operations']);
        $ids = array_map(static fn (array $op): int => $op['internalTransferId'], $data['items'][0]['operations']);
        self::assertSame([$data['items'][0]['id'], $data['items'][0]['id']], $ids);

        // DELETE défait l'appariement.
        $transferId = (int) $data['items'][0]['id'];
        $response = $this->json($client, 'DELETE', '/api/transfers/'.$transferId);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(InternalTransfer::class)->findAll());
        $operations = $this->em()->getRepository(\App\Entity\Operation::class)->findAll();
        foreach ($operations as $operation) {
            self::assertNull($operation->getInternalTransfer());
            self::assertNull($operation->getCategory());
        }

        // 404 sur un virement inconnu.
        $response = $this->json($client, 'DELETE', '/api/transfers/9999');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }
}
