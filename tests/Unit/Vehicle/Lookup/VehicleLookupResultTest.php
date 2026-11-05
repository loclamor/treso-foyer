<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle\Lookup;

use App\Vehicle\Lookup\VehicleLookupResult;
use PHPUnit\Framework\TestCase;

final class VehicleLookupResultTest extends TestCase
{
    public function testToArrayFormatsDateAsYmdAndKeepsRaw(): void
    {
        $result = new VehicleLookupResult(
            make: 'Peugeot',
            model: '308',
            version: null,
            energy: 'diesel',
            fiscalPower: 6,
            firstRegistrationAt: new \DateTimeImmutable('2021-05-12'),
            vin: 'VF3XXXXXXXXXXXXXXX',
            raw: ['data' => ['Marque' => 'Peugeot']],
        );

        self::assertSame([
            'make' => 'Peugeot',
            'model' => '308',
            'version' => null,
            'energy' => 'diesel',
            'fiscalPower' => 6,
            'firstRegistrationAt' => '2021-05-12',
            'vin' => 'VF3XXXXXXXXXXXXXXX',
            'raw' => ['data' => ['Marque' => 'Peugeot']],
        ], $result->toArray());
    }

    public function testToArrayWithNullDate(): void
    {
        $result = new VehicleLookupResult(null, null, null, null, null, null, null, []);

        $array = $result->toArray();

        self::assertNull($array['firstRegistrationAt']);
        self::assertSame([], $array['raw']);
    }
}
