<?php
/**
 * @package TouchPointWP
 */

namespace tp\TouchPointWP;

/**
 * A Location is generally a physical place, with an internet connection.  These likely correspond to campuses, but
 * don't necessarily need to.
 */
class Location implements geo
{
	protected static ?array $_locations = null;

	public string $name;
	public ?float $lat;
	public ?float $lng;
	public float $radius;
	public array $ipAddresses;

	protected function __construct($data)
	{
		$this->ipAddresses = $data->ipAddresses ?? [];
		$this->lat         = Utilities::toFloatOrNull($data->lat);
		$this->lng         = Utilities::toFloatOrNull($data->lng);

		$this->name = $data->name;

		$this->radius = $data->radius ?? 0.1;
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

	public static function getLocationForIP(string $ipAddress = null): ?Location
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

	public function asGeoIFace(string $type = "unknown"): ?object
	{
		if ($this->hasGeo()) {
			return (object)[
				'lat'   => $this->lat,
				'lng'   => $this->lng,
				'human' => $this->name,
				'type'  => $type
			];
		}

		return null;
	}

	public static function getLocationForLatLng(float $lat, float $lng): ?Location
	{
		$locs = self::getLocations();
		foreach ($locs as $l) {
			$d = Utilities\Geo::distance($lat, $lng, $l->lat, $l->lng);
			if ($d <= $l->radius) {
				return $l;
			}
		}

		return null;
	}

	public static function validateSetting(string $settings)
	{
		$d = json_decode($settings);
		foreach ($d as $l) {
			foreach ($l as $k => $v) {
				if ( ! property_exists(self::class, $k)) {
					unset($l->$k);
				}
			}
			$l->lat    = Utilities::toFloatOrNull($l->lat);
			$l->lng    = Utilities::toFloatOrNull($l->lng);
			$l->radius = Utilities::toFloatOrNull($l->radius, 1);

			$l->ipAddresses = array_values(
				array_filter($l->ipAddresses, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP))
			);
		}

		return json_encode($d);
	}
}