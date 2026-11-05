<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Statement;
use App\Enum\CategorizationSource;
use Symfony\Component\HttpFoundation\Response;

final class AccountApiTest extends ApiTestCase
{
    public function testListWithBalanceAndHolders(): void
    {
        // Dernier relevé (clôture) puis opérations postérieures.
        $batch = new \App\Entity\ImportBatch('releve.csv', hash('sha256', 'lot-test-1'), \App\Enum\ImportFormat::Csv, \App\Enum\ImportStatus::Completed);
        $batch->setAccount($this->account);
        $this->em()->persist($batch);
        $statement = new Statement($this->account, $batch, new \DateTimeImmutable('2026-01-31'), 100000);
        $this->em()->persist($statement);
        $this->addOperation($this->account, '2026-01-15', -500, 'ACHAT CB AVANT', 'AVANT');
        $this->addOperation($this->account, '2026-02-10', -2500, 'ACHAT CB APRES', 'APRES', $this->category('Alimentation › Courses'), CategorizationSource::Manual);
        $this->addOperation($this->account, '2026-02-11', 300, 'VIREMENT REÇU', 'RECU');
        $this->em()->flush();

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/accounts'));
        self::assertCount(2, $data);
        $perso = null;
        foreach ($data as $account) {
            if ('ccp-perso' === $account['alias']) {
                $perso = $account;
            }
        }
        self::assertNotNull($perso);
        self::assertSame('personal', $perso['type']);
        self::assertSame('La Banque Postale', $perso['bank']);
        self::assertSame(3, $perso['operationsCount']);
        self::assertSame('2026-01-15', $perso['firstDate']);
        self::assertSame('2026-02-11', $perso['lastDate']);
        // solde = clôture (100000) + opérations postérieures (-2500 + 300)
        self::assertSame(['amount' => 97800, 'date' => '2026-01-31'], $perso['balance']);
        self::assertSame([['id' => (int) $this->member->getId(), 'username' => 'alice', 'displayName' => 'Alice']], $perso['holders']);
        $joint = null;
        foreach ($data as $account) {
            if ('compte-joint' === $account['alias']) {
                $joint = $account;
            }
        }
        self::assertSame('joint', $joint['type']);
        self::assertCount(2, $joint['holders']);
        self::assertNull($joint['balance']); // pas de relevé
    }

    public function testPatchAliasConflictAndHolders(): void
    {
        $client = $this->client();

        // alias en conflit ⇒ 409.
        $response = $this->json($client, 'PATCH', '/api/accounts/'.(int) $this->account->getId(), ['alias' => 'compte-joint']);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('alias_exists', $this->decode($response)['error']);

        // titulaires + type + label.
        $response = $this->json($client, 'PATCH', '/api/accounts/'.(int) $this->account->getId(), [
            'alias' => 'ccp principal',
            'label' => 'Compte principal',
            'type' => 'joint',
            'holderIds' => [(int) $this->member->getId(), (int) $this->otherMember->getId()],
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('ccp principal', $data['alias']);
        self::assertSame('joint', $data['type']);
        self::assertCount(2, $data['holders']);

        // holderId inconnu ⇒ 422.
        $response = $this->json($client, 'PATCH', '/api/accounts/'.(int) $this->account->getId(), ['holderIds' => [9999]]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }
}
