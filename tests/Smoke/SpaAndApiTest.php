<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SpaAndApiTest extends WebTestCase
{
    public function testSpaShellIsServedOnAnyFrontRoute(): void
    {
        $client = static::createClient();

        foreach (['/', '/operations/123'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSelectorExists('#app');
        }
    }

    public function testHealthEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","database":true}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testUnknownApiRouteIsNotCaughtBySpa(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/nope');

        self::assertResponseStatusCodeSame(404);
    }
}
