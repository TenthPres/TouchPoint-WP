<?php


namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

require_once 'api.iface.php';
require_once "jsInstantiation.php";
require_once 'InvolvementMembership.php';
require_once "Utilities/PersonQuery.php";

use stdClass;
use tp\TouchPointWP\Utilities\PersonQuery;
use WP_Error;

/**
 * Class Person - Fundamental object meant to correspond to a Person in TouchPoint
 *
 * @package tp\TouchPointWP
 */
class Person extends \WP_User implements api, \JsonSerializable
{
    use jsInstantiation;

    public const SHORTCODE_PEOPLE_INDEX = TouchPointWP::SHORTCODE_PREFIX . "People";
    public const BACKUP_USER_PREFIX = "touchpoint-";

    public const META_PEOPLEID = TouchPointWP::SETTINGS_PREFIX . 'peopleId';
    public const META_INV_MEMBER_PREFIX = TouchPointWP::SETTINGS_PREFIX . "inv_mem_";
    public const META_INV_ATTEND_PREFIX = TouchPointWP::SETTINGS_PREFIX . "inv_att_";
    public const META_INV_DESC_PREFIX = TouchPointWP::SETTINGS_PREFIX . "inv_desc_";

    private static bool $_isInitiated = false;
    private static bool $_indexingMode = false;
    private static array $_indexingQueries;

    public int $peopleId;

    private array $_fieldsToUpdate = [];
    private array $_invs;
    private bool $_invsAllFetched = false;


    /**
     * @param int    $id
     * @param string $name
     * @param string $site_id
     */
    public function __construct($id = 0, $name = '', $site_id = '')
    {
        parent::__construct($id, $name, $site_id);

        $this->peopleId = $this->get(self::META_PEOPLEID);
    }

    /**
     * @param $queryResult
     *
     * @return Person
     * @throws TouchPointWP_Exception If a WP User ID is not provided, this exception is thrown.
     */
    public static function fromQueryResult($queryResult): Person
    {
        if (is_numeric($queryResult)) {
            return new Person($queryResult);
        }

        else if (! property_exists($queryResult, "ID")) {
            throw new TouchPointWP_Exception(__("No WordPress User ID provided for initializing a person object."));
        }

        return new Person($queryResult->ID);
    }

    /**
     * Generic getter for fields and meta attributes.
     *
     * @param string $key
     *
     * @return int|mixed
     */
    public function __get($key)
    {
        switch (strtolower($key)) {
            case "peopleid":
                return $this->peopleId;
        }

        // TODO DIR deal with prefixed fields

        return parent::__get($key);
    }

    /**
     * Generic setter.  Does not get submitted to the database until
     *
     * @param string $key
     * @param mixed  $value
     */
    public function __set($key, $value)
    {
        switch (strtolower($key)) {
            case "peopleid":
            case "id":
                _doing_it_wrong(__FUNCTION__, "IDs can only be updated within the Person class.", TouchPointWP::VERSION);
                return;
        }

        // TODO DIR deal with prefixed fields

        if ($this->$key == $value) { // need '3' = 3 and '' == null
            return;
        }

        parent::__set($key, $value);

        $this->_fieldsToUpdate[] = $key;
    }

    /**
     * @param int $peopleId Update the PeopleId Meta field if it's somehow missing.
     *
     * @return bool False is the update is not completed properly.  True if the value is unchanged or updated.
     */
    protected function updatePeopleId(int $peopleId): bool
    {
        if ($peopleId === $this->peopleId) {
            return true;
        }

        $result = !!update_user_option($this->ID, self::META_PEOPLEID, $peopleId, true);

        $this->peopleId = $this->get(self::META_PEOPLEID);

        return $result;
    }

    /**
     * Display a collection of People, such as a list of leaders
     *
     * @param array|string $params
     * @param string       $content
     *
     * @return string
     * @throws TouchPointWP_Exception
     */
    public static function peopleIndexShortcode($params = [], string $content = ""): string
    {
        // standardize parameters
        if (is_string($params)) {
            $params = explode(",", $params);
        }
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        $params = shortcode_atts(
            [
                'class' => 'TouchPoint-involvement actions',
                'invid' => null,
                'id'    => wp_unique_id('tp-actions-')
            ],
            $params,
            self::SHORTCODE_PEOPLE_INDEX
        );

        $iid = intval($params['invid']);

        // If there's no invId, try to get one from the Post
        if ($iid === null) {
            $post = get_post();

            if (is_object($post)) {
                try {
                    $inv = Involvement::fromPost($post); // TODO involvement should not necessarily need to be imported as a post type
                    $iid = $inv->invId;
                } catch (TouchPointWP_Exception $e) {
                    $iid = null;
                }
            }
        }

        // TODO DIR other types of queries
        // TODO DIR standardize this this query concept to simplify mapping between TouchPoint concepts and WP_User_Query
        if (self::$_indexingMode) {
            if ($iid === null) {
                return "";
            }
            if (! isset(self::$_indexingQueries['inv'])) {
                self::$_indexingQueries['inv'] = [];
            }
            if (! isset(self::$_indexingQueries['inv'][$iid])) {
                self::$_indexingQueries['inv'][$iid] = [
                    'memTypes' => null,
                    'subGroups' => null,
                    'with_subGroups' => false
                ];
            }
            return "";
        }

        $WP_User_queryParams = [
            'meta_query' => ['relation' => 'AND'],

            // Sort by last name
            'meta_key' => 'last_name',
            'orderby' => 'meta_value',
            'order' => 'ASC'
        ];

        // TODO DIR also allow status flags or such
        // If there is no invId at this point, this is an error.
        if ($iid === null) {
            return "<!-- Error: Can't create Involvement Actions because there is no clear involvement.  Define the InvId and make sure it's imported. -->";
        }

        $WP_User_queryParams['meta_query'][] = [
            'key' => self::META_INV_MEMBER_PREFIX . $iid,
            'compare' => "EXISTS"
        ];

        // TODO DIR filter by involvement member type

        // TODO DIR make sure involvement members have synced recently

        $q = new PersonQuery($WP_User_queryParams);
        $out = "";

        $people = $q->get_results();

        $loadedPart = get_template_part('person-index', 'person-index');
        if ($loadedPart === false) {
            TouchPointWP::enqueuePartialsStyle();
            ob_start();
            require TouchPointWP::$dir . "/src/templates/parts/person-index.php";
            $out .= ob_get_clean();
        }
        // TODO DIR make sure this actually works with external partials.
        // TODO DIR provide an alternate if there are no people available.


        return $out;
    }

    /**
     * @return InvolvementMembership[]|InvolvementMembership
     * @throws TouchPointWP_Exception
     */
    public function getInvolvementMemberships(?int $iid = null)
    {
        $fetchAll = ($iid === null);
        $iid = intval($iid);

        if (
            (!$fetchAll && (
                !isset($this->_invs) || !isset($this->_invs[$iid])
                )
            ) || (
                $fetchAll && !$this->_invsAllFetched
            )
        ) {
            global $wpdb;
            $metaMemPrefix = self::META_INV_MEMBER_PREFIX;
            $metaAttPrefix = self::META_INV_ATTEND_PREFIX;
            $metaDescPrefix = self::META_INV_DESC_PREFIX;
            $metaMemPrefixLength = strlen($metaMemPrefix) + 1;
            /** @noinspection SqlResolve */
            $sql = "SELECT SUBSTR(mt.meta_key, {$metaMemPrefixLength}) AS iid, mt.meta_value AS mt, at.meta_value as at, d.meta_value AS descr
                    FROM {$wpdb->usermeta} AS mt
                    LEFT JOIN {$wpdb->usermeta} AS at ON CONCAT('{$metaAttPrefix}', SUBSTR(mt.meta_key, {$metaMemPrefixLength})) = at.meta_key AND at.user_id = {$this->ID}
                    LEFT JOIN {$wpdb->usermeta} AS d ON CONCAT('{$metaDescPrefix}', SUBSTR(mt.meta_key, {$metaMemPrefixLength})) = d.meta_key AND d.user_id = {$this->ID}
                    WHERE mt.user_id = {$this->ID}";

            if ($fetchAll) {
                $sql .= " AND mt.meta_key LIKE '{$metaMemPrefix}%'";
            } else {
                $sql .= " AND mt.meta_key = '{$metaMemPrefix}{$iid}'";
            }

            $invMeta = $wpdb->get_results($sql);

            $this->_invs = [];

            foreach ($invMeta as $im) {
                $inv = new InvolvementMembership($this->peopleId, $im->iid);
                $inv->mt = $im->mt;
                $inv->at = $im->at;
                $inv->description = $im->descr;
                $inv->person = $this;
                $this->_invs[$im->iid] = $inv;
            }
        }
        if ($fetchAll) {
            return $this->_invs;
        } elseif (isset($this->_invs[$iid])) {
            return $this->_invs[$iid];
        } else {
            throw new TouchPointWP_Exception("Requested membership could not be found.");
        }
    }

    /**
     * Updates the data for all People Indexes in the site.  TODO DIR Multisite: does not update for all sites in the network.
     */
    protected static function updatePeopleIndexes(): void
    {
        self::$_indexingQueries = [];

        // Update People Indexes
        $posts = Utilities::getPostContentWithShortcode(self::SHORTCODE_PEOPLE_INDEX);

        self::$_indexingMode = true;
        foreach ($posts as $postI) {
            global $post;
            $post = $postI;
            set_time_limit(10);
            apply_shortcodes($postI->post_content);
        }
        self::$_indexingMode = false;


        // Update Involvement Leaders
        // TODO DIR this

        // Make sure there's something to submit
        if (!isset(self::$_indexingQueries)) {
            return;
        }

        // Submit to API
        $data = TouchPointWP::instance()->apiPost('people_get', self::$_indexingQueries);

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        // Parse the API results
        $people = $data->people ?? [];
        self::updatePeopleFromApiData($people);
    }


    /**
     * @param stdClass[] $people
     *
     * @throws TouchPointWP_Exception
     */
    protected static function updatePeopleFromApiData(array $people): void
    {
        $peopleUpdated = 0;
        $fieldsUpdated = 0;

        foreach ($people as $pData) {
            $updated = false;

            set_time_limit(30);
            $person = null;

            // Find person by WordPress Id, if provided.
            if (isset($pData->WordPressId) && intval($pData->WordPressId) !== 0) {
                $q = new PersonQuery(
                    [
                        'include' => [intval($pData->WordPressId)]
                    ]
                );
                if ($q->get_total() > 0) {
                    $person = array_values($q->get_results())[0];
                }
            }

            // Find person by PeopleId
            if ($person === null) {
                $q = new PersonQuery(
                    [
                        'meta_key'     => self::META_PEOPLEID,
                        'meta_value'   => $pData->PeopleId,
                        'meta_compare' => '='
                    ]
                );
                if ($q->get_total() === 1) {
                    $person = array_values($q->get_results())[0];
                }
            }

            // Create new person
            if ($person === null) {
                if (true || TouchPointWP::instance()->settings->auth_auto_provision === 'on') { // TODO DIR replace with a condition that makes sense for the task at hand
                    // Provision a new user, since we were unsuccessful in finding one.
                    set_time_limit(60); // TODO DIR remove because this is absurd.
                    $uid = wp_create_user(self::generateUserName($pData), com_create_guid(), '');
                    if (is_numeric($uid)) { // user was successfully generated.
                        update_user_option($uid, 'created_by', 'TouchPoint-WP', true);
                        update_user_option($uid, self::META_PEOPLEID, $pData->PeopleId, true);
                        $person = new Person($uid);
                        $updated = true;
                    }
                }
            }

            // User doesn't exist.
            if ($person === null) {
                $peopleUpdated += $updated;
                continue;
            }

            // Make sure the PeopleId is correct, just in case.
            $person->updatePeopleId($pData->PeopleId);

            // TODO DIR update EV for WP User ID on TouchPoint if needed.
            $person->first_name = $pData->GoesBy;
            $person->last_name = $pData->LastName;
            $person->display_name = $pData->DisplayName;
            if (count($pData->Emails) > 0) {
                $person->user_email = $pData->Emails[0];
            } else {
                $person->user_email = null;
            }
//            var_dump($pData);
            $person->user_image = $pData->Picture;

            // Deliberately do not update usernames or passwords, as those could be set by any number of places for any number of reasons.

            // Involvements!
            $currentInvs = $person->getInvolvementMemberships();
            $inv_dels = array_keys($currentInvs);
            $inv_updates = [];

            // Process diffs, as appropriate.
            foreach ($pData->Inv as $i) {
                if(($key = array_search($i->iid, $inv_dels)) !== false){
                    unset($inv_dels[$key]);
                }

                if (!isset($currentInvs[$i->iid])) {
                    $inv_updates[self::META_INV_MEMBER_PREFIX . $i->iid] = $i->memType;
                    $inv_updates[self::META_INV_ATTEND_PREFIX . $i->iid] = $i->attType;
                    $inv_updates[self::META_INV_DESC_PREFIX . $i->iid] = $i->descr;
                    continue;
                }

                if ($currentInvs[$i->iid]->mt !== $i->memType) {
                    $inv_updates[self::META_INV_MEMBER_PREFIX . $i->iid] = $i->memType;
                }
                if ($currentInvs[$i->iid]->at !== $i->attType) {
                    $inv_updates[self::META_INV_ATTEND_PREFIX . $i->iid] = $i->attType;
                }
                if ($currentInvs[$i->iid]->description !== $i->descr) {
                    if (trim($i->descr) == null) {
                        delete_user_option($person->ID, self::META_INV_DESC_PREFIX . $i->iid, true);
                    } else {
                        $inv_updates[self::META_INV_DESC_PREFIX . $i->iid] = $i->descr;
                    }
                }
            }

            foreach ($inv_updates as $k => $v) {
                update_user_option($person->ID, $k, $v, true);
            }
            foreach ($inv_dels as $iid) {
                delete_user_option($person->ID, self::META_INV_MEMBER_PREFIX . $iid, true);
                delete_user_option($person->ID, self::META_INV_ATTEND_PREFIX . $iid, true);
                delete_user_option($person->ID, self::META_INV_DESC_PREFIX . $iid, true);
            }

            // Submit update for basic fields
            $person->submitUpdate();
        }
        set_time_limit(30);
    }


    /**
     * Save any user updates back to the database.
     */
    protected function submitUpdate(): void
    {
        if (count($this->_fieldsToUpdate) > 0) {
            wp_update_user($this->fieldsForUpdate());
            $this->_fieldsToUpdate = [];
        }
    }


    /**
     * Returns the html with buttons for actions the user can perform.  This must be called *within* an element with the
     * `data-tp-person` attribute with the invId as the value.
     *
     * @return string
     */
    public function getActionButtons(): string
    {
        TouchPointWP::requireScript('swal2-defer');
        TouchPointWP::requireScript('base-defer');
        $this->enqueueForJsInstantiation();

        $text = __("Contact", TouchPointWP::TEXT_DOMAIN);
        $ret = "<button type=\"button\" data-tp-action=\"contact\">{$text}</button>  ";

        return $ret;
    }

    /**
     * Controls the information that's actually serialized for jsInstantiation
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'peopleId' => $this->peopleId,
            'displayName' => $this->display_name
        ];
    }

    /**
     * Returns the raw JS that creates the Person objects
     *
     * @return string
     */
    public static function getJsInstantiationString(): string
    {
        $queue = static::getQueueForJsInstantiation();

        if (count($queue) < 1) {
            return "\t// No People to instantiate.\n";
        }

        $listStr = json_encode($queue);

        return "\ttpvm.addEventListener('Person_class_loaded', function() {
        TP_Person.fromObjArray($listStr);\n\t});\n";
    }


    /**
     * Gets the PeopleId number.  Required by jsInstantiation trait.
     * @return int
     */
    public function getTouchPointId(): int
    {
        return $this->peopleId;
    }


    /**
     * @return stdClass Assembles an object with updates to be submitted to the database by wp_update_user
     */
    private function fieldsForUpdate(): stdClass
    {
        $fields = $this->_fieldsToUpdate;
        $updates = [];

        foreach ($fields as $f) {
            $updates[$f] = $this->$f;
        }

        $updates['ID'] = $this->ID;

        return (object)$updates;
    }


    /**
     * Generates a username for a new WordPress user based on TouchPoint data.
     *
     * @param object $pData
     *
     * @return string  A viable, available username.
     */
    protected static function generateUserName(object $pData): string
    {
        // Best.  Matches TouchPoint username.  However, it is possible users won't have usernames.
        foreach ($pData->Usernames as $u) {
            if (! username_exists($u)) {
                return $u;
            }
        }

        // Better.  Concat of full name.  Does not intersect with above, which uses first initials, probably.
        $try = strtolower($pData->DisplayName);
        $try = preg_replace('/[^\w\d]+/', '', $try);
        if ( ! username_exists($try)) {
            return $try;
        }

        // Good.  Full name, plus the ID.
        $try .= $pData->PeopleId;
        if ( ! username_exists($try)) {
            return $try;
        }

        // Works.  Not readily human-readable.  But, unlikely to happen.
        return self::BACKUP_USER_PREFIX . $pData->PeopleId;
    }

    public function hasProfilePage(): bool
    {
        return count_user_posts($this->ID) > 0;
    }

    public function getProfileUrl(): ?string
    {
        if ($this->hasProfilePage()) {
            return get_author_posts_url($this->ID);
        } else {
            return null;
        }
    }


    /**
     * Take an array of people-ish objects and return a nicely human-readable list of names.
     *
     * @param array $people
     *
     * @return string
     */
    public static function arrangeNamesForPeople(array $people): string
    {
        $people = self::groupByFamily($people);

        $familyNames = [];
        $comma = ', ';
        $and = ' & ';
        $useOxford = false;
        foreach($people as $family) {
            $fn = self::formatNamesForFamily($family);
            if (strpos($fn, ', ') !== false) {
                $comma     = '; ';
                $useOxford = true;
            }
            if (strpos($fn, ' & ') !== false) {
                $and = ' and ';
                $useOxford = true;
            }
            $familyNames[] = $fn;
        }
        $last = array_pop($familyNames);
        $str = implode($comma, $familyNames);
        if (count($familyNames) > 0) {
            if ($useOxford)
                $str .= trim($comma);
            $str .= $and;
        }
        $str .= $last;
        return $str;
    }

    protected static function formatNamesForFamily(array $family): string
    {
        if (count($family) < 1)
            return "";

        $standingLastName = $family[0]->lastName;
        $string = "";

        $first = true;
        foreach ($family as $p) {
            if ($standingLastName != $p->lastName) {
                $string .= " " . $standingLastName; // TODO name privacy options

                $standingLastName = $p->lastName;
            }

            if (!$first && count($family) > 1)
                $string  .= " & ";

            $string .= $p->goesBy;

            $first = false;
        }
        $string .= " " . $standingLastName; // TODO name privacy options

        $lastAmpPos = strrpos($string, " & ");
        return str_replace(" & ", ", ", substr($string, 0, $lastAmpPos)) . substr($string, $lastAmpPos);
    }

    public static function groupByFamily(array $people): array
    {
        $families = [];
        foreach ($people as $p) {
            $fid = intval($p->familyId);

            if (!array_key_exists($fid, $families))
                $families[$fid] = [];

            $families[$fid][] = $p;
        }
        return $families;
    }

    private static function ajaxIdent(): void
    {
        $inputData = TouchPointWP::postHeadersAndFiltering();

        $data = TouchPointWP::instance()->apiPost('ident', json_decode($inputData));

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        $people = $data->people ?? [];

        // TODO sync or queue sync of people... maybe...

        $ret = [];
        foreach ($people as $p) {
            $p->lastName = $p->lastName[0] ? $p->lastName[0] . "." : "";
            unset($p->lastInitial);
            $ret[] = $p;
        }

        echo json_encode(['people' => $ret]);
        exit;
    }

    /**
     * Handle API requests
     *
     * @param array $uri The request URI already parsed by parse_url()
     *
     * @return bool False if endpoint is not found.  Should print the result.
     */
    public static function api(array $uri): bool
    {
        if (count($uri['path']) < 3) {
            return false;
        }

        switch (strtolower($uri['path'][2])) {
            case "ident":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
                self::ajaxIdent();
                exit;

            case "contact":
                self::ajaxContact();
                exit;

            case "force-sync":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
                self::updatePeopleIndexes();
                exit;
        }

        return false;
    }

    /**
     * Handles the API call to send a message through a contact form.
     */
    private static function ajaxContact(): void
    {
        $inputData = TouchPointWP::postHeadersAndFiltering();
        $inputData = json_decode($inputData);
        $inputData->keywords = [];

        // TODO DIR get keywords from somewhere
//        if (!!$settings || !$settings->contactKeywords) {
//            $inputData->keywords = Utilities::idArrayToIntArray($settings->contactKeywords);
//        }
        $inputData->keywords = []; // TODO DIR remove

        $data = TouchPointWP::instance()->apiPost('person_contact', $inputData);

        if ($data instanceof WP_Error) {
            echo json_encode(['error' => $data->get_error_message()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }

    public static function load(): bool
    {
        if (self::$_isInitiated) {
            return true;
        }

        self::$_isInitiated = true;

        if ( ! shortcode_exists(self::SHORTCODE_PEOPLE_INDEX)) {
            add_shortcode(self::SHORTCODE_PEOPLE_INDEX, [self::class, "peopleIndexShortcode"]);
        }

        // Setup cron for updating Users--especially those who are in indexes.
        // TODO DIR cron for updates @see Involvement:load()

        return true;
    }
}