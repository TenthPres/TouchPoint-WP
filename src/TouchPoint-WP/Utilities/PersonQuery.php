<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP\Utilities;

use tp\TouchPointWP\Person;
use WP_User;
use WP_User_Query;

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * Wrap the UserQuery class such that the returned object is a Person instead of a User.
 */
class PersonQuery extends WP_User_Query
{
    protected bool $forceResultToPerson;
	/** @var $results PersonArray|WP_User[] */
    private $results;
    protected string $fields = "all_with_meta"; // Not quite the same as the User Query $query['fields'] parameter

    /**
     * Queries WordPress Users as Person objects.
     *
     * @param array $query Essentially the parameters passed to WP_User_Query
     * @param bool  $forceResultToPerson If true, the output from the query will always be a Person, rather than a User.
     *
     * @see WP_User_Query::__construct
     */
    public function __construct($query = null, bool $forceResultToPerson = true)
    {
        $this->forceResultToPerson = !!$forceResultToPerson;

        if ($this->forceResultToPerson) {
            $query['fields'] = 'ID'; // Everything else is eventually dealt with via the getter.
        }
        $query['blog_id'] = 0;

        parent::__construct($query);
    }

	/**
	 * @return PersonArray|array Generally, a Person array.  Individual elements may be
	 *          TouchPointWP_Exception objects if the person could not be found.
	 */
    public function get_results()
    {
        if (! isset($this->results)) {
            $results = parent::get_results();

            cache_users($results);

            if ( ! $this->forceResultToPerson) {
                return $results;
            }

            $results = array_map(fn($r) => Person::fromQueryResult($r), $results);
	        $this->results = new PersonArray($results);
        }

        return $this->results;
    }

    /**
     * @return ?Person
     */
    public function get_first_result(): ?Person
    {
        if (! isset($this->results)) {
	        $this->get_results();
        }

        $r = $this->results[0];

        if (get_class($r) === Person::CLASS) {
            return $r;
        } else {
            return null;
        }
    }
}
