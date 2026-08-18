<?php
/**
 * The Mission Report's field definitions.
 *
 * Single source of truth for the form, the validator and (in Phase 5) the PDF,
 * so those three can never drift apart. Every entry traces back to a field in
 * the unit's existing Adobe LiveCycle form — the `xfa` note on each group
 * records the original field name so the mapping stays auditable.
 *
 * Four defects in the source form are deliberately NOT reproduced; see
 * PROJECT_SPEC.md §2. In short: several questions shared one underlying field,
 * so they could not be answered independently. Each gets its own field here.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Reports;

use MarineUnit\PatrolTypes;

defined( 'ABSPATH' ) || exit;

final class ReportSchema {

	/** Rows in the crew table on the paper form. */
	public const CREW_ROWS = 10;

	/** Rows in the calls-for-service and vessels-boarded tables. */
	public const CALL_ROWS = 4;
	public const BOARDED_ROWS = 4;

	/**
	 * Crew positions. From DropDownList2–10 on the source form.
	 *
	 * Row 1 of the crew table has no position control: it is fixed-labelled
	 * "Primary Operator" on the paper form and behaves the same way here.
	 *
	 * @return array<string, string>
	 */
	public static function positions(): array {
		return [
			'operator'  => __( 'Operator', 'marine-unit-plugin' ),
			'crewman'   => __( 'Crewman', 'marine-unit-plugin' ),
			'medic'     => __( 'Medic', 'marine-unit-plugin' ),
			'passenger' => __( 'Passenger', 'marine-unit-plugin' ),
		];
	}

	/**
	 * Mission types. Drawn from the admin-managed patrol type list so the
	 * report and the calendar always offer the same options.
	 *
	 * @return array<string, string>
	 */
	public static function missionTypes(): array {
		return PatrolTypes::all();
	}

	/**
	 * Training topics. CheckBox16 on the source form, which reused a single
	 * field for all nine — meaning they could not be recorded independently.
	 * Each gets its own field here.
	 *
	 * Order matches the paper form's column-major layout.
	 *
	 * @return array<string, string>
	 */
	public static function trainingTopics(): array {
		return [
			'mob'             => __( 'MOB', 'marine-unit-plugin' ),
			'towing'          => __( 'Towing', 'marine-unit-plugin' ),
			'gps'             => __( 'GPS', 'marine-unit-plugin' ),
			'flir'            => __( 'FLIR', 'marine-unit-plugin' ),
			'radar'           => __( 'Radar', 'marine-unit-plugin' ),
			'anchoring'       => __( 'Anchoring', 'marine-unit-plugin' ),
			'search_patterns' => __( 'Search Patterns', 'marine-unit-plugin' ),
			'boat_handling'   => __( 'Boat Handling', 'marine-unit-plugin' ),
			'other'           => __( 'Other', 'marine-unit-plugin' ),
		];
	}

	/**
	 * The six GAR elements, in worksheet order, with the guidance printed
	 * beneath each on the paper worksheet.
	 *
	 * The descriptions are reproduced verbatim from the source form, including
	 * its own wording. They are shown as collapsible help beside each input and
	 * printed in full on the PDF.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function garElements(): array {
		return [
			'gar_supervision'   => [
				'label'       => __( 'Supervision', 'marine-unit-plugin' ),
				'description' => __( 'Supervisory Control considers how qualified the supervisor is and whether effective supervision is taking place. Even if a person is qualified to perform a task, supervision acts as a control to minimize risk. This may simply be someone checking what is being done to ensure it is being done correctly. The higher the risk, the more the supervisor needs to be focused on observing and checking. A supervisor who is actively involved in a task (doing something) is easily distracted and should not be considered an effective safety observer in moderate to high-risk conditions.', 'marine-unit-plugin' ),
			],
			'gar_planning'      => [
				'label'       => __( 'Planning', 'marine-unit-plugin' ),
				'description' => __( 'Planning and preparation should consider how much information you have, how clear it is, and how much time you have to plan the evolution or evaluate the situation.', 'marine-unit-plugin' ),
			],
			'gar_team_selection' => [
				'label'       => __( 'Team Selection', 'marine-unit-plugin' ),
				'description' => __( 'Team selection should consider the qualifications and experience level of the individuals used for the specific event/evolution. Individuals may need to be replaced during the event/evolution and the experience level of the new team members should be assessed.', 'marine-unit-plugin' ),
			],
			'gar_team_fitness'  => [
				'label'       => __( 'Team Fitness', 'marine-unit-plugin' ),
				'description' => __( 'Team fitness should consider the physical and mental state of the crew. This is a function of the amount and quality of rest a crewmember has had. Quality of rest should consider how the ship rides, its habitability, potential sleep length, and any interruptions. Fatigue normally becomes a factor after 18 hours without rest; however, lack of quality sleep builds a deficit that worsens the effects of fatigue.', 'marine-unit-plugin' ),
			],
			'gar_environment'   => [
				'label'       => __( 'Environment', 'marine-unit-plugin' ),
				'description' => __( 'Environment should consider factors affecting personnel performance as well as the performance of the asset or resource. This includes, but is not limited to, time of day, temperature, humidity, precipitation, wind and sea conditions, proximity of aerial/navigational hazards and other exposures (e.g., oxygen deficiency, toxic chemicals, and/or injury from falls and sharp objects).', 'marine-unit-plugin' ),
			],
			'gar_complexity'    => [
				'label'       => __( 'Event/Evolution Complexity', 'marine-unit-plugin' ),
				'description' => __( 'Event/Evolution complexity should consider both the required time and the situation. Generally, the longer one is exposed to a hazard, the greater are the risks. However, each circumstance is unique. For example, more iterations of an evolution can increase the opportunity for a loss to occur, but may have the positive effect of improving the proficiency of the team, thus possibly decreasing the chance of error. This would depend upon the experience level of the team. The situation includes considering how long the environmental conditions will remain stable and the complexity of the work.', 'marine-unit-plugin' ),
			],
		];
	}

	/**
	 * Standing text at the head of the GAR worksheet, reproduced from the
	 * source form for the PDF.
	 */
	public static function garIntro(): string {
		return __( 'To compute the total level of risk for each hazard identified below, assign a risk code of 0 (For No Risk) through 10 (For Maximum Risk) to each of the six elements. This is your personal estimate of the risk. Add the risk scores to come up with a Total Risk Score for each hazard.', 'marine-unit-plugin' );
	}

	public static function garClosing(): string {
		return __( 'The ability to assign numerical values or "color codes" to hazards using the GAR Model is not the most important part of risk assessment. What is critical to this step is team discussions leading to an understanding of the risks and how they will be managed.', 'marine-unit-plugin' );
	}

	/**
	 * Plain text fields, grouped by section. Used to drive both rendering and
	 * sanitisation without repeating the list.
	 *
	 * @return array<string, string[]>
	 */
	public static function textFields(): array {
		return [
			'general'     => [ 'launch_point', 'mission_type_other' ],
			'engine'      => [ 'fuel_usage', 'oil_usage' ],
			'weather'     => [ 'weather_conditions', 'air_temp', 'water_temp', 'tide_high', 'tide_low', 'wind_conditions' ],
			'mission'     => [ 'unit_supervisor', 'communications' ],
			'signature'   => [ 'completed_by', 'completed_by_id' ],
		];
	}

	/**
	 * @return string[]
	 */
	public static function allTextFields(): array {
		return array_merge( ...array_values( self::textFields() ) );
	}

	/**
	 * Multi-line fields.
	 *
	 * @return string[]
	 */
	public static function textareaFields(): array {
		return [ 'incident_location', 'mission_notes', 'maintenance_problems', 'training_description' ];
	}

	/**
	 * Yes/No questions. Each is an independent field — the source form reused
	 * CheckBox14/15 for both "Calls for Service" and "Vessels Boarded", so
	 * answering one answered the other.
	 *
	 * @return array<string, string>
	 */
	public static function boolFields(): array {
		return [
			'pretrip_inspection' => __( 'Pre-Trip Inspection completed?', 'marine-unit-plugin' ),
			'notifications_made' => __( 'Proper notifications made?', 'marine-unit-plugin' ),
			'calls_for_service'  => __( 'Calls for Service?', 'marine-unit-plugin' ),
			'vessels_boarded'    => __( 'Vessels Boarded and/or Inspected?', 'marine-unit-plugin' ),
		];
	}

	/**
	 * Engine and generator hour meters. Each column has a start and finish
	 * reading; the total is always computed, never accepted from the browser.
	 *
	 * @return array<string, string>
	 */
	public static function meters(): array {
		return [
			'engine_port' => __( 'Engine Hours Port', 'marine-unit-plugin' ),
			'engine_stbd' => __( 'Engine Hours Starboard', 'marine-unit-plugin' ),
			'generator'   => __( 'Generator Hours', 'marine-unit-plugin' ),
		];
	}

	/**
	 * Fields whose value the server always recalculates. Anything the browser
	 * submits for these is discarded.
	 *
	 * @return string[]
	 */
	public static function computedFields(): array {
		return [
			'engine_port_total',
			'engine_stbd_total',
			'generator_total',
			'gar_total',
			'gar_risk_score',
		];
	}
}
