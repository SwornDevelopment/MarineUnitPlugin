<?php
/**
 * The Mission Report form.
 *
 * Section order, headings and field order follow the unit's paper form, so
 * anyone who has filled in the Adobe version recognises this one immediately.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Frontend;

use MarineUnit\Enum\GarBand;
use MarineUnit\Plugin;
use MarineUnit\Reports\ReportData;
use MarineUnit\Reports\ReportSchema;
use MarineUnit\Settings;
use MarineUnit\UserProfile;

defined( 'ABSPATH' ) || exit;

final class ReportRenderer {

	public function form( int $user_id ): string {
		$data    = ReportData::fromArray( [] );
		$vessels = Plugin::instance()->vessels()->options( false );
		$today   = current_time( 'Y-m-d' );

		ob_start();
		?>
		<form class="mup-report" data-mup-report novalidate>
			<header class="mup-report__head">
				<h2><?php echo esc_html( Settings::getString( 'unit_name' ) ); ?></h2>
				<p class="mup-report__subtitle"><?php esc_html_e( 'Mission Report', 'marine-unit-plugin' ); ?></p>
			</header>

			<div class="mup-report__messages" data-mup-report-messages></div>

			<?php
			echo $this->sectionGeneral( $data, $vessels ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionWeather( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionCrew( $data, $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionMission( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionMaintenance( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionTraining( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionGar( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo $this->sectionSignature( $data, $user_id, $today ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>

			<div class="mup-report__actions">
				<button type="submit" class="mup-btn mup-btn--primary">
					<?php esc_html_e( 'Submit report', 'marine-unit-plugin' ); ?>
				</button>
				<p class="mup-hint">
					<?php esc_html_e( 'Once submitted a report is locked. Only a Supervisor can amend it afterwards.', 'marine-unit-plugin' ); ?>
				</p>
			</div>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, string> $vessels
	 */
	private function sectionGeneral( ReportData $data, array $vessels ): string {
		$maps = Settings::getString( 'google_maps_url' );

		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'I. General Mission Information', 'marine-unit-plugin' ); ?></h3>

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-r-date"><?php esc_html_e( 'Mission Date', 'marine-unit-plugin' ); ?></label>
					<input type="date" id="mup-r-date" name="mission_date" required value="<?php echo esc_attr( $data->getString( 'mission_date' ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-depart"><?php esc_html_e( 'Depart Time', 'marine-unit-plugin' ); ?></label>
					<input type="time" id="mup-r-depart" name="depart_time" value="<?php echo esc_attr( $data->getString( 'depart_time' ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-return"><?php esc_html_e( 'Return Time', 'marine-unit-plugin' ); ?></label>
					<input type="time" id="mup-r-return" name="return_time" value="<?php echo esc_attr( $data->getString( 'return_time' ) ); ?>" />
				</div>
			</div>

			<div class="mup-field">
				<label for="mup-r-launch"><?php esc_html_e( 'Launch Point', 'marine-unit-plugin' ); ?></label>
				<input type="text" id="mup-r-launch" name="launch_point" maxlength="180" value="<?php echo esc_attr( $data->getString( 'launch_point' ) ); ?>" />
			</div>

			<fieldset class="mup-fieldset">
				<legend><?php esc_html_e( 'Mission Type', 'marine-unit-plugin' ); ?></legend>
				<div class="mup-checkgrid">
					<?php foreach ( ReportSchema::missionTypes() as $slug => $label ) : ?>
						<label class="mup-check">
							<input type="checkbox" name="mission_type[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $data->getList( 'mission_type' ), true ) ); ?> />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="mup-field">
					<label for="mup-r-type-other"><?php esc_html_e( 'If Other, specify', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-type-other" name="mission_type_other" maxlength="180" value="<?php echo esc_attr( $data->getString( 'mission_type_other' ) ); ?>" />
				</div>
			</fieldset>

			<div class="mup-field">
				<label for="mup-r-location"><?php esc_html_e( 'Incident Location or Area/s of Operation', 'marine-unit-plugin' ); ?></label>
				<textarea id="mup-r-location" name="incident_location" rows="2" maxlength="1000"><?php echo esc_textarea( $data->getString( 'incident_location' ) ); ?></textarea>
				<p class="mup-hint">
					<?php esc_html_e( 'May insert GPS coordinates or general area.', 'marine-unit-plugin' ); ?>
					<?php if ( '' !== $maps ) : ?>
						<a href="<?php echo esc_url( $maps ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open maps', 'marine-unit-plugin' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<div class="mup-field">
				<label for="mup-r-vessel"><?php esc_html_e( 'Vessel Launched', 'marine-unit-plugin' ); ?></label>
				<select id="mup-r-vessel" name="vessel_id" required>
					<option value=""><?php esc_html_e( 'Choose a vessel', 'marine-unit-plugin' ); ?></option>
					<?php foreach ( $vessels as $id => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="mup-meters">
				<?php foreach ( ReportSchema::meters() as $meter => $label ) : ?>
					<div class="mup-meter">
						<h4><?php echo esc_html( $label ); ?></h4>
						<div class="mup-meter__row">
							<div class="mup-field">
								<label for="mup-r-<?php echo esc_attr( $meter ); ?>-start"><?php esc_html_e( 'Start', 'marine-unit-plugin' ); ?></label>
								<input type="text" inputmode="decimal" id="mup-r-<?php echo esc_attr( $meter ); ?>-start"
									name="<?php echo esc_attr( $meter ); ?>_start" data-mup-meter="<?php echo esc_attr( $meter ); ?>" data-mup-end="start" />
							</div>
							<div class="mup-field">
								<label for="mup-r-<?php echo esc_attr( $meter ); ?>-finish"><?php esc_html_e( 'Finish', 'marine-unit-plugin' ); ?></label>
								<input type="text" inputmode="decimal" id="mup-r-<?php echo esc_attr( $meter ); ?>-finish"
									name="<?php echo esc_attr( $meter ); ?>_finish" data-mup-meter="<?php echo esc_attr( $meter ); ?>" data-mup-end="finish" />
							</div>
							<div class="mup-field">
								<label for="mup-r-<?php echo esc_attr( $meter ); ?>-total"><?php esc_html_e( 'Total', 'marine-unit-plugin' ); ?></label>
								<output id="mup-r-<?php echo esc_attr( $meter ); ?>-total" class="mup-output" data-mup-meter-total="<?php echo esc_attr( $meter ); ?>">—</output>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-r-fuel"><?php esc_html_e( 'Fuel Usage', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-fuel" name="fuel_usage" maxlength="40" value="<?php echo esc_attr( $data->getString( 'fuel_usage' ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-oil"><?php esc_html_e( 'Oil Usage', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-oil" name="oil_usage" maxlength="40" value="<?php echo esc_attr( $data->getString( 'oil_usage' ) ); ?>" />
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionWeather( ReportData $data ): string {
		$tides = Settings::getString( 'tide_tables_url' );

		$fields = [
			'weather_conditions' => __( 'Weather Conditions', 'marine-unit-plugin' ),
			'air_temp'           => __( 'Air Temp', 'marine-unit-plugin' ),
			'water_temp'         => __( 'Water Temp', 'marine-unit-plugin' ),
		];

		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'II. Operational Weather Conditions', 'marine-unit-plugin' ); ?></h3>

			<div class="mup-form__row">
				<?php foreach ( $fields as $name => $label ) : ?>
					<div class="mup-field">
						<label for="mup-r-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
						<input type="text" id="mup-r-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" maxlength="60"
							value="<?php echo esc_attr( $data->getString( $name ) ); ?>" />
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-r-tide-h"><?php esc_html_e( 'Tides — High', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-tide-h" name="tide_high" maxlength="40" value="<?php echo esc_attr( $data->getString( 'tide_high' ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-tide-l"><?php esc_html_e( 'Tides — Low', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-tide-l" name="tide_low" maxlength="40" value="<?php echo esc_attr( $data->getString( 'tide_low' ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-wind"><?php esc_html_e( 'Wind Conditions', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-wind" name="wind_conditions" maxlength="60" value="<?php echo esc_attr( $data->getString( 'wind_conditions' ) ); ?>" />
				</div>
			</div>

			<?php if ( '' !== $tides ) : ?>
				<p class="mup-hint">
					<a href="<?php echo esc_url( $tides ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open tide tables', 'marine-unit-plugin' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionCrew( ReportData $data, int $user_id ): string {
		$positions = ReportSchema::positions();

		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'III. Crew Information', 'marine-unit-plugin' ); ?></h3>

			<div class="mup-crewfill">
				<label for="mup-r-fillfrom"><?php esc_html_e( 'Fill crew from a patrol', 'marine-unit-plugin' ); ?></label>
				<div class="mup-crewfill__row">
					<select id="mup-r-fillfrom" data-mup-crew-source>
						<option value=""><?php esc_html_e( 'Loading your patrols…', 'marine-unit-plugin' ); ?></option>
					</select>
					<button type="button" class="mup-btn" data-mup-fill-crew><?php esc_html_e( 'Fill', 'marine-unit-plugin' ); ?></button>
				</div>
				<p class="mup-hint">
					<?php esc_html_e( 'Optional shortcut. This report is a standalone record — filling from a patrol only saves typing.', 'marine-unit-plugin' ); ?>
				</p>
			</div>

			<table class="mup-table mup-table--crew">
				<thead>
					<tr>
						<th scope="col" class="mup-col-num"><span class="mup-sr"><?php esc_html_e( 'Row', 'marine-unit-plugin' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Name', 'marine-unit-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ID', 'marine-unit-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Position', 'marine-unit-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody data-mup-crew-body>
					<?php for ( $i = 0; $i < ReportSchema::CREW_ROWS; $i++ ) : ?>
						<tr>
							<td class="mup-col-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?>.</td>
							<td>
								<label class="mup-sr" for="mup-r-crew-<?php echo esc_attr( (string) $i ); ?>-name">
									<?php
									printf(
										/* translators: %d: crew row number. */
										esc_html__( 'Crew %d name', 'marine-unit-plugin' ),
										(int) $i + 1
									);
									?>
								</label>
								<input type="text" id="mup-r-crew-<?php echo esc_attr( (string) $i ); ?>-name"
									name="crew[<?php echo esc_attr( (string) $i ); ?>][name]" maxlength="120"
									list="mup-user-list" data-mup-crew-name
									value="<?php echo 0 === $i ? esc_attr( UserProfile::displayName( $user_id ) ) : ''; ?>" />
							</td>
							<td>
								<label class="mup-sr" for="mup-r-crew-<?php echo esc_attr( (string) $i ); ?>-id">
									<?php
									printf(
										/* translators: %d: crew row number. */
										esc_html__( 'Crew %d ID number', 'marine-unit-plugin' ),
										(int) $i + 1
									);
									?>
								</label>
								<input type="text" id="mup-r-crew-<?php echo esc_attr( (string) $i ); ?>-id"
									name="crew[<?php echo esc_attr( (string) $i ); ?>][id]" maxlength="32" data-mup-crew-id
									value="<?php echo 0 === $i ? esc_attr( UserProfile::officerId( $user_id ) ) : ''; ?>" />
							</td>
							<td>
								<?php if ( 0 === $i ) : ?>
									<span class="mup-fixed"><?php esc_html_e( 'Primary Operator', 'marine-unit-plugin' ); ?></span>
								<?php else : ?>
									<label class="mup-sr" for="mup-r-crew-<?php echo esc_attr( (string) $i ); ?>-pos">
										<?php
										printf(
											/* translators: %d: crew row number. */
											esc_html__( 'Crew %d position', 'marine-unit-plugin' ),
											(int) $i + 1
										);
										?>
									</label>
									<select id="mup-r-crew-<?php echo esc_attr( (string) $i ); ?>-pos"
										name="crew[<?php echo esc_attr( (string) $i ); ?>][position]" data-mup-crew-position>
										<option value=""><?php esc_html_e( '—', 'marine-unit-plugin' ); ?></option>
										<?php foreach ( $positions as $slug => $label ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php endif; ?>
							</td>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>

			<datalist id="mup-user-list" data-mup-user-list></datalist>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionMission( ReportData $data ): string {
		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'IV. Mission Information', 'marine-unit-plugin' ); ?></h3>
			<p class="mup-hint"><?php esc_html_e( 'Check and answer all categories that apply.', 'marine-unit-plugin' ); ?></p>

			<?php echo $this->yesNo( 'pretrip_inspection', __( 'Pre-Trip Inspection completed?', 'marine-unit-plugin' ), $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->yesNo( 'notifications_made', __( 'Proper notifications made?', 'marine-unit-plugin' ), $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-r-supervisor"><?php esc_html_e( 'Unit Supervisor', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-supervisor" name="unit_supervisor" maxlength="120" value="<?php echo esc_attr( $data->getString( 'unit_supervisor' ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-comms"><?php esc_html_e( 'Communications', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-comms" name="communications" maxlength="120" value="<?php echo esc_attr( $data->getString( 'communications' ) ); ?>" />
				</div>
			</div>

			<?php echo $this->yesNo( 'calls_for_service', __( 'Calls for Service?', 'marine-unit-plugin' ), $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<table class="mup-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Event #', 'marine-unit-plugin' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'marine-unit-plugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php for ( $i = 0; $i < ReportSchema::CALL_ROWS; $i++ ) : ?>
						<tr>
							<td>
								<label class="mup-sr" for="mup-r-call-<?php echo esc_attr( (string) $i ); ?>-no">
									<?php
									printf(
										/* translators: %d: row number. */
										esc_html__( 'Call %d event number', 'marine-unit-plugin' ),
										(int) $i + 1
									);
									?>
								</label>
								<input type="text" id="mup-r-call-<?php echo esc_attr( (string) $i ); ?>-no"
									name="calls[<?php echo esc_attr( (string) $i ); ?>][event_no]" maxlength="60" />
							</td>
							<td>
								<label class="mup-sr" for="mup-r-call-<?php echo esc_attr( (string) $i ); ?>-desc">
									<?php
									printf(
										/* translators: %d: row number. */
										esc_html__( 'Call %d description', 'marine-unit-plugin' ),
										(int) $i + 1
									);
									?>
								</label>
								<input type="text" id="mup-r-call-<?php echo esc_attr( (string) $i ); ?>-desc"
									name="calls[<?php echo esc_attr( (string) $i ); ?>][description]" maxlength="255" />
							</td>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>

			<?php echo $this->yesNo( 'vessels_boarded', __( 'Vessels Boarded and/or Inspected?', 'marine-unit-plugin' ), $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="mup-hint"><?php esc_html_e( 'List vessel name and/or registration number.', 'marine-unit-plugin' ); ?></p>

			<div class="mup-form__row">
				<?php for ( $i = 0; $i < ReportSchema::BOARDED_ROWS; $i++ ) : ?>
					<div class="mup-field">
						<label for="mup-r-boarded-<?php echo esc_attr( (string) $i ); ?>">
							<?php
							printf(
								/* translators: %d: row number. */
								esc_html__( 'Vessel %d', 'marine-unit-plugin' ),
								(int) $i + 1
							);
							?>
						</label>
						<input type="text" id="mup-r-boarded-<?php echo esc_attr( (string) $i ); ?>"
							name="boarded[<?php echo esc_attr( (string) $i ); ?>][vessel]" maxlength="180" />
					</div>
				<?php endfor; ?>
			</div>

			<div class="mup-field">
				<label for="mup-r-notes"><?php esc_html_e( 'Notes', 'marine-unit-plugin' ); ?></label>
				<textarea id="mup-r-notes" name="mission_notes" rows="4" maxlength="4000"><?php echo esc_textarea( $data->getString( 'mission_notes' ) ); ?></textarea>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionMaintenance( ReportData $data ): string {
		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'V. Maintenance Information', 'marine-unit-plugin' ); ?></h3>

			<div class="mup-field">
				<label for="mup-r-maint"><?php esc_html_e( 'Maintenance Problems', 'marine-unit-plugin' ); ?></label>
				<textarea id="mup-r-maint" name="maintenance_problems" rows="4" maxlength="4000"><?php echo esc_textarea( $data->getString( 'maintenance_problems' ) ); ?></textarea>
			</div>

			<p class="mup-hint"><?php esc_html_e( 'Attach CCSO Form 310 if completed.', 'marine-unit-plugin' ); ?></p>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionTraining( ReportData $data ): string {
		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'VI. Training', 'marine-unit-plugin' ); ?></h3>

			<fieldset class="mup-fieldset">
				<legend class="mup-sr"><?php esc_html_e( 'Training topics', 'marine-unit-plugin' ); ?></legend>
				<div class="mup-checkgrid">
					<?php foreach ( ReportSchema::trainingTopics() as $slug => $label ) : ?>
						<label class="mup-check">
							<input type="checkbox" name="training[]" value="<?php echo esc_attr( $slug ); ?>" />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="mup-field">
				<label for="mup-r-training-desc"><?php esc_html_e( 'Training description', 'marine-unit-plugin' ); ?></label>
				<textarea id="mup-r-training-desc" name="training_description" rows="3" maxlength="2000"><?php echo esc_textarea( $data->getString( 'training_description' ) ); ?></textarea>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionGar( ReportData $data ): string {
		ob_start();
		?>
		<section class="mup-section mup-gar">
			<h3 class="mup-section__title"><?php esc_html_e( 'GAR Risk Calculation Worksheet', 'marine-unit-plugin' ); ?></h3>
			<p class="mup-hint"><?php echo esc_html( ReportSchema::garIntro() ); ?></p>

			<div class="mup-gar__grid">
				<?php foreach ( ReportSchema::garElements() as $key => $element ) : ?>
					<div class="mup-gar__item">
						<label for="mup-r-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $element['label'] ); ?></label>
						<input
							type="number"
							id="mup-r-<?php echo esc_attr( $key ); ?>"
							name="<?php echo esc_attr( $key ); ?>"
							min="<?php echo esc_attr( (string) GarBand::ELEMENT_MIN ); ?>"
							max="<?php echo esc_attr( (string) GarBand::ELEMENT_MAX ); ?>"
							step="1"
							inputmode="numeric"
							data-mup-gar
						/>
						<details class="mup-gar__help">
							<summary><?php esc_html_e( 'What this covers', 'marine-unit-plugin' ); ?></summary>
							<p><?php echo esc_html( $element['description'] ); ?></p>
						</details>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mup-gar__result" data-mup-gar-result>
				<div class="mup-gar__total">
					<span class="mup-gar__label"><?php esc_html_e( 'Total Risk Score', 'marine-unit-plugin' ); ?></span>
					<output class="mup-gar__value" data-mup-gar-total>0</output>
					<span class="mup-gar__of"><?php esc_html_e( 'of 60', 'marine-unit-plugin' ); ?></span>
				</div>
				<p class="mup-gar__band" data-mup-gar-band>
					<?php echo esc_html( GarBand::Unscored->guidance() ); ?>
				</p>
				<div class="mup-gar__scale" aria-hidden="true">
					<span class="mup-gar__seg mup-gar__seg--green"><?php esc_html_e( 'Green 1–23', 'marine-unit-plugin' ); ?></span>
					<span class="mup-gar__seg mup-gar__seg--amber"><?php esc_html_e( 'Amber 24–44', 'marine-unit-plugin' ); ?></span>
					<span class="mup-gar__seg mup-gar__seg--red"><?php esc_html_e( 'Red 45–60', 'marine-unit-plugin' ); ?></span>
				</div>
			</div>

			<p class="mup-hint"><?php echo esc_html( ReportSchema::garClosing() ); ?></p>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	private function sectionSignature( ReportData $data, int $user_id, string $today ): string {
		ob_start();
		?>
		<section class="mup-section">
			<h3 class="mup-section__title"><?php esc_html_e( 'Report', 'marine-unit-plugin' ); ?></h3>

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-r-by"><?php esc_html_e( 'Report Completed By', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-by" name="completed_by" required maxlength="120"
						value="<?php echo esc_attr( UserProfile::displayName( $user_id ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-by-id"><?php esc_html_e( 'ID #', 'marine-unit-plugin' ); ?></label>
					<input type="text" id="mup-r-by-id" name="completed_by_id" maxlength="32"
						value="<?php echo esc_attr( UserProfile::officerId( $user_id ) ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-r-by-date"><?php esc_html_e( 'Date', 'marine-unit-plugin' ); ?></label>
					<input type="date" id="mup-r-by-date" name="completed_date" required value="<?php echo esc_attr( $today ); ?>" />
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * A Yes/No pair. Rendered as radios rather than two checkboxes so the
	 * answers are genuinely exclusive — the paper form used two checkboxes,
	 * which allowed both to be ticked at once.
	 */
	private function yesNo( string $name, string $label, ReportData $data ): string {
		$value = $data->getBool( $name );

		ob_start();
		?>
		<fieldset class="mup-yesno">
			<legend><?php echo esc_html( $label ); ?></legend>
			<label class="mup-check">
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( true === $value ); ?> />
				<span><?php esc_html_e( 'Yes', 'marine-unit-plugin' ); ?></span>
			</label>
			<label class="mup-check">
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="0" <?php checked( false === $value ); ?> />
				<span><?php esc_html_e( 'No', 'marine-unit-plugin' ); ?></span>
			</label>
		</fieldset>
		<?php

		return (string) ob_get_clean();
	}
}
