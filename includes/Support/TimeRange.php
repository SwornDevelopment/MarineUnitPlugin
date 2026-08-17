<?php
/**
 * A half-open time interval [start, end).
 *
 * Half-open is the important part: a patrol running 13:00–17:00 and one
 * running 17:00–21:00 do not overlap. Crew routinely hand a boat straight over,
 * and treating a shared boundary as a clash would block a legitimate sign-up.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Support;

defined( 'ABSPATH' ) || exit;

final class TimeRange {

	/**
	 * @param int $start Unix timestamp, inclusive.
	 * @param int $end   Unix timestamp, exclusive.
	 */
	public function __construct(
		public readonly int $start,
		public readonly int $end,
	) {}

	/**
	 * Build a range from a stored date plus start/end times.
	 *
	 * When the end time is at or before the start time the patrol is treated as
	 * running past midnight and the end rolls to the following day. Night
	 * operations are routine for a marine unit — 22:00 to 02:00 is a four-hour
	 * patrol, not an invalid one.
	 *
	 * @param string $date  `Y-m-d`.
	 * @param string $start `H:i`.
	 * @param string $end   `H:i`.
	 */
	public static function fromDateAndTimes(
		string $date,
		string $start,
		string $end,
		?\DateTimeZone $timezone = null
	): ?self {
		$timezone ??= new \DateTimeZone( 'UTC' );

		$start_at = self::parse( $date, $start, $timezone );
		$end_at   = self::parse( $date, $end, $timezone );

		if ( ! $start_at instanceof \DateTimeImmutable || ! $end_at instanceof \DateTimeImmutable ) {
			return null;
		}

		if ( $end_at <= $start_at ) {
			$end_at = $end_at->modify( '+1 day' );
		}

		$range = new self( $start_at->getTimestamp(), $end_at->getTimestamp() );

		// Belt and braces. An inverted range would make overlaps() return
		// false for everything, silently switching conflict detection off,
		// so refuse to construct one at all.
		return $range->isValid() ? $range : null;
	}

	/**
	 * Strict `Y-m-d` + `H:i` parsing.
	 *
	 * DateTimeImmutable::createFromFormat() does not reject nonsense: it
	 * overflows it. "99:99" becomes four days later rather than an error, which
	 * would hand us a bogus window that quietly fails every overlap check. The
	 * round-trip comparison below is what actually rejects bad input.
	 */
	private static function parse( string $date, string $time, \DateTimeZone $timezone ): ?\DateTimeImmutable {
		$time = self::normaliseTime( $time );

		if ( '' === $time ) {
			return null;
		}

		$stamp  = $date . ' ' . $time;
		$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $stamp, $timezone );

		if ( ! $parsed instanceof \DateTimeImmutable ) {
			return null;
		}

		return $parsed->format( 'Y-m-d H:i' ) === $stamp ? $parsed : null;
	}

	/**
	 * Accepts `H:i`, `G:i` and the `H:i:s` an <input type="time"> emits when
	 * the browser includes seconds. Returns '' for anything else.
	 */
	private static function normaliseTime( string $time ): string {
		$time = trim( $time );

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m ) ) {
			return '';
		}

		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}

	/**
	 * True when the two ranges share any time at all.
	 */
	public function overlaps( self $other ): bool {
		return $this->start < $other->end && $other->start < $this->end;
	}

	/**
	 * Zero-length and inverted ranges are meaningless for a patrol.
	 */
	public function isValid(): bool {
		return $this->end > $this->start;
	}

	public function durationMinutes(): int {
		return (int) round( ( $this->end - $this->start ) / 60 );
	}

	/**
	 * Whether the patrol has begun. Sign-ups stay open past this point by
	 * design (the unit backfills records), but withdrawal closes.
	 */
	public function hasStarted( ?int $now = null ): bool {
		return ( $now ?? time() ) >= $this->start;
	}

	public function crossesMidnight(): bool {
		return gmdate( 'Y-m-d', $this->start ) !== gmdate( 'Y-m-d', $this->end - 1 );
	}
}
