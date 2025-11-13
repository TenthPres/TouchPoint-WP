<?php
/**
 * Tests for the Http class
 *
 * @package TouchPointWP\Tests\Unit\Utilities
 */

namespace tp\TouchPointWP\Tests\Unit\Utilities;

use tp\TouchPointWP\Utilities\Http;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the Http class.
 * Tests that HTTP status code constants are correctly defined.
 *
 * @covers \tp\TouchPointWP\Utilities\Http
 */
class HttpTest extends TestCase
{
    /**
     * Test 100-level informational status codes.
     */
    public function test_informational_status_codes(): void
    {
        $this->assertSame(100, Http::CONTINUE_);
        $this->assertSame(101, Http::SWITCHING_PROTOCOLS);
    }

    /**
     * Test 200-level success status codes.
     */
    public function test_success_status_codes(): void
    {
        $this->assertSame(200, Http::OK);
        $this->assertSame(201, Http::CREATED);
        $this->assertSame(202, Http::ACCEPTED);
        $this->assertSame(203, Http::NON_AUTHORITATIVE);
        $this->assertSame(204, Http::NO_CONTENT);
        $this->assertSame(205, Http::RESET_CONTENT);
        $this->assertSame(206, Http::PARTIAL_CONTENT);
    }

    /**
     * Test 300-level redirection status codes.
     */
    public function test_redirection_status_codes(): void
    {
        $this->assertSame(300, Http::MULTIPLE_CHOICES);
        $this->assertSame(301, Http::MOVED_PERMANENTLY);
        $this->assertSame(302, Http::SEE_OTHER_GET);
        $this->assertSame(303, Http::NOT_MODIFIED);
        $this->assertSame(307, Http::SEE_OTHER_TEMP);
        $this->assertSame(308, Http::SEE_OTHER);
    }

    /**
     * Test 400-level client error status codes.
     */
    public function test_client_error_status_codes(): void
    {
        $this->assertSame(400, Http::BAD_REQUEST);
        $this->assertSame(401, Http::UNAUTHORIZED);
        $this->assertSame(402, Http::PAYMENT_REQUIRED);
        $this->assertSame(403, Http::FORBIDDEN);
        $this->assertSame(404, Http::NOT_FOUND);
        $this->assertSame(405, Http::METHOD_NOT_ALLOWED);
        $this->assertSame(406, Http::NOT_ACCEPTABLE);
        $this->assertSame(408, Http::REQUEST_TIMEOUT);
        $this->assertSame(409, Http::CONFLICT);
        $this->assertSame(410, Http::GONE);
        $this->assertSame(411, Http::LENGTH_REQUIRED);
        $this->assertSame(412, Http::PRECONDITION_FAILED);
        $this->assertSame(413, Http::REQUEST_ENTITY_TOO_LARGE);
        $this->assertSame(414, Http::REQUEST_URI_TOO_LONG);
        $this->assertSame(415, Http::UNSUPPORTED_MEDIA_TYPE);
        $this->assertSame(416, Http::REQUESTED_RANGE_NOT_SATISFIABLE);
        $this->assertSame(417, Http::EXPECTATION_FAILED);
        $this->assertSame(418, Http::IM_A_TEAPOT);
        $this->assertSame(426, Http::UPGRADE_REQUIRED);
        $this->assertSame(428, Http::PRECONDITION_REQUIRED);
        $this->assertSame(429, Http::TOO_MANY_REQUESTS);
    }

    /**
     * Test 500-level server error status codes.
     */
    public function test_server_error_status_codes(): void
    {
        $this->assertSame(500, Http::SERVER_ERROR);
        $this->assertSame(501, Http::NOT_IMPLEMENTED);
        $this->assertSame(503, Http::SERVICE_UNAVAILABLE);
        $this->assertSame(505, Http::VERSION_NOT_SUPPORTED);
    }

    /**
     * Test that commonly used HTTP status codes are available.
     */
    public function test_common_status_codes_are_available(): void
    {
        // Test some of the most commonly used codes
        $this->assertSame(200, Http::OK);
        $this->assertSame(404, Http::NOT_FOUND);
        $this->assertSame(500, Http::SERVER_ERROR);
        $this->assertSame(401, Http::UNAUTHORIZED);
        $this->assertSame(403, Http::FORBIDDEN);
    }

    /**
     * Test that Easter egg status code is available (I'm a teapot).
     */
    public function test_teapot_status_code(): void
    {
        $this->assertSame(418, Http::IM_A_TEAPOT);
    }

    /**
     * Test that all constants are integers.
     */
    public function test_all_constants_are_integers(): void
    {
        $reflection = new \ReflectionClass(Http::class);
        $constants = $reflection->getConstants();
        
        foreach ($constants as $name => $value) {
            $this->assertIsInt($value, "Constant {$name} should be an integer");
        }
    }

    /**
     * Test that all constants are in valid HTTP status code range.
     */
    public function test_all_constants_are_valid_http_codes(): void
    {
        $reflection = new \ReflectionClass(Http::class);
        $constants = $reflection->getConstants();
        
        foreach ($constants as $name => $value) {
            $this->assertGreaterThanOrEqual(100, $value, "Constant {$name} should be >= 100");
            $this->assertLessThan(600, $value, "Constant {$name} should be < 600");
        }
    }

    /**
     * Test that no duplicate values exist.
     */
    public function test_no_duplicate_status_code_values(): void
    {
        $reflection = new \ReflectionClass(Http::class);
        $constants = $reflection->getConstants();
        
        $values = array_values($constants);
        $uniqueValues = array_unique($values);
        
        $this->assertCount(count($values), $uniqueValues, 'All HTTP status codes should be unique');
    }
}
