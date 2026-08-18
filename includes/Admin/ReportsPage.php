<?php
/**
 * Mission Reports admin screen.
 *
 * Supervisors and Administrators see every report; anyone else sees only their
 * own. Reports are locked records, so this screen reads, re-downloads and
 * resends — it does not edit.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Admin;

use MarineUnit\Reports\ReportData;
use MarineUnit\Reports\ReportMailer;
use MarineUnit\Reports\ReportRepository;
use MarineUnit\Roles;
use MarineUnit\Settings;
use MarineUnit\UserProfile;

defined( 'ABSPATH' ) || exit;

final class ReportsPage {

	private const NONCE = 'mup_reports';

	private ReportRepository $reports;

	public function __construct() {
		$this->reports = new ReportRepository();
	}

	/**
	 * Handle resend and download before any output, so headers still work.
	 */
	public function handleActions(): void {
		if ( ! isset( $_REQUEST['page'] ) || AdminMenu::SLUG !== $_REQUEST['page'] ) {
			return;
		}

		$action = isset( $_REQUEST['mup_action'] )
			? sanitize_key( wp_unslash( $_REQUEST['mup_action'] ) )
			: '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$report_id = isset( $_REQUEST['report_id'] ) ? (int) $_REQUEST['report_id'] : 0;

		if ( $report_id <= 0 || ! current_user_can( Roles::VIEW_REPORT, $report_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view that report.', 'marine-unit-plugin' ) );
		}

		$data = $this->reports->find( $report_id );

		if ( ! $data instanceof ReportData ) {
			wp_die( esc_html__( 'That report could not be loaded.', 'marine-unit-plugin' ) );
		}

		$author_id = (int) get_post_field( 'post_author', $report_id );

		match ( $action ) {
			'download' => $this->download( $report_id, $data, $author_id ),
			'resend'   => $this->resend( $report_id, $data, $author_id ),
			default    => null,
		};
	}

	/**
	 * Stream the PDF straight to the browser. Regenerated on demand rather
	 * than stored, so it always reflects the current record.
	 */
	private function download( int $report_id, ReportData $data, int $author_id ): void {
		$mailer = new ReportMailer();
		$pdf    = $mailer->pdf( $data, $author_id );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $mailer->filename( $report_id, $data ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );

		// Output is a generated binary PDF, not markup.
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	private function resend( int $report_id, ReportData $data, int $author_id ): void {
		if ( ! current_user_can( Roles::VIEW_ALL_REPORTS ) ) {
			wp_die( esc_html__( 'Only a Supervisor can resend a report.', 'marine-unit-plugin' ) );
		}

		$outcome = ( new ReportMailer() )->send( $report_id, $data, $author_id );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => AdminMenu::SLUG,
					'mup_notice' => $outcome['status'],
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		$can_see_all = current_user_can( Roles::VIEW_ALL_REPORTS );

		if ( ! $can_see_all && ! current_user_can( Roles::VIEW_OWN_REPORTS ) ) {
			wp_die( esc_html__( 'You do not have permission to view mission reports.', 'marine-unit-plugin' ) );
		}

		$query = new \WP_Query(
			[
				'post_type'      => ReportRepository::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				// Without the oversight capability, only your own reports.
				'author'         => $can_see_all ? '' : get_current_user_id(),
			]
		);
		?>
		<div class="wrap mup-admin">
			<h1><?php esc_html_e( 'Mission Reports', 'marine-unit-plugin' ); ?></h1>

			<?php $this->renderNotice(); ?>

			<?php if ( ! Settings::canEmailReports() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No report recipient address is set, so submitted reports are saved but not emailed.', 'marine-unit-plugin' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::SETTINGS_SLUG ) ); ?>">
							<?php esc_html_e( 'Set it now', 'marine-unit-plugin' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mission date', 'marine-unit-plugin' ); ?></th>
						<th><?php esc_html_e( 'Submitted by', 'marine-unit-plugin' ); ?></th>
						<th><?php esc_html_e( 'Launch point', 'marine-unit-plugin' ); ?></th>
						<th><?php esc_html_e( 'GAR', 'marine-unit-plugin' ); ?></th>
						<th><?php esc_html_e( 'Email', 'marine-unit-plugin' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'marine-unit-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $query->posts ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No mission reports yet.', 'marine-unit-plugin' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $query->posts as $post ) : ?>
						<?php
						$data = $this->reports->find( (int) $post->ID );

						if ( ! $data instanceof ReportData ) {
							continue;
						}

						$status = ReportMailer::status( (int) $post->ID );
						$band   = $data->garBand();
						?>
						<tr>
							<td><strong><?php echo esc_html( $data->getString( 'mission_date' ) ); ?></strong></td>
							<td><?php echo esc_html( UserProfile::displayNameWithId( (int) $post->post_author ) ); ?></td>
							<td><?php echo esc_html( $data->getString( 'launch_point' ) ?: '—' ); ?></td>
							<td>
								<span class="mup-band" style="background: <?php echo esc_attr( $band->color() ); ?>">
									<?php echo esc_html( sprintf( '%d %s', $data->garTotal(), $band->label() ) ); ?>
								</span>
							</td>
							<td>
								<span class="mup-status mup-status--<?php echo esc_attr( $status ?: 'none' ); ?>">
									<?php echo esc_html( ReportMailer::statusLabel( $status ) ); ?>
								</span>
							</td>
							<td>
								<a href="<?php echo esc_url( $this->actionUrl( 'download', (int) $post->ID ) ); ?>">
									<?php esc_html_e( 'Download PDF', 'marine-unit-plugin' ); ?>
								</a>
								<?php if ( current_user_can( Roles::VIEW_ALL_REPORTS ) ) : ?>
									|
									<a href="<?php echo esc_url( $this->actionUrl( 'resend', (int) $post->ID ) ); ?>">
										<?php esc_html_e( 'Resend', 'marine-unit-plugin' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function actionUrl( string $action, int $report_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'page'       => AdminMenu::SLUG,
					'mup_action' => $action,
					'report_id'  => $report_id,
				],
				admin_url( 'admin.php' )
			),
			self::NONCE
		);
	}

	private function renderNotice(): void {
		$notice = isset( $_GET['mup_notice'] ) ? sanitize_key( wp_unslash( $_GET['mup_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = [
			ReportMailer::STATUS_SENT       => [ 'success', __( 'Report emailed.', 'marine-unit-plugin' ) ],
			ReportMailer::STATUS_FAILED     => [ 'error', __( 'The email could not be sent. Check the site\'s email configuration — an SMTP plugin is usually the fix.', 'marine-unit-plugin' ) ],
			ReportMailer::STATUS_NO_ADDRESS => [ 'warning', __( 'No recipient address is configured.', 'marine-unit-plugin' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $type, $message ] = $messages[ $notice ];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
