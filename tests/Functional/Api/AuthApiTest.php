<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Symfony\Component\HttpFoundation\Response;

final class AuthApiTest extends ApiTestCase
{
    public function testLoginOkReturnsMember(): void
    {
        $client = $this->client(false);
        $response = $this->json($client, 'POST', '/api/login', ['username' => 'alice', 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame('alice', $data['username']);
        self::assertSame('Alice', $data['displayName']);
        self::assertArrayHasKey('id', $data);
    }

    public function testLoginKoReturns401InvalidCredentials(): void
    {
        $client = $this->client(false);
        $response = $this->json($client, 'POST', '/api/login', ['username' => 'alice', 'password' => 'mauvais']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function testMeRequiresAuthenticationThenReturnsMember(): void
    {
        $client = $this->client(false);
        $response = $this->json($client, 'GET', '/api/me');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('unauthenticated', $this->decode($response)['error']);

        $client = $this->client();
        $response = $this->json($client, 'GET', '/api/me');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('alice', $this->decode($response)['username']);
    }

    public function testProtectedRouteRequiresAuthentication(): void
    {
        $client = $this->client(false);
        $client->request('GET', '/api/operations');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertSame('unauthenticated', $this->decode($client->getResponse())['error']);
    }

    public function testLogoutReturns204ThenMeIs401(): void
    {
        $client = $this->client();
        $response = $this->json($client, 'POST', '/api/logout');
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        $response = $this->json($client, 'GET', '/api/me');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testHealthIsPublic(): void
    {
        $client = $this->client(false);
        $client->request('GET', '/api/health');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(['status' => 'ok', 'database' => true], $this->decode($client->getResponse()));
    }

    public function testWriteWithoutJsonContentTypeReturns415(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/operations/1/categorize', [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello');
        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $client->getResponse()->getStatusCode());
        self::assertSame('unsupported_media_type', $this->decode($client->getResponse())['error']);
    }

    public function testWriteWithoutRequestedWithHeaderIsForbidden(): void
    {
        $client = $this->client();
        // Requête « simple » (sans corps, sans en-tête personnalisé) : ce qu'une page d'un autre
        // site pourrait envoyer avec le cookie de session.
        $client->setServerParameter('HTTP_X_REQUESTED_WITH', '');
        $client->request('POST', '/api/transfers/confirm-all');
        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        self::assertSame('missing_requested_with', $this->decode($client->getResponse())['error']);

        // Les lectures ne sont pas concernées.
        $client->request('GET', '/api/operations');
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function testInvalidJsonBodyReturns400(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/operations/bulk-categorize', [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid');
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertSame('invalid_json', $this->decode($client->getResponse())['error']);
    }

    public function testUnknownOperationReturns404Json(): void
    {
        $client = $this->client();
        $response = $this->json($client, 'GET', '/api/operations/9999');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('not_found', $this->decode($response)['error']);
    }
}
