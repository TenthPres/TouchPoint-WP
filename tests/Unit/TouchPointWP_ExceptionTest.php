<?php
/**
 * Tests for the TouchPointWP_Exception class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use tp\TouchPointWP\TouchPointWP_Exception;
use tp\TouchPointWP\Tests\TestCase;

/**
 * Test case for the TouchPointWP_Exception class.
 *
 * @covers \tp\TouchPointWP\TouchPointWP_Exception
 */
class TouchPointWP_ExceptionTest extends TestCase
{
    /**
     * Test that exception can be instantiated with message.
     */
    public function test_exception_instantiation_with_message(): void
    {
        $message = 'Test error message';
        $exception = new TouchPointWP_Exception($message);
        
        $this->assertSame($message, $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    /**
     * Test that exception can be instantiated with message and code.
     */
    public function test_exception_instantiation_with_code(): void
    {
        $message = 'Test error message';
        $code = 404;
        $exception = new TouchPointWP_Exception($message, $code);
        
        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($code, $exception->getCode());
    }

    /**
     * Test that exception can be thrown and caught.
     */
    public function test_exception_can_be_thrown_and_caught(): void
    {
        $message = 'Test error message';
        
        try {
            throw new TouchPointWP_Exception($message);
            $this->fail('Exception should have been thrown');
        } catch (TouchPointWP_Exception $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * Test that exception can be created with previous exception.
     */
    public function test_exception_with_previous_exception(): void
    {
        $previousMessage = 'Previous error';
        $message = 'Current error';
        
        $previous = new \Exception($previousMessage);
        $exception = new TouchPointWP_Exception($message, 0, $previous);
        
        $this->assertSame($message, $exception->getMessage());
        $this->assertNotNull($exception->getPrevious());
        $this->assertSame($previousMessage, $exception->getPrevious()->getMessage());
    }

    /**
     * Test toJson method returns valid JSON.
     */
    public function test_to_json_returns_valid_json(): void
    {
        $message = 'Test error message';
        $code = 500;
        $exception = new TouchPointWP_Exception($message, $code);
        
        $json = $exception->toJson();
        $this->assertIsString($json);
        
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertArrayHasKey('status', $decoded['error']);
        $this->assertArrayHasKey('code', $decoded['error']);
        $this->assertArrayHasKey('message', $decoded['error']);
        $this->assertArrayHasKey('location', $decoded['error']);
        
        $this->assertSame('failure', $decoded['error']['status']);
        $this->assertSame($code, $decoded['error']['code']);
        $this->assertSame($message, $decoded['error']['message']);
    }

    /**
     * Test toWpError method returns WP_Error object.
     */
    public function test_to_wp_error_returns_wp_error(): void
    {
        $message = 'Test error message';
        $code = 404;
        $exception = new TouchPointWP_Exception($message, $code);
        
        $wpError = $exception->toWpError();
        
        // WP_Error is mocked in our test environment, but we can check the structure
        $this->assertInstanceOf(\WP_Error::class, $wpError);
    }

    /**
     * Test exception extends standard PHP Exception.
     */
    public function test_exception_extends_exception(): void
    {
        $exception = new TouchPointWP_Exception('test');
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    /**
     * Test exception has file and line information.
     */
    public function test_exception_has_file_and_line_info(): void
    {
        $exception = new TouchPointWP_Exception('test');
        
        $this->assertIsString($exception->getFile());
        $this->assertIsInt($exception->getLine());
        $this->assertGreaterThan(0, $exception->getLine());
    }

    /**
     * Test exception has trace information.
     */
    public function test_exception_has_trace_info(): void
    {
        $exception = new TouchPointWP_Exception('test');
        
        $trace = $exception->getTrace();
        $this->assertIsArray($trace);
        
        $traceString = $exception->getTraceAsString();
        $this->assertIsString($traceString);
    }
}
