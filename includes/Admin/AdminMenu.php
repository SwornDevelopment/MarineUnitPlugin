<?php
/**
 * Marine Unit admin menu.
 *
 * Operators never need this area — patrols are created from the front-end
 * calendar. It exists for Supervisors and Administrators.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Admin;

use MarineUnit\Roles;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	public const SLUG          = 'marine-unit';
	public const VESSELS_SLUG  = 'marine-unit-vessels';
	public const SETTINGS_SLUG = 'marine-unit-settings';

	private VesselsPage $vessels;
	private SettingsPage $settings;
	private ReportsPage $reports;

	public function __construct() {
		$this->vessels  = new VesselsPage();
		$this->settings = new SettingsPage();
		$this->reports  = new ReportsPage();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addMenus' ] );
		add_action( 'admin_init', [ $this->settings, 'registerSettings' ] );
		add_action( 'admin_init', [ $this->vessels, 'handleActions' ] );
		add_action( 'admin_init', [ $this->reports, 'handleActions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'configurationNotice' ] );
	}

	public function addMenus(): void {
		add_menu_page(
			__( 'Marine Unit', 'marine-unit-plugin' ),
			__( 'Marine Unit', 'marine-unit-plugin' ),
			// Everyone who can file a report needs the menu; the Vessels
			// submenu below stays restricted.
			Roles::VIEW_OWN_REPORTS,
			self::SLUG,
			[ $this->reports, 'render' ],
			'dashicons-sos',
			26
		);

		// The parent slug renders Mission Reports, so relabel that first entry
		// and give Vessels its own slug.
		add_submenu_page(
			self::SLUG,
			__( 'Mission Reports', 'marine-unit-plugin' ),
			__( 'Mission Reports', 'marine-unit-plugin' ),
			Roles::VIEW_OWN_REPORTS,
			self::SLUG,
			[ $this->reports, 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Vessels', 'marine-unit-plugin' ),
			__( 'Vessels', 'marine-unit-plugin' ),
			Roles::MANAGE_VESSELS,
			self::VESSELS_SLUG,
			[ $this->vessels, 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'marine-unit-plugin' ),
			__( 'Settings', 'marine-unit-plugin' ),
			Roles::MANAGE_SETTINGS,
			self::SETTINGS_SLUG,
			[ $this->settings, 'render' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'mup-admin',
			MUP_URL . 'assets/css/admin.css',
			[],
			\MarineUnit\VERSION
		);

		// Powers the colour pickers on the settings screen.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_script(
			'mup-admin',
			MUP_URL . 'assets/js/admin.js',
			[ 'jquery', 'wp-color-picker' ],
			\MarineUnit\VERSION,
			true
		);

		wp_localize_script(
			'mup-admin',
			'mupAdmin',
			[
				'confirmRemoveType' => __( 'Remove this patrol type? Patrols already using it are not changed.', 'marine-unit-plugin' ),
			]
		);
	}

	/**
	 * Reports cannot be delivered until a recipient address is configured.
	 * Worth saying out loud rather than discovering it after the first
	 * submission goes nowhere.
	 */
	public function configurationNotice(): void {
		if ( ! current_user_can( Roles::MANAGE_SETTINGS ) ) {
			return;
		}

		if ( \MarineUnit\Settings::canEmailReports() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && str_contains( $screen->id, self::SETTINGS_SLUG ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Marine Unit: no mission report recipient address is set, so submitted reports will be saved but not emailed.', 'marine-unit-plugin' ),
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Set it now', 'marine-unit-plugin' )
		);
	}
}
