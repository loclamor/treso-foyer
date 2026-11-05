<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle\Lookup;

use App\Vehicle\Lookup\RapidApiVehicleLookup;
use App\Vehicle\Lookup\VehicleLookupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RapidApiVehicleLookupTest extends TestCase
{
    private const PLAQUE_FICTIVE = 'AB-123-CD';
    private const CLE_TEST = 'cle-test-ne-pas-logger';

    public function testLookupSuccessMapsFieldsAndSendsHeaders(): void
    {
        $body = json_encode([
            'data' => [
                'Marque' => 'Peugeot',
                'Modele' => '308',
                'Version' => 'GT Line',
                'Energie' => 'ESSENCE',
                'Puissance_Fiscale' => '6 CV',
                'Date1erCir' => '12-05-2021',
                'VIN' => 'VF3XXXXXXXXXXXXXXX',
            ],
        ]);
        self::assertIsString($body);

        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $body) {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($body, ['http_code' => 200]);
        });
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        $result = $lookup->lookup(self::PLAQUE_FICTIVE);

        self::assertSame('Peugeot', $result->make);
        self::assertSame('308', $result->model);
        self::assertSame('GT Line', $result->version);
        self::assertSame('petrol', $result->energy);
        self::assertSame(6, $result->fiscalPower);
        self::assertSame('2021-05-12', $result->firstRegistrationAt?->format('Y-m-d'));
        self::assertSame('VF3XXXXXXXXXXXXXXX', $result->vin);
        self::assertSame('Peugeot', $result->raw['data']['Marque']);

        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('GET', $request['method']);
        self::assertStringContainsString('plaque='.self::PLAQUE_FICTIVE, $request['url']);
        $headers = implode("\n", array_map('strval', $request['options']['headers'] ?? []));
        self::assertStringContainsStringIgnoringCase('x-rapidapi-host: api-de-plaque-d-immatriculation-france.p.rapidapi.com', $headers);
        self::assertStringContainsStringIgnoringCase('x-rapidapi-key: '.self::CLE_TEST, $headers);
        self::assertEquals(10, $request['options']['timeout'] ?? null);
    }

    public function testLookupReadsTopLevelKeysInsensitiveToCase(): void
    {
        $body = json_encode([
            'make' => 'Renault',
            'MODEL' => 'Clio',
            'energy' => 'GAZOLE',
            'cv' => 4,
            'firstRegistration' => '2019-11-03',
            'vin' => 'VF1YYYYYYYYYYYYYYY',
        ]);
        self::assertIsString($body);
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        $result = $lookup->lookup(self::PLAQUE_FICTIVE);

        self::assertSame('Renault', $result->make);
        self::assertSame('Clio', $result->model);
        self::assertSame('diesel', $result->energy);
        self::assertSame(4, $result->fiscalPower);
        self::assertSame('2019-11-03', $result->firstRegistrationAt?->format('Y-m-d'));
    }

    public function testNotFoundOn404AndMessageHasNoPlate(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"not found"}', ['http_code' => 404])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        try {
            $lookup->lookup(self::PLAQUE_FICTIVE);
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_NOT_FOUND, $e->reason);
            self::assertStringNotContainsString(self::PLAQUE_FICTIVE, $e->getMessage());
        }
    }

    public function testEmptyBodyOn200IsNotFound(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 200])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        try {
            $lookup->lookup(self::PLAQUE_FICTIVE);
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_NOT_FOUND, $e->reason);
            self::assertStringNotContainsString(self::PLAQUE_FICTIVE, $e->getMessage());
        }
    }

    public function testQuotaOn429(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 429])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        try {
            $lookup->lookup(self::PLAQUE_FICTIVE);
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_QUOTA, $e->reason);
            self::assertStringNotContainsString(self::PLAQUE_FICTIVE, $e->getMessage());
        }
    }

    #[DataProvider('provideUnavailableStatuses')]
    public function testUnavailableOnAuthServerAndClientErrors(int $status): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"x"}', ['http_code' => $status])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        try {
            $lookup->lookup(self::PLAQUE_FICTIVE);
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_UNAVAILABLE, $e->reason);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function provideUnavailableStatuses(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
        yield '500' => [500];
        yield '503' => [503];
    }

    public function testUnavailableOnTransportError(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['error' => 'Could not resolve host'])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        try {
            $lookup->lookup(self::PLAQUE_FICTIVE);
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_UNAVAILABLE, $e->reason);
            self::assertStringNotContainsString(self::PLAQUE_FICTIVE, $e->getMessage());
        }
    }

    public function testInvalidJsonResponse(): void
    {
        $client = new MockHttpClient([new MockResponse('<html>pas du json</html>', ['http_code' => 200])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        try {
            $lookup->lookup(self::PLAQUE_FICTIVE);
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_INVALID_RESPONSE, $e->reason);
        }
    }

    #[DataProvider('provideEnergies')]
    public function testEnergyMapping(string $energie, string $expected): void
    {
        $body = json_encode(['energie' => $energie]);
        self::assertIsString($body);
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        self::assertSame($expected, $lookup->lookup(self::PLAQUE_FICTIVE)->energy);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideEnergies(): iterable
    {
        yield 'essence' => ['ESSENCE', 'petrol'];
        yield 'sans plomb' => ['SP98', 'petrol'];
        yield 'gazole' => ['GAZOLE', 'diesel'];
        yield 'diesel' => ['Diesel', 'diesel'];
        yield 'électrique' => ['ÉLECTRIQUE', 'electric'];
        yield 'hybride' => ['Hybride', 'hybrid'];
        yield 'hybride rechargeable' => ['HYBRIDE RECHARGEABLE', 'plugin_hybrid'];
        yield 'gpl' => ['GPL', 'lpg'];
        yield 'inconnu' => ['Hydrogène', 'other'];
    }

    #[DataProvider('provideDates')]
    public function testDateFormats(string $date, ?string $expected): void
    {
        $body = json_encode(['date_premiere_immatriculation' => $date]);
        self::assertIsString($body);
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);
        $lookup = new RapidApiVehicleLookup($client, self::CLE_TEST);

        self::assertSame($expected, $lookup->lookup(self::PLAQUE_FICTIVE)->firstRegistrationAt?->format('Y-m-d'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function provideDates(): iterable
    {
        yield 'JJ-MM-AAAA' => ['12-05-2021', '2021-05-12'];
        yield 'JJ/MM/AAAA' => ['12/05/2021', '2021-05-12'];
        yield 'AAAA-MM-JJ' => ['2021-05-12', '2021-05-12'];
        yield 'invalide' => ['pas une date', null];
    }

    public function testIsEnabledDependsOnApiKey(): void
    {
        $client = new MockHttpClient();
        self::assertTrue((new RapidApiVehicleLookup($client, self::CLE_TEST))->isEnabled());
        self::assertFalse((new RapidApiVehicleLookup($client, ''))->isEnabled());
        self::assertFalse((new RapidApiVehicleLookup($client, '   '))->isEnabled());
    }
}
