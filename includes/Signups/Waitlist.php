<?php
/**
 * Waitlist arithmetic.
 *
 * Deliberately pure: every method takes plain arrays and returns plain data,
 * with no database access, so the rules can be tested exhaustively without
 * WordPress. SignupService is responsible for persisting whatever this decides.
 *
 * The rules, from the project spec:
 *
 *  - A new sign-up is confirmed while confirmed crew < max crew, otherwise it
 *    joins the waitlist at the back.
 *  - When a confirmed member leaves, the lowest-positioned waitlisted member is
 *    promoted.
 *  - Raising max crew promotes waitlisted members in order until the new limit
 *    is met.
 *  - Lowering max crew NEVER demotes anyone already confirmed. The patrol simply
 *    runs over capacity. Bumping someone who has already committed is worse
 *    than a temporary overage.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Signups;

use MarineUnit\Enum\SignupStatus;

defined( 'ABSPATH' ) || exit;

final class Waitlist {

	/**
	 * Status a new sign-up should receive.
	 *
	 * @param int $confirmed_count Confirmed crew already on the patrol.
	 * @param int $max_crew        Crew limit for the patrol.
	 */
	public static function statusForNewSignup( int $confirmed_count, int $max_crew ): SignupStatus {
		return $confirmed_count < $max_crew
			? SignupStatus::Confirmed
			: SignupStatus::Waitlisted;
	}

	/**
	 * Position for a new waitlist entry: one past the highest in use, so
	 * ordering survives arbitrary promotions and withdrawals without a
	 * renumbering pass.
	 *
	 * @param Signup[] $signups Every sign-up on the patrol.
	 */
	public static function nextPosition( array $signups ): int {
		$highest = 0;

		foreach ( $signups as $signup ) {
			if ( $signup->waitlistPosition > $highest ) {
				$highest = $signup->waitlistPosition;
			}
		}

		return $highest + 1;
	}

	/**
	 * @param Signup[] $signups
	 *
	 * @return Signup[]
	 */
	public static function confirmed( array $signups ): array {
		return array_values(
			array_filter( $signups, static fn ( Signup $s ): bool => $s->status->occupiesSlot() )
		);
	}

	/**
	 * Waitlisted sign-ups in promotion order.
	 *
	 * Ties on position fall back to sign-up id, so the ordering is total and
	 * stable rather than depending on however the database returned the rows.
	 *
	 * @param Signup[] $signups
	 *
	 * @return Signup[]
	 */
	public static function waiting( array $signups ): array {
		$waiting = array_values(
			array_filter( $signups, static fn ( Signup $s ): bool => ! $s->status->occupiesSlot() )
		);

		usort(
			$waiting,
			static function ( Signup $a, Signup $b ): int {
				return [ $a->waitlistPosition, $a->id ] <=> [ $b->waitlistPosition, $b->id ];
			}
		);

		return $waiting;
	}

	public static function confirmedCount( array $signups ): int {
		return count( self::confirmed( $signups ) );
	}

	/**
	 * Which sign-ups should be promoted to fill the patrol.
	 *
	 * Returns ids rather than mutating anything, so the caller can apply them
	 * inside its own transaction.
	 *
	 * @param Signup[] $signups  Every sign-up on the patrol.
	 * @param int      $max_crew Crew limit.
	 *
	 * @return int[] Sign-up ids to promote, in promotion order.
	 */
	public static function promotions( array $signups, int $max_crew ): array {
		$free = $max_crew - self::confirmedCount( $signups );

		if ( $free <= 0 ) {
			return [];
		}

		$promote = [];

		foreach ( self::waiting( $signups ) as $signup ) {
			if ( count( $promote ) >= $free ) {
				break;
			}

			$promote[] = $signup->id;
		}

		return $promote;
	}

	/**
	 * True when the patrol is carrying more confirmed crew than its limit.
	 * Happens only when an Operator lowers the limit after people signed up.
	 *
	 * @param Signup[] $signups
	 */
	public static function isOverCapacity( array $signups, int $max_crew ): bool {
		return self::confirmedCount( $signups ) > $max_crew;
	}

	/**
	 * Remaining places, floored at zero so an over-capacity patrol reports
	 * "full" rather than a negative count.
	 *
	 * @param Signup[] $signups
	 */
	public static function placesRemaining( array $signups, int $max_crew ): int {
		return max( 0, $max_crew - self::confirmedCount( $signups ) );
	}

	/**
	 * @param Signup[] $signups
	 */
	public static function isFull( array $signups, int $max_crew ): bool {
		return self::confirmedCount( $signups ) >= $max_crew;
	}
}
