<?php
/**
 * Front-end shortcodes.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Frontend;

use MarineUnit\Roles;
use MarineUnit\Settings;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public const CALENDAR   = 'marine_calendar';
	public const REPORT     = 'marine_mission_report';
	public const MY_PATROLS = 'marine_my_patrols';

	private Renderer $renderer;

	public function __construct() {
		$this->renderer = new Renderer();
	}

	public function register(): void {
		add_shortcode( self::CALENDAR, [ $this, 'calendar' ] );
		add_shortcode( self::MY_PATROLS, [ $this, 'myPatrols' ] );
		add_shortcode( self::REPORT, [ $this, 'missionReport' ] );

		// Signed-out visitors are bounced before the page renders, so no part
		// of the calendar is ever sent to them.
		add_action( 'template_redirect', [ $this, 'maybeRedirectToLogin' ] );
	}

	/**
	 * Redirect signed-out visitors away from any page carrying a members-only
	 * shortcode, back to the configured login page.
	 */
	public function maybeRedirectToLogin(): void {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$gated = [ self::CALENDAR, self::REPORT, self::MY_PATROLS ];

		foreach ( $gated as $shortcode ) {
			if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
				$here = get_permalink( $post );

				wp_safe_redirect( Settings::loginUrl( is_string( $here ) ? $here : '' ) );
				exit;
			}
		}
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function calendar( $atts = [] ): string {
		if ( ! is_user_logged_in() ) {
			return $this->signedOutNotice();
		}

		if ( ! current_user_can( Roles::VIEW_CALENDAR ) ) {
			return $this->notice( __( 'Your account does not have access to the patrol calendar. Ask a Supervisor to check your role.', 'marine-unit-plugin' ) );
		}

		$atts = shortcode_atts(
			[
				'month' => '',
				'year'  => '',
			],
			is_array( $atts ) ? $atts : [],
			self::CALENDAR
		);

		$year  = (int) ( '' !== $atts['year'] ? $atts['year'] : current_time( 'Y' ) );
		$month = (int) ( '' !== $atts['month'] ? $atts['month'] : current_time( 'n' ) );

		if ( $month < 1 || $month > 12 ) {
			$month = (int) current_time( 'n' );
		}

		Assets::enqueueCalendar();

		return $this->renderer->calendar( $year, $month );
	}

	public function missionReport(): string {
		if ( ! is_user_logged_in() ) {
			return $this->signedOutNotice();
		}

		if ( ! current_user_can( Roles::SUBMIT_REPORT ) ) {
			return $this->notice( __( 'Your account cannot file mission reports. Ask a Supervisor to check your role.', 'marine-unit-plugin' ) );
		}

		Assets::enqueueCalendar();
		Assets::enqueueReport();

		return ( new ReportRenderer() )->form( get_current_user_id() );
	}

	public function myPatrols(): string {
		if ( ! is_user_logged_in() ) {
			return $this->signedOutNotice();
		}

		Assets::enqueueCalendar();

		return $this->renderer->myPatrols( get_current_user_id() );
	}

	/**
	 * Fallback for when the redirect cannot fire — for example the shortcode
	 * sitting in a widget or a template rather than in post content.
	 */
	private function signedOutNotice(): string {
		return sprintf(
			'<div class="mup-notice"><p>%s</p><p><a class="mup-btn mup-btn--primary" href="%s">%s</a></p></div>',
			esc_html__( 'Please sign in to view the patrol calendar.', 'marine-unit-plugin' ),
			esc_url( Settings::loginUrl( (string) ( get_permalink() ?: home_url() ) ) ),
			esc_html__( 'Sign in', 'marine-unit-plugin' )
		);
	}

	private function notice( string $message ): string {
		return '<div class="mup-notice"><p>' . esc_html( $message ) . '</p></div>';
	}
}
