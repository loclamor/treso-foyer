<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle\Lookup;

use App\Vehicle\Lookup\NullVehicleLookup;
use App\Vehicle\Lookup\VehicleLookupException;
use PHPUnit\Framework\TestCase;

final class NullVehicleLookupTest extends TestCase
{
    public function testIsDisabled(): void
    {
        self::assertFalse((new NullVehicleLookup())->isEnabled());
    }

    public function testLookupThrowsUnavailableWithoutPlateInMessage(): void
    {
        $lookup = new NullVehicleLookup();

        try {
            $lookup->lookup('AB-123-CD');
            self::fail('VehicleLookupException attendue.');
        } catch (VehicleLookupException $e) {
            self::assertSame(VehicleLookupException::REASON_UNAVAILABLE, $e->reason);
            self::assertSame('Aucun service de recherche par plaque n’est configuré.', $e->getMessage());
            self::assertStringNotContainsString('AB-123-CD', $e->getMessage());
        }
    }
}
