<?php

declare(strict_types=1);

namespace IdnoPlugins\Checkin\Tests;

use IdnoPlugins\Checkin\Checkin;

/**
 * The rule of what a viewer may be told of a check-in whose location is protected, on check-ins that are not stored: the edge of the 24 hours on both sides
 * (stored times only: nothing sleeps), exactly what a visitor's position is rounded to, and when the page of a protected check-in last changed.
 */
final class ProtectedLocationRuleTest extends \Tests\IdnoTestCase
{
    private function checkin(int $created, string $latitude = '51.752345', string $longitude = '-1.257712'): Checkin
    {
        $checkin = new Checkin();
        $checkin->anonymity = 'Yes';
        $checkin->placename = 'Quagga Hollow';
        $checkin->address = '17 Okapirow';
        $checkin->lat = $latitude;
        $checkin->long = $longitude;
        $checkin->created = $created;

        return $checkin;
    }

    public function testJustInsideTheDayTheLocationIsProtected(): void
    {
        $checkin = $this->checkin(time() - Checkin::PROTECTED_FOR + 600);

        $this->assertTrue($checkin->isLocationProtected());
        $location = $checkin->locationFor(null);
        $this->assertFalse($location['precise']);
        $this->assertSame('', $location['placename']);
        $this->assertSame('', $location['address']);
    }

    public function testJustOutsideTheDayTheLocationIsNotProtected(): void
    {
        $checkin = $this->checkin(time() - Checkin::PROTECTED_FOR - 600);

        $this->assertFalse($checkin->isLocationProtected());
        $location = $checkin->locationFor(null);
        $this->assertTrue($location['precise']);
        $this->assertSame('Quagga Hollow', $location['placename']);
        $this->assertSame(51.752345, $location['latitude']);
    }

    public function testAVisitorsPositionIsTheCoordinatesRoundedToTwoDecimalPlaces(): void
    {
        $location = $this->checkin(time())->locationFor(null);

        $this->assertSame(51.75, $location['latitude'], 'rounded, not cut to four places, and not the exact position');
        $this->assertSame(-1.26, $location['longitude'], 'rounded away from zero where that is the nearer');
    }

    public function testAPositionOnTheGridIsStillNotTheExactOne(): void
    {
        $location = $this->checkin(time(), '51.75', '-1.26')->locationFor(null);

        $this->assertEqualsWithDelta(51.755, $location['latitude'], 1e-9);
        $this->assertEqualsWithDelta(-1.255, $location['longitude'], 1e-9);
    }

    public function testThePageOfAProtectedCheckinChangesWhenTheProtectionEnds(): void
    {
        $created = time() - Checkin::PROTECTED_FOR - 600;
        $checkin = $this->checkin($created);
        $checkin->updated = $created + 10;

        $this->assertSame($created + Checkin::PROTECTED_FOR, $checkin->lastChangedAt(), 'a copy a visitor kept from inside the day is out of date from the moment the place is shown');
    }

    public function testThePageOfACheckinStillProtectedHasItsLastSavesTime(): void
    {
        $created = time() - 600;
        $checkin = $this->checkin($created);
        $checkin->updated = $created + 10;

        $this->assertSame($created + 10, $checkin->lastChangedAt());
    }

    public function testThePageOfACheckinThatWasNeverProtectedHasItsLastSavesTime(): void
    {
        $created = time() - Checkin::PROTECTED_FOR - 600;
        $checkin = $this->checkin($created);
        $checkin->anonymity = '';
        $checkin->updated = $created + 10;

        $this->assertSame($created + 10, $checkin->lastChangedAt());
    }

    public function testAnEditAfterTheProtectionEndedIsTheLastChange(): void
    {
        $created = time() - Checkin::PROTECTED_FOR - 600;
        $checkin = $this->checkin($created);
        $checkin->updated = $created + Checkin::PROTECTED_FOR + 100;

        $this->assertSame($created + Checkin::PROTECTED_FOR + 100, $checkin->lastChangedAt());
    }
}
