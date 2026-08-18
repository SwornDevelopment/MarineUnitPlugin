<?php
/**
 * Calendar AJAX endpoints.
 *
 * Every handler re-checks the nonce and the capability. The UI hides buttons a
 * user may not use, but that is presentation — these checks are the actual
 * security boundary.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Frontend;

use MarineUnit\Patrols\Patrol;
use MarineUnit\PatrolTypes;
use MarineUnit\Plugin;
use MarineUnit\Roles;
use MarineUnit\Support\Result;
use MarineUnit\Support\TimeRange;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	public const NONCE = 'mup_calendar';

	private Renderer $renderer;

	public function __construct() {
		$this->renderer = new Renderer();
	}

	public function register(): void {
		$actions = [
			'month'        => 'month',
			'patrol'       => 'patrol',
			'patrol_form'  => 'patrolForm',
			'save_patrol'  => 'savePatrol',
			'cancel_patrol' => 'cancelPatrol',
			'signup'       => 'signup',
			'withdraw'     => 'withdraw',
			'remove_crew'  => 'removeCrew',
		];

		foreach ( $actions as $action => $method ) {
			// Logged-in users only. There is no `nopriv` variant by design —
			// the whole feature is members-only.
			add_action( 'wp_ajax_mup_' . $action, [ $this, $method ] );
		}
	}

	/**
	 * Shared preamble: verify the nonce and that somebody is logged in.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( Roles::VIEW_CALENDAR ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You need to be signed in to use the patrol calendar.', 'marine-unit-plugin' ) ],
				403
			);
		}
	}

	private function requestedInt( string $key ): int {
		return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
	}

	private function requestedText( string $key ): string {
		return isset( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	public function month(): void {
		$this->guard();

		$year  = $this->requestedInt( 'year' );
		$month = $this->requestedInt( 'month' );

		if ( $year < 1970 || $year > 2200 || $month < 1 || $month > 12 ) {
			wp_send_json_error( [ 'message' => __( 'That month is out of range.', 'marine-unit-plugin' ) ], 400 );
		}

		wp_send_json_success(
			[
				'html'  => $this->renderer->monthGrid( $year, $month ),
				'title' => $this->renderer->monthTitle( $year, $month ),
				'year'  => $year,
				'month' => $month,
			]
		);
	}

	public function patrol(): void {
		$this->guard();

		$patrol = $this->loadPatrol( $this->requestedInt( 'patrol_id' ) );

		wp_send_json_success( [ 'html' => $this->renderer->patrolDetail( $patrol ) ] );
	}

	public function patrolForm(): void {
		$this->guard();

		$patrol_id = $this->requestedInt( 'patrol_id' );

		if ( $patrol_id > 0 ) {
			$patrol = $this->loadPatrol( $patrol_id );

			if ( ! current_user_can( Roles::EDIT_PATROL, $patrol_id ) ) {
				wp_send_json_error( [ 'message' => __( 'You cannot edit this patrol.', 'marine-unit-plugin' ) ], 403 );
			}

			wp_send_json_success( [ 'html' => $this->renderer->patrolForm( $patrol ) ] );
		}

		if ( ! current_user_can( Roles::CREATE_PATROL ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot create patrols.', 'marine-unit-plugin' ) ], 403 );
		}

		wp_send_json_success( [ 'html' => $this->renderer->patrolForm() ] );
	}

	public function savePatrol(): void {
		$this->guard();

		$patrol_id = $this->requestedInt( 'patrol_id' );
		$is_edit   = $patrol_id > 0;

		if ( $is_edit ) {
			$this->loadPatrol( $patrol_id );

			if ( ! current_user_can( Roles::EDIT_PATROL, $patrol_id ) ) {
				wp_send_json_error( [ 'message' => __( 'You cannot edit this patrol.', 'marine-unit-plugin' ) ], 403 );
			}
		} elseif ( ! current_user_can( Roles::CREATE_PATROL ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot create patrols.', 'marine-unit-plugin' ) ], 403 );
		}

		$data = [
			'date'         => $this->requestedText( 'date' ),
			'start_time'   => $this->requestedText( 'start_time' ),
			'end_time'     => $this->requestedText( 'end_time' ),
			'launch_point' => $this->requestedText( 'launch_point' ),
			'vessel_id'    => $this->requestedInt( 'vessel_id' ),
			'patrol_type'  => sanitize_key( $this->requestedText( 'patrol_type' ) ),
			'max_crew'     => $this->requestedInt( 'max_crew' ),
			'notes'        => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		];

		$errors = $this->validate( $data );

		if ( $errors ) {
			wp_send_json_error(
				[
					'message' => $errors[0],
					'errors'  => $errors,
				],
				422
			);
		}

		$warnings = [];
		$clash    = Plugin::instance()->conflicts()->findVesselConflict(
			$data['vessel_id'],
			$data['date'],
			$data['start_time'],
			$data['end_time'],
			$patrol_id,
			wp_timezone()
		);

		if ( $clash instanceof Patrol ) {
			// A warning, not a block: plans change and boats get swapped.
			// Refusing outright would push the unit back to paper.
			$warnings[] = sprintf(
				/* translators: 1: date, 2: start time, 3: end time. */
				__( 'This vessel is already assigned to the patrol on %1$s, %2$s–%3$s.', 'marine-unit-plugin' ),
				$clash->date,
				$clash->startTime,
				$clash->endTime
			);
		}

		$repo = Plugin::instance()->patrols();

		if ( $is_edit ) {
			$repo->update( $patrol_id, $data );

			// The crew limit may have moved, so reconcile the waitlist.
			$sync = Plugin::instance()->signupService()->syncCapacity( $patrol_id );

			$warnings = array_merge( $warnings, $sync->warnings );
			$message  = __( 'Patrol updated.', 'marine-unit-plugin' );
		} else {
			$patrol_id = $repo->create( $data, get_current_user_id() );

			if ( 0 === $patrol_id ) {
				wp_send_json_error( [ 'message' => __( 'Could not save the patrol. Please try again.', 'marine-unit-plugin' ) ], 500 );
			}

			// The Operator who schedules a patrol is on it, and occupies a slot.
			$signup = Plugin::instance()->signupService()->signUp( $patrol_id, get_current_user_id() );

			if ( $signup->failed() ) {
				// The patrol still exists — only the automatic sign-up failed,
				// most likely because they are already booked elsewhere.
				$warnings[] = sprintf(
					/* translators: %s: reason the sign-up failed. */
					__( 'Patrol created, but you were not added to the crew: %s', 'marine-unit-plugin' ),
					$signup->message
				);
			}

			$message = __( 'Patrol created.', 'marine-unit-plugin' );
		}

		wp_send_json_success(
			[
				'message'   => $message,
				'warnings'  => $warnings,
				'patrol_id' => $patrol_id,
			]
		);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @return string[]
	 */
	private function validate( array $data ): array {
		$errors = [];

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $data['date'] ) ) {
			$errors[] = __( 'Choose a valid date.', 'marine-unit-plugin' );
		}

		$range = TimeRange::fromDateAndTimes(
			(string) $data['date'],
			(string) $data['start_time'],
			(string) $data['end_time'],
			wp_timezone()
		);

		if ( ! $range instanceof TimeRange ) {
			$errors[] = __( 'Enter a valid depart and return time.', 'marine-unit-plugin' );
		}

		if ( '' === trim( (string) $data['launch_point'] ) ) {
			$errors[] = __( 'Enter a launch point.', 'marine-unit-plugin' );
		}

		$vessel = Plugin::instance()->vessels()->find( (int) $data['vessel_id'] );

		if ( ! $vessel ) {
			$errors[] = __( 'Choose a vessel.', 'marine-unit-plugin' );
		}

		if ( ! PatrolTypes::exists( (string) $data['patrol_type'] ) ) {
			$errors[] = __( 'Choose a patrol type.', 'marine-unit-plugin' );
		}

		if ( (int) $data['max_crew'] < 1 ) {
			$errors[] = __( 'A patrol needs at least one crew place.', 'marine-unit-plugin' );
		}

		return $errors;
	}

	public function cancelPatrol(): void {
		$this->guard();

		$patrol_id = $this->requestedInt( 'patrol_id' );
		$patrol    = $this->loadPatrol( $patrol_id );

		if ( ! current_user_can( Roles::CANCEL_PATROL, $patrol_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot change this patrol.', 'marine-unit-plugin' ) ], 403 );
		}

		$reinstate = 'reinstate' === $this->requestedText( 'mode' );
		$repo      = Plugin::instance()->patrols();

		if ( $reinstate ) {
			$repo->reinstate( $patrol_id );
			$message = __( 'Patrol reinstated.', 'marine-unit-plugin' );
		} else {
			$repo->cancel( $patrol_id );
			Plugin::instance()->signupService()->onPatrolCancelled( $patrol_id );

			// The crew list is kept: who was scheduled is part of the record.
			$message = __( 'Patrol cancelled. The crew list has been kept.', 'marine-unit-plugin' );
		}

		unset( $patrol );

		$this->respondWithPatrol( $patrol_id, Result::success( 'ok', $message ) );
	}

	public function signup(): void {
		$this->guard();

		if ( ! current_user_can( Roles::SIGNUP_PATROL ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot sign up for patrols.', 'marine-unit-plugin' ) ], 403 );
		}

		$patrol_id = $this->requestedInt( 'patrol_id' );
		$result    = Plugin::instance()->signupService()->signUp( $patrol_id, get_current_user_id() );

		$this->respondWithPatrol( $patrol_id, $result );
	}

	public function withdraw(): void {
		$this->guard();

		$patrol_id = $this->requestedInt( 'patrol_id' );
		$result    = Plugin::instance()->signupService()->withdraw( $patrol_id, get_current_user_id() );

		$this->respondWithPatrol( $patrol_id, $result );
	}

	public function removeCrew(): void {
		$this->guard();

		$patrol_id = $this->requestedInt( 'patrol_id' );
		$user_id   = $this->requestedInt( 'user_id' );

		$this->loadPatrol( $patrol_id );

		if ( ! current_user_can( Roles::MANAGE_PATROL_CREW, $patrol_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot change this crew list.', 'marine-unit-plugin' ) ], 403 );
		}

		$result = Plugin::instance()->signupService()->remove( $patrol_id, $user_id );

		$this->respondWithPatrol( $patrol_id, $result );
	}

	/**
	 * Send the outcome together with freshly rendered patrol detail, so the
	 * modal always reflects committed state rather than what the browser
	 * guessed happened.
	 */
	private function respondWithPatrol( int $patrol_id, Result $result ): void {
		if ( $result->failed() ) {
			wp_send_json_error(
				[
					'message' => $result->message,
					'code'    => $result->code,
				],
				400
			);
		}

		$patrol  = Plugin::instance()->patrols()->find( $patrol_id );
		$payload = [
			'message'  => $result->message,
			'code'     => $result->code,
			'warnings' => $result->warnings,
		];

		if ( $patrol instanceof Patrol ) {
			$payload['html'] = $this->renderer->patrolDetail( $patrol );
		}

		wp_send_json_success( $payload );
	}

	private function loadPatrol( int $patrol_id ): Patrol {
		$patrol = Plugin::instance()->patrols()->find( $patrol_id );

		if ( ! $patrol instanceof Patrol ) {
			wp_send_json_error( [ 'message' => __( 'That patrol no longer exists.', 'marine-unit-plugin' ) ], 404 );
		}

		return $patrol;
	}
}
