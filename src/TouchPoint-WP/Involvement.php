<?php
namespace tp\TouchPointWP;
use WP_Post;

/**
 * Class Involvement - Fundamental object meant to correspond to an Involvement in TouchPoint
 * TODO: explore whether this can (or should) extend a WP_Post object
 *
 * @package tp\TouchPointWP
 */
abstract class Involvement
{
    public string $name;
    public int $invId;
    public int $post_id;
    public string $post_excerpt;
    protected WP_Post $post;

    const INVOLVEMENT_META_KEY = TouchPointWP::SETTINGS_PREFIX . "invId";

    public object $attributes;

    /**
     * Involvement constructor.
     *
     * @param $object WP_Post|object an object representing the small group/post.
     *                  Must have post_id and inv id attributes.
     */
    protected function __construct(object $object)
    {
        $this->attributes = (object)[];

        if (gettype($object) === "object" && get_class($object) == WP_Post::class) {
            /** @var $object WP_Post */
            // WP_Post Object
            $this->post = $object;
            $this->name = $object->post_title;
            $this->invId = intval($object->{self::INVOLVEMENT_META_KEY});
            $this->post_id = $object->ID;

        } elseif (gettype($object) === "object") {
            // Sql Object, probably.

            if (!property_exists($object, 'post_id'))
                _doing_it_wrong(
                    __FUNCTION__,
                    esc_html(__('Creating an Involvement object from an object without a post_id is not yet supported.')),
                    esc_attr(TouchPointWP::VERSION)
                );

            /** @noinspection PhpFieldAssignmentTypeMismatchInspection  The type is correct. */
            $this->post = get_post($object, "OBJECT");

            foreach ($object as $property => $value) {
                if (property_exists(self::class, $property)) {
                    $this->$property = $value;
                } // TODO does this deal with properties in inheritors?

                // TODO add an else for nonstandard/optional metadata fields
            }
        }
    }
}