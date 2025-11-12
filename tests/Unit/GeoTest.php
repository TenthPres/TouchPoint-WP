<?php
/**
 * Tests for the Geo class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\Geo;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the Geo class.
 *
 * @covers \tp\TouchPointWP\Geo
 */
class GeoTest extends TestCase
{
    /**
     * Test that Geo object can be instantiated with default values.
     */
    public function test_geo_instantiation_with_defaults(): void
    {
        $geo = new Geo();
        
        $this->assertNull($geo->lat);
        $this->assertNull($geo->lng);
        $this->assertNull($geo->human);
        $this->assertNull($geo->type);
    }

    /**
     * Test that Geo object can be instantiated with specific values.
     */
    public function test_geo_instantiation_with_values(): void
    {
        $lat = 40.7128;
        $lng = -74.0060;
        $human = 'New York, NY';
        $type = 'city';
        
        $geo = new Geo($lat, $lng, $human, $type);
        
        $this->assertSame($lat, $geo->lat);
        $this->assertSame($lng, $geo->lng);
        $this->assertSame($human, $geo->human);
        $this->assertSame($type, $geo->type);
    }

    /**
     * Test distance calculation between two points.
     * Testing with known coordinates (New York to Los Angeles).
     */
    public function test_distance_calculation(): void
    {
        // New York coordinates
        $nyLat = 40.7128;
        $nyLng = -74.0060;
        
        // Los Angeles coordinates
        $laLat = 34.0522;
        $laLng = -118.2437;
        
        $distance = Geo::distance($nyLat, $nyLng, $laLat, $laLng);
        
        // Expected distance is approximately 2451 miles
        // Allow for some rounding variance
        $this->assertEqualsWithDelta(2451, $distance, 10);
    }

    /**
     * Test distance calculation returns zero for same coordinates.
     */
    public function test_distance_calculation_same_point(): void
    {
        $lat = 40.7128;
        $lng = -74.0060;
        
        $distance = Geo::distance($lat, $lng, $lat, $lng);
        
        $this->assertSame(0.0, $distance);
    }

    /**
     * Test distance calculation with nearby points.
     */
    public function test_distance_calculation_nearby_points(): void
    {
        // Two points very close together (approximately 1 mile apart)
        $lat1 = 40.7128;
        $lng1 = -74.0060;
        $lat2 = 40.7260;
        $lng2 = -74.0050;
        
        $distance = Geo::distance($lat1, $lng1, $lat2, $lng2);
        
        // Should be close to 1 mile
        $this->assertGreaterThan(0, $distance);
        $this->assertLessThan(2, $distance);
    }

    /**
     * Test distance calculation with international coordinates.
     */
    public function test_distance_calculation_international(): void
    {
        // London coordinates
        $londonLat = 51.5074;
        $londonLng = -0.1278;
        
        // Paris coordinates
        $parisLat = 48.8566;
        $parisLng = 2.3522;
        
        $distance = Geo::distance($londonLat, $londonLng, $parisLat, $parisLng);
        
        // Expected distance is approximately 213 miles
        $this->assertEqualsWithDelta(213, $distance, 10);
    }

    /**
     * Test that distance calculation handles negative coordinates (Southern/Western hemispheres).
     */
    public function test_distance_calculation_negative_coordinates(): void
    {
        // Sydney, Australia
        $sydneyLat = -33.8688;
        $sydneyLng = 151.2093;
        
        // Melbourne, Australia
        $melbourneLat = -37.8136;
        $melbourneLng = 144.9631;
        
        $distance = Geo::distance($sydneyLat, $sydneyLng, $melbourneLat, $melbourneLng);
        
        // Expected distance is approximately 443 miles
        $this->assertEqualsWithDelta(443, $distance, 10);
    }
}
