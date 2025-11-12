<?php
/**
 * Integration tests for Geo and Utilities classes
 *
 * @package TouchPointWP\Tests\Integration
 */

namespace tp\TouchPointWP\Tests\Integration;

use Brain\Monkey;
use tp\TouchPointWP\Geo;
use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Integration test case for Geo and Utilities working together.
 *
 * @covers \tp\TouchPointWP\Geo
 * @covers \tp\TouchPointWP\Utilities
 */
class GeoUtilitiesIntegrationTest extends TestCase
{
    /**
     * Test that Geo distance calculations work with Utilities date/time functions.
     * This tests integration between geographic and temporal utilities.
     */
    public function test_geo_distance_with_datetime_calculations(): void
    {
        // Get current datetime from Utilities
        $now = Utilities::dateTimeNow();
        $this->assertInstanceOf(\DateTimeImmutable::class, $now);

        // Use Geo to calculate distance between two points
        $distance = Geo::distance(40.7128, -74.0060, 34.0522, -118.2437);
        
        // Verify the distance calculation is reasonable (NY to LA)
        $this->assertGreaterThan(2400, $distance);
        $this->assertLessThan(2500, $distance);

        // Verify datetime is available for logging/tracking purposes
        $this->assertNotNull($now->getTimestamp());
    }

    /**
     * Test creating Geo objects with timezone-aware datetimes from Utilities.
     */
    public function test_geo_object_creation_with_timezone_utilities(): void
    {
        $utcZone = Utilities::utcTimeZone();
        $this->assertInstanceOf(\DateTimeZone::class, $utcZone);
        $this->assertSame('UTC', $utcZone->getName());

        // Create a Geo object representing a location
        $location = new Geo(51.5074, -0.1278, 'London, UK', 'city');
        
        // Verify the Geo object was created correctly
        $this->assertSame(51.5074, $location->lat);
        $this->assertSame(-0.1278, $location->lng);
        $this->assertSame('London, UK', $location->human);
        $this->assertSame('city', $location->type);

        // Get current time in UTC for potential timestamp logging
        $currentTime = Utilities::dateTimeNow()->setTimezone($utcZone);
        $this->assertInstanceOf(\DateTimeImmutable::class, $currentTime);
    }

    /**
     * Test calculating distances between multiple points over time.
     */
    public function test_multiple_distance_calculations_with_time_tracking(): void
    {
        $startTime = Utilities::dateTimeNow();

        // Calculate distances between multiple city pairs
        $distances = [
            'NYC-LA' => Geo::distance(40.7128, -74.0060, 34.0522, -118.2437),
            'London-Paris' => Geo::distance(51.5074, -0.1278, 48.8566, 2.3522),
            'Tokyo-Sydney' => Geo::distance(35.6762, 139.6503, -33.8688, 151.2093),
        ];

        $endTime = Utilities::dateTimeNow();

        // Verify all distances were calculated
        $this->assertCount(3, $distances);
        $this->assertArrayHasKey('NYC-LA', $distances);
        $this->assertArrayHasKey('London-Paris', $distances);
        $this->assertArrayHasKey('Tokyo-Sydney', $distances);

        // Verify distances are reasonable
        $this->assertGreaterThan(2000, $distances['NYC-LA']);
        $this->assertGreaterThan(200, $distances['London-Paris']);
        $this->assertGreaterThan(4800, $distances['Tokyo-Sydney']);

        // Verify timing works (should be same instance due to caching)
        $this->assertSame($startTime, $endTime);
    }

    /**
     * Test converting numeric strings to floats with Geo coordinates.
     */
    public function test_utilities_float_conversion_with_geo_coordinates(): void
    {
        // Convert string coordinates to floats
        $lat = Utilities::toFloatOrNull('40.7128', 4);
        $lng = Utilities::toFloatOrNull('-74.0060', 4);

        $this->assertSame(40.7128, $lat);
        $this->assertSame(-74.0060, $lng);

        // Create Geo object with converted coordinates
        $location = new Geo($lat, $lng, 'New York', 'city');
        
        // Calculate distance to another location
        $distance = Geo::distance(
            $location->lat,
            $location->lng,
            34.0522,
            -118.2437
        );

        $this->assertGreaterThan(2000, $distance);
    }

    /**
     * Test day of week utilities integration with geographic location tracking.
     */
    public function test_day_of_week_utilities_with_geo_locations(): void
    {
        // Get day names
        $monday = Utilities::getPluralDayOfWeekNameForNumber_noI18n(1);
        $this->assertSame('Mondays', $monday);

        // Create locations for a hypothetical event schedule
        $locations = [
            new Geo(40.7128, -74.0060, 'NYC Office', 'office'),
            new Geo(34.0522, -118.2437, 'LA Office', 'office'),
            new Geo(51.5074, -0.1278, 'London Office', 'office'),
        ];

        $this->assertCount(3, $locations);

        // Verify distance between first and last location
        $distance = Geo::distance(
            $locations[0]->lat,
            $locations[0]->lng,
            $locations[2]->lat,
            $locations[2]->lng
        );

        // NYC to London is approximately 3450 miles
        $this->assertGreaterThan(3400, $distance);
        $this->assertLessThan(3500, $distance);
    }

    /**
     * Test date range calculations with geographic data.
     */
    public function test_date_range_with_geographic_tracking(): void
    {
        $today = Utilities::dateTimeTodayAtMidnight();
        $tomorrow = Utilities::dateTimeNowPlus1D();
        $nextWeek = Utilities::dateTimeNowPlus90D();

        // Verify date progression
        $this->assertInstanceOf(\DateTimeImmutable::class, $today);
        $this->assertInstanceOf(\DateTimeImmutable::class, $tomorrow);
        $this->assertInstanceOf(\DateTimeImmutable::class, $nextWeek);

        // Create a route with multiple stops
        $route = [
            ['location' => new Geo(40.7128, -74.0060), 'day' => 0],
            ['location' => new Geo(41.8781, -87.6298), 'day' => 1], // Chicago
            ['location' => new Geo(34.0522, -118.2437), 'day' => 3], // LA
        ];

        $this->assertCount(3, $route);

        // Calculate total distance of route
        $totalDistance = 0;
        for ($i = 0; $i < count($route) - 1; $i++) {
            $totalDistance += Geo::distance(
                $route[$i]['location']->lat,
                $route[$i]['location']->lng,
                $route[$i + 1]['location']->lat,
                $route[$i + 1]['location']->lng
            );
        }

        // Total distance from NYC -> Chicago -> LA should be roughly 2400-2600 miles
        $this->assertGreaterThan(2300, $totalDistance);
        $this->assertLessThan(2700, $totalDistance);
    }
}
