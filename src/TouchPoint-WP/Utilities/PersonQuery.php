<?php

namespace tp\TouchPointWP\Utilities;

use tp\TouchPointWP\Person;
use tp\TouchPointWP\TouchPointWP_Exception;
use WP_User_Query;

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * PersonQuery - Wrap the UserQuery object such that the returned object is a Person instead of a User.
 *
 * @package tp\TouchPointWP
 */
class PersonQuery extends WP_User_Query
{
    protected bool $forceResultToPerson;
    private array $results;
    protected string $fields = "all_with_meta"; // Not quite the same as the User Query $query['fields'] parameter

    public function __construct($query = null, $forceResultToPerson = true)
    {
        $this->forceResultToPerson = !!$forceResultToPerson;

        if ($this->forceResultToPerson) {
            $query['fields'] = 'ID'; // Everything else is eventually dealt with via the getter.
        }
        $query['blog_id'] = 0;

        parent::__construct($query);
    }

    /**
     * @return Person[]  Generally, a Person array.  Individual elements may be
     *          TouchPointWP_Exception objects if the person could not be found.
     */
    public function get_results(): array
    {
        if (! isset($this->results)) {
            $results = parent::get_results();

            cache_users($results);

            if ( ! $this->forceResultToPerson) {
                return $results;
            }

            $this->results = array_map(fn($r) => Person::fromQueryResult($r), $results);
        }

        return $this->results;
    }

    /**
     * @return ?Person
     */
    public function get_first_result(): ?Person
    {

        if (! isset($this->results)) {
            try {
                $this->get_results();
            } catch (TouchPointWP_Exception $e) { // TODO why is the exception returned, and not thrown?
                return null;
            }
        }

        $r = array_values($this->results)[0];

        if (get_class($r) === Person::CLASS) {
            return $r;
        } else {
            return null;
        }
    }
}
