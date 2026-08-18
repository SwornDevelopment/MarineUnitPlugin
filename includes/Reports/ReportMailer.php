<?php
/**
 * Builds and sends the mission report email.
 *
 * A delivery failure never fails the submission — the report is already saved
 * and that is the record. The outcome is written to the report so Supervisors
 * can see what happened and resend.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Reports;

use MarineUnit\Pdf\ReportPdf;
use MarineUnit\Plugin;
use MarineUnit\Settings;
use MarineUnit\UserProfile;

defined( 'ABSPATH' ) || exit;

final class ReportMailer {

	public const META_STATUS = '_mup_email_status';
	public const META_DETAIL = '_mup_email_detail';
	public const META_SENT   = '_mup_email_sent_at';

	public const STATUS_SENT       = 'sent';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_NO_ADDRESS = 'no_address';

	/**
	 * Generate the PDF and email it.
	 *
	 * @return array{status: string, message: string}
	 */
	public function send( int $report_id, ReportData $data, int $author_id ): array {
		$to = Settings::getString( 'report_recipient_email' );

		if ( '' === $to || ! is_email( $to ) ) {
			$outcome = [
				'status'  => self::STATUS_NO_ADDRESS,
				'message' => __( 'No report recipient address is configured, so the report was saved but not emailed. A Supervisor can set one in Marine Unit → Settings.', 'marine-unit-plugin' ),
			];

			$this->record( $report_id, $outcome );

			return $outcome;
		}

		$pdf  = $this->pdf( $data, $author_id );
		$path = $this->writeTempFile( $report_id, $data, $pdf );

		if ( null === $path ) {
			$outcome = [
				'status'  => self::STATUS_FAILED,
				'message' => __( 'The report PDF could not be written to disk, so nothing was emailed.', 'marine-unit-plugin' ),
			];

			$this->record( $report_id, $outcome );

			return $outcome;
		}

		$sent = wp_mail(
			$to,
			$this->subject( $data, $author_id ),
			$this->body( $report_id, $data, $author_id ),
			$this->headers(),
			[ $path ]
		);

		// The attachment only needs to survive until wp_mail has read it.
		wp_delete_file( $path );

		$outcome = $sent
			? [
				'status'  => self::STATUS_SENT,
				'message' => sprintf(
					/* translators: %s: recipient email address. */
					__( 'Report emailed to %s.', 'marine-unit-plugin' ),
					$to
				),
			]
			: [
				'status'  => self::STATUS_FAILED,
				'message' => __( 'The report was saved, but the email could not be sent. A Supervisor can resend it from the Mission Reports screen.', 'marine-unit-plugin' ),
			];

		$this->record( $report_id, $outcome );

		return $outcome;
	}

	/**
	 * Build the PDF for a report, with the context the layout needs.
	 */
	public function pdf( ReportData $data, int $author_id ): string {
		$vessel = Plugin::instance()->vessels()->find( (int) $data->get( 'vessel_id', 0 ) );

		$mission_stamp = strtotime( $data->getString( 'mission_date' ) );
		$completed     = strtotime( $data->getString( 'completed_date' ) );

		return ReportPdf::render(
			$data,
			[
				'unit_name'                => Settings::getString( 'unit_name' ),
				'form_number'              => Settings::getString( 'form_number' ),
				'vessel'                   => $vessel?->label() ?? '',
				'author'                   => UserProfile::displayNameWithId( $author_id ),
				'mission_date_formatted'   => $mission_stamp ? wp_date( Settings::dateFormat(), $mission_stamp ) : '',
				'completed_date_formatted' => $completed ? wp_date( Settings::dateFormat(), $completed ) : '',
				'submitted_at'             => sprintf(
					/* translators: %s: submission timestamp. */
					__( 'Submitted %s', 'marine-unit-plugin' ),
					wp_date( Settings::dateFormat() . ' ' . Settings::timeFormat() )
				),
			]
		);
	}

	/**
	 * A readable, collision-resistant filename.
	 */
	public function filename( int $report_id, ReportData $data ): string {
		$date = $data->getString( 'mission_date' );
		$date = '' === $date ? gmdate( 'Y-m-d' ) : $date;

		return sprintf( 'mission-report-%s-%d.pdf', $date, $report_id );
	}

	/**
	 * Write the PDF somewhere wp_mail can attach it from.
	 *
	 * @return string|null Absolute path, or null on failure.
	 */
	private function writeTempFile( int $report_id, ReportData $data, string $pdf ): ?string {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'marine-unit-tmp';

		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		// Reports are not public documents. Belt and braces alongside the
		// unguessable filename, in case the directory is ever web-reachable.
		$this->protectDirectory( $dir );

		$path = trailingslashit( $dir ) . $this->filename( $report_id, $data );

		return false === file_put_contents( $path, $pdf ) ? null : $path;
	}

	private function protectDirectory( string $dir ): void {
		$index = trailingslashit( $dir ) . 'index.html';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n" );
		}
	}

	private function subject( ReportData $data, int $author_id ): string {
		return sprintf(
			/* translators: 1: unit name, 2: mission date, 3: reporting officer. */
			__( '%1$s — Mission Report %2$s (%3$s)', 'marine-unit-plugin' ),
			Settings::getString( 'unit_name' ),
			$data->getString( 'mission_date' ),
			UserProfile::displayName( $author_id )
		);
	}

	private function body( int $report_id, ReportData $data, int $author_id ): string {
		$band = $data->garBand();

		$lines = [
			__( 'A Marine Unit Mission Report has been submitted.', 'marine-unit-plugin' ),
			'',
			sprintf( '%s: %s', __( 'Mission date', 'marine-unit-plugin' ), $data->getString( 'mission_date' ) ),
			sprintf( '%s: %s - %s', __( 'Times', 'marine-unit-plugin' ), $data->getString( 'depart_time' ), $data->getString( 'return_time' ) ),
			sprintf( '%s: %s', __( 'Launch point', 'marine-unit-plugin' ), $data->getString( 'launch_point' ) ),
			sprintf( '%s: %s', __( 'Submitted by', 'marine-unit-plugin' ), UserProfile::displayNameWithId( $author_id ) ),
			sprintf(
				'%s: %d/60 (%s)',
				__( 'GAR risk score', 'marine-unit-plugin' ),
				$data->garTotal(),
				$band->label()
			),
			sprintf( '%s: %d', __( 'Crew', 'marine-unit-plugin' ), count( $data->crew() ) ),
			'',
			__( 'The full report is attached as a PDF.', 'marine-unit-plugin' ),
			'',
			sprintf(
				/* translators: %d: report id. */
				__( 'Report reference: #%d', 'marine-unit-plugin' ),
				$report_id
			),
		];

		return implode( "\n", $lines );
	}

	/**
	 * @return string[]
	 */
	private function headers(): array {
		$headers = [];
		$from    = Settings::getString( 'email_from_address' );
		$name    = Settings::getString( 'email_from_name' );

		if ( '' !== $from && is_email( $from ) ) {
			$headers[] = '' !== $name
				? sprintf( 'From: %s <%s>', $name, $from )
				: sprintf( 'From: %s', $from );
		}

		return $headers;
	}

	/**
	 * @param array{status: string, message: string} $outcome
	 */
	private function record( int $report_id, array $outcome ): void {
		update_post_meta( $report_id, self::META_STATUS, $outcome['status'] );
		update_post_meta( $report_id, self::META_DETAIL, $outcome['message'] );

		if ( self::STATUS_SENT === $outcome['status'] ) {
			update_post_meta( $report_id, self::META_SENT, current_time( 'mysql', true ) );
		}
	}

	public static function status( int $report_id ): string {
		$status = get_post_meta( $report_id, self::META_STATUS, true );

		return is_string( $status ) ? $status : '';
	}

	public static function statusLabel( string $status ): string {
		return match ( $status ) {
			self::STATUS_SENT       => __( 'Emailed', 'marine-unit-plugin' ),
			self::STATUS_FAILED     => __( 'Delivery failed', 'marine-unit-plugin' ),
			self::STATUS_NO_ADDRESS => __( 'No recipient set', 'marine-unit-plugin' ),
			default                 => __( 'Not sent', 'marine-unit-plugin' ),
		};
	}
}
