<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Enum;

defined( 'ABSPATH' ) || exit;

enum PatrolStatus: string {

	case Active    = 'active';
	case Cancelled = 'cancelled';

	public function label(): string {
		return match ( $this ) {
			self::Active    => __( 'Scheduled', 'marine-unit-plugin' ),
			self::Cancelled => __( 'Cancelled', 'marine-unit-plugin' ),
		};
	}

	/**
	 * Whether crew may join this patrol. A cancelled patrol stays on the
	 * calendar so people can see it was cancelled, but nobody new can join.
	 */
	public function acceptsSignups(): bool {
		return self::Active === $this;
	}

	public static function tryFromMixed( mixed $value ): self {
		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::Active ) : self::Active;
	}
}
