<?php
namespace tp\TouchPointWP;

use Exception;

if ( ! defined('ABSPATH')) {
    exit(1);
}

/**
 * Involvement_PostTypeSettings.  This is instantiated as an array of these objects.
 *
 * @property-read string $nameSingular
 * @property-read string $namePlural
 * @property-read string $slug
 * @property-read string[] $importDivs
 * @property-read bool $useGeo
 * @property-read string[] $leaderTypes
 * @property-read string[] $hostTypes
 * @property-read string[] $filters
 * @property-read int[] $contactKeywords
 * @property-read int[] $joinKeywords
 * @property-read string $postType
 */
class Involvement_PostTypeSettings {
    protected static array $settings;

    protected string $nameSingular;
    protected string $namePlural;
    protected string $slug;
    protected array $importDivs;
    protected bool $useGeo;
    protected array $leaderTypes;
    protected array $hostTypes;
    protected array $filters;
    protected array $contactKeywords;
    protected array $joinKeywords;
    protected ?string $postType = null;

    /**
     * @return Involvement_PostTypeSettings[]
     */
    final public static function &instance(): array
    {
        if (! isset(self::$settings)) {
            $json = json_decode(TouchPointWP::instance()->settings->inv_json);
            $settingsArr = [];

            foreach ($json as $o) {
                $settingsArr[] = new Involvement_PostTypeSettings($o);
            }

            self::$settings = $settingsArr;
        }
        return self::$settings;
    }

    /**
     * @param object $o
     */
    public function __construct(object $o)
    {
        foreach ($o as $k => $v) {
            if (property_exists(self::class, $k)) {
                $this->$k = $v;
            }
        }
    }

    public function postTypeWithPrefix(): string
    {
        self::instance();

        return TouchPointWP::HOOK_PREFIX . $this->postType;
    }

    public function postTypeWithoutPrefix(): string
    {
        self::instance();

        return $this->postType;
    }

    public function __get($what)
    {
        self::instance(); // ensures object has been instantiated.

        if (property_exists(self::class, $what)) {
            if ($what === "postType") {
                return $this->postTypeWithPrefix();
            }

            return $this->$what;
        }
        return TouchPointWP_Settings::UNDEFINED_PLACEHOLDER;
    }

    /**
     * Gets an array of the postType strings.
     *
     * @return string[]
     */
    final public static function getPostTypes(): array
    {
        $ret = [];
        foreach (self::instance() as $type) {
            if (isset($type->postType)) {
                $ret[] = $type->postTypeWithPrefix();
            }
        }
        return $ret;
    }

    /**
     * Gets an array of the postType strings for post type that use Geo.
     *
     * @return string[]
     */
    final public static function getPostTypesWithGeoEnabled(): array
    {
        $ret = [];
        foreach (self::instance() as $type) {
            if ($type->useGeo && isset($type->postType)) {
                $ret[] = $type->postTypeWithPrefix();
            }
        }
        return $ret;
    }

    /**
     * Gets the Involvement Post Type Settings object for a given post type.
     *
     * @param string $postType
     *
     * @return Involvement_PostTypeSettings|null
     */
    public static function getForPostType(string $postType): ?Involvement_PostTypeSettings
    {
        foreach (self::instance() as $type) {
            if ($type->postType === $postType || $type->__get('postType') === $postType) {
                return $type;
            }
        }
        return null;
    }

    /**
     * @param string $new
     *
     * @return string
     *
     * @zthrows Exception
     */
    public static function validateNewSettings(string $new): string
    {
        $postTypesSlugs = [];
        $postTypeStrings = [];
        $new = json_decode($new);

        $settings = TouchPointWP::instance()->settings;

        $old = json_decode($settings->inv_json);
        $oldPostTypeStrings = [];
        foreach ($old as $type) {
            $oldPostTypeStrings[] = $type->postType;
        }

        // Validate (or forcibly replace) slugs
        foreach ($new as $type) {
            $first = true;
            $lower = preg_replace('/\W+/', '-', strtolower($type->slug));
            if ($lower !== $type->slug) { // force slug to be lowercase
                $type->slug = $lower;
                TouchPointWP::queueFlushRewriteRules();
            }
            $count = 1;
            while ( // all the conditions in which the post type will need to be regenerated.
                in_array($type->slug, $postTypesSlugs)
            ) {
                $name = preg_replace('/\W+/', '-', strtolower($type->namePlural));
                try {
                    $type->slug = $name . ($first ? "" : "-" . bin2hex(random_bytes(1)));
                } catch (Exception $e) {
                    $type->slug = $name . ($first ? "" : "-" . bin2hex($count++));
                }
                $first = false;
                TouchPointWP::queueFlushRewriteRules();
            }
            $postTypesSlugs[] = $type->slug;
        }

        // Generate new Post Type strings
        foreach ($new as $type) {
            $first = true;
            if (!isset($type->postType) || $type->postType === "") {
                $type->postType = null;
            }
            if ($type->postType !== null) {
                $lower = preg_replace('/\W+/', '', strtolower($type->postType));
                if ($lower !== $type->postType) { // force postType to be lowercase
                    $type->postType = $lower;
                    TouchPointWP::queueFlushRewriteRules();
                }
            }
            $count = 1;
            while ( // all the conditions in which the post type will need to be regenerated.
                $type->postType === null ||
                in_array($type->postType, $postTypeStrings) ||
                (
                    $type->postType !== "smallgroup" &&
                    $type->postType !== "course" &&
                    substr($type->postType, 0, 4) !== "inv_"
                )
            ) {
                $slug = preg_replace('/\W+/', '', strtolower($type->slug));
                try {
                    $type->postType = "inv_" . $slug . ($first ? "" : "_" . bin2hex(random_bytes(1)));
                } catch (Exception $e) {
                    $type->postType = "inv_" . $slug . ($first ? "" : "_" . bin2hex($count++));
                }
                $first = false;
                $type->postType = preg_replace('/\W+/', '_', $type->postType);
                TouchPointWP::queueFlushRewriteRules();
            }
            $postTypeStrings[] = $type->postType;
        }

        // Delete Posts from defunct types
        foreach ($oldPostTypeStrings as $typeString) {
            if (in_array($typeString, $postTypeStrings)) {
                continue;
            }
            /** @noinspection SpellCheckingInspection */
            $postsToRm = get_posts(['post_type' => TouchPointWP::HOOK_PREFIX . $typeString, 'numberposts' => -1 ]);
            foreach ($postsToRm as $p) {
                wp_delete_post($p->ID, true);
            }
        }

        // If there are new types, do some housekeeping.
        foreach ($postTypeStrings as $typeString) {
            if (! in_array($typeString, $oldPostTypeStrings)) {
                // There's something new here.
                $settings->set('inv_cron_last_run', 0);
                break;
            }
        }

        return json_encode($new);
    }
}