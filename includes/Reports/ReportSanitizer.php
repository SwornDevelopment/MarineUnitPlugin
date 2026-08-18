<?php
/**
 * Turns a submitted mission report into a validated ReportData.
 *
 * Client-side validation is a convenience for the person filling the form in.
 * This is the real thing: everything is re-checked here, and every computed
 * figure is recalculated rather than taken from the request.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Reports;

use MarineUnit\Enum\GarBand;

defined( 'ABSPATH' ) || exit;

final class ReportSanitizer {

	/** @var string[] */
	private array $errors = [];

	/**
	 * @param array<string, mixed> $input Raw request data, already unslashed.
	 */
	public function sanitize( array $input ): ReportData {
		$this->errors = [];

		$values = [];

		$values['mission_date'] = $this->date( $input['mission_date'] ?? '' );

		if ( '' === $values['mission_date'] ) {
			$this->errors[] = __( 'Enter the mission date.', 'marine-unit-plugin' );
		}

		$values['depart_time'] = $this->time( $input['depart_time'] ?? '' );
		$values['return_time'] = $this->time( $input['return_time'] ?? '' );

		foreach ( ReportSchema::allTextFields() as $field ) {
			$values[ $field ] = $this->text( $input[ $field ] ?? '' );
		}

		foreach ( ReportSchema::textareaFields() as $field ) {
			$values[ $field ] = $this->textarea( $input[ $field ] ?? '' );
		}

		// Mission types: keep only slugs that exist in the current list.
		$types  = array_keys( ReportSchema::missionTypes() );
		$chosen = is_array( $input['mission_type'] ?? null ) ? $input['mission_type'] : [];

		$values['mission_type'] = array_values(
			array_intersect( array_map( 'strval', $chosen ), $types )
		);

		if ( ! $values['mission_type'] ) {
			$this->errors[] = __( 'Choose at least one mission type.', 'marine-unit-plugin' );
		}

		$values['vessel_id'] = max( 0, (int) ( $input['vessel_id'] ?? 0 ) );

		if ( 0 === $values['vessel_id'] ) {
			$this->errors[] = __( 'Choose the vessel launched.', 'marine-unit-plugin' );
		}

		// Meter readings.
		foreach ( array_keys( ReportSchema::meters() ) as $meter ) {
			foreach ( [ 'start', 'finish' ] as $end ) {
				$key            = $meter . '_' . $end;
				$values[ $key ] = $this->decimal( $input[ $key ] ?? '' );
			}
		}

		// Yes/No questions. Absent means unanswered, which is not "No".
		foreach ( array_keys( ReportSchema::boolFields() ) as $field ) {
			$values[ $field ] = $this->tribool( $input[ $field ] ?? null );
		}

		$values['crew']    = $this->crewRows( $input['crew'] ?? [] );
		$values['calls']   = $this->callRows( $input['calls'] ?? [] );
		$values['boarded'] = $this->boardedRows( $input['boarded'] ?? [] );

		// Training topics.
		$topics           = array_keys( ReportSchema::trainingTopics() );
		$chosen_training  = is_array( $input['training'] ?? null ) ? $input['training'] : [];
		$values['training'] = array_values(
			array_intersect( array_map( 'strval', $chosen_training ), $topics )
		);

		// GAR element scores.
		foreach ( array_keys( ReportSchema::garElements() ) as $element ) {
			$values[ $element ] = $this->garScore( $input[ $element ] ?? '' );
		}

		$values['completed_date'] = $this->date( $input['completed_date'] ?? '' );

		if ( '' === $values['completed_date'] ) {
			$this->errors[] = __( 'Enter the date the report was completed.', 'marine-unit-plugin' );
		}

		if ( '' === $values['completed_by'] ) {
			$this->errors[] = __( 'Enter who completed the report.', 'marine-unit-plugin' );
		}

		// Anything the browser sent for a computed field is thrown away.
		foreach ( ReportSchema::computedFields() as $computed ) {
			unset( $values[ $computed ] );
		}

		$data = ReportData::fromArray( $values );

		$this->validateMeters( $data );
		$this->validateCrew( $data );

		return $data;
	}

	/**
	 * Meter finish readings must not be below their start readings. Caught here
	 * rather than silently producing a negative or null total.
	 */
	private function validateMeters( ReportData $data ): void {
		foreach ( ReportSchema::meters() as $meter => $label ) {
			$start  = $data->meter( $meter . '_start' );
			$finish = $data->meter( $meter . '_finish' );

			if ( null === $start || null === $finish ) {
				continue;
			}

			if ( $finish < $start ) {
				$this->errors[] = sprintf(
					/* translators: %s: meter name, e.g. "Engine Hours Port". */
					__( '%s: the finish reading is lower than the start reading.', 'marine-unit-plugin' ),
					$label
				);
			}
		}
	}

	private function validateCrew( ReportData $data ): void {
		if ( ! $data->crew() ) {
			$this->errors[] = __( 'Enter at least one crew member.', 'marine-unit-plugin' );
		}
	}

	/**
	 * @param mixed $rows
	 *
	 * @return array<int, array{name: string, id: string, position: string}>
	 */
	private function crewRows( mixed $rows ): array {
		$rows      = is_array( $rows ) ? $rows : [];
		$positions = array_keys( ReportSchema::positions() );
		$out       = [];

		for ( $i = 0; $i < ReportSchema::CREW_ROWS; $i++ ) {
			$row = is_array( $rows[ $i ] ?? null ) ? $rows[ $i ] : [];

			$position = (string) ( $row['position'] ?? '' );

			$out[] = [
				'name'     => $this->text( $row['name'] ?? '' ),
				'id'       => $this->text( $row['id'] ?? '' ),
				// Row 0 is the Primary Operator: fixed on the paper form, so
				// no submitted position is honoured for it.
				'position' => 0 === $i ? '' : ( in_array( $position, $positions, true ) ? $position : '' ),
			];
		}

		return $out;
	}

	/**
	 * @param mixed $rows
	 *
	 * @return array<int, array{event_no: string, description: string}>
	 */
	private function callRows( mixed $rows ): array {
		$rows = is_array( $rows ) ? $rows : [];
		$out  = [];

		for ( $i = 0; $i < ReportSchema::CALL_ROWS; $i++ ) {
			$row = is_array( $rows[ $i ] ?? null ) ? $rows[ $i ] : [];

			$out[] = [
				'event_no'    => $this->text( $row['event_no'] ?? '' ),
				'description' => $this->text( $row['description'] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * @param mixed $rows
	 *
	 * @return array<int, array{vessel: string}>
	 */
	private function boardedRows( mixed $rows ): array {
		$rows = is_array( $rows ) ? $rows : [];
		$out  = [];

		for ( $i = 0; $i < ReportSchema::BOARDED_ROWS; $i++ ) {
			$row = $rows[ $i ] ?? '';
			$val = is_array( $row ) ? ( $row['vessel'] ?? '' ) : $row;

			$out[] = [ 'vessel' => $this->text( $val ) ];
		}

		return $out;
	}

	private function text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return function_exists( 'sanitize_text_field' )
			? sanitize_text_field( (string) $value )
			: trim( strip_tags( (string) $value ) );
	}

	private function textarea( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return function_exists( 'sanitize_textarea_field' )
			? sanitize_textarea_field( (string) $value )
			: trim( strip_tags( (string) $value ) );
	}

	private function date( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * Times are stored as submitted in `H:i`. The paper form writes them as
	 * `1700`, so a four-digit entry is accepted and normalised too.
	 */
	private function time( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m ) ) {
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
		} elseif ( preg_match( '/^(\d{2})(\d{2})$/', $value, $m ) ) {
			$hour   = (int) $m[1];
			$minute = (int) $m[2];
		} else {
			return '';
		}

		if ( $hour > 23 || $minute > 59 ) {
			return '';
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}

	/**
	 * Meter readings: a decimal, or '' when blank. Never coerced to 0, because
	 * a blank meter and a zero reading are different facts.
	 */
	private function decimal( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		// Accept a leading decimal point, as the paper form uses (".50").
		if ( ! preg_match( '/^-?\d*\.?\d+$/', $value ) ) {
			return '';
		}

		return (string) (float) $value;
	}

	/**
	 * Yes / No / unanswered.
	 */
	private function tribool( mixed $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		return match ( $value ) {
			'1', 'yes', 'true', 'on' => true,
			'0', 'no', 'false'       => false,
			default                  => null,
		};
	}

	/**
	 * A GAR element score: an integer 0–10, or '' when not yet scored.
	 * Out-of-range numbers are clamped rather than rejected — a 12 clearly
	 * means "maximum", and refusing the whole form over it helps nobody.
	 */
	private function garScore( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		$score = (int) $value;
		$score = max( GarBand::ELEMENT_MIN, min( GarBand::ELEMENT_MAX, $score ) );

		return (string) $score;
	}

	/**
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	public function isValid(): bool {
		return [] === $this->errors;
	}
}
