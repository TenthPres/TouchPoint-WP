<?php

namespace tp\TouchPointWP;

abstract class Utilities
{
    public static function toFloatOrNull($numeric): ?float
    {
        if (is_numeric($numeric)) {
            return (float)$numeric;
        }

        return null;
    }
}