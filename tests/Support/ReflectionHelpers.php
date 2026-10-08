<?php
/**
 * Helpers for reaching the protected and private static members of the plugin's classes.
 *
 * @package TouchPointWP\Tests
 */

namespace tp\TouchPointWP\Tests\Support;

use ReflectionMethod;
use ReflectionProperty;

/**
 * Use this in a test case class to set static properties and call static methods, even if they're protected or private.
 */
trait ReflectionHelpers
{
    /**
     * Set a static property, even if it's protected or private.
     *
     * @param string $class
     * @param string $property
     * @param mixed  $value
     */
    protected static function setStatic(string $class, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($class, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $reflection->setValue(null, $value);
    }

    /**
     * Read a static property, even if it's protected or private.
     *
     * @param string $class
     * @param string $property
     *
     * @return mixed
     */
    protected static function getStatic(string $class, string $property): mixed
    {
        $reflection = new ReflectionProperty($class, $property);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->getValue();
    }

    /**
     * Call a static method, even if it's protected or private.
     *
     * @param string $class
     * @param string $method
     * @param mixed  ...$arguments
     *
     * @return mixed
     */
    protected static function callStatic(string $class, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($class, $method);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        return $reflection->invokeArgs(null, $arguments);
    }
}
