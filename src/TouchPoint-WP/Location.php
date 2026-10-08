<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

use tp\TouchPointWP\Interfaces\hasGeo;

/**
 * A Location is generally a physical place, with an internet connection.  These likely correspond to campuses, but
 * don't necessarily need to.
 */
class Location implements hasGeo
{
	protected static ?array $_locations = null;

	public string $name;
	public ?float $lat;
	public ?float $lng;
	public float $radius; // miles
	public array $ipAddresses;

	protected function __construct($data)
	{
		$this->lat         = Utilities::toFloatOrNull($data->lat ?? null);
		$this->lng         = Utilities::toFloatOrNull($data->lng ?? null);
		$this->name        = $data->name;
		$this->radius      = Utilities::toFloatOrNull($data->radius ?? null, 2) ?? 0.1;

        if (isset($data->ipAddresses) && is_array($data->ipAddresses)) {
            $this->ipAddresses = array_values(
                array_filter($data->ipAddresses, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP))
            );
        } else {
            $this->ipAddresses = [];
        }
	}

	/**
	 * Get an array of the
	 *
	 * @return Location[]
	 */
	public static function getLocations(): array
	{
		if (self::$_locations === null) {
			$s = TouchPointWP::instance()->settings->locations_json;
			$s = json_decode($s);

			self::$_locations = [];
			foreach ($s as $l) {
				self::$_locations[] = new Location($l);
			}
		}

		return self::$_locations;
	}

	public static function getLocationForIP(?string $ipAddress = null): ?Location
	{
		$ipAddress = $ipAddress ?? Utilities::getClientIp();

		$s = TouchPointWP::instance()->settings->locations_json;
		if ( ! str_contains($s, "\"" . $ipAddress . "\"")) {
			return null;
		}

		$locs = self::getLocations();
		foreach ($locs as $l) {
			if (in_array($ipAddress, $l->ipAddresses, true)) {
				return $l;
			}
		}

		return null;
	}

	/**
	 * Indicates whether this particular location has lat/lng location.
	 *
	 * @return bool
	 */
	public function hasGeo(): bool
	{
		return $this->lat !== null && $this->lng !== null;
	}

	public function asGeoIFace(string $type = "unknown"): ?Geo
	{
		if ($this->hasGeo()) {
			return new Geo(
				$this->lat,
				$this->lng,
				$this->name,
				$type
			);
		}

		return null;
	}

	/**
	 * Get a location that corresponds to a given lat/lng, or null if none match.
	 *
	 * @param float $lat
	 * @param float $lng
	 *
	 * @return Location|null
	 */
	public static function getLocationForLatLng(float $lat, float $lng): ?Location
	{
		$locs = self::getLocations();
		foreach ($locs as $l) {
			$d = Geo::distance($lat, $lng, $l->lat, $l->lng);
			if ($d <= $l->radius) {
				return $l;
			}
		}

		return null;
	}

	/**
	 * Validates and corrects the Locations settings value.
	 *
	 * @param string $settings
	 *
	 * @return string
	 */
	public static function validateSetting(string $settings): string
    {
		$d = json_decode($settings);
        $do = [];

        if (!is_array($d)) {
            return json_encode([]);
        }

		foreach ($d as $l) {
            $do[] = new self($l);
		}

		return json_encode($do);
	}

	/**
	 * Get the name of the location.
	 *
	 * @return ?string
	 */
	public function locationName(): ?string
	{
		return $this->name;
	}
}