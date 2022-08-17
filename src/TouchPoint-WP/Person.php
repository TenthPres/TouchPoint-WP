<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}

if (!TOUCHPOINT_COMPOSER_ENABLED) {
    require_once 'api.php';
    require_once 'extraValues.php';
    require_once "jsInstantiation.php";
    require_once 'InvolvementMembership.php';
    require_once "Utilities/PersonQuery.php";
}

use Exception;
use JsonSerializable;
use stdClass;
use tp\TouchPointWP\Utilities\PersonQuery;
use WP_User;

/**
 * This Person Object connects a WordPress User with a TouchPoint Person.
 *
 * @property ?object $picture An object with the picture URLs and other metadata
 */
class Person extends WP_User implements api, JsonSerializable
{
    use jsInstantiation;
    use extraValues;

    public const SHORTCODE_PEOPLE_LIST = TouchPointWP::SHORTCODE_PREFIX . "People";
    public const CRON_HOOK = TouchPointWP::HOOK_PREFIX . "person_cron_hook";
    public const BACKUP_USER_PREFIX = "touchpoint-";

    public const META_PEOPLEID = TouchPointWP::SETTINGS_PREFIX . 'peopleId';
    public const META_CUSTOM_PREFIX = 'c_'; // setters and getters also insert standard setting prefix
    public const META_INV_MEMBER_PREFIX = TouchPointWP::SETTINGS_PREFIX . "inv_mem_";
    public const META_INV_ATTEND_PREFIX = TouchPointWP::SETTINGS_PREFIX . "inv_att_";
    public const META_INV_DESC_PREFIX = TouchPointWP::SETTINGS_PREFIX . "inv_desc_";

    public const META_PEOPLE_EV_PREFIX = TouchPointWP::SETTINGS_PREFIX . "pev_";

    private static bool $_isLoaded = false;
    private static bool $_indexingMode = false;
    private static array $_indexingQueries;
    private static array $_instances = [];

    public int $peopleId;

    private array $_userFieldsToUpdate = [];
    private array $_userMetaToUpdate = [];
    private array $_meta = [];
    private array $_invs;
    private bool $_invsAllFetched = false;

    private const FIELDS_FOR_USER_UPDATE = [
        'user_pass',
        'user_login',
        'user_nicename',
        'user_url',
        'user_email',
        'display_name',
        'nickname',
        'first_name',
        'last_name',
        'description',
        'rich_editing',
        'syntax_highlighting',
        'comment_shortcuts',
        'admin_color',
        'use_ssl',
        'user_registered',
        'user_activation_key',
        'spam',
        'show_admin_bar_front',
//        'role', // Excluding prevents this from being set through __set
        'locale'
    ];

    private const FIELDS_FOR_META = [
        'picture',
        'familyId'
    ];


    /**
     * @param int    $id
     * @param string $name
     * @param string $site_id
     */
    protected function __construct($id = 0, $name = '', $site_id = '')
    {
        parent::__construct($id, $name, $site_id);

        $this->peopleId = intval($this->get(self::META_PEOPLEID));

        self::$_instances[$this->ID] = $this;
    }

    /**
     * @param $queryResult
     *
     * @return Person|TouchPointWP_Exception If a WP User ID is not provided, this exception is returned.
     */
    public static function fromQueryResult($queryResult): Person
    {
        if (is_numeric($queryResult)) {
            return new Person($queryResult);
        }

        if (! property_exists($queryResult, "ID")) {
            return new TouchPointWP_Exception(__("No WordPress User ID provided for initializing a person object.", TouchPointWP::TEXT_DOMAIN));
        }

        return new Person($queryResult->ID);
    }

    /**
     * Get a person from a WordPress User ID
     *
     * @param $id
     *
     * @return Person|null
     */
    public static function fromId($id): ?Person
    {
        if (is_object($id)) {
            $id = $id->ID;
        }
        if (is_array($id)) {
            $id = $id['ID'];
        }

        if (empty($id)) {
            return null;
        }

        if (isset(self::$_instances[$id])) {
            return self::$_instances[$id];
        }

        return new Person($id);
    }

    /**
     * Get a person from a TouchPoint PeopleID
     *
     * @param int $pid
     *
     * @return Person|null
     */
    public static function fromPeopleId(int $pid): ?Person
    {
        $q = new PersonQuery(
            [
                'meta_key'     => self::META_PEOPLEID,
                'meta_value'   => $pid,
                'meta_compare' => '='
            ]
        );
        if ($q->get_total() === 1) {
            return $q->get_first_result();
        }
        return null;
    }

    /**
     * @param $field
     * @param $value
     *
     * @return Person|null
     */
    public static function from($field, $value): ?Person
    {
        $userdata = WP_User::get_data_by($field, $value);

        if ( ! $userdata) {
            return null;
        }

        return new Person($userdata);
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
            /** @noinspection SpellCheckingInspection */
            case "peopleid":
                return $this->peopleId;
            case "id":
                return $this->ID;
        }

        // Direct user fields
        if (in_array($key, self::FIELDS_FOR_USER_UPDATE)) {
            return parent::__get($key);
        }

        // standardized meta fields
        if (in_array($key, self::FIELDS_FOR_META)) {
            $v = parent::__get(TouchPointWP::SETTINGS_PREFIX . $key);
            $this->_meta[$key] = $v;
            return $v;
        }

        // Try a direct field, potentially from a different plugin.
        $v = parent::__get($key);
        if ($v !== '') {
            return $v;
        }

        // Custom meta through TouchPoint-WP
        $v = parent::__get(TouchPointWP::SETTINGS_PREFIX . self::META_CUSTOM_PREFIX . $key);
        if ($v === '') {
            $v = null;
        }
        $this->_meta[self::META_CUSTOM_PREFIX . $key] = $v;
        return $v;
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
            /** @noinspection SpellCheckingInspection */
            case "peopleid":
            case "id":
                _doing_it_wrong(__FUNCTION__, "IDs can only be updated within the Person class.", TouchPointWP::VERSION);
                return;
        }

        // If value isn't changed, don't update.
        if ($this->$key == $value) {
            return;
        }

        if (in_array($key, self::FIELDS_FOR_USER_UPDATE)) {
            parent::__set($key, $value);
            $this->_userFieldsToUpdate[] = $key;
            return;
        }

        // Standardized Meta fields
        if (in_array($key, self::FIELDS_FOR_META)) {
            $this->_meta[$key] = $value;
            parent::__set(TouchPointWP::SETTINGS_PREFIX . $key, $value);
            $this->_userMetaToUpdate[] = $key;
            return;
        }

        // Custom Meta fields
        $this->_meta[self::META_CUSTOM_PREFIX . $key] = $value;
        parent::__set(TouchPointWP::SETTINGS_PREFIX . self::META_CUSTOM_PREFIX . $key, $value);
        $this->_userMetaToUpdate[] = self::META_CUSTOM_PREFIX . $key;
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
     * @noinspection PhpUnusedParameterInspection
     */
    public static function peopleListShortcode($params = [], string $content = ""): string
    {
        // standardize parameters
        if (is_string($params)) {
            $params = explode(",", $params);
        }
        $params = array_change_key_case($params, CASE_LOWER);

        // set some defaults
        /** @noinspection SpellCheckingInspection */
        $params = shortcode_atts(
            [
                'class' => 'TouchPoint-person people-list',
                'invid' => null,
                'id'    => wp_unique_id('tp-actions-'),
                'withsubgroups' => false,
                'btnclass' => 'btn button'
            ],
            $params,
            self::SHORTCODE_PEOPLE_LIST
        );

        /** @noinspection SpellCheckingInspection */
        $params['withsubgroups'] = !!$params['withsubgroups'];

        /** @noinspection SpellCheckingInspection */
        $iid = intval($params['invid']);

        // If there's no invId, try to get one from the Post
        if ($iid === 0) {
            $post = get_post();

            if (is_object($post)) {
                try {
                    $inv = Involvement::fromPost($post);
                    $iid = $inv->invId;
                } catch (TouchPointWP_Exception $e) {
                    $iid = null;
                }
            }
        }

        // Sync involvement lists with server, if that's what's being attempted right now.  Don't need to return any content.
        if (self::$_indexingMode) {
            if ($iid === null) {
                return "";
            }
            if (! isset(self::$_indexingQueries['inv'][$iid])) {
                /** @noinspection SpellCheckingInspection */
                self::$_indexingQueries['inv'][$iid] = [
                    'invId' => $iid,
                    'memTypes' => null,
//                    'subGroups' => null,
                    'with_subGroups' => false // populated below
                ];
            }

            // Populating here so all info is imported if an involvement is embedded multiple times with different parameters.
            /** @noinspection SpellCheckingInspection */
            self::$_indexingQueries['inv'][$iid]['with_subGroups'] =
                self::$_indexingQueries['inv'][$iid]['with_subGroups'] || $params['withsubgroups'];

            return "";
        }

        $WP_User_queryParams = [
            'meta_query' => ['relation' => 'AND'],

            // Sort by last name
            'meta_key' => 'last_name',
            'orderby' => 'meta_value',
            'order' => 'ASC'
        ];

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
        $btnClass = $params['btnclass'];

        $loadedPart = get_template_part('person-list', 'person-list');
        if ($loadedPart === false) {
            TouchPointWP::enqueuePartialsStyle();
            ob_start();
            /** @noinspection PhpIncludeInspection */
            require TouchPointWP::$dir . "/src/templates/parts/person-list.php";
            $out .= ob_get_clean();
        }
        // TODO DIR make sure this actually works with external partials.
        // TODO DIR provide an alternate if there are no people available.


        return $out;
    }

    /**
     * Gets Involvement Memberships.  If an involvement ID is provided, the matching membership is provided or null if no
     * membership exists.
     *
     * @return InvolvementMembership[]|InvolvementMembership
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
            $sql = "SELECT SUBSTR(mt.meta_key, $metaMemPrefixLength) AS iid, mt.meta_value AS mt, at.meta_value as at, d.meta_value AS descr
                    FROM $wpdb->usermeta AS mt
                    LEFT JOIN $wpdb->usermeta AS at ON CONCAT('$metaAttPrefix', SUBSTR(mt.meta_key, $metaMemPrefixLength)) = at.meta_key AND at.user_id = $this->ID
                    LEFT JOIN $wpdb->usermeta AS d ON CONCAT('$metaDescPrefix', SUBSTR(mt.meta_key, $metaMemPrefixLength)) = d.meta_key AND d.user_id = $this->ID
                    WHERE mt.user_id = $this->ID";

            if ($fetchAll) {
                $sql .= " AND mt.meta_key LIKE '$metaMemPrefix%'";
            } else {
                $sql .= " AND mt.meta_key = '$metaMemPrefix$iid'";
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
            return null;
        }
    }

    /**
     * @param array $args
     *
     * @return string|null
     *
     * @noinspection PhpUnused
     */
    public function getPictureUrl(array $args = []): ?string
    {
        return self::getPictureForPerson($this, $args);
    }

    /**
     * @param mixed $idEmailUserOrPerson
     * @param ?array $args
     *
     * @return string|null
     */
    public static function getPictureForPerson($idEmailUserOrPerson, ?array $args = []): ?string
    {
        $p = null;
        if (is_numeric($idEmailUserOrPerson)) {
            $p = Person::fromId($idEmailUserOrPerson);
        } elseif (is_object($idEmailUserOrPerson) && get_class($idEmailUserOrPerson) === self::class) {
            $p = $idEmailUserOrPerson;
        } elseif (is_object($idEmailUserOrPerson) && ! empty($idEmailUserOrPerson->user_id)) {
            $p = Person::fromId($idEmailUserOrPerson->user_id);
        } elseif (is_object($idEmailUserOrPerson) && ! empty($idEmailUserOrPerson->ID)) {
            $p = Person::fromId($idEmailUserOrPerson->ID);
        } elseif (is_string($idEmailUserOrPerson)) {
            $p = Person::from('email', $idEmailUserOrPerson);
        }

        if ($p === null) {
            return null;
        }

        $pictureData = $p->picture;

        if (!is_object($pictureData)) {
            return null;
        }

        if (!is_array($args) || (!isset($args['height']) || !isset($args['width'])) && !isset($args['size'])) {
            return $pictureData->large;
        }
        $h = max($args['size'] ?? 0, $args['height'] ?? 0);
        $w = max($args['size'] ?? 0, $args['width'] ?? 0);

        if ($w <= 50 && $h <= 50) {
            return $pictureData->thumb;
        }

        if ($w <= 120 && $h <= 120) {
            return $pictureData->small;
        }

        if ($w <= 320 && $h <= 400) {
            return $pictureData->medium;
        }

        return $pictureData->large;
    }

    /**
     * @param string|mixed $url The URL of the avatar.  Type is not guaranteed.
     * @param mixed $idEmailOrObject The person for whom to retrieve. Accepts a user_id, gravatar md5 hash, user email,
     *                          WP_User object, WP_Post object, or WP_Comment object.
     * @param array|mixed $args Arguments passed to get_avatar_data(…), after processing.  Type is not guaranteed.
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public static function pictureFilter($url, $idEmailOrObject, $args = []): ?string
    {
        return self::getPictureForPerson($idEmailOrObject, $args);
    }

    /**
     * Controls the list of Contact Methods visible on the wp-admin/user-edit view.  Allows admins to add/edit TouchPoint People IDs.
     * Array is keyed on the Meta name.
     *
     * @param $methods string[] Methods available before this filter.
     *
     * @return string[] Methods available after this filter.
     */
    public static function userContactMethods(array $methods): array
    {
        if (TouchPointWP::currentUserIsAdmin()) {
            $methods[self::META_PEOPLEID] = __("TouchPoint People ID", TouchPointWP::TEXT_DOMAIN);
        }

        return $methods;
    }

    /**
     * Run the updating cron task.  Fail quietly to not disturb the visitor experience if using WP default cron handling.
     *
     * @return void
     */
    public static function updateCron(): void
    {
        try {
            self::updateFromTouchPoint();
        } catch (Exception $ex) {
        }
    }

    /**
     * Updates the data for all People Lists in the site.
     *
     * @param bool $verbose Whether to print debugging info.
     *
     * @return false|int False on failure, or the number of partner posts that were updated or deleted.
     * @throws TouchPointWP_Exception
     */
    protected static function updateFromTouchPoint(bool $verbose = false)
    {
        global $wpdb;

        self::$_indexingQueries = TouchPointWP::newQueryObject();
        self::$_indexingQueries['context'] = "users";

        $pidMeta = self::META_PEOPLEID;
        $queryNeeded = false;

        $verbose &= TouchPointWP::currentUserIsAdmin();

        // Existing Users
        /** @noinspection SqlResolve */
//        $sql = "SELECT meta_value FROM $wpdb->usermeta WHERE meta_key = '$pidMeta'";  TODO restore when bvcms/bvcms#2166 is merged
//        self::$_indexingQueries['pid'] = $wpdb->get_col($sql);
//        if (count(self::$_indexingQueries['pid']) > 0) {
//            $queryNeeded = true;
//        }

        // Update People Indexes
        if (TouchPointWP::instance()->settings->enable_people_lists) {
            $posts = Utilities::getPostContentWithShortcode(self::SHORTCODE_PEOPLE_LIST);

            self::$_indexingMode = true;
            foreach ($posts as $postI) {
                global $post;
                $post = $postI;
                set_time_limit(10);
                apply_shortcodes($postI->post_content);
            }
            self::$_indexingMode = false;
        }

        // Update Involvement Leaders
        // TODO DIR this

        if (count(self::$_indexingQueries['inv']) > 0) {
            $queryNeeded = true;
        }

        // Prevent sync from running if plugin hasn't been configured and there's nothing to sync yet.
        if (!$queryNeeded) {
            if ($verbose)
                echo "Nothing to update";
            return 0;
        }

        // List needed Extra Value fields.
        // People EVs:
        $pevFieldIds = TouchPointWP::instance()->settings->people_ev_custom;
        $pevFieldIds[] = TouchPointWP::instance()->settings->people_ev_wpId . " | Int";
        if (!in_array(TouchPointWP::instance()->settings->people_ev_bio, $pevFieldIds)) {
            $pevFieldIds[] = TouchPointWP::instance()->settings->people_ev_bio;
        }
        self::$_indexingQueries['meta']['pev'] = TouchPointWP::instance()->getPersonEvFields($pevFieldIds);
        self::$_indexingQueries['context'] = 'peopleLists';

        // Submit to API
        $people = TouchPointWP::instance()->doPersonQuery(self::$_indexingQueries, $verbose, 50);

        // Parse the API results
        $count = self::updatePeopleFromApiData($people->people, $verbose);

        if ($count !== 0) {
            TouchPointWP::instance()->settings->set('person_cron_last_run', time());
            if ($verbose)
                echo "Success";
        }

        return $count;
    }

    /**
     * @param stdClass[] $people An array of objects from TouchPoint that each corresponds with a Person.
     * @param bool       $verbose
     *
     * @return int  False on failure.  Otherwise, the number of updates.
     */
    protected static function updatePeopleFromApiData(array $people, bool $verbose = false): int
    {
        $peopleUpdated = 0;

        $peopleWhoNeedWpIdUpdatedInTouchPoint = [];

        foreach ($people as $pData) {
            $peopleUpdated++;

            set_time_limit(30);
            $person = null;
            $updateWpIdInTouchPoint = true;

            // Find person by WordPress ID, if provided.
            $wpId = null;
            foreach ($pData->PeopleEV as $pev) {
                if ($pev->field === TouchPointWP::instance()->settings->people_ev_wpId && $pev->type === "Int") {
                    if (intval($pev->value) !== 0) {
                        $wpId = intval($pev->value);
                    }
                    break;
                }
            }
            if ($wpId !== null) {
                $q = new PersonQuery(
                    [
                        'include' => [$wpId]
                    ]
                );
                if ($q->get_total() > 0) { // Will only be 0 or 1, unless something has gone disastrously wrong.
                    $person = $q->get_first_result();
                    update_user_option($person->ID, self::META_PEOPLEID, $pData->PeopleId, true);
                    $updateWpIdInTouchPoint = false;
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
                    $person = $q->get_first_result();
                }
            }

            // Create new person
            if ($person === null) {
                if (Person::createUsers()) {
                    // Provision a new user, since we were unsuccessful in finding one.
                    set_time_limit(60);
                    $uid = wp_create_user(self::generateUserName($pData), com_create_guid()); // Email addresses are imported/updated later, which prevents notification emails.
                    if (is_numeric($uid)) { // user was successfully generated.
                        update_user_option($uid, 'created_by', 'TouchPoint-WP', true);
                        update_user_option($uid, self::META_PEOPLEID, $pData->PeopleId, true);
                        $person = new Person($uid);
                    }
                }
            }

            // User doesn't exist.
            if ($person === null) {
                continue;
            }

            if ($updateWpIdInTouchPoint) {
                $peopleWhoNeedWpIdUpdatedInTouchPoint[] = [
                    'PeopleId'  => $pData->PeopleId,
                    'WpId'      => $person->ID
                ];
            }

            // Make sure the PeopleId is correct, just in case.
            $person->updatePeopleId($pData->PeopleId);

            $person->first_name = $pData->GoesBy;
            $person->last_name = $pData->LastName;
            $person->display_name = $pData->DisplayName;
            if (count($pData->Emails) > 0) {
                $person->user_email = $pData->Emails[0];
            } else {
                $person->user_email = null;
            }
            $person->picture = $pData->Picture;

            // Deliberately do not update usernames or passwords, as those could be set by any number of places for any number of reasons.

            // Apply EV Types
            $pData->PeopleEV = ExtraValueHandler::jsonToDataTyped($pData->PeopleEV ?? (object)[]);

            // Apply Custom EVs
            $fields = TouchPointWP::instance()->getPersonEvFields(TouchPointWP::instance()->settings->people_ev_custom);
            foreach ($fields as $fld) {
                if (isset($pData->PeopleEV->{$fld->hash})) {
                    $person->setExtraValueWP($fld->field, $pData->PeopleEV->{$fld->hash}->value);
                } else {
                    $person->removeExtraValueWP($fld->field);
                }
            }
            unset($fields, $fld);

            // Apply Bio EV
            $bioField = TouchPointWP::instance()->settings->people_ev_bio;
            if ($bioField !== "") {
                if (isset($pData->PeopleEV->$bioField)) {
                    update_user_meta($person->ID, 'description', $pData->PeopleEV->$bioField->value);
                } else {
                    update_user_meta($person->ID, 'description', '');
                }
            }
            unset($bioField);

            // Removal of no-longer valid EV Fields happens within Cleanup::cleanupPersonEVs

            // Involvements!
            if (isset($pData->Inv)) {
                $currentInvs = $person->getInvolvementMemberships();
                $inv_del     = array_keys($currentInvs);
                $inv_updates = [];

                // Process diffs, as appropriate.
                foreach ($pData->Inv as $i) {
                    if (($key = array_search($i->iid, $inv_del)) !== false) {
                        unset($inv_del[$key]);
                    }

                    if ( ! isset($currentInvs[$i->iid])) {
                        $inv_updates[self::META_INV_MEMBER_PREFIX . $i->iid] = $i->memType;
                        $inv_updates[self::META_INV_ATTEND_PREFIX . $i->iid] = $i->attType;
                        $inv_updates[self::META_INV_DESC_PREFIX . $i->iid]   = $i->descr;
                        continue;
                    }

                    if ($currentInvs[$i->iid]->mt !== $i->memType) {
                        $inv_updates[self::META_INV_MEMBER_PREFIX . $i->iid] = $i->memType;
                    }
                    if ($currentInvs[$i->iid]->at !== $i->attType) {
                        $inv_updates[self::META_INV_ATTEND_PREFIX . $i->iid] = $i->attType;
                    }
                    if ($currentInvs[$i->iid]->description !== $i->descr) {
                        $inv_updates[self::META_INV_DESC_PREFIX . $i->iid] = $i->descr;
                    }
                }

                foreach ($inv_updates as $k => $v) {
                    if ($v === null || trim($v) === '') { // don't waste space on blanks--especially descriptions.
                        delete_user_option($person->ID, $k, true);
                    } else {
                        update_user_option($person->ID, $k, $v, true);
                    }
                }
                foreach ($inv_del as $iid) {
                    delete_user_option($person->ID, self::META_INV_MEMBER_PREFIX . $iid, true);
                    delete_user_option($person->ID, self::META_INV_ATTEND_PREFIX . $iid, true);
                    delete_user_option($person->ID, self::META_INV_DESC_PREFIX . $iid, true);
                }
            }

            if ($verbose) {
                var_dump($pData);
                var_dump($person);
                echo "<hr />";
            }

            // Submit update.
            $person->submitUpdate();
        }
        set_time_limit(30);

        if (count($peopleWhoNeedWpIdUpdatedInTouchPoint) > 0) {
            self::updatePeopleWordPressIDs($peopleWhoNeedWpIdUpdatedInTouchPoint);
            TouchPointWP::instance()->updatePersonEvFields(); // force update to account for new WordPress IDs
        }

        return $peopleUpdated;
    }

    /**
     * Save any user updates back to the database.
     */
    protected function submitUpdate(): void
    {
        if (count($this->_userFieldsToUpdate) > 0) {
            add_filter('send_password_change_email', '__return_false');
            add_filter('send_email_change_email', '__return_false');
            wp_update_user($this->fieldsForUpdate());
            remove_filter('send_password_change_email', '__return_false');
            remove_filter('send_email_change_email', '__return_false');
            $this->_userFieldsToUpdate = [];
        }

        foreach ($this->_userMetaToUpdate as $f) {
            update_user_option($this->ID, TouchPointWP::SETTINGS_PREFIX . $f, $this->_meta[$f], true);
        }
        $this->_userMetaToUpdate = [];
    }

    /**
     * @param array $changes
     *
     * @return void
     */
    protected static function updatePeopleWordPressIDs(array $changes)
    {
        try {
            TouchPointWP::instance()->apiPost('person_wpIds', [
                'people' => $changes,
                'evName' => TouchPointWP::instance()->settings->people_ev_wpId
            ]);
        } catch (Exception $ex) { // If it fails this time, it'll probably get fixed next time
        }
    }

    /**
     * Returns the html with buttons for actions the user can perform.  This must be called *within* an element with the
     * `data-tp-person` attribute with the invId as the value.
     *
     * @param ?string $context A reference to where the action buttons are meant to be used.
     * @param string  $btnClass A string for classes to add to the buttons.  Note that buttons can be a or button elements.
     *
     * @return string
     */
    public function getActionButtons(string $context = null, string $btnClass = ""): string
    {
        TouchPointWP::requireScript('swal2-defer');
        TouchPointWP::requireScript('base-defer');
        $this->enqueueForJsInstantiation();

        if ($btnClass !== "") {
            $btnClass = " class=\"$btnClass\"";
        }

        $text = __("Contact", TouchPointWP::TEXT_DOMAIN);
        TouchPointWP::enqueueActionsStyle('person-contact');
        $ret = "<button type=\"button\" data-tp-action=\"contact\" $btnClass>$text</button>  ";

        return apply_filters(TouchPointWP::HOOK_PREFIX . "person_actions", $ret, $this, $context, $btnClass);
    }

    /**
     * Ensure a person with a given PeopleId will be available in JS.
     *
     * @param int $pid TouchPoint People ID
     *
     * @return ?bool null if person is not found.  True if newly enqueued, false if it was already enqueued.
     */
    public static function enqueueForJS_byPeopleId(int $pid): ?bool
    {
        $p = self::fromPeopleId($pid);
        if ($p === null) {
            return null;
        }
        return $p->enqueueForJsInstantiation();
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
            'displayName' => $this->display_name,
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

        return "\ttpvm.addOrTriggerEventListener('Person_class_loaded', function() {
        TP_Person.fromObjArray($listStr);\n\t});\n";
    }

    /**
     * Determines whether JS Instantiation should be used.
     *
     * @return bool
     */
    public static function useJsInstantiation(): bool
    {
        return count(static::$queueForJsInstantiation) > 0;
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
        $fields = $this->_userFieldsToUpdate;
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
     * @return ?string  Returns a human-readable list of names, nicely formatted with commas and such.
     */
    public static function arrangeNamesForPeople(array $people): ?string
    {
        $people = self::groupByFamily($people);
        if (count($people) === 0) {
            return null;
        }

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

    /**
     * Input a "family" of Person-like objects and get a human-friendly string of their names.  Returns null if no
     * suitable people are provided.
     *
     * This is only meant to be called within arrangeNamesForPeople
     *
     * @param array $family
     *
     * @return ?string Returns a human-readable list of names, nicely formatted with commas and such.
     */
    protected static function formatNamesForFamily(array $family): ?string
    {
        if (count($family) < 1)
            return null;

        $standingLastName = $family[0]->LastName;
        $string = "";

        usort($family, fn($a, $b) => ($a->GenderId ?? 0) <=> ($b->GenderId ?? 0)); // TODO use something a little more intelligent (head first)

        $first = true;
        foreach ($family as $p) {
            if ($standingLastName != $p->LastName) {
                $string .= " " . $standingLastName;

                $standingLastName = $p->LastName;
            }

            if (!$first && count($family) > 1)
                $string  .= " & ";

            $string .= $p->GoesBy;

            $first = false;
        }
        $string .= " " . $standingLastName;

        $lastAmpPos = strrpos($string, " & ");
        return str_replace(" & ", ", ", substr($string, 0, $lastAmpPos)) . substr($string, $lastAmpPos);
    }

    public static function groupByFamily(array $people): array
    {
        $families = [];
        foreach ($people as $p) {
            if ($p === null) {
                continue;
            }

            $fid = intval($p->FamilyId);

            if (!array_key_exists($fid, $families))
                $families[$fid] = [];

            $families[$fid][] = $p;
        }
        return $families;
    }

    private static function ajaxIdent(): void
    {
        $inputData = TouchPointWP::postHeadersAndFiltering();

        try {
            $inputData = json_decode($inputData);
            $inputData->context = "ident";
            $data = TouchPointWP::instance()->apiPost('ident', $inputData, 30);
        } catch (Exception $ex) {
            echo json_encode(['error' => $ex->getMessage()]);
            exit;
        }

        $people = $data->people ?? [];

        // TODO consider adding person as a user and/or authenticating user.

        $ret = [];
        foreach ($people as $p) {
            $ret[] = [
                'goesBy' => $p->GoesBy,
                'lastName' => $p->LastName[0] ? $p->LastName[0] . "." : "",
                'displayName' => trim($p->GoesBy . " " . ($p->LastName[0] ? $p->LastName[0] . "." : "")),
                'familyId' => $p->FamilyId,
                'peopleId' => $p->PeopleId
            ];
        }

        echo json_encode([
            'people' => $ret,
            'primaryFam' => $data->primaryFam ?? []
        ]);
        exit;
    }

    private static function ajaxSrc(): void
    {
        $q['q'] = $_GET['q'] ?? '';
        $q['context'] = 'src';


        if ($q['q'] !== '') {
            try {
                $data = TouchPointWP::instance()->apiGet('src', $q, 30);
                $data = $data->people ?? [];
            } catch (Exception $ex) {
                echo json_encode(['error' => $ex->getMessage()]);
                exit;
            }
        } else {
            $data = [];
        }

        $out = [];
        if ($_GET['fmt'] == "s2") {
            $out['fmt'] = "select2";
            $out['pagination'] = [
                'more' => false
            ];

            $out['results'] = [];
            foreach ($data as $p) {
                $out['results'][] = [
                    'id' => $p->peopleId,
                    'text' => $p->goesBy . " " . $p->lastName
                ];
            }

        } else {
            $out = $data;
        }

        echo json_encode($out);
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

            case "src":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_PRIVATE);
                self::ajaxSrc();
                exit;

            case "contact":
                self::ajaxContact();
                exit;

            case "force-sync":
                TouchPointWP::doCacheHeaders(TouchPointWP::CACHE_NONE);
                try {
                    echo self::updateFromTouchPoint(true);
                } catch (Exception $ex) {
                    echo "Update Failed: " . $ex->getMessage();
                }
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

        $kw = TouchPointWP::instance()->settings->people_contact_keywords;
        $inputData->keywords = Utilities::idArrayToIntArray($kw);

        try {
            $data = TouchPointWP::instance()->apiPost('person_contact', $inputData);
        } catch (Exception $ex) {
            echo json_encode(['error' => $ex->getMessage()]);
            exit;
        }

        echo json_encode(['success' => $data->success]);
        exit;
    }

    public static function load(): bool
    {
        if (self::$_isLoaded) {
            return true;
        }

        self::$_isLoaded = true;

        if ( ! shortcode_exists(self::SHORTCODE_PEOPLE_LIST) && TouchPointWP::instance()->settings->enable_people_lists === "on") {
            add_shortcode(self::SHORTCODE_PEOPLE_LIST, [self::class, "peopleListShortcode"]);
        }

        add_action('init', [self::class, 'checkUpdates']);

        // Setup cron for updating People daily.
        add_action(self::CRON_HOOK, [self::class, 'updateCron']);
        if ( ! wp_next_scheduled(self::CRON_HOOK)) {
            // Runs at 6:30am EST (11:30am UTC), hypothetically after TouchPoint runs its Morning Batches.
            wp_schedule_event(
                date('U', strtotime('tomorrow') + 3600 * 11.5),
                'daily',
                self::CRON_HOOK
            );
        }

        // Add filter for TouchPoint pictures to be used as avatars.  Priority is high so this can be easily overridden by
        // another plugin if desired.
        add_filter('get_avatar_url', [self::class, 'pictureFilter'], 10, 3);

        // Add filter to allow admins to set a TouchPoint PeopleId
        add_filter('user_contactmethods', [self::class, 'userContactMethods'], 10);

        return true;
    }

    /**
     * Run cron if it hasn't been run before or is overdue.
     */
    public static function checkUpdates(): void
    {
        if (TouchPointWP::instance()->settings->person_cron_last_run * 1 < time() - 86400 - 3600) {
            try {
                self::updateFromTouchPoint();
            } catch (Exception $ex) {
            }
        }
    }

    /**
     * Determines whether users should be imported when presented through a sync process.
     *
     * @return bool
     */
    protected static function createUsers(): bool
    {
        return TouchPointWP::instance()->settings->enable_people_lists === "on";
    }

    /**
     * Get an Extra Value.
     *
     * @param string $name The name of the extra value to get.
     *
     * @return mixed  The value of the extra value.  Returns null if it doesn't exist.
     */
    public function getExtraValue(string $name)
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        return get_user_option(self::META_PEOPLE_EV_PREFIX . $name, $this->ID);
    }

    /**
     * Set an extra value in WordPress.  Value should already be converted to appropriate datatype (e.g. DateTime)
     *
     * DOES NOT SET THE EXTRA VALUE IN TouchPoint, AND IS ONLY MEANT TO BE USED AS PART OF A SYNC PROCEDURE.
     *
     * @param string $name The name of the extra value to set.
     * @param mixed $value The value to set.
     *
     * @return bool|int User meta ID if field did not exist, true on successful update, false on failure.
     */
    protected function setExtraValueWP(string $name, $value)
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        return update_user_option($this->ID, self::META_PEOPLE_EV_PREFIX . $name, $value, true);
    }

    /**
     * Remove an extra value in WordPress.
     *
     * DOES NOT REMOVE IT IN TouchPoint, AND IS ONLY MEANT TO BE USED AS PART OF A SYNC PROCEDURE.
     *
     * @param string $name The name of the extra value to remove.
     *
     * @return bool True on Success, False on failure.
     */
    protected function removeExtraValueWP(string $name): bool
    {
        $name = ExtraValueHandler::standardizeExtraValueName($name);
        return delete_user_option($this->ID, self::META_PEOPLE_EV_PREFIX . $name, true);
    }
}