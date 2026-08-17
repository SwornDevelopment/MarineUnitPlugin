<?php
/**
 * Settings screen, built on the WordPress Settings API.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Admin;

use MarineUnit\Roles;
use MarineUnit\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const GROUP = 'mup_settings_group';

	public function registerSettings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'sanitize' ],
				'default'           => Settings::defaults(),
			]
		);

		$this->addSection(
			'reports',
			__( 'Mission reports', 'marine-unit-plugin' ),
			__( 'Where submitted reports are sent. Reports are always saved in WordPress as well, so a delivery failure never loses the record.', 'marine-unit-plugin' )
		);

		$this->addField( 'report_recipient_email', __( 'Report recipient email', 'marine-unit-plugin' ), 'reports', 'email', __( 'The address that receives a PDF of every submitted mission report.', 'marine-unit-plugin' ) );
		$this->addField( 'form_number', __( 'Form number', 'marine-unit-plugin' ), 'reports', 'text', __( 'Printed in the footer of the generated PDF, e.g. the CCSO form number.', 'marine-unit-plugin' ) );
		$this->addField( 'email_from_name', __( 'Email "from" name', 'marine-unit-plugin' ), 'reports', 'text' );
		$this->addField( 'email_from_address', __( 'Email "from" address', 'marine-unit-plugin' ), 'reports', 'email', __( 'Leave blank to use the WordPress default.', 'marine-unit-plugin' ) );

		$this->addSection(
			'access',
			__( 'Access', 'marine-unit-plugin' ),
			__( 'The calendar and report form are members-only.', 'marine-unit-plugin' )
		);

		$this->addField( 'login_page_url', __( 'Login page URL', 'marine-unit-plugin' ), 'access', 'url', __( 'Signed-out visitors are sent here. Leave blank to use the standard WordPress login.', 'marine-unit-plugin' ) );

		$this->addSection(
			'branding',
			__( 'Branding', 'marine-unit-plugin' ),
			__( 'Used on the calendar, the report form and the generated PDF.', 'marine-unit-plugin' )
		);

		$this->addField( 'unit_name', __( 'Unit name', 'marine-unit-plugin' ), 'branding', 'text' );
		$this->addField( 'accent_primary', __( 'Primary colour', 'marine-unit-plugin' ), 'branding', 'color' );
		$this->addField( 'accent_secondary', __( 'Secondary colour', 'marine-unit-plugin' ), 'branding', 'color' );

		$this->addSection(
			'links',
			__( 'Reference links', 'marine-unit-plugin' ),
			__( 'Shown as helper links on the mission report, matching the links on the paper form. Leave blank to hide a link.', 'marine-unit-plugin' )
		);

		$this->addField( 'google_maps_url', __( 'Maps link', 'marine-unit-plugin' ), 'links', 'url' );
		$this->addField( 'tide_tables_url', __( 'Tide tables link', 'marine-unit-plugin' ), 'links', 'url' );

		$this->addSection( 'formats', __( 'Date &amp; time', 'marine-unit-plugin' ), '' );

		$this->addField( 'time_format', __( 'Time format', 'marine-unit-plugin' ), 'formats', 'time_format' );
		$this->addField( 'date_format', __( 'Date format', 'marine-unit-plugin' ), 'formats', 'text', __( 'PHP date format. Leave blank to use the site setting.', 'marine-unit-plugin' ) );

		$this->addSection(
			'danger',
			__( 'Data', 'marine-unit-plugin' ),
			__( 'Deactivating the plugin never deletes anything. This controls what happens if the plugin is deleted outright.', 'marine-unit-plugin' )
		);

		$this->addField( 'delete_data_on_uninstall', __( 'Delete all data on uninstall', 'marine-unit-plugin' ), 'danger', 'checkbox', __( 'Permanently removes patrols, sign-ups, mission reports, vessels and settings when the plugin is deleted. Off by default.', 'marine-unit-plugin' ) );
	}

	private function addSection( string $id, string $title, string $description ): void {
		add_settings_section(
			'mup_section_' . $id,
			$title,
			static function () use ( $description ): void {
				if ( '' !== $description ) {
					printf( '<p class="description">%s</p>', esc_html( $description ) );
				}
			},
			AdminMenu::SETTINGS_SLUG
		);
	}

	private function addField( string $key, string $label, string $section, string $type, string $description = '' ): void {
		add_settings_field(
			'mup_field_' . $key,
			$label,
			[ $this, 'renderField' ],
			AdminMenu::SETTINGS_SLUG,
			'mup_section_' . $section,
			[
				'key'         => $key,
				'type'        => $type,
				'description' => $description,
				'label_for'   => 'mup-' . $key,
			]
		);
	}

	/**
	 * @param array{key:string,type:string,description:string} $args
	 */
	public function renderField( array $args ): void {
		$key   = $args['key'];
		$type  = $args['type'];
		$id    = 'mup-' . $key;
		$name  = Settings::OPTION . '[' . $key . ']';
		$value = Settings::get( $key, '' );

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Enabled', 'marine-unit-plugin' )
				);
				break;

			case 'color':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="mup-color-picker" data-default-color="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( Settings::defaults()[ $key ] ?? '' ) )
				);
				break;

			case 'time_format':
				$current = (string) $value;
				?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<option value="24" <?php selected( $current, '24' ); ?>>
						<?php esc_html_e( '24-hour (1700)', 'marine-unit-plugin' ); ?>
					</option>
					<option value="12" <?php selected( $current, '12' ); ?>>
						<?php esc_html_e( '12-hour (5:00 pm)', 'marine-unit-plugin' ); ?>
					</option>
				</select>
				<?php
				break;

			case 'email':
			case 'url':
			case 'text':
			default:
				$input_type = in_array( $type, [ 'email', 'url' ], true ) ? $type : 'text';

				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" />',
					esc_attr( $input_type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( '' !== $args['description'] ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render(): void {
		if ( ! current_user_can( Roles::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'marine-unit-plugin' ) );
		}
		?>
		<div class="wrap mup-admin">
			<h1><?php esc_html_e( 'Marine Unit Settings', 'marine-unit-plugin' ); ?></h1>

			<?php if ( ! Settings::canEmailReports() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Set a report recipient address below so submitted mission reports can be emailed.', 'marine-unit-plugin' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( AdminMenu::SETTINGS_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
