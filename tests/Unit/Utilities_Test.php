<?php
/**
 * Tests for the Utilities class
 *
 * @package TouchPointWP\Tests\Unit
 */

namespace tp\TouchPointWP\Tests\Unit;

use DateTime;
use tp\TouchPointWP\Utilities;
use tp\TouchPointWP\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Test case for the Utilities class.
 *
 * @covers \tp\TouchPointWP\Utilities
 */
class Utilities_Test extends TestCase
{
	/**
	 * Tests that change $_SERVER are put back afterward.
	 *
	 * @var array
	 */
	private array $serverBackup = [];

	protected function set_up(): void
	{
		parent::set_up();

		$this->serverBackup = $_SERVER;
	}

	protected function tear_down(): void
	{
		$_SERVER = $this->serverBackup;

		parent::tear_down();
	}

	/**
	 * Test toFloatOrNull returns null for non-numeric values.
	 */
	public function test_toFloatOrNull_nonNumeric(): void
	{
		$this->assertNull(Utilities::toFloatOrNull('not a number'));
		$this->assertNull(Utilities::toFloatOrNull('abc'));
		$this->assertNull(Utilities::toFloatOrNull([]));
		$this->assertNull(Utilities::toFloatOrNull(null));
	}

	/**
	 * Test toFloatOrNull converts numeric strings to float.
	 */
	public function test_toFloatOrNull_numericStrings(): void
	{
		$this->assertSame(123.0, Utilities::toFloatOrNull('123'));
		$this->assertSame(123.45, Utilities::toFloatOrNull('123.45'));
		$this->assertSame(-45.67, Utilities::toFloatOrNull('-45.67'));
	}

	/**
	 * Test toFloatOrNull converts integers to float.
	 */
	public function test_toFloatOrNull_integers(): void
	{
		$this->assertSame(123.0, Utilities::toFloatOrNull(123));
		$this->assertSame(0.0, Utilities::toFloatOrNull(0));
		$this->assertSame(-456.0, Utilities::toFloatOrNull(-456));
	}

	/**
	 * Test toFloatOrNull with rounding.
	 */
	public function test_toFloatOrNull_rounding(): void
	{
		$this->assertSame(123.46, Utilities::toFloatOrNull(123.456, 2));
		$this->assertSame(123.5, Utilities::toFloatOrNull(123.456, 1));
		$this->assertSame(123.0, Utilities::toFloatOrNull(123.456, 0));
	}

	/**
	 * Test dateTimeNow returns DateTimeImmutable.
	 */
	public function test_dateTimeNow_returnsDateTimeImmutable(): void
	{
		$now = Utilities::dateTimeNow();
		/** @noinspection PhpConditionAlreadyCheckedInspection */
		$this->assertInstanceOf(DateTimeImmutable::class, $now);
	}

	/**
	 * Test dateTimeNow caches the result.
	 */
	public function test_dateTimeNow_cachesResult(): void
	{
		$first = Utilities::dateTimeNow();
		sleep(1); // Ensure time would differ if not cached
		$second = Utilities::dateTimeNow();

		// Should be the exact same instance due to caching
		$this->assertSame($first, $second);
	}

	/**
	 * Test dateTimeTodayAtMidnight returns midnight.
	 */
	public function test_dateTimeTodayAtMidnight(): void
	{
		$midnight = Utilities::dateTimeTodayAtMidnight();

		/** @noinspection PhpConditionAlreadyCheckedInspection */
		$this->assertInstanceOf(DateTimeImmutable::class, $midnight);
		$this->assertSame('00:00', $midnight->format('H:i'));
		$this->assertSame(current_datetime()->format('Y-m-d'), $midnight->format('Y-m-d'));
	}

	/**
	 * Test dateTimeNowPlus1D is approximately one day in the future.
	 */
	public function test_dateTimeNowPlus1D(): void
	{
		$now      = Utilities::dateTimeNow();
		$tomorrow = Utilities::dateTimeNowPlus1D();

		$diff = $tomorrow->getTimestamp() - $now->getTimestamp();

		// Should be approximately 86400 seconds (1 day)
		$this->assertEqualsWithDelta(86400, $diff, 1);
	}

	/**
	 * Test dateTimeNowPlus90D is approximately 90 days in the future.
	 */
	public function test_dateTimeNowPlus90D(): void
	{
		$now    = Utilities::dateTimeNow();
		$future = Utilities::dateTimeNowPlus90D();

		$diff = $future->getTimestamp() - $now->getTimestamp();

		// Should be approximately 7776000 seconds (90 days)
		$this->assertEqualsWithDelta(7776000, $diff, 1);
	}

	/**
	 * Test dateTimeNowPlus1Y is approximately one year in the future.
	 */
	public function test_dateTimeNowPlus1Y(): void
	{
		$now      = Utilities::dateTimeNow();
		$nextYear = Utilities::dateTimeNowPlus1Y();

		$diff = $nextYear->getTimestamp() - $now->getTimestamp();

		// Should be approximately 31536000 seconds (365 days)
		// Allow for leap years
		$this->assertEqualsWithDelta(31536000, $diff, 86400);
	}

	/**
	 * Test dateTimeNowMinus1D is approximately one day in the past.
	 */
	public function test_dateTimeNowMinus1D(): void
	{
		$now       = Utilities::dateTimeNow();
		$yesterday = Utilities::dateTimeNowMinus1D();

		$diff = $now->getTimestamp() - $yesterday->getTimestamp();

		// Should be approximately 86400 seconds (1 day)
		$this->assertEqualsWithDelta(86400, $diff, 1);
	}

	/**
	 * Test utcTimeZone returns UTC timezone.
	 */
	public function test_utcTimeZone(): void
	{
		$utc = Utilities::utcTimeZone();

		/** @noinspection PhpConditionAlreadyCheckedInspection */
		$this->assertInstanceOf(DateTimeZone::class, $utc);
		$this->assertSame('UTC', $utc->getName());
	}

	/**
	 * Test utcTimeZone caches the result.
	 */
	public function test_utcTimeZone_caching(): void
	{
		$first  = Utilities::utcTimeZone();
		$second = Utilities::utcTimeZone();

		// Should be the exact same instance due to caching
		$this->assertSame($first, $second);
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber_noI18n returns correct day names.
	 */
	public function test_getPluralDayOfWeekNameForNumber_noI18n(): void
	{
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(0));
		$this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(1));
		$this->assertSame('Tuesdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(2));
		$this->assertSame('Wednesdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(3));
		$this->assertSame('Thursdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(4));
		$this->assertSame('Fridays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(5));
		$this->assertSame('Saturdays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(6));
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber_noI18n handles modulo correctly.
	 */
	public function test_getPluralDayOfWeekNameForNumber_noI18n_gt7(): void
	{
		// Test that numbers > 6 wrap around
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(7));
		$this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(8));
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber_noI18n(14));
	}

	/**
	 * Test converting numeric strings to floats with Geo coordinates.
	 */
	public function test_toFloatOrNull_string(): void
	{
		// Convert string coordinates to floats
		$lat = Utilities::toFloatOrNull('40.7128', 4);
		$lng = Utilities::toFloatOrNull('-74.0060', 4);

		$this->assertSame(40.7128, $lat);
		$this->assertSame(-74.0060, $lng);
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber returns correct day names.
	 */
	public function test_getPluralDayOfWeekNameForNumber(): void
	{
		$this->assertSame('Sundays', Utilities::getPluralDayOfWeekNameForNumber(0));
		$this->assertSame('Mondays', Utilities::getPluralDayOfWeekNameForNumber(1));
		$this->assertSame('Tuesdays', Utilities::getPluralDayOfWeekNameForNumber(2));
		$this->assertSame('Wednesdays', Utilities::getPluralDayOfWeekNameForNumber(3));
		$this->assertSame('Thursdays', Utilities::getPluralDayOfWeekNameForNumber(4));
		$this->assertSame('Fridays', Utilities::getPluralDayOfWeekNameForNumber(5));
		$this->assertSame('Saturdays', Utilities::getPluralDayOfWeekNameForNumber(6));
	}

	/**
	 * Test getPluralDayOfWeekNameForNumber returns correct day names.
	 */
	public function test_getPluralDayOfWeekNameForNumber_gt7(): void
	{
		$this->assertSame('Saturdays', Utilities::getPluralDayOfWeekNameForNumber(13));
	}

	/**
	 * Test getDayOfWeekShortForNumber returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber(): void
	{
		$this->assertSame('Sun', Utilities::getDayOfWeekShortForNumber(0));
		$this->assertSame('Mon', Utilities::getDayOfWeekShortForNumber(1));
		$this->assertSame('Tue', Utilities::getDayOfWeekShortForNumber(2));
		$this->assertSame('Wed', Utilities::getDayOfWeekShortForNumber(3));
		$this->assertSame('Thu', Utilities::getDayOfWeekShortForNumber(4));
		$this->assertSame('Fri', Utilities::getDayOfWeekShortForNumber(5));
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber(6));
	}

	/**
	 * Test getDayOfWeekShortForNumber returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber_gt7(): void
	{
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber(13));
	}

	/**
	 * Test getDayOfWeekShortForNumber_noi18n returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber_noI18n(): void
	{
		$this->assertSame('Sun', Utilities::getDayOfWeekShortForNumber_noI18n(0));
		$this->assertSame('Mon', Utilities::getDayOfWeekShortForNumber_noI18n(1));
		$this->assertSame('Tue', Utilities::getDayOfWeekShortForNumber_noI18n(2));
		$this->assertSame('Wed', Utilities::getDayOfWeekShortForNumber_noI18n(3));
		$this->assertSame('Thu', Utilities::getDayOfWeekShortForNumber_noI18n(4));
		$this->assertSame('Fri', Utilities::getDayOfWeekShortForNumber_noI18n(5));
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber_noI18n(6));
	}

	/**
	 * Test getDayOfWeekShortForNumber_noI18n returns correct day names.
	 */
	public function test_getDayOfWeekShortForNumber_noI18n_gt7(): void
	{
		$this->assertSame('Sat', Utilities::getDayOfWeekShortForNumber_noI18n(13));
	}

	/**
	 * Test getTimeOfDayTermForTime returns correct terms.
	 */
	public function test_getTimeOfDayTermForTime(): void
	{
		$this->assertSame('Early Morning', Utilities::getTimeOfDayTermForTime(new DateTime('05:30')));
		$this->assertSame('Morning', Utilities::getTimeOfDayTermForTime(new DateTime('08:30')));
		$this->assertSame('Midday', Utilities::getTimeOfDayTermForTime(new DateTime('12:30')));
		$this->assertSame('Afternoon', Utilities::getTimeOfDayTermForTime(new DateTime('13:15')));
		$this->assertSame('Evening', Utilities::getTimeOfDayTermForTime(new DateTime('19:45')));
		$this->assertSame('Night', Utilities::getTimeOfDayTermForTime(new DateTime('21:00')));
		$this->assertSame('Late Night', Utilities::getTimeOfDayTermForTime(new DateTime('23:00')));
	}

	/**
	 * Test getTimeOfDayTermForTime_noI18n returns correct terms.
	 */
	public function test_getTimeOfDayTermForTime_noI18n(): void
	{
		$this->assertSame('Early Morning', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('05:30')));
		$this->assertSame('Morning', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('08:30')));
		$this->assertSame('Midday', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('12:30')));
		$this->assertSame('Afternoon', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('13:15')));
		$this->assertSame('Evening', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('19:45')));
		$this->assertSame('Night', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('21:00')));
		$this->assertSame('Late Night', Utilities::getTimeOfDayTermForTime_noI18n(new DateTime('23:00')));
	}


	/**
	 * Test stringArrayToListString converts arrays to list strings.
	 */
	public function test_stringArrayToListString(): void
	{
		// Basic test
		$this->assertSame(
			'apple, banana & cherry',
			Utilities::stringArrayToListString(['apple', 'banana', 'cherry'])
		);
	}

	public function test_stringArrayToListString_withLimitAndOthers(): void
	{
		// Test with limit
		$this->assertSame(
			'apple, banana & others',
			Utilities::stringArrayToListString(['apple', 'banana', 'cherry', 'date'], 2, true)
		);

		// Test without "and others" explicitly set
		$this->assertSame(
			'apple, banana & others',
			Utilities::stringArrayToListString(['apple', 'banana', 'cherry'], 2)
		);
	}

	public function test_stringArrayToListString_andComma(): void
	{
		$this->assertSame(
			'John, Paul, George & Ringo; and Peter, James & John',
			Utilities::stringArrayToListString(
				['John, Paul, George & Ringo', 'Peter, James & John']
			)
		);
	}

	public function test_stringArrayToListString_single(): void
	{
		// Test single item
		$this->assertSame(
			'apple',
			Utilities::stringArrayToListString(['apple'])
		);
	}

	public function test_stringArrayToListString_empty(): void
	{

		// Test empty array
		$this->assertSame(
			'',
			Utilities::stringArrayToListString([])
		);
	}

	////////////////////
	// idArrayToIntArray //
	////////////////////

	/**
	 * @return array[] Lists that are well-formed, or at least have always worked: [input, expected].
	 */
	public static function provider_idLists(): array
	{
		return [
			'comma-separated numbers' => ['1,2,3', [1, 2, 3]],
			'an array'                => [[1, '2', 3], [1, 2, 3]],
			'numbers as strings'      => [['10', '20'], [10, 20]],
			'nothing'                 => ['', []],
			'an empty array'          => [[], []],
			'spaces'                  => [' 7 , 8 ', [7, 8]],
			'letters stuck to IDs'    => ['12abc,34', [12, 34]],
		];
	}

	/**
	 * @dataProvider provider_idLists
	 */
	public function test_idArrayToIntArray_lists(string|array $input, array $expected): void
	{
		$this->assertSame($expected, Utilities::idArrayToIntArray($input));
	}

	/**
	 * @return array[] Lists with something unexpected in them, which should be tolerated: [input, expected].
	 */
	public static function provider_oddIdLists(): array
	{
		return [
			'an empty item in the middle'    => ['1,,2', [1, 2]],
			'a trailing comma'               => ['1,2,', [1, 2]],
			'a leading comma'                => [',1,2', [1, 2]],
			'an item with leading zeros'     => ['007,8', [7, 8]],
			'a non-number in an array'       => [[1, 'x', 4], [1, 4]],
			'a non-number between commas'    => ['1,a,2', [1, 2]],
			'nothing but non-numbers'        => ['a,b', []],
		];
	}

	/**
	 * IDs come from settings that people edit, and are used to build the queries sent to TouchPoint, so a stray comma
	 * shouldn't stop an import.
	 *
	 * @dataProvider provider_oddIdLists
	 */
	public function test_idArrayToIntArray_toleratesOddLists(string|array $input, array $expected): void
	{
		$this->assertSame($expected, Utilities::idArrayToIntArray($input));
	}

	public function test_idArrayToIntArray_canReturnTheCleanedString(): void
	{
		$this->assertSame('1,2,3', Utilities::idArrayToIntArray('1, 2, 3', false));
		$this->assertSame('1,2,3', Utilities::idArrayToIntArray([1, 2, 3], false));
		$this->assertSame('', Utilities::idArrayToIntArray('', false));
	}

	///////////////////
	// stringToSlug  //
	///////////////////

	/**
	 * @return array[] [title, slug]
	 */
	public static function provider_slugs(): array
	{
		return [
			'words'                           => ['Hello World', 'hello-world'],
			'only what comes before a colon'  => ['Title: Subtitle', 'title'],
			'only what comes before a period' => ['Version 2. The Sequel', 'version-2'],
			'symbols become dashes'           => ['Q&A Night', 'q-a-night'],
			'extra spaces'                    => ['  spaced   out  ', 'spaced-out'],
			'repeated dashes'                 => ['A--B', 'a-b'],
			'digits'                          => ['Room 101', 'room-101'],
			'nothing'                         => ['', ''],
		];
	}

	/**
	 * @dataProvider provider_slugs
	 */
	public function test_stringToSlug(string $title, string $slug): void
	{
		$this->assertSame($slug, Utilities::stringToSlug($title));
	}

	public function test_stringToSlug_onlyAsciiLettersAndDigitsSurvive(): void
	{
		// Apostrophes and accented letters aren't dropped or simplified, they're replaced.  Callers that want
		// "gods-house" have to clean those up first.
		$this->assertSame('god-s-house', Utilities::stringToSlug("God's House"));
		$this->assertSame('caf-night', Utilities::stringToSlug('Café Night'));
	}

	//////////////////////////
	// titleWithoutPrefix   //
	//////////////////////////

	/**
	 * @return array[] [title, prefix, expected]
	 */
	public static function provider_titlePrefixes(): array
	{
		return [
			'a colon'                         => ['Global Outreach Conference: Q&A Luncheon', 'Global Outreach Conference', 'Q&A Luncheon'],
			'an ampersand in what is left'    => ['Global Outreach Conference: Saturday Breakfast & Lunch', 'Global Outreach Conference', 'Saturday Breakfast & Lunch'],
			'a spaced hyphen'                 => ['global outreach conference - Day 1', 'Global Outreach Conference', 'Day 1'],
			'an en dash'                      => ['Global Outreach Conference – Day 2', 'Global Outreach Conference', 'Day 2'],
			'an em dash'                      => ['Global Outreach Conference — Day 3', 'Global Outreach Conference', 'Day 3'],
			'a bar'                           => ['Global Outreach Conference | Dinner', 'Global Outreach Conference', 'Dinner'],
			'a colon without a space'         => ['Global Outreach Conference:Dinner', 'Global Outreach Conference', 'Dinner'],
			'a prefix with a trailing space'  => ['Camp: Day 1', 'Camp ', 'Day 1'],
			'a curly apostrophe'              => ['God’s House: Worship', "God's House", 'Worship'],
			'capitals and accents'            => ['Café Night: Ünïcode Ñ', 'CAFÉ NIGHT', 'Ünïcode Ñ'],
			'no separator'                    => ['Christmas Eve Service', 'Christmas', 'Christmas Eve Service'],
			'a hyphen inside a word'          => ['Global Outreach Conference-wide Dinner', 'Global Outreach Conference', 'Global Outreach Conference-wide Dinner'],
			'a different start'               => ['GO Conference Midweek Service', 'Global Outreach Conference', 'GO Conference Midweek Service'],
			'a title unrelated to the prefix' => ["Worshipping in God's House", 'Global Outreach Conference', "Worshipping in God's House"],
			'the same title'                  => ['Christmas Lessons & Carols', 'Christmas Lessons & Carols', 'Christmas Lessons & Carols'],
			'a separator and nothing else'    => ['Church Retreat: ', 'Church Retreat', 'Church Retreat:'],
			'a separator and a symbol'        => ['Church Retreat: —', 'Church Retreat', 'Church Retreat: —'],
			'no prefix'                       => ['Anything', '', 'Anything'],
		];
	}

	/**
	 * @dataProvider provider_titlePrefixes
	 */
	public function test_titleWithoutPrefix(string $title, string $prefix, string $expected): void
	{
		$this->assertSame($expected, Utilities::titleWithoutPrefix($title, $prefix));
	}

	//////////////////////
	// standardizeHTags //
	//////////////////////

	/**
	 * @return array[] [highest heading allowed, HTML, expected]
	 */
	public static function provider_headings(): array
	{
		return [
			'no headings'                       => [2, '<p>no headings</p>', '<p>no headings</p>'],
			'a heading that is too high'        => [2, '<h1>A</h1>', '<h2>A</h2>'],
			'a heading that is already fine'    => [2, '<h2>A</h2>', '<h2>A</h2>'],
			'two levels move down together'     => [2, '<h1>A</h1><h2>B</h2>', '<h2>A</h2><h3>B</h3>'],
			'three levels'                      => [2, '<h1>A</h1><h2>B</h2><h3>C</h3>', '<h2>A</h2><h3>B</h3><h4>C</h4>'],
			'a skipped level is closed up'      => [2, '<h2>A</h2><h4>B</h4>', '<h2>A</h2><h3>B</h3>'],
			'a skipped level at the bottom'     => [2, '<h1>A</h1><h6>B</h6>', '<h2>A</h2><h3>B</h3>'],
			'attributes are kept'               => [2, '<h1 class="x">A</h1>', '<h2 class="x">A</h2>'],
			'the order in the text is kept'     => [2, '<h3>A</h3><h2>B</h2><h1>C</h1>', '<h4>A</h4><h3>B</h3><h2>C</h2>'],
			'a different highest level'         => [3, '<h1>A</h1><h2>B</h2>', '<h3>A</h3><h4>B</h4>'],
			'low headings move up'              => [1, '<h3>A</h3><h5>B</h5>', '<h1>A</h1><h2>B</h2>'],
			'past h6 becomes bold text'         => [6, '<h1>A</h1><h2>B</h2>', '<h6>A</h6><p><strong>B</strong></p>'],
			'a highest level that is too low'   => [0, '<h2>A</h2>', '<h1>A</h1>'],
			'a highest level that is too high'  => [9, '<h1>A</h1>', '<h6>A</h6>'],
		];
	}

	/**
	 * @dataProvider provider_headings
	 */
	public function test_standardizeHTags(int $maxAllowed, string $html, string $expected): void
	{
		$this->assertSame($expected, Utilities::standardizeHTags($maxAllowed, $html));
	}

	////////////////////
	// standardizeHtml //
	////////////////////

	public function test_standardizeHtml_removesTagsThatAreNotAllowed(): void
	{
		$this->assertSame('Hi there', Utilities::standardizeHtml('<div><span>Hi</span><img src="x"> there</div>'));
	}

	public function test_standardizeHtml_keepsTheTagsThatAreAllowed(): void
	{
		$html = '<p><strong>Bold</strong>, <em>italic</em>, and <a href="https://example.com">a link</a>.</p><ul><li>One</li></ul>';

		$this->assertSame($html, Utilities::standardizeHtml($html));
	}

	public function test_standardizeHtml_movesHeadingsDownAndTrims(): void
	{
		$this->assertSame('<h2>Title</h2><p>Text</p>', Utilities::standardizeHtml("  <h1>Title</h1><p>Text</p>\n"));
	}

	public function test_standardizeHtml_nothing(): void
	{
		$this->assertSame('', Utilities::standardizeHtml(null));
		$this->assertSame('', Utilities::standardizeHtml(''));
	}

	public function test_standardizeHtml_filterCanReplaceTheStandardization(): void
	{
		add_filter('tp_standardize_html', fn($html, $context) => "custom for $context", 10, 2);

		$this->assertSame('custom for events', Utilities::standardizeHtml('<h1>Not used</h1>', 'events'));
	}

	public function test_standardizeHtml_filtersCanAdjustTheSteps(): void
	{
		add_filter('tp_pre_standardize_html', fn($html, $context) => str_replace('PLACEHOLDER', "<h1>$context</h1>", $html), 10, 2);
		add_filter('tp_standardize_h_tags_max_h', fn($max) => 3);
		add_filter('tp_standardize_allowed_tags', fn($tags) => [...$tags, 'span']);
		add_filter('tp_post_standardize_html', fn($html, $context) => "$html<!-- $context -->", 10, 2);

		$this->assertSame(
			'<h3>events</h3><span>kept</span><!-- events -->',
			Utilities::standardizeHtml('PLACEHOLDER<span>kept</span>', 'events')
		);
	}

	//////////////////////////
	// fileHeadersFromString //
	//////////////////////////

	public function test_fileHeadersFromString_readsTheHeadersAskedFor(): void
	{
		$text = "Plugin Name: TouchPoint WP\r\nVersion: 1.2.3\nUpdate URI: https://example.com/path\nOther: ignored";

		$headers = Utilities::fileHeadersFromString($text, ['Version' => null, 'Update URI' => null]);

		$this->assertSame(['Version' => '1.2.3', 'Update URI' => 'https://example.com/path'], $headers);
	}

	public function test_fileHeadersFromString_headersThatAreMissingKeepTheirDefaults(): void
	{
		$headers = Utilities::fileHeadersFromString("Version: 2.0", ['Version' => null, 'Requires PHP' => '8.0']);

		$this->assertSame(['Version' => '2.0', 'Requires PHP' => '8.0'], $headers);
	}

	public function test_fileHeadersFromString_asksForNothing(): void
	{
		$this->assertSame([], Utilities::fileHeadersFromString("Version: 2.0"));
	}

	///////////////////////////
	// createGuid, weekdays  //
	///////////////////////////

	public function test_createGuid_format(): void
	{
		$this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', Utilities::createGuid());
	}

	public function test_createGuid_isDifferentEachTime(): void
	{
		$guids = [];
		for ($i = 0; $i < 50; $i++) {
			$guids[] = Utilities::createGuid();
		}

		$this->assertCount(50, array_unique($guids));
	}

	public function test_getDaysOfWeekShort_startsOnSunday(): void
	{
		$this->assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], Utilities::getDaysOfWeekShort());
	}

	////////////////////////////////////
	// getAllHeaders, getClientIp     //
	////////////////////////////////////

	public function test_getAllHeaders_readsHttpValuesFromTheServer(): void
	{
		$_SERVER['HTTP_X_CUSTOM_HEADER'] = 'one';
		$_SERVER['HTTP_ACCEPT']          = 'text/html';
		$_SERVER['SERVER_NAME']          = 'not a header';

		$headers = Utilities::getAllHeaders();

		$this->assertSame('one', $headers['X-Custom-Header']);
		$this->assertSame('text/html', $headers['Accept']);
		$this->assertArrayNotHasKey('Server-Name', $headers);
	}

	/**
	 * Set the server values that say where a request came from, and remove the rest.
	 *
	 * @param array $values
	 */
	private function requestFrom(array $values): void
	{
		foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'] as $key) {
			unset($_SERVER[$key]);
		}
		foreach ($values as $key => $value) {
			$_SERVER[$key] = $value;
		}
	}

	public function test_getClientIp_usesTheAddressOfTheConnection(): void
	{
		$this->requestFrom(['REMOTE_ADDR' => '192.0.2.5']);

		$this->assertSame('192.0.2.5', Utilities::getClientIp());
	}

	public function test_getClientIp_acceptsIpv6(): void
	{
		$this->requestFrom(['REMOTE_ADDR' => '2001:db8::1']);

		$this->assertSame('2001:db8::1', Utilities::getClientIp());
	}

	public function test_getClientIp_noAddress(): void
	{
		$this->requestFrom([]);

		$this->assertNull(Utilities::getClientIp());
	}

	public function test_getClientIp_ignoresValuesThatAreNotAddresses(): void
	{
		$this->requestFrom(['HTTP_CLIENT_IP' => 'unknown', 'REMOTE_ADDR' => '192.0.2.5']);

		$this->assertSame('192.0.2.5', Utilities::getClientIp());
	}

	public function test_getClientIp_prefersForwardingHeadersToTheConnection(): void
	{
		// These headers can be set by whoever makes the request, so the result is only as trustworthy as the proxy
		// in front of the site.
		$this->requestFrom(['HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'REMOTE_ADDR' => '192.0.2.5']);

		$this->assertSame('203.0.113.9', Utilities::getClientIp());
	}

	public function test_getClientIp_aListOfAddressesIsNotUsed(): void
	{
		$this->requestFrom(['HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1', 'REMOTE_ADDR' => '192.0.2.5']);

		$this->assertSame('192.0.2.5', Utilities::getClientIp());
	}

	public function test_getClientIp_headersAreUsedInOrder(): void
	{
		$this->requestFrom([
			'HTTP_FORWARDED_FOR'   => '198.51.100.4',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
			'HTTP_CLIENT_IP'       => '192.0.2.77',
		]);

		$this->assertSame('192.0.2.77', Utilities::getClientIp());
	}

	public function test_getClientIp_isRememberedForTheRequest(): void
	{
		$this->requestFrom(['REMOTE_ADDR' => '192.0.2.5']);
		Utilities::getClientIp();

		$this->requestFrom(['REMOTE_ADDR' => '192.0.2.99']);

		$this->assertSame('192.0.2.5', Utilities::getClientIp());
	}
}
