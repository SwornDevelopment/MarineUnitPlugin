<?php
/**
 * Calendar month arithmetic.
 *
 * Kept free of WordPress so the off-by-one traps — how many blank cells sit
 * before the 1st, what "previous month" means in January — can be tested
 * directly instead of by eyeballing a rendered page.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Support;

defined( 'ABSPATH' ) || exit;

final class MonthGrid {

	/**
	 * Blank cells needed before the 1st of the month.
	 *
	 * @param int $first_weekday Weekday of the 1st, 0 (Sunday) to 6 (Saturday).
	 * @param int $start_of_week Site's first day of the week, same scale.
	 */
	public static function leadingBlanks( int $first_weekday, int $start_of_week ): int {
		return ( ( $first_weekday % 7 ) - ( $start_of_week % 7 ) + 7 ) % 7;
	}

	public static function daysInMonth( int $year, int $month ): int {
		return (int) cal_days_in_month_fallback( $year, $month );
	}

	/**
	 * @return array{0: int, 1: int} [year, month]
	 */
	public static function previous( int $year, int $month ): array {
		--$month;

		if ( $month < 1 ) {
			$month = 12;
			--$year;
		}

		return [ $year, $month ];
	}

	/**
	 * @return array{0: int, 1: int} [year, month]
	 */
	public static function next( int $year, int $month ): array {
		++$month;

		if ( $month > 12 ) {
			$month = 1;
			++$year;
		}

		return [ $year, $month ];
	}

	/**
	 * Whether a year/month pair is one we are willing to render.
	 */
	public static function isValid( int $year, int $month ): bool {
		return $year >= 1970 && $year <= 2200 && $month >= 1 && $month <= 12;
	}

	/**
	 * Total cells the grid occupies, including the leading blanks. Useful for
	 * deciding whether a sixth week row is needed.
	 */
	public static function cellCount( int $year, int $month, int $start_of_week, int $first_weekday ): int {
		return self::leadingBlanks( $first_weekday, $start_of_week ) + self::daysInMonth( $year, $month );
	}

	public static function weekCount( int $year, int $month, int $start_of_week, int $first_weekday ): int {
		return (int) ceil( self::cellCount( $year, $month, $start_of_week, $first_weekday ) / 7 );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\cal_days_in_month_fallback' ) ) {
	/**
	 * Days in a month without requiring the calendar extension.
	 */
	function cal_days_in_month_fallback( int $year, int $month ): int {
		return (int) gmdate( 't', (int) gmmktime( 0, 0, 0, $month, 1, $year ) );
	}
}
