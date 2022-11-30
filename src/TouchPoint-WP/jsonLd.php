<?php
/**
 * @package TouchPointWP
 */
namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
    exit(1);
}


/**
 * Used for client-side instantiation
 */
trait jsonLd {

	/** @var jsonLd[] */
    private static array $queueForJsonLd = [];

	public int $post_id;

    /**
     * Add to a queue for instantiation.
     *
     * @return bool True if added to queue, false if already in queue.
     */
    protected function enqueueForJsonLdInstantiation(): bool
    {
		$link = $this->getPermalink();
        if (!isset(static::$queueForJsonLd[$link])) {
            static::$queueForJsonLd[$link] = $this;
            return true;
        }
        return false;
    }

    /**
     * Returns the permalink corresponding to the JSON-LD object.
     *
     * @return string
     */
    public function getPermalink(): string
    {
		return get_permalink($this->post_id);
    }

	/**
	 * return an object that turns into JSON-LD as an event, compliant with schema.org.  Return null if the object can't
	 * be printed to jsonLd for some reason (e.g. required fields are missing).
	 *
	 * @return ?object
	 */
    public abstract function toJsonLD(): ?object;

    /**
     * Get the JS for instantiation.
     *
     * @return void
     */
    public static function printJsonLd(): void
    {
		$r = array_map(fn($j) => $j->toJsonLD(), self::$queueForJsonLd);
		$r = array_filter($r, 'is_array');
		$r = array_values($r);
		print json_encode($r);
    }
}