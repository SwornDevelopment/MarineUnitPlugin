<?php
/**
 * Sign-up orchestration.
 *
 * Every capacity-changing operation runs inside a transaction that first
 * SELECTs the patrol's sign-ups FOR UPDATE. InnoDB's gap locking on that read
 * stops a concurrent request slipping an extra row in and over-filling the
 * boat — two people hitting Sign Up at the same instant on the last seat get
 * one confirmed place and one waitlist place, not two confirmed places.
 *
 * The unique key on (patrol_id, user_id) is the second half of that guarantee:
 * a double-submitted form cannot create two rows for the same person.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Signups;

use MarineUnit\Enum\SignupStatus;
use MarineUnit\Patrols\ConflictChecker;
use MarineUnit\Patrols\Patrol;
use MarineUnit\Patrols\PatrolRepository;
use MarineUnit\Support\Result;
use MarineUnit\Support\TimeRange;

defined( 'ABSPATH' ) || exit;

final class SignupService {

	public function __construct(
		private readonly PatrolRepository $patrols,
		private readonly SignupRepository $signups,
		private readonly ConflictChecker $conflicts,
	) {}

	/**
	 * Sign a user up, confirming them or waitlisting them as capacity allows.
	 */
	public function signUp( int $patrol_id, int $user_id ): Result {
		$patrol = $this->patrols->find( $patrol_id );

		if ( ! $patrol instanceof Patrol ) {
			return Result::failure( 'patrol_not_found', __( 'That patrol no longer exists.', 'marine-unit-plugin' ) );
		}

		if ( ! $patrol->acceptsSignups() ) {
			return Result::failure( 'patrol_cancelled', __( 'That patrol has been cancelled.', 'marine-unit-plugin' ) );
		}

		$existing = $this->signups->find( $patrol_id, $user_id );

		if ( $existing instanceof Signup ) {
			// Idempotent: re-submitting is not an error, it is a no-op.
			return Result::success(
				$existing->isConfirmed() ? 'already_confirmed' : 'already_waitlisted',
				$existing->isConfirmed()
					? __( 'You are already on this patrol.', 'marine-unit-plugin' )
					: __( 'You are already on the waitlist for this patrol.', 'marine-unit-plugin' ),
				[ 'status' => $existing->status->value ]
			);
		}

		$clash = $this->conflicts->findUserConflict( $user_id, $patrol, $this->timezone() );

		if ( $clash instanceof Patrol ) {
			return Result::failure(
				'user_conflict',
				sprintf(
					/* translators: 1: patrol date, 2: start time, 3: end time. */
					__( 'You are already booked on the patrol on %1$s, %2$s–%3$s. You cannot be on two patrols at the same time.', 'marine-unit-plugin' ),
					$clash->date,
					$clash->startTime,
					$clash->endTime
				),
				[ 'conflict_patrol_id' => $clash->id ]
			);
		}

		$this->signups->beginTransaction();

		try {
			$current  = $this->signups->forPatrol( $patrol_id, true );
			$status   = Waitlist::statusForNewSignup( Waitlist::confirmedCount( $current ), $patrol->maxCrew );
			$position = SignupStatus::Waitlisted === $status ? Waitlist::nextPosition( $current ) : 0;

			$inserted = $this->signups->insert( $patrol_id, $user_id, $status, $position );

			if ( 0 === $inserted ) {
				$this->signups->rollback();

				// Lost a race to another request for the same user; whatever
				// that request created is the truth.
				$now = $this->signups->find( $patrol_id, $user_id );

				if ( $now instanceof Signup ) {
					return Result::success(
						$now->isConfirmed() ? 'already_confirmed' : 'already_waitlisted',
						__( 'You are already signed up for this patrol.', 'marine-unit-plugin' ),
						[ 'status' => $now->status->value ]
					);
				}

				return Result::failure( 'signup_failed', __( 'Could not sign you up. Please try again.', 'marine-unit-plugin' ) );
			}

			$this->signups->commit();
		} catch ( \Throwable $e ) {
			$this->signups->rollback();

			return Result::failure( 'signup_failed', __( 'Could not sign you up. Please try again.', 'marine-unit-plugin' ) );
		}

		return SignupStatus::Confirmed === $status
			? Result::success( 'confirmed', __( 'You are on the crew for this patrol.', 'marine-unit-plugin' ), [ 'status' => $status->value ] )
			: Result::success(
				'waitlisted',
				__( 'This patrol is full. You have been added to the waitlist and will move up automatically if a place frees up.', 'marine-unit-plugin' ),
				[
					'status'   => $status->value,
					'position' => $position,
				]
			);
	}

	/**
	 * A crew member taking themselves off a patrol.
	 *
	 * Allowed at any time before the patrol starts. Once it has started only an
	 * Operator or Supervisor can remove someone, so the record of who actually
	 * went out cannot be quietly rewritten afterwards.
	 */
	public function withdraw( int $patrol_id, int $user_id ): Result {
		$patrol = $this->patrols->find( $patrol_id );

		if ( ! $patrol instanceof Patrol ) {
			return Result::failure( 'patrol_not_found', __( 'That patrol no longer exists.', 'marine-unit-plugin' ) );
		}

		$range = $patrol->range( $this->timezone() );

		if ( $range instanceof TimeRange && $range->hasStarted( $this->now() ) ) {
			return Result::failure(
				'patrol_started',
				__( 'This patrol has already started. Ask the Operator or a Supervisor to take you off it.', 'marine-unit-plugin' )
			);
		}

		return $this->removeSignup( $patrol_id, $user_id, 'withdrawn', __( 'You have been taken off this patrol.', 'marine-unit-plugin' ) );
	}

	/**
	 * An Operator or Supervisor removing someone. No time restriction.
	 *
	 * The caller is responsible for the capability check —
	 * current_user_can( Roles::MANAGE_PATROL_CREW, $patrol_id ).
	 */
	public function remove( int $patrol_id, int $user_id ): Result {
		return $this->removeSignup( $patrol_id, $user_id, 'removed', __( 'Crew member removed from this patrol.', 'marine-unit-plugin' ) );
	}

	private function removeSignup( int $patrol_id, int $user_id, string $code, string $message ): Result {
		$signup = $this->signups->find( $patrol_id, $user_id );

		if ( ! $signup instanceof Signup ) {
			return Result::failure( 'not_signed_up', __( 'That person is not signed up for this patrol.', 'marine-unit-plugin' ) );
		}

		$patrol = $this->patrols->find( $patrol_id );

		if ( ! $patrol instanceof Patrol ) {
			return Result::failure( 'patrol_not_found', __( 'That patrol no longer exists.', 'marine-unit-plugin' ) );
		}

		$this->signups->beginTransaction();

		try {
			// Lock before deleting so the promotion that follows sees a
			// consistent picture.
			$this->signups->forPatrol( $patrol_id, true );
			$this->signups->delete( $patrol_id, $user_id );

			$promoted = $this->applyPromotions( $patrol_id, $patrol->maxCrew );

			$this->signups->commit();
		} catch ( \Throwable $e ) {
			$this->signups->rollback();

			return Result::failure( 'remove_failed', __( 'Could not update the crew list. Please try again.', 'marine-unit-plugin' ) );
		}

		return Result::success(
			$code,
			$message,
			[
				'promoted'    => $promoted,
				'was_status'  => $signup->status->value,
			]
		);
	}

	/**
	 * Reconcile the waitlist after the crew limit changes.
	 *
	 * Raising the limit promotes waitlisted crew in order. Lowering it does
	 * nothing: confirmed crew are never demoted, so the patrol simply runs over
	 * capacity until someone withdraws.
	 */
	public function syncCapacity( int $patrol_id ): Result {
		$patrol = $this->patrols->find( $patrol_id );

		if ( ! $patrol instanceof Patrol ) {
			return Result::failure( 'patrol_not_found', __( 'That patrol no longer exists.', 'marine-unit-plugin' ) );
		}

		$this->signups->beginTransaction();

		try {
			$this->signups->forPatrol( $patrol_id, true );
			$promoted = $this->applyPromotions( $patrol_id, $patrol->maxCrew );

			$this->signups->commit();
		} catch ( \Throwable $e ) {
			$this->signups->rollback();

			return Result::failure( 'sync_failed', __( 'Could not update the waitlist.', 'marine-unit-plugin' ) );
		}

		$current  = $this->signups->forPatrol( $patrol_id );
		$warnings = [];

		if ( Waitlist::isOverCapacity( $current, $patrol->maxCrew ) ) {
			$warnings[] = sprintf(
				/* translators: 1: confirmed crew count, 2: crew limit. */
				__( 'This patrol has %1$d confirmed crew but a limit of %2$d. Nobody has been removed — the limit applies to new sign-ups.', 'marine-unit-plugin' ),
				Waitlist::confirmedCount( $current ),
				$patrol->maxCrew
			);
		}

		return Result::success( 'synced', '', [ 'promoted' => $promoted ], $warnings );
	}

	/**
	 * Cancelling a patrol keeps its crew list intact — the record of who was
	 * scheduled matters — but no new sign-ups are accepted.
	 */
	public function onPatrolCancelled( int $patrol_id ): void {
		// Intentionally a no-op beyond the status change the repository makes.
		// Kept as a seam so later phases can hook notifications in here.
		unset( $patrol_id );
	}

	/**
	 * Delete the crew list when a patrol is deleted outright.
	 */
	public function onPatrolDeleted( int $patrol_id ): void {
		$this->signups->deleteForPatrol( $patrol_id );
	}

	/**
	 * @return int Number promoted.
	 */
	private function applyPromotions( int $patrol_id, int $max_crew ): int {
		$signups    = $this->signups->forPatrol( $patrol_id );
		$promotions = Waitlist::promotions( $signups, $max_crew );

		if ( ! $promotions ) {
			return 0;
		}

		return $this->signups->promoteMany( $promotions );
	}

	/**
	 * Crew list for display, confirmed first then the waitlist in order.
	 *
	 * @return array{confirmed: Signup[], waiting: Signup[], places_remaining: int, is_full: bool}
	 */
	public function crew( int $patrol_id, int $max_crew ): array {
		$signups = $this->signups->forPatrol( $patrol_id );

		return [
			'confirmed'        => Waitlist::confirmed( $signups ),
			'waiting'          => Waitlist::waiting( $signups ),
			'places_remaining' => Waitlist::placesRemaining( $signups, $max_crew ),
			'is_full'          => Waitlist::isFull( $signups, $max_crew ),
		];
	}

	private function timezone(): \DateTimeZone {
		return wp_timezone();
	}

	private function now(): int {
		return time();
	}
}
