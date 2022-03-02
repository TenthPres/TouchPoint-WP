<?php

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}


/**
 * Enables a class with Extra Values
 *
 * Interface jsInstantiation
 * @package tp\TouchPointWP
 */
trait extraValues {

    protected ?ExtraValueHandler $handler = null;

    /**
     * @return ExtraValueHandler
     *
     * @throws TouchPointWP_Exception When the object does not support Extra Values, an exception is thrown.
     */
    public function ExtraValues(): ExtraValueHandler
    {
        if ($this->handler === null) {
            $this->handler = new ExtraValueHandler($this);
        }
        return $this->handler;
    }

    /**
     * Get an Extra Value.
     *
     * @param string $name The name of the extra value to get.
     *
     * @return mixed.  The value of the extra value.  Returns null if it doesn't exist.
     */
    public abstract function getExtraValue(string $name);

    /**
     * Set an extra value in WordPress.  Value should already be converted to appropriate datatype (e.g. DateTime)
     *
     * DOES NOT SET THE EXTRA VALUE IN TouchPoint, AND IS ONLY MEANT TO BE USED AS PART OF A SYNC PROCEDURE.
     *
     * @param string $name The name of the extra value to set.
     * @param mixed $value The value to set.
     *
     * @return int|bool User meta ID if field did not exist, true on successful update, false on failure.
     */
    protected abstract function setExtraValueWP(string $name, $value);

    /**
     * Remove an extra value in WordPress.
     *
     * DOES NOT REMOVE IT IN TouchPoint, AND IS ONLY MEANT TO BE USED AS PART OF A SYNC PROCEDURE.
     *
     * @param string $name The name of the extra value to remove.
     *
     * @return bool True on Success, False on failure.
     */
    protected abstract function removeExtraValueWP(string $name): bool;
}