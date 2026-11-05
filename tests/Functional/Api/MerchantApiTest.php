<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\CategorizationSource;
use Symfony\Component\HttpFoundation\Response;

final class MerchantApiTest extends ApiTestCase
{
    public function testGroupsByMerchantAndDirection(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB INTERMARCHE 05.01.26', 'INTERMARCHE');
        $this->addOperation($this->account, '2026-02-05', -14900, 'ACHAT CB INTERMARCHE 05.02.26', 'INTERMARCHE');
        $this->addOperation($this->account, '2026-02-06', 50000, 'VIREMENT REÇU INTERMARCHE', 'INTERMARCHE');
        $this->addOperation($this->account, '2026-02-07', -120, 'PRELEVEMENT DE FOURNISSEUR ENERGIE', null);
        $this->addOperation($this->account, '2026-02-08', -120, 'ACHAT CB INTERMARCHE 08.02.26', 'INTERMARCHE', $courses, CategorizationSource::Manual);

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/merchants'));

        self::assertSame(3, $data['total']); // INTERMARCHE débit, INTERMARCHE crédit, libellé sans commerçant
        $byKey = [];
        foreach ($data['items'] as $item) {
            $byKey[$item['key'].'|'.$item['direction']] = $item;
        }
        $debit = $byKey['INTERMARCHE|debit'];
        self::assertSame('INTERMARCHE', $debit['merchant']);
        self::assertSame(3, $debit['count']);
        self::assertSame(-15820, $debit['total']);
        self::assertSame(-14900, $debit['minAmount']);
        self::assertSame(-120, $debit['maxAmount']);
        self::assertSame('2026-01-05', $debit['firstDate']);
        self::assertSame('2026-02-08', $debit['lastDate']);
        self::assertNotEmpty($debit['sampleLabel']);
        // Répartition par catégorie : 1 manuelle (Courses), 2 non catégorisées (null).
        self::assertCount(2, $debit['categories']);

        // uncategorized=1 : compteurs limités aux opérations non catégorisées.
        $data = $this->decode($this->json($client, 'GET', '/api/merchants?uncategorized=1'));
        $debit = null;
        foreach ($data['items'] as $item) {
            if ('INTERMARCHE' === $item['key'] && 'debit' === $item['direction']) {
                $debit = $item;
            }
        }
        self::assertNotNull($debit);
        self::assertSame(2, $debit['count']);
        self::assertSame([['category' => null, 'count' => 2]], $debit['categories']);

        // q filtre les groupes.
        $data = $this->decode($this->json($client, 'GET', '/api/merchants?q=intermarche'));
        self::assertSame(2, $data['total']);

        // tri par total.
        $data = $this->decode($this->json($client, 'GET', '/api/merchants?sort=total&order=desc'));
        self::assertSame(50000, $data['items'][0]['total']);
    }

    public function testSuggestionFromSimilarManualOperation(): void
    {
        $courses = $this->category('Alimentation › Courses');
        // Opération manuelle de référence, même signe.
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB INTERMARCHE SUPERCENTRE 05.01.26', 'INTERMARCHE SUPERCENTRE', $courses, CategorizationSource::Manual);
        // Groupe à catégoriser, commerçant proche.
        $this->addOperation($this->account, '2026-02-05', -900, 'ACHAT CB INTERMARCHE SUPERCENTRE 05.02.26', 'INTERMARCHE SUPERCENTRE');

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/merchants'));
        $group = null;
        foreach ($data['items'] as $item) {
            if ('INTERMARCHE SUPERCENTRE' === $item['key'] && 'debit' === $item['direction']) {
                $group = $item;
            }
        }
        self::assertNotNull($group);
        self::assertNotNull($group['suggestion']);
        self::assertSame('similarity', $group['suggestion']['source']);
        self::assertSame('Alimentation › Courses', $group['suggestion']['category']['fullName']);
    }

    public function testCategorizeGroupCreatesRuleAndUpdatesNonManualOperations(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB CARREFOUR 05.01.26', 'CARREFOUR');
        $this->addOperation($this->account, '2026-02-05', -900, 'ACHAT CB CARREFOUR 05.02.26', 'CARREFOUR');
        $manual = $this->addOperation($this->account, '2026-03-05', -100, 'ACHAT CB CARREFOUR 05.03.26', 'CARREFOUR', $this->category('Alimentation › Boulangerie & snacks'), CategorizationSource::Manual);

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/merchants/categorize', [
            'key' => 'CARREFOUR',
            'direction' => 'debit',
            'categoryId' => (int) $courses->getId(),
            'createRule' => true,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(2, $data['updatedCount']); // 2 non manuelles mises à jour
        self::assertNotNull($data['rule']);
        self::assertSame('CARREFOUR', $data['rule']['pattern']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(\App\Entity\Operation::class)->findBy(['merchant' => 'CARREFOUR']);
        foreach ($reloaded as $operation) {
            if ((int) $operation->getId() === (int) $manual->getId()) {
                self::assertSame('manual', $operation->getCategorizationSource()?->value); // manuelle intacte
            } else {
                self::assertSame((int) $courses->getId(), (int) $operation->getCategory()?->getId());
            }
        }
    }

    public function testCategorizeGroupWithoutMerchantUsesLabelRule(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $this->addOperation($this->account, '2026-01-05', -800, 'PRELEVEMENT DE FOURNISSEUR ENERGIE', null);
        $this->addOperation($this->account, '2026-02-05', -810, 'PRELEVEMENT DE FOURNISSEUR ENERGIE', null);

        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/merchants/categorize', [
            'key' => 'PRELEVEMENT DE FOURNISSEUR ENERGIE',
            'direction' => 'debit',
            'categoryId' => (int) $courses->getId(),
            'createRule' => true,
        ]);
        $data = $this->decode($response);
        self::assertSame(2, $data['updatedCount']);
        self::assertSame('label', $data['rule']['matchField']);
        self::assertSame('exact', $data['rule']['matchType']);
        self::assertSame('PRELEVEMENT DE FOURNISSEUR ENERGIE', $data['rule']['pattern']);
    }
}
