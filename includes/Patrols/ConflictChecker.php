<?php
/**
 * Scheduling conflict detection.
 *
 * Two different conflicts, with deliberately different severity:
 *
 *  - A user booked on two overlapping patrols is BLOCKED. A person cannot be
 *    in two boats at once, so this is always an error.
 *  - A vessel booked for two overlapping patrols is a WARNING the Operator can
 *    override. Plans change, boats get swapped, and refusing outright would
 *    just push the unit back to paper.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Patrols;

use MarineUnit\Signups\SignupRepository;
use MarineUnit\Support\TimeRange;

defined( 'ABSPATH' ) || exit;

final class ConflictChecker {

	public function __construct(
		private readonly PatrolRepository $patrols,
		private readonly SignupRepository $signups,
	) {}

	/**
	 * Pure overlap comparison, extracted so it can be tested without a
	 * database. Returns the patrols whose window intersects the given range.
	 *
	 * @param Patrol[] $candidates
	 *
	 * @return Patrol[]
	 */
	public static function overlapping( TimeRange $range, array $candidates, ?\DateTimeZone $timezone = null ): array {
		$clashes = [];

		foreach ( $candidates as $candidate ) {
			$other = $candidate->range( $timezone );

			if ( ! $other instanceof TimeRange ) {
				continue;
			}

			if ( $range->overlaps( $other ) ) {
				$clashes[] = $candidate;
			}
		}

		return $clashes;
	}

	/**
	 * The patrol, if any, that stops this user joining the given patrol.
	 *
	 * Both confirmed and waitlisted sign-ups count: a waitlisted member may be
	 * promoted at any moment, so letting them hold a clashing place would just
	 * defer the problem.
	 */
	public function findUserConflict( int $user_id, Patrol $patrol, ?\DateTimeZone $timezone = null ): ?Patrol {
		$range = $patrol->range( $timezone );

		if ( ! $range instanceof TimeRange ) {
			return null;
		}

		$patrol_ids = $this->signups->patrolIdsForUser( $user_id );
		$patrol_ids = array_values( array_diff( $patrol_ids, [ $patrol->id ] ) );

		if ( ! $patrol_ids ) {
			return null;
		}

		$others = array_filter(
			$this->patrols->findMany( $patrol_ids ),
			// A cancelled patrol never blocks anything.
			static fn ( Patrol $p ): bool => ! $p->isCancelled()
		);

		$clashes = self::overlapping( $range, $others, $timezone );

		return $clashes[0] ?? null;
	}

	/**
	 * The patrol, if any, already using this vessel during the given window.
	 *
	 * @param int    $vessel_id         Vessel being assigned.
	 * @param string $date              Patrol date, `Y-m-d`.
	 * @param string $start_time        `H:i`.
	 * @param string $end_time          `H:i`.
	 * @param int    $exclude_patrol_id Patrol being edited, so it does not
	 *                                  clash with itself.
	 */
	public function findVesselConflict(
		int $vessel_id,
		string $date,
		string $start_time,
		string $end_time,
		int $exclude_patrol_id = 0,
		?\DateTimeZone $timezone = null
	): ?Patrol {
		if ( $vessel_id <= 0 ) {
			return null;
		}

		$range = TimeRange::fromDateAndTimes( $date, $start_time, $end_time, $timezone );

		if ( ! $range instanceof TimeRange ) {
			return null;
		}

		$candidates = $this->patrols->forVesselAroundDate( $vessel_id, $date, $exclude_patrol_id );
		$clashes    = self::overlapping( $range, $candidates, $timezone );

		return $clashes[0] ?? null;
	}
}
