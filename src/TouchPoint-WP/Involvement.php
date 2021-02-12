<?php


namespace tp\TouchPointWP;

/**
 * Class Involvement - Fundamental object meant to correspond to an Involvement in TouchPoint
 *
 * @package tp\TouchPointWP
 */
abstract class Involvement
{
    public string $name;
    public int $invId;
    public int $post_id;
    public string $post_excerpt;
    protected \WP_Post $post;

    const INVOLVEMENT_META_KEY = TouchPointWP::SETTINGS_PREFIX . "invId";

    protected function __construct($invIdOrObj)
    {
        if (is_numeric($invIdOrObj)) {
            $this->invId = intval($invIdOrObj);
            return; // TODO get post; remove return;

        } elseif (gettype($invIdOrObj) === "object" && get_class($invIdOrObj) == \WP_Post::class) {
            // WP_Post Object
            $this->post = $invIdOrObj;
            $this->invId = intval($invIdOrObj->{self::INVOLVEMENT_META_KEY});

        } elseif (gettype($invIdOrObj) === "object") {
            // Sql Object, probably.

            if (!property_exists($invIdOrObj, 'post_id'))
                _doing_it_wrong(
                    __FUNCTION__,
                    esc_html(__('Creating an Involvement object from an object without a post_id is not yet supported.')),
                    esc_attr(TouchPointWP::VERSION)
                );

            foreach ($invIdOrObj as $property => $value) {
                if (property_exists(self::class, $property)) {
                    $this->$property = $value;
                } // TODO does this deal with properties in inheritors?

                // TODO add an else for nonstandard/optional metadata fields
            }
        }
    }
}