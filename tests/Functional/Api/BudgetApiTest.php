<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Symfony\Component\HttpFoundation\Response;

final class BudgetApiTest extends ApiTestCase
{
    public function testCrud(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $client = $this->client();

        // Création 201.
        $response = $this->json($client, 'POST', '/api/budgets', [
            'categoryId' => (int) $courses->getId(),
            'monthlyAmount' => 40000,
            'scope' => 'household',
            'startMonth' => '2026-01-01',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $created = $this->decode($response);
        self::assertSame(40000, $created['monthlyAmount']);
        self::assertSame('household', $created['scope']);
        self::assertSame('2026-01-01', $created['startMonth']);
        self::assertNull($created['endMonth']);
        self::assertNull($created['member']);
        self::assertSame('Alimentation › Courses', $created['category']['fullName']);

        // Liste.
        $data = $this->decode($this->json($client, 'GET', '/api/budgets'));
        self::assertCount(1, $data);

        // PATCH.
        $response = $this->json($client, 'PATCH', '/api/budgets/'.(int) $created['id'], [
            'monthlyAmount' => 45000,
            'scope' => 'member',
            'memberId' => (int) $this->member->getId(),
            'endMonth' => '2026-12-01',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(45000, $data['monthlyAmount']);
        self::assertSame('member', $data['scope']);
        self::assertSame('alice', $data['member']['username']);
        self::assertSame('2026-12-01', $data['endMonth']);

        // DELETE 204.
        $response = $this->json($client, 'DELETE', '/api/budgets/'.(int) $created['id']);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $data = $this->decode($this->json($client, 'GET', '/api/budgets'));
        self::assertCount(0, $data);
    }

    public function testValidationErrors(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $client = $this->client();

        // Portée member sans memberId ⇒ 422 (callback de l'entité).
        $response = $this->json($client, 'POST', '/api/budgets', [
            'categoryId' => (int) $courses->getId(),
            'monthlyAmount' => 40000,
            'scope' => 'member',
            'startMonth' => '2026-01-01',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('validation_failed', $data['error']);
        $fields = array_column($data['violations'], 'field');
        self::assertContains('memberId', $fields);

        // Montant non positif ⇒ 422 (contrainte du DTO).
        $response = $this->json($client, 'POST', '/api/budgets', [
            'categoryId' => (int) $courses->getId(),
            'monthlyAmount' => -5,
            'scope' => 'household',
            'startMonth' => '2026-01-01',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // Catégorie inconnue ⇒ 422.
        $response = $this->json($client, 'POST', '/api/budgets', [
            'categoryId' => 9999,
            'monthlyAmount' => 1000,
            'scope' => 'household',
            'startMonth' => '2026-01-01',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }
}
