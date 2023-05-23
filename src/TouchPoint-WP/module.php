<?php

namespace tp\TouchPointWP;

interface module {
    /**
     * Loads the module and initializes the other actions.
     *
     * @return bool
     */
    public static function load(): bool;
}