<?php
/**
 * Vessels admin screen.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Admin;

use MarineUnit\Enum\VesselStatus;
use MarineUnit\Plugin;
use MarineUnit\PatrolTypes;
use MarineUnit\Roles;

defined( 'ABSPATH' ) || exit;

final class VesselsPage {

	private const NONCE = 'mup_vessels';

	/**
	 * Handle POST and GET actions before any output, so redirects work.
	 */
	public function handleActions(): void {
		if ( ! isset( $_REQUEST['page'] ) || AdminMenu::VESSELS_SLUG !== $_REQUEST['page'] ) {
			return;
		}

		if ( ! current_user_can( Roles::MANAGE_VESSELS ) ) {
			return;
		}

		$action = isset( $_REQUEST['mup_action'] )
			? sanitize_key( wp_unslash( $_REQUEST['mup_action'] ) )
			: '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$notice = match ( $action ) {
			'add_vessel'      => $this->addVessel(),
			'update_vessel'   => $this->updateVessel(),
			'retire_vessel'   => $this->setVesselStatus( false ),
			'restore_vessel'  => $this->setVesselStatus( true ),
			'add_type'        => $this->addPatrolType(),
			'remove_type'     => $this->removePatrolType(),
			default           => '',
		};

		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => AdminMenu::VESSELS_SLUG,
					'mup_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function addVessel(): string {
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			return 'vessel_name_required';
		}

		$capacity = isset( $_POST['capacity'] ) ? (int) $_POST['capacity'] : 0;

		Plugin::instance()->vessels()->create(
			[
				'name'         => $name,
				'registration' => isset( $_POST['registration'] ) ? sanitize_text_field( wp_unslash( $_POST['registration'] ) ) : '',
				'capacity'     => $capacity > 0 ? $capacity : null,
			]
		);

		return 'vessel_added';
	}

	private function updateVessel(): string {
		$id = isset( $_POST['vessel_id'] ) ? (int) $_POST['vessel_id'] : 0;

		if ( $id <= 0 ) {
			return '';
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			return 'vessel_name_required';
		}

		$capacity = isset( $_POST['capacity'] ) ? (int) $_POST['capacity'] : 0;

		Plugin::instance()->vessels()->update(
			$id,
			[
				'name'         => $name,
				'registration' => isset( $_POST['registration'] ) ? sanitize_text_field( wp_unslash( $_POST['registration'] ) ) : '',
				'capacity'     => $capacity > 0 ? $capacity : null,
				'sort_order'   => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			]
		);

		return 'vessel_updated';
	}

	private function setVesselStatus( bool $active ): string {
		$id = isset( $_REQUEST['vessel_id'] ) ? (int) $_REQUEST['vessel_id'] : 0;

		if ( $id <= 0 ) {
			return '';
		}

		$repo = Plugin::instance()->vessels();

		$active ? $repo->restore( $id ) : $repo->retire( $id );

		return $active ? 'vessel_restored' : 'vessel_retired';
	}

	private function addPatrolType(): string {
		$label = isset( $_POST['type_label'] ) ? sanitize_text_field( wp_unslash( $_POST['type_label'] ) ) : '';

		if ( '' === $label ) {
			return '';
		}

		PatrolTypes::add( $label );

		return 'type_added';
	}

	private function removePatrolType(): string {
		$slug = isset( $_REQUEST['type_slug'] ) ? sanitize_key( wp_unslash( $_REQUEST['type_slug'] ) ) : '';

		if ( '' === $slug ) {
			return '';
		}

		PatrolTypes::remove( $slug );

		return 'type_removed';
	}

	public function render(): void {
		if ( ! current_user_can( Roles::MANAGE_VESSELS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage vessels.', 'marine-unit-plugin' ) );
		}

		$vessels   = Plugin::instance()->vessels()->all();
		$types     = PatrolTypes::all();
		$editing   = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$edit_item = $editing > 0 ? Plugin::instance()->vessels()->find( $editing ) : null;
		?>
		<div class="wrap mup-admin">
			<h1><?php esc_html_e( 'Marine Unit — Vessels &amp; Patrol Types', 'marine-unit-plugin' ); ?></h1>

			<?php $this->renderNotice(); ?>

			<div class="mup-columns">
				<div class="mup-column">
					<h2><?php esc_html_e( 'Vessels', 'marine-unit-plugin' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Only active vessels can be chosen for a new patrol. Taking a vessel out of service leaves every past patrol and report untouched.', 'marine-unit-plugin' ); ?>
					</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'marine-unit-plugin' ); ?></th>
								<th><?php esc_html_e( 'Registration', 'marine-unit-plugin' ); ?></th>
								<th><?php esc_html_e( 'Capacity', 'marine-unit-plugin' ); ?></th>
								<th><?php esc_html_e( 'Status', 'marine-unit-plugin' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'marine-unit-plugin' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $vessels ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No vessels yet.', 'marine-unit-plugin' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $vessels as $vessel ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $vessel->name ); ?></strong></td>
								<td><?php echo esc_html( $vessel->registration ?: '—' ); ?></td>
								<td><?php echo esc_html( null === $vessel->capacity ? '—' : (string) $vessel->capacity ); ?></td>
								<td>
									<span class="mup-status mup-status--<?php echo esc_attr( $vessel->status->value ); ?>">
										<?php echo esc_html( $vessel->status->label() ); ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url( add_query_arg( [ 'page' => AdminMenu::VESSELS_SLUG, 'edit' => $vessel->id ], admin_url( 'admin.php' ) ) ); ?>">
										<?php esc_html_e( 'Edit', 'marine-unit-plugin' ); ?>
									</a>
									|
									<?php
									$toggle = $vessel->isBookable() ? 'retire_vessel' : 'restore_vessel';
									$label  = $vessel->isBookable()
										? __( 'Take out of service', 'marine-unit-plugin' )
										: __( 'Return to service', 'marine-unit-plugin' );
									$url    = wp_nonce_url(
										add_query_arg(
											[
												'page'       => AdminMenu::VESSELS_SLUG,
												'mup_action' => $toggle,
												'vessel_id'  => $vessel->id,
											],
											admin_url( 'admin.php' )
										),
										self::NONCE
									);
									?>
									<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<h3><?php echo $edit_item ? esc_html__( 'Edit vessel', 'marine-unit-plugin' ) : esc_html__( 'Add a vessel', 'marine-unit-plugin' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::VESSELS_SLUG ) ); ?>">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="mup_action" value="<?php echo $edit_item ? 'update_vessel' : 'add_vessel'; ?>" />
						<?php if ( $edit_item ) : ?>
							<input type="hidden" name="vessel_id" value="<?php echo esc_attr( (string) $edit_item->id ); ?>" />
						<?php endif; ?>

						<table class="form-table" role="presentation">
							<tr>
								<th><label for="mup-vessel-name"><?php esc_html_e( 'Name', 'marine-unit-plugin' ); ?></label></th>
								<td>
									<input type="text" id="mup-vessel-name" name="name" class="regular-text" required
										value="<?php echo esc_attr( $edit_item->name ?? '' ); ?>" />
								</td>
							</tr>
							<tr>
								<th><label for="mup-vessel-reg"><?php esc_html_e( 'Registration / hull number', 'marine-unit-plugin' ); ?></label></th>
								<td>
									<input type="text" id="mup-vessel-reg" name="registration" class="regular-text"
										value="<?php echo esc_attr( $edit_item->registration ?? '' ); ?>" />
								</td>
							</tr>
							<tr>
								<th><label for="mup-vessel-capacity"><?php esc_html_e( 'Capacity', 'marine-unit-plugin' ); ?></label></th>
								<td>
									<input type="number" id="mup-vessel-capacity" name="capacity" min="0" step="1" class="small-text"
										value="<?php echo esc_attr( null !== ( $edit_item->capacity ?? null ) ? (string) $edit_item->capacity : '' ); ?>" />
									<p class="description"><?php esc_html_e( 'Optional. Informational only — the crew limit is set per patrol.', 'marine-unit-plugin' ); ?></p>
								</td>
							</tr>
							<?php if ( $edit_item ) : ?>
							<tr>
								<th><label for="mup-vessel-order"><?php esc_html_e( 'Sort order', 'marine-unit-plugin' ); ?></label></th>
								<td>
									<input type="number" id="mup-vessel-order" name="sort_order" step="1" class="small-text"
										value="<?php echo esc_attr( (string) $edit_item->sortOrder ); ?>" />
								</td>
							</tr>
							<?php endif; ?>
						</table>

						<?php submit_button( $edit_item ? __( 'Save vessel', 'marine-unit-plugin' ) : __( 'Add vessel', 'marine-unit-plugin' ) ); ?>
						<?php if ( $edit_item ) : ?>
							<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::VESSELS_SLUG ) ); ?>">
								<?php esc_html_e( 'Cancel', 'marine-unit-plugin' ); ?>
							</a>
						<?php endif; ?>
					</form>
				</div>

				<div class="mup-column">
					<h2><?php esc_html_e( 'Patrol types', 'marine-unit-plugin' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Shown when an Operator schedules a patrol. Removing a type here does not change patrols already using it.', 'marine-unit-plugin' ); ?>
					</p>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'marine-unit-plugin' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'marine-unit-plugin' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $types as $slug => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td>
									<?php
									$url = wp_nonce_url(
										add_query_arg(
											[
												'page'       => AdminMenu::VESSELS_SLUG,
												'mup_action' => 'remove_type',
												'type_slug'  => $slug,
											],
											admin_url( 'admin.php' )
										),
										self::NONCE
									);
									?>
									<a href="<?php echo esc_url( $url ); ?>" class="mup-danger">
										<?php esc_html_e( 'Remove', 'marine-unit-plugin' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<h3><?php esc_html_e( 'Add a patrol type', 'marine-unit-plugin' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . AdminMenu::VESSELS_SLUG ) ); ?>">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="mup_action" value="add_type" />
						<p>
							<label class="screen-reader-text" for="mup-type-label"><?php esc_html_e( 'Patrol type', 'marine-unit-plugin' ); ?></label>
							<input type="text" id="mup-type-label" name="type_label" class="regular-text" required />
						</p>
						<?php submit_button( __( 'Add type', 'marine-unit-plugin' ), 'secondary' ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private function renderNotice(): void {
		$notice = isset( $_GET['mup_notice'] ) ? sanitize_key( wp_unslash( $_GET['mup_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = [
			'vessel_added'         => [ 'success', __( 'Vessel added.', 'marine-unit-plugin' ) ],
			'vessel_updated'       => [ 'success', __( 'Vessel saved.', 'marine-unit-plugin' ) ],
			'vessel_retired'       => [ 'success', __( 'Vessel taken out of service.', 'marine-unit-plugin' ) ],
			'vessel_restored'      => [ 'success', __( 'Vessel returned to service.', 'marine-unit-plugin' ) ],
			'vessel_name_required' => [ 'error', __( 'A vessel needs a name.', 'marine-unit-plugin' ) ],
			'type_added'           => [ 'success', __( 'Patrol type added.', 'marine-unit-plugin' ) ],
			'type_removed'         => [ 'success', __( 'Patrol type removed.', 'marine-unit-plugin' ) ],
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
