<?php
/**
 * Marine Unit fields on the WordPress user profile.
 *
 * Officer ID is stored once on the user and reused everywhere: it auto-fills
 * the mission report crew table and signature block, and appears beside names
 * on the roster.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

defined( 'ABSPATH' ) || exit;

final class UserProfile {

	public const OFFICER_ID   = 'marine_officer_id';
	public const DISPLAY_NAME = 'marine_display_name';

	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'renderFields' ] );
		add_action( 'edit_user_profile', [ $this, 'renderFields' ] );
		add_action( 'personal_options_update', [ $this, 'saveFields' ] );
		add_action( 'edit_user_profile_update', [ $this, 'saveFields' ] );
	}

	/**
	 * Officer ID is free text, not numeric: the unit's existing form shows
	 * values like `644`, but nothing guarantees future IDs stay purely numeric.
	 */
	public static function officerId( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::OFFICER_ID, true );
	}

	/**
	 * Name to show on the calendar, roster and reports. Prefers the unit's
	 * rank-and-surname override (e.g. "Cpl. Wynne") over the WordPress
	 * display name.
	 */
	public static function displayName( int $user_id ): string {
		$override = (string) get_user_meta( $user_id, self::DISPLAY_NAME, true );

		if ( '' !== $override ) {
			return $override;
		}

		$user = get_userdata( $user_id );

		return $user instanceof \WP_User ? $user->display_name : '';
	}

	/**
	 * Display name with the officer ID appended when one is recorded.
	 */
	public static function displayNameWithId( int $user_id ): string {
		$name = self::displayName( $user_id );
		$id   = self::officerId( $user_id );

		if ( '' === $id ) {
			return $name;
		}

		return sprintf( '%s (%s)', $name, $id );
	}

	/**
	 * A user may edit their own Marine Unit fields; Supervisors and
	 * Administrators may edit anyone's.
	 */
	private function canEdit( int $user_id ): bool {
		if ( get_current_user_id() === $user_id ) {
			return true;
		}

		return current_user_can( Roles::MANAGE_SETTINGS ) && current_user_can( 'edit_user', $user_id );
	}

	public function renderFields( \WP_User $user ): void {
		if ( ! $this->canEdit( (int) $user->ID ) ) {
			return;
		}

		wp_nonce_field( 'mup_save_profile_' . $user->ID, 'mup_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Marine Unit', 'marine-unit-plugin' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th>
					<label for="mup_officer_id"><?php esc_html_e( 'Officer ID #', 'marine-unit-plugin' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="mup_officer_id"
						name="<?php echo esc_attr( self::OFFICER_ID ); ?>"
						value="<?php echo esc_attr( self::officerId( (int) $user->ID ) ); ?>"
						class="regular-text"
						maxlength="32"
					/>
					<p class="description">
						<?php esc_html_e( 'Auto-fills on mission reports and appears beside this member\'s name on the roster.', 'marine-unit-plugin' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="mup_display_name"><?php esc_html_e( 'Rank / name for unit records', 'marine-unit-plugin' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="mup_display_name"
						name="<?php echo esc_attr( self::DISPLAY_NAME ); ?>"
						value="<?php echo esc_attr( (string) get_user_meta( $user->ID, self::DISPLAY_NAME, true ) ); ?>"
						class="regular-text"
						maxlength="120"
						placeholder="<?php echo esc_attr( $user->display_name ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Optional. For example "Cpl. Wynne". Leave blank to use the display name above.', 'marine-unit-plugin' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function saveFields( int $user_id ): void {
		if ( ! $this->canEdit( $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['mup_profile_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['mup_profile_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce, 'mup_save_profile_' . $user_id ) ) {
			return;
		}

		foreach ( [ self::OFFICER_ID => 32, self::DISPLAY_NAME => 120 ] as $key => $max ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			$value = mb_substr( $value, 0, $max );

			if ( '' === $value ) {
				delete_user_meta( $user_id, $key );
				continue;
			}

			update_user_meta( $user_id, $key, $value );
		}
	}

	/**
	 * Remove every Marine Unit user meta key. Uninstall only.
	 */
	public static function deleteAllMeta(): void {
		global $wpdb;

		foreach ( [ self::OFFICER_ID, self::DISPLAY_NAME ] as $key ) {
			$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $key ], [ '%s' ] );
		}
	}
}
