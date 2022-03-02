<?php

namespace tp\TouchPointWP;

class ExtraValueHandler
{
    protected object $owner; // Must have the extraValues trait.

    /**
     * @throws TouchPointWP_Exception
     */
    public function __construct(object $owner)
    {
        if (!in_array("", class_uses($owner, false))) {
            throw new TouchPointWP_Exception("An object of type " . get_class($owner) . " does not support Extra Values.");
        }
        $this->owner = $owner;
    }

    public static function standardizeExtraValueName(string $name): string
    {
        return preg_replace("/[^A-Za-z0-9]/", '', $name);
    }

    public function __get($what)
    {
        $what = self::standardizeExtraValueName($what);
        return $this->owner->getExtraValue($what);
    }
}