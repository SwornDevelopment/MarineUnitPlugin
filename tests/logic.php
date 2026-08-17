<?php
/**
 * Tests for the Phase 2 scheduling logic.
 *
 * Covers the pure pieces — time ranges, overlap detection and waitlist
 * arithmetic — which is where the rules that are easy to get wrong live.
 * Runs without WordPress or PHPUnit:
 *
 *     php tests/logic.php
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$plugin_dir = dirname( __DIR__ ) . '/';

define( 'MUP_DIR', $plugin_dir );

function __( string $text, string $domain = '' ): string {
	return $text;
}

require_once $plugin_dir . 'includes/Enum/SignupStatus.php';
require_once $plugin_dir . 'includes/Enum/PatrolStatus.php';
require_once $plugin_dir . 'includes/Support/TimeRange.php';
require_once $plugin_dir . 'includes/Support/Result.php';
require_once $plugin_dir . 'includes/Signups/Signup.php';
require_once $plugin_dir . 'includes/Signups/Waitlist.php';
require_once $plugin_dir . 'includes/Patrols/Patrol.php';
require_once $plugin_dir . 'includes/Patrols/ConflictChecker.php';

use MarineUnit\Enum\SignupStatus;
use MarineUnit\Patrols\ConflictChecker;
use MarineUnit\Patrols\Patrol;
use MarineUnit\Signups\Signup;
use MarineUnit\Signups\Waitlist;
use MarineUnit\Support\Result;
use MarineUnit\Support\TimeRange;

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

/** Build a sign-up without touching the database. */
function signup( int $id, SignupStatus $status, int $position = 0, int $user_id = 0 ): Signup {
	return new Signup(
		id: $id,
		patrolId: 1,
		userId: $user_id ?: $id,
		status: $status,
		waitlistPosition: $position,
		createdAt: '2026-01-01 00:00:00',
	);
}

function patrol( int $id, string $date, string $start, string $end, string $status = 'active' ): Patrol {
	return Patrol::fromArray(
		[
			'id'         => $id,
			'date'       => $date,
			'start_time' => $start,
			'end_time'   => $end,
			'max_crew'   => 4,
			'status'     => $status,
		]
	);
}

$utc = new DateTimeZone( 'UTC' );

// ---------------------------------------------------------------------------
section( 'TimeRange — half-open intervals' );

$afternoon = TimeRange::fromDateAndTimes( '2026-01-08', '13:00', '17:00', $utc );
$evening   = TimeRange::fromDateAndTimes( '2026-01-08', '17:00', '21:00', $utc );
$overlap   = TimeRange::fromDateAndTimes( '2026-01-08', '16:00', '18:00', $utc );
$next_day  = TimeRange::fromDateAndTimes( '2026-01-09', '13:00', '17:00', $utc );

check( 'a valid range parses', $afternoon instanceof TimeRange, true );
check( 'back-to-back patrols do not overlap', $afternoon->overlaps( $evening ), false );
check( 'overlap detection is symmetric', $evening->overlaps( $afternoon ), false );
check( 'a straddling patrol overlaps the earlier one', $overlap->overlaps( $afternoon ), true );
check( 'a straddling patrol overlaps the later one', $overlap->overlaps( $evening ), true );
check( 'the reverse direction agrees', $afternoon->overlaps( $overlap ), true );
check( 'a patrol overlaps itself', $afternoon->overlaps( $afternoon ), true );
check( 'different days do not overlap', $afternoon->overlaps( $next_day ), false );
check( 'duration is in minutes', $afternoon->durationMinutes(), 240 );
check( 'a same-day range does not cross midnight', $afternoon->crossesMidnight(), false );

section( 'TimeRange — overnight patrols' );

// 22:00 to 02:00 is a four-hour night patrol, not an invalid one.
$night = TimeRange::fromDateAndTimes( '2026-01-08', '22:00', '02:00', $utc );

check( 'an overnight patrol parses', $night instanceof TimeRange, true );
check( 'an overnight patrol is valid', $night->isValid(), true );
check( 'an overnight patrol lasts four hours', $night->durationMinutes(), 240 );
check( 'an overnight patrol crosses midnight', $night->crossesMidnight(), true );

$early = TimeRange::fromDateAndTimes( '2026-01-09', '01:00', '03:00', $utc );
check( 'an overnight patrol clashes with the next morning', $night->overlaps( $early ), true );

$late_evening = TimeRange::fromDateAndTimes( '2026-01-08', '20:00', '22:00', $utc );
check( 'a patrol ending as the night one starts does not clash', $late_evening->overlaps( $night ), false );

// Equal start and end means a full 24 hours rather than a zero-length patrol.
$all_day = TimeRange::fromDateAndTimes( '2026-01-08', '08:00', '08:00', $utc );
check( 'equal times roll to a full day', $all_day->durationMinutes(), 1440 );

section( 'TimeRange — rejecting bad input' );

// createFromFormat() overflows rather than failing, so these must be rejected
// explicitly. An accepted-but-inverted range would silently disable every
// overlap check.
check( 'a malformed date is rejected', TimeRange::fromDateAndTimes( 'not-a-date', '10:00', '12:00', $utc ), null );
check( 'an out-of-range hour is rejected', TimeRange::fromDateAndTimes( '2026-01-08', '99:99', '12:00', $utc ), null );
check( 'hour 24 is rejected', TimeRange::fromDateAndTimes( '2026-01-08', '24:00', '12:00', $utc ), null );
check( 'minute 60 is rejected', TimeRange::fromDateAndTimes( '2026-01-08', '10:60', '12:00', $utc ), null );
check( 'a bad end time is rejected', TimeRange::fromDateAndTimes( '2026-01-08', '10:00', '88:00', $utc ), null );
check( 'an empty time is rejected', TimeRange::fromDateAndTimes( '2026-01-08', '', '12:00', $utc ), null );
check( 'junk text is rejected', TimeRange::fromDateAndTimes( '2026-01-08', 'lunchtime', '12:00', $utc ), null );
check( 'the 31st of February is rejected', TimeRange::fromDateAndTimes( '2026-02-31', '10:00', '12:00', $utc ), null );

// Lenient about the shapes a browser actually submits.
check(
	'a single-digit hour is accepted',
	TimeRange::fromDateAndTimes( '2026-01-08', '9:00', '11:00', $utc )?->durationMinutes(),
	120
);
check(
	'seconds from an <input type="time"> are accepted',
	TimeRange::fromDateAndTimes( '2026-01-08', '09:00:00', '11:00:00', $utc )?->durationMinutes(),
	120
);
check(
	'surrounding whitespace is tolerated',
	TimeRange::fromDateAndTimes( '2026-01-08', ' 09:00 ', '11:00', $utc )?->durationMinutes(),
	120
);

// Whatever comes out must never be inverted.
foreach ( [ [ '22:00', '02:00' ], [ '08:00', '08:00' ], [ '00:00', '23:59' ] ] as [ $s, $e ] ) {
	$r = TimeRange::fromDateAndTimes( '2026-01-08', $s, $e, $utc );
	check( "range {$s}-{$e} is never inverted", $r instanceof TimeRange && $r->isValid(), true );
}

section( 'TimeRange — start detection' );

$start_ts = $afternoon->start;
check( 'not started a second before', $afternoon->hasStarted( $start_ts - 1 ), false );
check( 'started exactly on the start time', $afternoon->hasStarted( $start_ts ), true );
check( 'started afterwards', $afternoon->hasStarted( $start_ts + 3600 ), true );

// ---------------------------------------------------------------------------
section( 'ConflictChecker — overlap scanning' );

$candidates = [
	patrol( 10, '2026-01-08', '08:00', '12:00' ),
	patrol( 11, '2026-01-08', '16:00', '18:00' ),
	patrol( 12, '2026-01-09', '13:00', '17:00' ),
];

$clashes = ConflictChecker::overlapping( $afternoon, $candidates, $utc );
check( 'finds exactly the clashing patrol', count( $clashes ), 1 );
check( 'and identifies which one', $clashes[0]->id, 11 );

$morning = TimeRange::fromDateAndTimes( '2026-01-08', '12:00', '13:00', $utc );
check( 'a gap between patrols is free', count( ConflictChecker::overlapping( $morning, $candidates, $utc ) ), 0 );

$all_evening = TimeRange::fromDateAndTimes( '2026-01-08', '06:00', '20:00', $utc );
check( 'a long patrol clashes with several', count( ConflictChecker::overlapping( $all_evening, $candidates, $utc ) ), 2 );

$broken = [ Patrol::fromArray( [ 'id' => 99, 'date' => '', 'start_time' => '', 'end_time' => '' ] ) ];
check( 'an unparseable patrol is skipped, not fatal', count( ConflictChecker::overlapping( $afternoon, $broken, $utc ) ), 0 );

// ---------------------------------------------------------------------------
section( 'Waitlist — placing a new sign-up' );

check( 'empty patrol confirms', Waitlist::statusForNewSignup( 0, 4 ), SignupStatus::Confirmed );
check( 'last seat confirms', Waitlist::statusForNewSignup( 3, 4 ), SignupStatus::Confirmed );
check( 'full patrol waitlists', Waitlist::statusForNewSignup( 4, 4 ), SignupStatus::Waitlisted );
check( 'over-capacity patrol waitlists', Waitlist::statusForNewSignup( 6, 4 ), SignupStatus::Waitlisted );

check( 'first waitlist position is 1', Waitlist::nextPosition( [] ), 1 );
check(
	'next position follows the highest in use',
	Waitlist::nextPosition( [ signup( 1, SignupStatus::Waitlisted, 3 ), signup( 2, SignupStatus::Waitlisted, 7 ) ] ),
	8
);
check(
	'confirmed rows do not disturb positions',
	Waitlist::nextPosition( [ signup( 1, SignupStatus::Confirmed, 0 ) ] ),
	1
);

section( 'Waitlist — counting and capacity' );

$crew = [
	signup( 1, SignupStatus::Confirmed ),
	signup( 2, SignupStatus::Confirmed ),
	signup( 3, SignupStatus::Waitlisted, 1 ),
	signup( 4, SignupStatus::Waitlisted, 2 ),
];

check( 'counts only confirmed crew', Waitlist::confirmedCount( $crew ), 2 );
check( 'lists confirmed crew', count( Waitlist::confirmed( $crew ) ), 2 );
check( 'lists waiting crew', count( Waitlist::waiting( $crew ) ), 2 );
check( 'places remaining', Waitlist::placesRemaining( $crew, 4 ), 2 );
check( 'not full', Waitlist::isFull( $crew, 4 ), false );
check( 'full at the limit', Waitlist::isFull( $crew, 2 ), true );
check( 'over capacity is detected', Waitlist::isOverCapacity( $crew, 1 ), true );
check( 'places remaining never goes negative', Waitlist::placesRemaining( $crew, 1 ), 0 );

section( 'Waitlist — promotion order' );

$queue = [
	signup( 1, SignupStatus::Confirmed ),
	signup( 5, SignupStatus::Waitlisted, 3 ),
	signup( 6, SignupStatus::Waitlisted, 1 ),
	signup( 7, SignupStatus::Waitlisted, 2 ),
];

check( 'waiting list sorts by position, not insertion', array_map( static fn ( $s ) => $s->id, Waitlist::waiting( $queue ) ), [ 6, 7, 5 ] );
check( 'one free seat promotes the front of the queue', Waitlist::promotions( $queue, 2 ), [ 6 ] );
check( 'two free seats promote the first two', Waitlist::promotions( $queue, 3 ), [ 6, 7 ] );
check( 'more seats than waiting promotes everyone', Waitlist::promotions( $queue, 10 ), [ 6, 7, 5 ] );
check( 'a full patrol promotes nobody', Waitlist::promotions( $queue, 1 ), [] );

// Lowering the limit must never demote anyone already confirmed.
$over = [
	signup( 1, SignupStatus::Confirmed ),
	signup( 2, SignupStatus::Confirmed ),
	signup( 3, SignupStatus::Confirmed ),
	signup( 4, SignupStatus::Waitlisted, 1 ),
];

check( 'lowering the limit promotes nobody', Waitlist::promotions( $over, 1 ), [] );
check( 'lowering the limit leaves confirmed crew alone', Waitlist::confirmedCount( $over ), 3 );
check( 'and the patrol reports as over capacity', Waitlist::isOverCapacity( $over, 1 ), true );

// Ties on position must still produce a deterministic order.
$tied = [
	signup( 9, SignupStatus::Waitlisted, 1 ),
	signup( 4, SignupStatus::Waitlisted, 1 ),
];

check( 'tied positions break by sign-up id', Waitlist::promotions( $tied, 1 ), [ 4 ] );

check( 'an empty patrol has nothing to promote', Waitlist::promotions( [], 4 ), [] );
check( 'a patrol with no waitlist promotes nobody', Waitlist::promotions( [ signup( 1, SignupStatus::Confirmed ) ], 4 ), [] );

// ---------------------------------------------------------------------------
section( 'Result' );

$ok = Result::success( 'confirmed', 'You are on the crew.', [ 'status' => 'confirmed' ] );
check( 'success is ok', $ok->ok, true );
check( 'success is not failed', $ok->failed(), false );
check( 'data is readable', $ok->get( 'status' ), 'confirmed' );
check( 'missing data falls back', $ok->get( 'nothing', 'default' ), 'default' );
check( 'no warnings by default', $ok->hasWarnings(), false );

$warned = $ok->withWarnings( [ 'Vessel double-booked.' ] );
check( 'warnings can be attached', $warned->hasWarnings(), true );
check( 'attaching warnings preserves the code', $warned->code, 'confirmed' );
check( 'the original is untouched', $ok->hasWarnings(), false );

$bad = Result::failure( 'user_conflict', 'Already booked.' );
check( 'failure is not ok', $bad->ok, false );
check( 'failure reports failed', $bad->failed(), true );

// ---------------------------------------------------------------------------
section( 'Patrol model' );

$p = patrol( 1, '2026-01-08', '17:00', '21:00' );
check( 'active patrols accept sign-ups', $p->acceptsSignups(), true );
check( 'active patrols are not cancelled', $p->isCancelled(), false );
check( 'the range resolves', $p->range( $utc )->durationMinutes(), 240 );

$cancelled = patrol( 2, '2026-01-08', '17:00', '21:00', 'cancelled' );
check( 'cancelled patrols refuse sign-ups', $cancelled->acceptsSignups(), false );
check( 'cancelled patrols report cancelled', $cancelled->isCancelled(), true );

check( 'max crew is floored at 1', Patrol::fromArray( [ 'max_crew' => 0 ] )->maxCrew, 1 );
check( 'negative max crew is floored at 1', Patrol::fromArray( [ 'max_crew' => -5 ] )->maxCrew, 1 );
check( 'an unknown status falls back to active', Patrol::fromArray( [ 'status' => 'nonsense' ] )->acceptsSignups(), true );

echo "\n" . str_repeat( '-', 48 ) . "\n";
printf( "%d passed, %d failed\n", Results::$passed, Results::$failed );

exit( Results::$failed > 0 ? 1 : 0 );
