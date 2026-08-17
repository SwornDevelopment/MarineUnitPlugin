<?php
/**
 * GAR (Green–Amber–Red) risk banding.
 *
 * Bands and thresholds are taken verbatim from the unit's existing GAR Risk
 * Calculation Worksheet:
 *
 *   GREEN 1–23   low risk
 *   AMBER 24–44  caution; consider procedures to minimise the risk
 *   RED   45–60  high risk; reduce the risk before starting
 *
 * A total of 0 (nothing scored yet) is treated as unscored rather than green.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Enum;

defined( 'ABSPATH' ) || exit;

enum GarBand: string {

	case Unscored = 'unscored';
	case Green    = 'green';
	case Amber    = 'amber';
	case Red      = 'red';

	/** Lowest permitted score for a single GAR element. */
	public const ELEMENT_MIN = 0;

	/** Highest permitted score for a single GAR element. */
	public const ELEMENT_MAX = 10;

	/** Number of elements on the worksheet, hence a 0–60 total. */
	public const ELEMENT_COUNT = 6;

	public static function fromTotal( int $total ): self {
		return match ( true ) {
			$total <= 0  => self::Unscored,
			$total <= 23 => self::Green,
			$total <= 44 => self::Amber,
			default      => self::Red,
		};
	}

	public function label(): string {
		return match ( $this ) {
			self::Unscored => __( 'Not scored', 'marine-unit-plugin' ),
			self::Green    => __( 'Green', 'marine-unit-plugin' ),
			self::Amber    => __( 'Amber', 'marine-unit-plugin' ),
			self::Red      => __( 'Red', 'marine-unit-plugin' ),
		};
	}

	/**
	 * The parenthetical wording used on the printed worksheet.
	 */
	public function descriptor(): string {
		return match ( $this ) {
			self::Unscored => '',
			self::Green    => __( 'Low Risk', 'marine-unit-plugin' ),
			self::Amber    => __( 'Caution', 'marine-unit-plugin' ),
			self::Red      => __( 'High Risk', 'marine-unit-plugin' ),
		};
	}

	/**
	 * Guidance shown beneath the worksheet total.
	 */
	public function guidance(): string {
		return match ( $this ) {
			self::Unscored => __( 'Score all six elements to calculate the mission risk.', 'marine-unit-plugin' ),
			self::Green    => __( 'Risk is rated as low.', 'marine-unit-plugin' ),
			self::Amber    => __( 'Risk is moderate. Consider adopting procedures to minimise the risk.', 'marine-unit-plugin' ),
			self::Red      => __( 'Implement measures to reduce the risk prior to starting the event or evolution.', 'marine-unit-plugin' ),
		};
	}

	/**
	 * Hex colour used for the band chip in the form, admin list and PDF.
	 * Chosen to stay legible against white with white text on top.
	 */
	public function color(): string {
		return match ( $this ) {
			self::Unscored => '#6b7280',
			self::Green    => '#15803d',
			self::Amber    => '#b45309',
			self::Red      => '#b91c1c',
		};
	}
}
