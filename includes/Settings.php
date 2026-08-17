<?php
/**
 * Plugin settings.
 *
 * Everything lives in a single `mup_settings` option array so that reading
 * configuration costs one autoloaded row rather than a dozen.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'mup_settings';

	/** @var array<string, mixed>|null Request-level cache. */
	private static ?array $cache = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			// Reports.
			'report_recipient_email' => '',
			'form_number'            => '',

			// Email.
			'email_from_name'        => get_bloginfo( 'name' ),
			'email_from_address'     => '',

			// Access.
			'login_page_url'         => '',

			// Branding.
			'unit_name'              => "Charles County Sheriff's Office Marine Unit",
			'accent_primary'         => '#1e3a5f',
			'accent_secondary'       => '#c8912f',

			// Helper links on the mission report.
			'google_maps_url'        => 'https://www.google.com/maps',
			'tide_tables_url'        => '',

			// Formats.
			'time_format'            => '24',
			'date_format'            => '',

			// Danger zone.
			'delete_data_on_uninstall' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, [] );
			$stored = is_array( $stored ) ? $stored : [];

			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	public static function get( string $key, mixed $fallback = null ): mixed {
		$all = self::all();

		return $all[ $key ] ?? $fallback;
	}

	public static function getString( string $key ): string {
		return (string) self::get( $key, '' );
	}

	public static function getBool( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * Clear the request-level cache. Called after a save and by tests.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Where to send signed-out visitors who hit a members-only screen.
	 * Falls back to the standard WordPress login when no custom page is set.
	 */
	public static function loginUrl( string $redirect_to = '' ): string {
		$custom = self::getString( 'login_page_url' );

		if ( '' === $custom ) {
			return wp_login_url( $redirect_to );
		}

		return '' === $redirect_to
			? $custom
			: add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $custom );
	}

	/**
	 * Format for displaying times, honouring the 12/24-hour setting.
	 */
	public static function timeFormat(): string {
		return '12' === self::getString( 'time_format' ) ? 'g:i a' : 'H:i';
	}

	public static function dateFormat(): string {
		$custom = self::getString( 'date_format' );

		return '' === $custom ? (string) get_option( 'date_format', 'F j, Y' ) : $custom;
	}

	/**
	 * True when reports can actually be delivered. The submission flow still
	 * saves the report when this is false — it just cannot email it.
	 */
	public static function canEmailReports(): bool {
		return '' !== self::getString( 'report_recipient_email' );
	}

	/**
	 * Sanitise the settings form. Registered as the option's sanitize_callback,
	 * so this runs on every save including programmatic ones.
	 *
	 * @param mixed $input Raw submitted values.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$out    = self::defaults();
		$stored = get_option( self::OPTION, [] );

		if ( is_array( $stored ) ) {
			$out = array_merge( $out, $stored );
		}

		$text_keys = [ 'unit_name', 'form_number', 'email_from_name' ];

		foreach ( $text_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		foreach ( [ 'report_recipient_email', 'email_from_address' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$email       = sanitize_email( (string) $input[ $key ] );
				$out[ $key ] = is_email( $email ) ? $email : '';
			}
		}

		foreach ( [ 'login_page_url', 'google_maps_url', 'tide_tables_url' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = esc_url_raw( trim( (string) $input[ $key ] ) );
			}
		}

		foreach ( [ 'accent_primary', 'accent_secondary' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$color       = sanitize_hex_color( (string) $input[ $key ] );
				$out[ $key ] = $color ?: self::defaults()[ $key ];
			}
		}

		if ( isset( $input['time_format'] ) ) {
			$out['time_format'] = '12' === (string) $input['time_format'] ? '12' : '24';
		}

		if ( isset( $input['date_format'] ) ) {
			$out['date_format'] = sanitize_text_field( (string) $input['date_format'] );
		}

		// Checkbox: absent means unchecked, so this is set unconditionally.
		$out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		self::flush();

		return $out;
	}

	public static function deleteOption(): void {
		delete_option( self::OPTION );
		self::flush();
	}
}
