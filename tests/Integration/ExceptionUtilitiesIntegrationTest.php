<?php
/**
 * Integration tests for Exception handling with Utilities
 *
 * @package TouchPointWP\Tests\Integration
 */

namespace tp\TouchPointWP\Tests\Integration;

use Brain\Monkey;
use tp\TouchPointWP\TouchPointWP_Exception;
use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Integration test case for Exception handling with Utilities.
 *
 * @covers \tp\TouchPointWP\TouchPointWP_Exception
 * @covers \tp\TouchPointWP\Utilities
 */
class ExceptionUtilitiesIntegrationTest extends TestCase
{
    /**
     * Test exception creation with timestamp tracking.
     */
    public function test_exception_with_timestamp(): void
    {
        $beforeException = Utilities::dateTimeNow();
        
        try {
            throw new TouchPointWP_Exception('Test exception with timestamp', 500);
        } catch (TouchPointWP_Exception $e) {
            $afterException = Utilities::dateTimeNow();
            
            $this->assertSame('Test exception with timestamp', $e->getMessage());
            $this->assertSame(500, $e->getCode());
            
            // Verify timestamps (should be same due to caching)
            $this->assertSame($beforeException, $afterException);
        }
    }

    /**
     * Test exception JSON output includes proper error structure.
     */
    public function test_exception_json_format_validation(): void
    {
        $exception = new TouchPointWP_Exception('Data validation failed', 400);
        $json = $exception->toJson();
        
        // Verify JSON is valid
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        
        // Verify structure
        $this->assertArrayHasKey('error', $decoded);
        $this->assertArrayHasKey('status', $decoded['error']);
        $this->assertArrayHasKey('code', $decoded['error']);
        $this->assertArrayHasKey('message', $decoded['error']);
        
        // Verify values
        $this->assertSame('failure', $decoded['error']['status']);
        $this->assertSame(400, $decoded['error']['code']);
        $this->assertSame('Data validation failed', $decoded['error']['message']);
    }

    /**
     * Test exception chaining with timezone conversions.
     */
    public function test_exception_chaining_with_timezone_info(): void
    {
        $utcZone = Utilities::utcTimeZone();
        $this->assertSame('UTC', $utcZone->getName());
        
        $originalException = new \Exception('Database connection failed');
        $wrappedException = new TouchPointWP_Exception(
            'Unable to fetch data',
            503,
            $originalException
        );
        
        $this->assertSame('Unable to fetch data', $wrappedException->getMessage());
        $this->assertSame(503, $wrappedException->getCode());
        $this->assertNotNull($wrappedException->getPrevious());
        $this->assertSame('Database connection failed', $wrappedException->getPrevious()->getMessage());
    }

    /**
     * Test exception handling in a workflow with multiple utility calls.
     */
    public function test_exception_in_data_processing_workflow(): void
    {
        $startTime = Utilities::dateTimeNow();
        $errors = [];
        
        // Simulate data processing with potential errors
        $dataPoints = [
            ['value' => '123.45', 'valid' => true],
            ['value' => 'invalid', 'valid' => false],
            ['value' => '67.89', 'valid' => true],
        ];
        
        foreach ($dataPoints as $index => $data) {
            try {
                if (!$data['valid']) {
                    throw new TouchPointWP_Exception(
                        "Invalid data at index {$index}",
                        400
                    );
                }
                
                $converted = Utilities::toFloatOrNull($data['value']);
                $this->assertIsFloat($converted);
            } catch (TouchPointWP_Exception $e) {
                $errors[] = [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'json' => $e->toJson(),
                ];
            }
        }
        
        $endTime = Utilities::dateTimeNow();
        
        // Verify we caught exactly one error
        $this->assertCount(1, $errors);
        $this->assertSame('Invalid data at index 1', $errors[0]['message']);
        $this->assertSame(400, $errors[0]['code']);
        
        // Verify timestamps
        $this->assertSame($startTime, $endTime);
    }

    /**
     * Test WP_Error conversion with utility date formatting.
     */
    public function test_wp_error_conversion_with_utilities(): void
    {
        $exception = new TouchPointWP_Exception('Resource not found', 404);
        $wpError = $exception->toWpError();
        
        $this->assertInstanceOf(\WP_Error::class, $wpError);
        $this->assertSame(404, $wpError->get_error_code());
        $this->assertSame('Resource not found', $wpError->get_error_message());
        
        // Add timestamp context using utilities
        $timestamp = Utilities::dateTimeNow()->getTimestamp();
        $this->assertIsInt($timestamp);
        $this->assertGreaterThan(0, $timestamp);
    }

    /**
     * Test exception with float conversion for numeric error codes.
     */
    public function test_exception_with_numeric_utilities(): void
    {
        // Test with various error scenarios
        $testCases = [
            ['input' => '500', 'expected' => 500.0],
            ['input' => '404.5', 'expected' => 404.5],
            ['input' => 'invalid', 'expected' => null],
        ];
        
        $exceptionsLogged = [];
        
        foreach ($testCases as $case) {
            $converted = Utilities::toFloatOrNull($case['input']);
            $this->assertSame($case['expected'], $converted);
            
            if ($converted === null) {
                try {
                    throw new TouchPointWP_Exception(
                        "Invalid numeric value: {$case['input']}",
                        422
                    );
                } catch (TouchPointWP_Exception $e) {
                    $exceptionsLogged[] = $e;
                }
            }
        }
        
        $this->assertCount(1, $exceptionsLogged);
        $this->assertStringContainsString('Invalid numeric value: invalid', $exceptionsLogged[0]->getMessage());
    }

    /**
     * Test error handling with date range validation.
     */
    public function test_exception_with_date_range_validation(): void
    {
        $today = Utilities::dateTimeTodayAtMidnight();
        $tomorrow = Utilities::dateTimeNowPlus1D();
        $yesterday = Utilities::dateTimeNowMinus1D();
        
        // Simulate date validation
        $validationErrors = [];
        
        // Test with invalid date comparison
        if ($tomorrow < $today) {
            try {
                throw new TouchPointWP_Exception('Invalid date range: end before start', 400);
            } catch (TouchPointWP_Exception $e) {
                $validationErrors[] = $e;
            }
        }
        
        // Test with past date
        if ($yesterday > $today) {
            try {
                throw new TouchPointWP_Exception('Cannot schedule in the past', 400);
            } catch (TouchPointWP_Exception $e) {
                $validationErrors[] = $e;
            }
        }
        
        // Should have no validation errors with correct date logic
        $this->assertCount(0, $validationErrors);
        
        // Verify date relationships
        $this->assertGreaterThan($tomorrow->getTimestamp(), Utilities::dateTimeNowPlus90D()->getTimestamp());
        $this->assertGreaterThan($yesterday->getTimestamp(), $today->getTimestamp());
    }
}
