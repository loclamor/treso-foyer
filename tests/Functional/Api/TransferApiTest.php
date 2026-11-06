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

        // Validation unitaire : sort du compteur « à vérifier » et du filtre status=auto.
        $transferId = (int) $data['items'][0]['id'];
        self::assertSame(1, $this->decode($this->json($client, 'GET', '/api/summary'))['pendingTransfersCount']);
        $response = $this->json($client, 'POST', '/api/transfers/'.$transferId.'/confirm');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('confirmed', $this->decode($response)['status']);
        self::assertSame(0, $this->decode($this->json($client, 'GET', '/api/summary'))['pendingTransfersCount']);
        self::assertSame(0, $this->decode($this->json($client, 'GET', '/api/transfers?status=auto'))['total']);
        self::assertSame(1, $this->decode($this->json($client, 'GET', '/api/transfers?status=confirmed'))['total']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->json($client, 'GET', '/api/transfers?status=foo')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->json($client, 'POST', '/api/transfers/9999/confirm')->getStatusCode());

        // DELETE défait l'appariement.
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

    public function testConfirmAll(): void
    {
        $this->addOperation($this->account, '2026-01-10', -50000, 'VIREMENT VERS JOINT A', null);
        $this->addOperation($this->jointAccount, '2026-01-11', 50000, 'VIREMENT RECU A', null);
        $this->addOperation($this->account, '2026-02-10', -12000, 'VIREMENT VERS JOINT B', null);
        $this->addOperation($this->jointAccount, '2026-02-11', 12000, 'VIREMENT RECU B', null);
        $client = $this->client();
        self::assertSame(2, $this->decode($this->json($client, 'POST', '/api/transfers/match'))['matched']);
        self::assertSame(2, $this->decode($this->json($client, 'GET', '/api/summary'))['pendingTransfersCount']);

        $response = $this->json($client, 'POST', '/api/transfers/confirm-all');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(2, $this->decode($response)['confirmedCount']);
        self::assertSame(0, $this->decode($this->json($client, 'GET', '/api/summary'))['pendingTransfersCount']);

        // Idempotent ; un nouvel appariement reste « à vérifier ».
        self::assertSame(0, $this->decode($this->json($client, 'POST', '/api/transfers/confirm-all'))['confirmedCount']);
        $this->addOperation($this->account, '2026-03-10', -3000, 'VIREMENT VERS JOINT C', null);
        $this->addOperation($this->jointAccount, '2026-03-11', 3000, 'VIREMENT RECU C', null);
        $this->json($client, 'POST', '/api/transfers/match');
        self::assertSame(1, $this->decode($this->json($client, 'GET', '/api/summary'))['pendingTransfersCount']);
        self::assertSame(2, $this->decode($this->json($client, 'GET', '/api/transfers?status=confirmed'))['total']);
    }
}
