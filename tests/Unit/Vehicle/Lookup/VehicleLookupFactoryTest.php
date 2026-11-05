<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle\Lookup;

use App\Vehicle\Lookup\NullVehicleLookup;
use App\Vehicle\Lookup\RapidApiVehicleLookup;
use App\Vehicle\Lookup\VehicleLookupFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class VehicleLookupFactoryTest extends TestCase
{
    public function testNoneProviderGivesNullLookup(): void
    {
        $factory = new VehicleLookupFactory('none', new MockHttpClient(), '');

        $lookup = $factory->create();

        self::assertInstanceOf(NullVehicleLookup::class, $lookup);
        self::assertFalse($lookup->isEnabled());
    }

    public function testUnknownProviderFallsBackToNullLookup(): void
    {
        $factory = new VehicleLookupFactory('inconnu', new MockHttpClient(), 'cle-test');

        self::assertInstanceOf(NullVehicleLookup::class, $factory->create());
    }

    public function testRapidapiProviderGivesRapidApiLookup(): void
    {
        $factory = new VehicleLookupFactory('rapidapi', new MockHttpClient(), 'cle-test');

        $lookup = $factory->create();

        self::assertInstanceOf(RapidApiVehicleLookup::class, $lookup);
        self::assertTrue($lookup->isEnabled());
    }

    public function testRapidapiProviderWithEmptyTokenIsDisabled(): void
    {
        $factory = new VehicleLookupFactory('RAPIDAPI', new MockHttpClient(), '');

        $lookup = $factory->create();

        self::assertInstanceOf(RapidApiVehicleLookup::class, $lookup);
        self::assertFalse($lookup->isEnabled());
    }
}
