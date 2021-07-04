<?php

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}


/**
 * JS Instantiation Interface.  Used to indicate objects that should be provided to JS for client-side instantiation
 *
 * Interface jsInstantiation
 * @package tp\TouchPointWP
 */
trait jsInstantiation {

    private static array $queueForJsInstantiation = [];
    private static array $constructedObjects = [];
    private static bool $requireAllObjectsInJs = false;

    /**
     * Return the instances to be used for instantiation.
     *
     * @return object[]
     */
    protected static function getQueueForJsInstantiation(): array
    {
        if (self::$requireAllObjectsInJs) {
            $list = self::$constructedObjects;
        } else {
            $list = self::$queueForJsInstantiation;
        }

        // Remove duplicates.  (array_unique won't handle objects cleanly)
        $ids     = array_map(fn(Involvement $inv) => $inv->invId, $list);
        $uniqIds = array_unique($ids);

        return array_values(array_intersect_key($list, $uniqIds));
    }

    /**
     * Add to a queue for instantiation.
     *
     * @return void
     */
    protected function enqueueForJsInstantiation(): void
    {
        if (!isset(static::$queueForJsInstantiation[$this->invId]))
            static::$queueForJsInstantiation[$this->invId] = $this;
    }

    /**
     * Add to a queue for instantiation.
     *
     * @return void
     */
    protected function registerConstruction()
    {
        if (!isset(self::$constructedObjects[$this->invId]))
            self::$constructedObjects[$this->invId] = $this;
    }

    /**
     * If all objects need to be included in the JS,
     *
     * @param bool $require  Whether all objects should be required.  Almost always should be left with the default.
     */
    public static function requireAllObjectsInJs($require = true): void
    {
        self::$requireAllObjectsInJs = $require;
    }

    /**
     * Get the JS for instantiation.
     *
     * @return string
     */
    public abstract static function getJsInstantiationString(): string;
}