<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Enum;

defined( 'ABSPATH' ) || exit;

enum VesselStatus: string {

	case Active       = 'active';
	case OutOfService = 'out_of_service';

	public function label(): string {
		return match ( $this ) {
			self::Active       => __( 'Active', 'marine-unit-plugin' ),
			self::OutOfService => __( 'Out of service', 'marine-unit-plugin' ),
		};
	}

	/**
	 * Whether a vessel with this status may be assigned to a new patrol.
	 *
	 * Existing patrols keep their vessel even if it later goes out of service —
	 * we never rewrite history because a boat went into the shop.
	 */
	public function isBookable(): bool {
		return self::Active === $this;
	}

	public static function tryFromMixed( mixed $value ): self {
		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::Active ) : self::Active;
	}

	/**
	 * @return array<string, string> value => label, for <select> rendering.
	 */
	public static function options(): array {
		$options = [];

		foreach ( self::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}

		return $options;
	}
}
