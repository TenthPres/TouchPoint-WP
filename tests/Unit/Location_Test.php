<?php
/**
 * Tests for validation of the Locations setting
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\Location;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for Location::validateSetting(), which cleans up the list of locations an administrator enters.
 *
 * @covers \tp\TouchPointWP\Location
 */
class Location_Test extends TestCase
{
    /**
     * Validate a list of locations and return the result as arrays.
     *
     * @param array $locations
     *
     * @return array
     */
    private static function validate(array $locations): array
    {
        return json_decode(Location::validateSetting(json_encode($locations)), true);
    }

    public function test_validateSetting_aGoodLocationIsUnchanged(): void
    {
        $location = ['name' => 'Main Campus', 'lat' => 40.5, 'lng' => -74.25, 'radius' => 1.5, 'ipAddresses' => ['192.168.1.1', '2001:db8::1']];

        $this->assertSame([$location], self::validate([$location]));
    }

    public function test_validateSetting_coordinatesAreNumbers(): void
    {
        $result = self::validate([['name' => 'A', 'lat' => '40.5', 'lng' => '-74.25', 'radius' => '2.5', 'ipAddresses' => []]]);

        $this->assertSame(40.5, $result[0]['lat']);
        $this->assertSame(-74.25, $result[0]['lng']);
        $this->assertSame(2.5, $result[0]['radius']);
    }

    public function test_validateSetting_coordinatesThatAreNotNumbersAreRemoved(): void
    {
        $result = self::validate([['name' => 'A', 'lat' => '', 'lng' => 'west', 'radius' => null, 'ipAddresses' => []]]);

        $this->assertNull($result[0]['lat']);
        $this->assertNull($result[0]['lng']);
        $this->assertNull($result[0]['radius']);
    }

    public function test_validateSetting_theRadiusIsRoundedToTwoPlaces(): void
    {
        $result = self::validate([['name' => 'A', 'lat' => 1.5, 'lng' => 2.5, 'radius' => 1.23456, 'ipAddresses' => []]]);

        $this->assertSame(1.23, $result[0]['radius']);
    }

    public function test_validateSetting_unknownPropertiesAreRemoved(): void
    {
        $result = self::validate([['name' => 'A', 'lat' => 1.5, 'lng' => 2.5, 'radius' => 3.5, 'ipAddresses' => [], 'bogus' => 'x', 'password' => 'y']]);

        $this->assertSame(['name', 'lat', 'lng', 'radius', 'ipAddresses'], array_keys($result[0]));
    }

    public function test_validateSetting_invalidIpAddressesAreRemoved(): void
    {
        $result = self::validate([[
            'name'        => 'A', 'lat' => 1.5, 'lng' => 2.5, 'radius' => 3.5,
            'ipAddresses' => ['10.0.0.1', 'not an ip', '999.1.1.1', '', '::1', '10.0.0'],
        ]]);

        $this->assertSame(['10.0.0.1', '::1'], $result[0]['ipAddresses']);
    }

    public function test_validateSetting_ipAddressesAreAListEvenAfterRemovals(): void
    {
        $json = Location::validateSetting(json_encode([['name' => 'A', 'lat' => 1.5, 'lng' => 2.5, 'radius' => 3.5, 'ipAddresses' => ['bad', '10.0.0.1']]]));

        $this->assertStringContainsString('"ipAddresses":["10.0.0.1"]', $json);
    }

    public function test_validateSetting_eachLocationIsValidatedSeparately(): void
    {
        $result = self::validate([
            ['name' => 'A', 'lat' => 'x', 'lng' => 2.5, 'radius' => 3.5, 'ipAddresses' => ['bad']],
            ['name' => 'B', 'lat' => 5.5, 'lng' => 6.5, 'radius' => 7.5, 'ipAddresses' => ['1.2.3.4']],
        ]);

        $this->assertNull($result[0]['lat']);
        $this->assertSame([], $result[0]['ipAddresses']);
        $this->assertSame(5.5, $result[1]['lat']);
        $this->assertSame(['1.2.3.4'], $result[1]['ipAddresses']);
    }

    public function test_validateSetting_noLocations(): void
    {
        $this->assertSame('[]', Location::validateSetting('[]'));
    }
}
