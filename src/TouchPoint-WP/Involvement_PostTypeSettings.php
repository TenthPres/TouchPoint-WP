<?php
namespace tp\TouchPointWP;

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
 * @property-read string $postType
 */
class Involvement_PostTypeSettings {
    private static TouchPointWP $tpwp;
    protected static array $settings;

    protected string $nameSingular;
    protected string $namePlural;
    protected string $slug;
    protected array $importDivs;
    protected bool $useGeo;
    protected array $leaderTypes;
    protected array $hostTypes;
    protected array $filters;
    protected ?string $postType = null;

    /**
     * @return Involvement_PostTypeSettings[]
     */
    final public static function &instance(): array
    {
        if (! isset(self::$tpwp)) {
            self::$tpwp = TouchPointWP::instance();
        }
        if (! isset(self::$settings)) {
            $json = json_decode(self::$tpwp->settings->inv_json);
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
}