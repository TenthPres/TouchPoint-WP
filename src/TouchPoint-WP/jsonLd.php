<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

if ( ! defined('ABSPATH')) {
	exit(1);
}


/**
 * Some items should be indexable by search engines using standards such as JSON-LD.  This trait provides the base
 * variables and interface for standard generation of this markup.
 */
trait jsonLd
{

	/** @var jsonLd[] */
	protected static array $queueForJsonLd = [];

	public int $post_id;

	/**
	 * Add to a queue for instantiation.
	 *
	 * @return bool True if added to queue, false if already in queue.
	 */
	protected function enqueueForJsonLdInstantiation(): bool
	{
		$link = $this->getPermalink();
		if ( ! isset(static::$queueForJsonLd[$link])) {
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
	 * Return an object that turns into JSON-LD as an event, compliant with schema.org.  Return null if the object can't
	 * be printed to jsonLd for some reason (e.g. required fields are missing).
	 *
	 * @return ?object
	 */
	public abstract function toJsonLD(): ?array;

	/**
	 * Print the full JSON-LD info, including script tags.
	 *
	 * @return void
	 */
	public static function printJsonLd(): void
	{
		$r = array_map(fn($j) => $j->toJsonLD(), static::$queueForJsonLd);
		$r = array_filter($r, 'is_array');
		$r = array_values($r);

		if (count($r) > 0) {
			echo "<script type=\"application/ld+json\">";
			print json_encode($r);
			echo "</script>";
		}
	}
}