<?php
/**
 * Mission report AJAX endpoints.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Frontend;

use MarineUnit\Patrols\Patrol;
use MarineUnit\Plugin;
use MarineUnit\Reports\ReportMailer;
use MarineUnit\Reports\ReportRepository;
use MarineUnit\Reports\ReportSanitizer;
use MarineUnit\Roles;
use MarineUnit\Settings;
use MarineUnit\Signups\Signup;
use MarineUnit\Support\TimeRange;
use MarineUnit\UserProfile;

defined( 'ABSPATH' ) || exit;

final class ReportAjax {

	public function register(): void {
		add_action( 'wp_ajax_mup_submit_report', [ $this, 'submit' ] );
		add_action( 'wp_ajax_mup_report_crew_sources', [ $this, 'crewSources' ] );
	}

	private function guard(): void {
		check_ajax_referer( Ajax::NONCE, 'nonce' );

		if ( ! is_user_logged_in() || ! current_user_can( Roles::SUBMIT_REPORT ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You need to be signed in to file a mission report.', 'marine-unit-plugin' ) ],
				403
			);
		}
	}

	/**
	 * Patrols the user crewed, newest first, with their crew lists — the
	 * optional "fill crew from a patrol" shortcut.
	 *
	 * The report itself stays a standalone record: this only saves typing, and
	 * nothing about the report is tied to the patrol afterwards.
	 */
	public function crewSources(): void {
		$this->guard();

		$user_id = get_current_user_id();
		$signups = Plugin::instance()->signups()->forUser( $user_id );
		$ids     = array_map( static fn ( Signup $s ): int => $s->patrolId, $signups );
		$patrols = Plugin::instance()->patrols()->findMany( $ids );

		usort( $patrols, static fn ( Patrol $a, Patrol $b ): int => strcmp( $b->date, $a->date ) );

		$out = [];

		foreach ( array_slice( $patrols, 0, 40 ) as $patrol ) {
			$crew = Plugin::instance()->signupService()->crew( $patrol->id, $patrol->maxCrew );
			$rows = [];

			// Only confirmed crew: a waitlisted member did not go out.
			foreach ( $crew['confirmed'] as $member ) {
				$rows[] = [
					'name'     => UserProfile::displayName( $member->userId ),
					'id'       => UserProfile::officerId( $member->userId ),
					'position' => $member->userId === $patrol->authorId ? 'operator' : 'crewman',
				];
			}

			$out[] = [
				'id'    => $patrol->id,
				'label' => sprintf(
					'%s — %s (%s)',
					$patrol->date,
					$patrol->launchPoint ?: __( 'Patrol', 'marine-unit-plugin' ),
					$patrol->startTime
				),
				'crew'  => $rows,
			];
		}

		wp_send_json_success( [ 'patrols' => $out ] );
	}

	public function submit(): void {
		$this->guard();

		// wp_unslash before sanitising: WordPress slashes all superglobals.
		$raw = wp_unslash( $_POST );

		unset( $raw['action'], $raw['nonce'] );

		$sanitizer = new ReportSanitizer();
		$data      = $sanitizer->sanitize( is_array( $raw ) ? $raw : [] );

		if ( ! $sanitizer->isValid() ) {
			wp_send_json_error(
				[
					'message' => __( 'Please correct the highlighted fields.', 'marine-unit-plugin' ),
					'errors'  => $sanitizer->errors(),
				],
				422
			);
		}

		$repository = new ReportRepository();
		$report_id  = $repository->create( $data, get_current_user_id() );

		if ( 0 === $report_id ) {
			wp_send_json_error(
				[ 'message' => __( 'Could not save the report. Please try again.', 'marine-unit-plugin' ) ],
				500
			);
		}

		$warnings = [];

		// Delivery is attempted after the record exists. A failure here is
		// reported, never fatal — the report is already saved, and that is
		// what makes it a record.
		$delivery = ( new ReportMailer() )->send( $report_id, $data, get_current_user_id() );

		if ( ReportMailer::STATUS_SENT !== $delivery['status'] ) {
			$warnings[] = $delivery['message'];
		}

		wp_send_json_success(
			[
				'message'   => __( 'Mission report submitted. It is now locked — ask a Supervisor if it needs amending.', 'marine-unit-plugin' ),
				'report_id' => $report_id,
				'gar_total' => $data->garTotal(),
				'gar_band'  => $data->garBand()->value,
				'delivery'  => $delivery['status'],
				'warnings'  => $warnings,
			]
		);
	}
}
