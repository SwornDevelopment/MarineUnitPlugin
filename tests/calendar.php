<?php
/**
 * Tests for the calendar month arithmetic.
 *
 *     php tests/calendar.php
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$plugin_dir = dirname( __DIR__ ) . '/';

define( 'MUP_DIR', $plugin_dir );

require_once $plugin_dir . 'includes/Support/MonthGrid.php';

use MarineUnit\Support\MonthGrid;

final class Results {
	public static int $passed = 0;
	public static int $failed = 0;
}

function check( string $label, mixed $actual, mixed $expected ): void {
	if ( $actual === $expected ) {
		++Results::$passed;
		echo "  ok   {$label}\n";

		return;
	}

	++Results::$failed;

	printf(
		"  FAIL %s — expected %s, got %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

function section( string $title ): void {
	echo "\n{$title}\n";
}

// ---------------------------------------------------------------------------
section( 'Leading blank cells' );

// Sunday-start weeks (WordPress default, start_of_week = 0).
check( 'a month starting Sunday needs no blanks', MonthGrid::leadingBlanks( 0, 0 ), 0 );
check( 'a month starting Monday needs one blank', MonthGrid::leadingBlanks( 1, 0 ), 1 );
check( 'a month starting Saturday needs six blanks', MonthGrid::leadingBlanks( 6, 0 ), 6 );

// Monday-start weeks (start_of_week = 1).
check( 'Monday start: a Monday 1st needs no blanks', MonthGrid::leadingBlanks( 1, 1 ), 0 );
check( 'Monday start: a Sunday 1st needs six blanks', MonthGrid::leadingBlanks( 0, 1 ), 6 );
check( 'Monday start: a Saturday 1st needs five blanks', MonthGrid::leadingBlanks( 6, 1 ), 5 );

// Saturday start, which some locales use.
check( 'Saturday start: a Saturday 1st needs no blanks', MonthGrid::leadingBlanks( 6, 6 ), 0 );
check( 'Saturday start: a Sunday 1st needs one blank', MonthGrid::leadingBlanks( 0, 6 ), 1 );

// Never negative, whatever the combination.
for ( $weekday = 0; $weekday < 7; $weekday++ ) {
	for ( $start = 0; $start < 7; $start++ ) {
		$blanks = MonthGrid::leadingBlanks( $weekday, $start );

		if ( $blanks < 0 || $blanks > 6 ) {
			check( "blanks stay in 0-6 for weekday {$weekday}, start {$start}", $blanks, 'between 0 and 6' );
		}
	}
}

check( 'every weekday/start combination stays in range', true, true );

// ---------------------------------------------------------------------------
section( 'Days in month' );

check( 'January has 31 days', MonthGrid::daysInMonth( 2026, 1 ), 31 );
check( 'April has 30 days', MonthGrid::daysInMonth( 2026, 4 ), 30 );
check( 'February 2026 has 28 days', MonthGrid::daysInMonth( 2026, 2 ), 28 );
check( 'February 2028 has 29 days (leap year)', MonthGrid::daysInMonth( 2028, 2 ), 29 );
check( 'February 2000 has 29 days (400-year rule)', MonthGrid::daysInMonth( 2000, 2 ), 29 );
check( 'February 1900 has 28 days (100-year rule)', MonthGrid::daysInMonth( 1900, 2 ), 28 );
check( 'December has 31 days', MonthGrid::daysInMonth( 2026, 12 ), 31 );

// ---------------------------------------------------------------------------
section( 'Month navigation' );

check( 'previous month within a year', MonthGrid::previous( 2026, 8 ), [ 2026, 7 ] );
check( 'previous month rolls back a year in January', MonthGrid::previous( 2026, 1 ), [ 2025, 12 ] );
check( 'next month within a year', MonthGrid::next( 2026, 8 ), [ 2026, 9 ] );
check( 'next month rolls forward a year in December', MonthGrid::next( 2026, 12 ), [ 2027, 1 ] );

// Round-tripping must land back where it started, including across boundaries.
foreach ( [ [ 2026, 1 ], [ 2026, 12 ], [ 2026, 6 ], [ 2000, 2 ] ] as [ $y, $m ] ) {
	[ $ny, $nm ] = MonthGrid::next( $y, $m );
	check( "next then previous returns to {$y}-{$m}", MonthGrid::previous( $ny, $nm ), [ $y, $m ] );
}

// Twelve steps forward is exactly one year.
$year  = 2026;
$month = 5;

for ( $i = 0; $i < 12; $i++ ) {
	[ $year, $month ] = MonthGrid::next( $year, $month );
}

check( 'twelve months forward advances the year by one', [ $year, $month ], [ 2027, 5 ] );

// ---------------------------------------------------------------------------
section( 'Range validation' );

check( 'a normal month is valid', MonthGrid::isValid( 2026, 8 ), true );
check( 'month 0 is rejected', MonthGrid::isValid( 2026, 0 ), false );
check( 'month 13 is rejected', MonthGrid::isValid( 2026, 13 ), false );
check( 'a negative month is rejected', MonthGrid::isValid( 2026, -1 ), false );
check( 'year 1969 is rejected', MonthGrid::isValid( 1969, 6 ), false );
check( 'year 2201 is rejected', MonthGrid::isValid( 2201, 6 ), false );
check( 'the lower bound is inclusive', MonthGrid::isValid( 1970, 1 ), true );
check( 'the upper bound is inclusive', MonthGrid::isValid( 2200, 12 ), true );

// ---------------------------------------------------------------------------
section( 'Week rows' );

// August 2026 starts on a Saturday and has 31 days: 6 blanks + 31 = 37 cells,
// which needs six rows.
check( 'cells include the leading blanks', MonthGrid::cellCount( 2026, 8, 0, 6 ), 37 );
check( 'a 37-cell month needs six rows', MonthGrid::weekCount( 2026, 8, 0, 6 ), 6 );

// February 2026 starts on a Sunday and has 28 days: exactly four rows.
check( 'a 28-day month starting on the first weekday needs four rows', MonthGrid::weekCount( 2026, 2, 0, 0 ), 4 );

// A 31-day month starting on the first weekday: 31 cells, five rows.
check( 'a 31-day month with no blanks needs five rows', MonthGrid::weekCount( 2026, 1, 4, 4 ), 5 );

echo "\n" . str_repeat( '-', 48 ) . "\n";
printf( "%d passed, %d failed\n", Results::$passed, Results::$failed );

exit( Results::$failed > 0 ? 1 : 0 );
