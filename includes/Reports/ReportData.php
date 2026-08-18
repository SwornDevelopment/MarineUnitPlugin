<?php
/**
 * A mission report's values.
 *
 * Holds the sanitised payload and owns every derived figure. The engine and
 * generator totals and the GAR score are computed here and nowhere else, so a
 * stored report can never disagree with itself.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Reports;

use MarineUnit\Enum\GarBand;

defined( 'ABSPATH' ) || exit;

final class ReportData {

	/** Bumped when the stored shape changes, so old rows can be migrated. */
	public const SCHEMA_VERSION = 1;

	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct( private array $values = [] ) {}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function fromArray( array $values ): self {
		return new self( $values );
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->values[ $key ] ?? $fallback;
	}

	public function getString( string $key ): string {
		$value = $this->values[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	public function getBool( string $key ): ?bool {
		$value = $this->values[ $key ] ?? null;

		// Null is meaningful: the question was left unanswered, which is not
		// the same as "No".
		return null === $value ? null : (bool) $value;
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getRows( string $key ): array {
		$rows = $this->values[ $key ] ?? [];

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @return string[]
	 */
	public function getList( string $key ): array {
		$list = $this->values[ $key ] ?? [];

		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : [];
	}

	public function has( string $key ): bool {
		return array_key_exists( $key, $this->values );
	}

	public function set( string $key, mixed $value ): void {
		$this->values[ $key ] = $value;
	}

	/**
	 * A meter reading, or null when the field was left blank. Blank is not
	 * zero — a zero reading and no reading mean different things.
	 */
	public function meter( string $key ): ?float {
		$value = $this->values[ $key ] ?? '';

		if ( '' === $value || null === $value ) {
			return null;
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Hours run on one meter.
	 *
	 * Returns null unless both readings are present, and null if the finish
	 * reading is below the start — that is bad data, not negative hours, and
	 * the validator rejects it separately.
	 *
	 * @param string $meter One of the keys from ReportSchema::meters().
	 */
	public function meterTotal( string $meter ): ?float {
		$start  = $this->meter( $meter . '_start' );
		$finish = $this->meter( $meter . '_finish' );

		if ( null === $start || null === $finish ) {
			return null;
		}

		if ( $finish < $start ) {
			return null;
		}

		// Meter readings carry one or two decimals; rounding keeps 3015.2 minus
		// 3012.2 at exactly 3.0 rather than 2.9999999999996.
		return round( $finish - $start, 2 );
	}

	/**
	 * A single GAR element score, clamped to the worksheet's 0–10 range.
	 */
	public function garScore( string $key ): ?int {
		$value = $this->values[ $key ] ?? '';

		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$score = (int) $value;

		return max( GarBand::ELEMENT_MIN, min( GarBand::ELEMENT_MAX, $score ) );
	}

	/**
	 * Total risk score: the six element scores added together.
	 *
	 * Unscored elements count as zero, so a partially filled worksheet still
	 * produces a total rather than nothing.
	 */
	public function garTotal(): int {
		$total = 0;

		foreach ( array_keys( ReportSchema::garElements() ) as $key ) {
			$total += $this->garScore( $key ) ?? 0;
		}

		return $total;
	}

	public function garBand(): GarBand {
		return GarBand::fromTotal( $this->garTotal() );
	}

	/**
	 * True once every element has been given a score.
	 */
	public function garComplete(): bool {
		foreach ( array_keys( ReportSchema::garElements() ) as $key ) {
			if ( null === $this->garScore( $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Crew rows that actually carry a name. The form always submits ten rows;
	 * empty ones are not part of the record.
	 *
	 * @return array<int, array{name: string, id: string, position: string}>
	 */
	public function crew(): array {
		$crew = [];

		foreach ( $this->getRows( 'crew' ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = trim( (string) ( $row['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$crew[] = [
				'name'     => $name,
				'id'       => trim( (string) ( $row['id'] ?? '' ) ),
				// Row 0 is the Primary Operator and has no position control on
				// the form, so it is labelled rather than chosen.
				'position' => 0 === $index ? 'primary_operator' : (string) ( $row['position'] ?? '' ),
			];
		}

		return $crew;
	}

	/**
	 * @return array<int, array{event_no: string, description: string}>
	 */
	public function calls(): array {
		$calls = [];

		foreach ( $this->getRows( 'calls' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$event = trim( (string) ( $row['event_no'] ?? '' ) );
			$desc  = trim( (string) ( $row['description'] ?? '' ) );

			if ( '' === $event && '' === $desc ) {
				continue;
			}

			$calls[] = [
				'event_no'    => $event,
				'description' => $desc,
			];
		}

		return $calls;
	}

	/**
	 * @return string[]
	 */
	public function boardedVessels(): array {
		$vessels = [];

		foreach ( $this->getRows( 'boarded' ) as $row ) {
			$value = is_array( $row ) ? (string) ( $row['vessel'] ?? '' ) : (string) $row;
			$value = trim( $value );

			if ( '' !== $value ) {
				$vessels[] = $value;
			}
		}

		return $vessels;
	}

	/**
	 * The stored payload, with every computed field recalculated.
	 *
	 * This is what gets persisted: computed values are written alongside the
	 * inputs so a stored report reads correctly without re-deriving anything,
	 * but they are always produced here rather than trusted from a request.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$out = $this->values;

		foreach ( array_keys( ReportSchema::meters() ) as $meter ) {
			$total = $this->meterTotal( $meter );

			$out[ $meter . '_total' ] = null === $total ? '' : $total;
		}

		$out['gar_total'] = $this->garTotal();

		// Page 1's "GAR Risk Score" and the worksheet total are the same
		// number on the paper form. Writing both keeps the PDF simple, and
		// deriving both here means they cannot diverge.
		$out['gar_risk_score']  = $out['gar_total'];
		$out['gar_band']        = $this->garBand()->value;
		$out['_schema_version'] = self::SCHEMA_VERSION;

		return $out;
	}
}
