<?php
/**
 * Front-end asset loading.
 *
 * Assets are registered on `wp_enqueue_scripts` but only enqueued when a
 * shortcode actually renders, so pages without the calendar stay untouched.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Frontend;

use MarineUnit\Enum\GarBand;
use MarineUnit\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const HANDLE = 'mup-calendar';
	public const REPORT_HANDLE = 'mup-report';

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'registerAssets' ] );
	}

	public function registerAssets(): void {
		wp_register_style(
			self::HANDLE,
			MUP_URL . 'assets/css/calendar.css',
			[],
			\MarineUnit\VERSION
		);

		wp_register_script(
			self::HANDLE,
			MUP_URL . 'assets/js/calendar.js',
			[],
			\MarineUnit\VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'mupCalendar',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::NONCE ),
				'i18n'    => [
					'loading'        => __( 'Loading…', 'marine-unit-plugin' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'marine-unit-plugin' ),
					'confirmWithdraw' => __( 'Take yourself off this patrol?', 'marine-unit-plugin' ),
					'confirmRemove'  => __( 'Remove this crew member from the patrol?', 'marine-unit-plugin' ),
					'confirmCancel'  => __( 'Cancel this patrol? The crew list is kept, but nobody new can sign up.', 'marine-unit-plugin' ),
					'saving'         => __( 'Saving…', 'marine-unit-plugin' ),
					'choosePatrol'   => __( 'Choose a patrol', 'marine-unit-plugin' ),
					'noPatrols'      => __( 'No patrols to fill from', 'marine-unit-plugin' ),
					'meterBackwards' => __( 'Finish is below start', 'marine-unit-plugin' ),
					// Band wording matches GarBand::guidance() on the server.
					'garBands'       => [
						'unscored' => GarBand::Unscored->guidance(),
						'green'    => GarBand::Green->guidance(),
						'amber'    => GarBand::Amber->guidance(),
						'red'      => GarBand::Red->guidance(),
					],
				],
			]
		);

		wp_register_script(
			self::REPORT_HANDLE,
			MUP_URL . 'assets/js/report.js',
			[ self::HANDLE ],
			\MarineUnit\VERSION,
			true
		);

		// Branding colours come from settings, so they belong in an inline
		// custom-property block rather than the static stylesheet.
		wp_add_inline_style( self::HANDLE, self::paletteCss() );
	}

	public static function enqueueCalendar(): void {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	public static function enqueueReport(): void {
		wp_enqueue_script( self::REPORT_HANDLE );
	}

	private static function paletteCss(): string {
		$primary   = Settings::getString( 'accent_primary' );
		$secondary = Settings::getString( 'accent_secondary' );

		return sprintf(
			'.mup-calendar,.mup-mypatrols,.mup-notice{--mup-accent:%s;--mup-accent-2:%s;}',
			esc_attr( $primary ?: '#1e3a5f' ),
			esc_attr( $secondary ?: '#c8912f' )
		);
	}
}
