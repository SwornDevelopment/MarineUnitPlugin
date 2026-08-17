<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Enum;

defined( 'ABSPATH' ) || exit;

enum SignupStatus: string {

	case Confirmed  = 'confirmed';
	case Waitlisted = 'waitlisted';

	public function label(): string {
		return match ( $this ) {
			self::Confirmed  => __( 'Confirmed', 'marine-unit-plugin' ),
			self::Waitlisted => __( 'Waitlisted', 'marine-unit-plugin' ),
		};
	}

	/**
	 * Whether this sign-up occupies one of the patrol's crew slots.
	 */
	public function occupiesSlot(): bool {
		return self::Confirmed === $this;
	}

	public static function tryFromMixed( mixed $value ): self {
		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::Confirmed ) : self::Confirmed;
	}
}
