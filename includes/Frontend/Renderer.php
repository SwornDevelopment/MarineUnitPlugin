<?php
/**
 * Front-end HTML.
 *
 * Every fragment the calendar shows — the month grid, the patrol modal, the
 * patrol form — is built here and returned to the browser as HTML rather than
 * as JSON the JavaScript templates. That keeps escaping in one place and means
 * the markup has exactly one source of truth.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Frontend;

use MarineUnit\Enum\PatrolStatus;
use MarineUnit\Patrols\Patrol;
use MarineUnit\PatrolTypes;
use MarineUnit\Plugin;
use MarineUnit\Roles;
use MarineUnit\Settings;
use MarineUnit\Signups\Signup;
use MarineUnit\Support\MonthGrid;
use MarineUnit\Support\TimeRange;
use MarineUnit\UserProfile;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Full calendar: header, navigation, month grid and the modal shell.
	 */
	public function calendar( int $year, int $month ): string {
		ob_start();
		?>
		<div class="mup-calendar" data-year="<?php echo esc_attr( (string) $year ); ?>" data-month="<?php echo esc_attr( (string) $month ); ?>">
			<div class="mup-calendar__bar">
				<div class="mup-calendar__nav">
					<button type="button" class="mup-btn mup-btn--icon" data-mup-nav="prev" aria-label="<?php esc_attr_e( 'Previous month', 'marine-unit-plugin' ); ?>">&#8249;</button>
					<h2 class="mup-calendar__title" data-mup-title aria-live="polite"><?php echo esc_html( $this->monthTitle( $year, $month ) ); ?></h2>
					<button type="button" class="mup-btn mup-btn--icon" data-mup-nav="next" aria-label="<?php esc_attr_e( 'Next month', 'marine-unit-plugin' ); ?>">&#8250;</button>
					<button type="button" class="mup-btn mup-btn--quiet" data-mup-nav="today"><?php esc_html_e( 'Today', 'marine-unit-plugin' ); ?></button>
				</div>

				<?php if ( current_user_can( Roles::CREATE_PATROL ) ) : ?>
					<button type="button" class="mup-btn mup-btn--primary" data-mup-new-patrol>
						<?php esc_html_e( '+ Add patrol', 'marine-unit-plugin' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div class="mup-calendar__body" data-mup-grid>
				<?php echo $this->monthGrid( $year, $month ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
			</div>

			<div class="mup-modal" data-mup-modal hidden>
				<div class="mup-modal__backdrop" data-mup-close></div>
				<div class="mup-modal__panel" role="dialog" aria-modal="true" aria-labelledby="mup-modal-title" tabindex="-1">
					<button type="button" class="mup-modal__close" data-mup-close aria-label="<?php esc_attr_e( 'Close', 'marine-unit-plugin' ); ?>">&times;</button>
					<div class="mup-modal__content" data-mup-modal-content></div>
				</div>
			</div>

			<div class="mup-live" role="status" aria-live="polite" data-mup-live></div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The month grid, plus a stacked list used instead of the grid on narrow
	 * screens. Both are rendered and CSS decides which is shown, so navigation
	 * needs no extra round trip and neither view can drift from the other.
	 */
	public function monthGrid( int $year, int $month ): string {
		$first = $this->firstOfMonth( $year, $month );

		if ( ! $first instanceof \DateTimeImmutable ) {
			return '';
		}

		$days_in_month = (int) $first->format( 't' );
		$start_of_week = (int) get_option( 'start_of_week', 0 );
		$lead          = MonthGrid::leadingBlanks( (int) $first->format( 'w' ), $start_of_week );

		$patrols = $this->patrolsByDate(
			$first->format( 'Y-m-d' ),
			$first->modify( 'last day of this month' )->format( 'Y-m-d' )
		);

		$today = current_time( 'Y-m-d' );

		ob_start();
		?>
		<div class="mup-grid" role="table" aria-label="<?php echo esc_attr( $this->monthTitle( $year, $month ) ); ?>">
			<div class="mup-grid__head" role="row">
				<?php foreach ( $this->weekdayLabels( $start_of_week ) as $label ) : ?>
					<div class="mup-grid__weekday" role="columnheader"><?php echo esc_html( $label ); ?></div>
				<?php endforeach; ?>
			</div>
			<div class="mup-grid__body" role="rowgroup">
				<?php for ( $i = 0; $i < $lead; $i++ ) : ?>
					<div class="mup-grid__cell mup-grid__cell--empty" role="cell"></div>
				<?php endfor; ?>

				<?php
				for ( $day = 1; $day <= $days_in_month; $day++ ) :
					$date     = sprintf( '%04d-%02d-%02d', $year, $month, $day );
					$is_today = $date === $today;
					?>
					<div class="mup-grid__cell<?php echo $is_today ? ' is-today' : ''; ?>" role="cell">
						<span class="mup-grid__daynum"<?php echo $is_today ? ' aria-current="date"' : ''; ?>><?php echo esc_html( (string) $day ); ?></span>
						<?php foreach ( $patrols[ $date ] ?? [] as $patrol ) : ?>
							<?php echo $this->patrolChip( $patrol ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
						<?php endforeach; ?>
					</div>
				<?php endfor; ?>
			</div>
		</div>

		<?php echo $this->monthList( $year, $month, $patrols, $today ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Narrow-screen view: only the days that actually have patrols, stacked.
	 * Shrinking a seven-column grid onto a phone makes it unreadable.
	 *
	 * @param array<string, Patrol[]> $patrols
	 */
	private function monthList( int $year, int $month, array $patrols, string $today ): string {
		ob_start();
		?>
		<div class="mup-list">
			<?php if ( ! $patrols ) : ?>
				<p class="mup-empty"><?php esc_html_e( 'No patrols scheduled this month.', 'marine-unit-plugin' ); ?></p>
			<?php endif; ?>

			<?php
			ksort( $patrols );

			foreach ( $patrols as $date => $day_patrols ) :
				$stamp = strtotime( $date );
				?>
				<div class="mup-list__day<?php echo $date === $today ? ' is-today' : ''; ?>">
					<h3 class="mup-list__date">
						<?php echo esc_html( $stamp ? wp_date( 'l j F', $stamp ) : $date ); ?>
					</h3>
					<?php foreach ( $day_patrols as $patrol ) : ?>
						<?php echo $this->patrolChip( $patrol, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped. ?>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The clickable summary of a patrol shown in a day cell.
	 */
	private function patrolChip( Patrol $patrol, bool $verbose = false ): string {
		$crew      = Plugin::instance()->signupService()->crew( $patrol->id, $patrol->maxCrew );
		$confirmed = count( $crew['confirmed'] );
		$cancelled = $patrol->isCancelled();

		$classes = [ 'mup-chip' ];

		if ( $cancelled ) {
			$classes[] = 'mup-chip--cancelled';
		} elseif ( $crew['is_full'] ) {
			$classes[] = 'mup-chip--full';
		}

		ob_start();
		?>
		<button
			type="button"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-mup-patrol="<?php echo esc_attr( (string) $patrol->id ); ?>"
		>
			<span class="mup-chip__time"><?php echo esc_html( $this->timeRangeLabel( $patrol ) ); ?></span>
			<span class="mup-chip__type"><?php echo esc_html( PatrolTypes::label( $patrol->patrolType ) ); ?></span>
			<?php if ( $verbose ) : ?>
				<span class="mup-chip__meta"><?php echo esc_html( $patrol->launchPoint ); ?></span>
			<?php endif; ?>
			<span class="mup-chip__crew">
				<?php
				echo esc_html(
					$cancelled
						? __( 'Cancelled', 'marine-unit-plugin' )
						: sprintf(
							/* translators: 1: confirmed crew, 2: crew limit. */
							__( '%1$d/%2$d crew', 'marine-unit-plugin' ),
							$confirmed,
							$patrol->maxCrew
						)
				);
				?>
			</span>
		</button>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Modal body: full patrol detail, crew list and the action buttons.
	 */
	public function patrolDetail( Patrol $patrol ): string {
		$service   = Plugin::instance()->signupService();
		$crew      = $service->crew( $patrol->id, $patrol->maxCrew );
		$user_id   = get_current_user_id();
		$mine      = Plugin::instance()->signups()->find( $patrol->id, $user_id );
		$vessel    = Plugin::instance()->vessels()->find( $patrol->vesselId );
		$range     = $patrol->range( wp_timezone() );
		$started   = $range instanceof TimeRange && $range->hasStarted();
		$can_edit  = current_user_can( Roles::EDIT_PATROL, $patrol->id );
		$can_crew  = current_user_can( Roles::MANAGE_PATROL_CREW, $patrol->id );
		$over      = count( $crew['confirmed'] ) > $patrol->maxCrew;

		ob_start();
		?>
		<div class="mup-detail" data-mup-detail="<?php echo esc_attr( (string) $patrol->id ); ?>">
			<h2 id="mup-modal-title" class="mup-detail__title">
				<?php echo esc_html( PatrolTypes::label( $patrol->patrolType ) ); ?>
				<?php if ( $patrol->isCancelled() ) : ?>
					<span class="mup-badge mup-badge--cancelled"><?php esc_html_e( 'Cancelled', 'marine-unit-plugin' ); ?></span>
				<?php endif; ?>
			</h2>

			<dl class="mup-detail__facts">
				<div>
					<dt><?php esc_html_e( 'When', 'marine-unit-plugin' ); ?></dt>
					<dd>
						<?php echo esc_html( $this->dateLabel( $patrol->date ) ); ?><br />
						<?php echo esc_html( $this->timeRangeLabel( $patrol ) ); ?>
						<?php if ( $range instanceof TimeRange && $range->crossesMidnight() ) : ?>
							<span class="mup-note"><?php esc_html_e( '(overnight)', 'marine-unit-plugin' ); ?></span>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Launch point', 'marine-unit-plugin' ); ?></dt>
					<dd><?php echo esc_html( $patrol->launchPoint ?: '—' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Vessel', 'marine-unit-plugin' ); ?></dt>
					<dd><?php echo esc_html( $vessel?->label() ?? '—' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Operator', 'marine-unit-plugin' ); ?></dt>
					<dd><?php echo esc_html( UserProfile::displayNameWithId( $patrol->authorId ) ); ?></dd>
				</div>
			</dl>

			<?php if ( '' !== $patrol->notes ) : ?>
				<div class="mup-detail__notes">
					<h3><?php esc_html_e( 'Notes', 'marine-unit-plugin' ); ?></h3>
					<p><?php echo nl2br( esc_html( $patrol->notes ) ); ?></p>
				</div>
			<?php endif; ?>

			<div class="mup-detail__crew">
				<h3>
					<?php
					printf(
						/* translators: 1: confirmed crew, 2: crew limit. */
						esc_html__( 'Crew (%1$d of %2$d)', 'marine-unit-plugin' ),
						count( $crew['confirmed'] ),
						(int) $patrol->maxCrew
					);
					?>
				</h3>

				<?php if ( $over ) : ?>
					<p class="mup-warning">
						<?php esc_html_e( 'This patrol is over its crew limit. Nobody has been removed — the limit only applies to new sign-ups.', 'marine-unit-plugin' ); ?>
					</p>
				<?php endif; ?>

				<?php echo $this->crewList( $crew['confirmed'], $patrol, $can_crew, false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

				<?php if ( $crew['waiting'] ) : ?>
					<h4><?php esc_html_e( 'Waitlist', 'marine-unit-plugin' ); ?></h4>
					<?php echo $this->crewList( $crew['waiting'], $patrol, $can_crew, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>
			</div>

			<div class="mup-detail__actions">
				<?php if ( ! $patrol->isCancelled() && current_user_can( Roles::SIGNUP_PATROL ) ) : ?>
					<?php if ( $mine instanceof Signup ) : ?>
						<?php if ( $started ) : ?>
							<p class="mup-note"><?php esc_html_e( 'This patrol has started. Ask the Operator or a Supervisor to take you off it.', 'marine-unit-plugin' ); ?></p>
						<?php else : ?>
							<button type="button" class="mup-btn mup-btn--danger" data-mup-action="withdraw" data-mup-patrol-id="<?php echo esc_attr( (string) $patrol->id ); ?>">
								<?php esc_html_e( 'Withdraw', 'marine-unit-plugin' ); ?>
							</button>
						<?php endif; ?>
					<?php else : ?>
						<button type="button" class="mup-btn mup-btn--primary" data-mup-action="signup" data-mup-patrol-id="<?php echo esc_attr( (string) $patrol->id ); ?>">
							<?php echo esc_html( $crew['is_full'] ? __( 'Join waitlist', 'marine-unit-plugin' ) : __( 'Sign up', 'marine-unit-plugin' ) ); ?>
						</button>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $can_edit ) : ?>
					<button type="button" class="mup-btn" data-mup-action="edit" data-mup-patrol-id="<?php echo esc_attr( (string) $patrol->id ); ?>">
						<?php esc_html_e( 'Edit', 'marine-unit-plugin' ); ?>
					</button>
					<?php if ( $patrol->isCancelled() ) : ?>
						<button type="button" class="mup-btn" data-mup-action="reinstate" data-mup-patrol-id="<?php echo esc_attr( (string) $patrol->id ); ?>">
							<?php esc_html_e( 'Reinstate', 'marine-unit-plugin' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="mup-btn mup-btn--danger" data-mup-action="cancel" data-mup-patrol-id="<?php echo esc_attr( (string) $patrol->id ); ?>">
							<?php esc_html_e( 'Cancel patrol', 'marine-unit-plugin' ); ?>
						</button>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param Signup[] $signups
	 */
	private function crewList( array $signups, Patrol $patrol, bool $can_manage, bool $waiting ): string {
		if ( ! $signups ) {
			return '<p class="mup-empty">' . esc_html__( 'Nobody signed up yet.', 'marine-unit-plugin' ) . '</p>';
		}

		ob_start();
		?>
		<ul class="mup-crew<?php echo $waiting ? ' mup-crew--waiting' : ''; ?>">
			<?php foreach ( $signups as $index => $signup ) : ?>
				<li class="mup-crew__item">
					<span class="mup-crew__pos"><?php echo esc_html( (string) ( $index + 1 ) ); ?>.</span>
					<span class="mup-crew__name">
						<?php echo esc_html( UserProfile::displayNameWithId( $signup->userId ) ); ?>
						<?php if ( $signup->userId === $patrol->authorId ) : ?>
							<span class="mup-badge"><?php esc_html_e( 'Operator', 'marine-unit-plugin' ); ?></span>
						<?php endif; ?>
					</span>
					<?php if ( $can_manage ) : ?>
						<button
							type="button"
							class="mup-link mup-link--danger"
							data-mup-action="remove"
							data-mup-patrol-id="<?php echo esc_attr( (string) $patrol->id ); ?>"
							data-mup-user-id="<?php echo esc_attr( (string) $signup->userId ); ?>"
						><?php esc_html_e( 'Remove', 'marine-unit-plugin' ); ?></button>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Modal body: the create/edit patrol form.
	 */
	public function patrolForm( ?Patrol $patrol = null ): string {
		$vessels  = Plugin::instance()->vessels()->options( true );
		$types    = PatrolTypes::all();
		$is_edit  = $patrol instanceof Patrol;
		$default  = current_time( 'Y-m-d' );

		// An out-of-service vessel already assigned stays selectable so that
		// editing an old patrol does not silently reassign its boat.
		if ( $is_edit && $patrol->vesselId > 0 && ! isset( $vessels[ $patrol->vesselId ] ) ) {
			$existing = Plugin::instance()->vessels()->find( $patrol->vesselId );

			if ( $existing ) {
				$vessels[ $existing->id ] = sprintf(
					/* translators: %s: vessel name. */
					__( '%s (out of service)', 'marine-unit-plugin' ),
					$existing->label()
				);
			}
		}

		ob_start();
		?>
		<form class="mup-form" data-mup-patrol-form>
			<h2 id="mup-modal-title" class="mup-form__title">
				<?php echo esc_html( $is_edit ? __( 'Edit patrol', 'marine-unit-plugin' ) : __( 'Add a patrol', 'marine-unit-plugin' ) ); ?>
			</h2>

			<input type="hidden" name="patrol_id" value="<?php echo esc_attr( $is_edit ? (string) $patrol->id : '0' ); ?>" />

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-date"><?php esc_html_e( 'Date', 'marine-unit-plugin' ); ?></label>
					<input type="date" id="mup-date" name="date" required value="<?php echo esc_attr( $is_edit ? $patrol->date : $default ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-start"><?php esc_html_e( 'Depart time', 'marine-unit-plugin' ); ?></label>
					<input type="time" id="mup-start" name="start_time" required value="<?php echo esc_attr( $is_edit ? $patrol->startTime : '' ); ?>" />
				</div>
				<div class="mup-field">
					<label for="mup-end"><?php esc_html_e( 'Return time', 'marine-unit-plugin' ); ?></label>
					<input type="time" id="mup-end" name="end_time" required value="<?php echo esc_attr( $is_edit ? $patrol->endTime : '' ); ?>" />
					<p class="mup-hint"><?php esc_html_e( 'An earlier return time means the patrol runs overnight.', 'marine-unit-plugin' ); ?></p>
				</div>
			</div>

			<div class="mup-field">
				<label for="mup-launch"><?php esc_html_e( 'Launch point', 'marine-unit-plugin' ); ?></label>
				<input type="text" id="mup-launch" name="launch_point" required maxlength="180" value="<?php echo esc_attr( $is_edit ? $patrol->launchPoint : '' ); ?>" />
			</div>

			<div class="mup-form__row">
				<div class="mup-field">
					<label for="mup-vessel"><?php esc_html_e( 'Vessel', 'marine-unit-plugin' ); ?></label>
					<select id="mup-vessel" name="vessel_id" required>
						<option value=""><?php esc_html_e( 'Choose a vessel', 'marine-unit-plugin' ); ?></option>
						<?php foreach ( $vessels as $id => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $is_edit && $patrol->vesselId === $id ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mup-field">
					<label for="mup-type"><?php esc_html_e( 'Patrol type', 'marine-unit-plugin' ); ?></label>
					<select id="mup-type" name="patrol_type" required>
						<?php foreach ( $types as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $is_edit && $patrol->patrolType === $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mup-field mup-field--narrow">
					<label for="mup-crew"><?php esc_html_e( 'Max crew', 'marine-unit-plugin' ); ?></label>
					<input type="number" id="mup-crew" name="max_crew" min="1" max="50" required value="<?php echo esc_attr( $is_edit ? (string) $patrol->maxCrew : '4' ); ?>" />
				</div>
			</div>

			<div class="mup-field">
				<label for="mup-notes"><?php esc_html_e( 'Notes', 'marine-unit-plugin' ); ?></label>
				<textarea id="mup-notes" name="notes" rows="3" maxlength="2000"><?php echo esc_textarea( $is_edit ? $patrol->notes : '' ); ?></textarea>
			</div>

			<?php if ( ! $is_edit ) : ?>
				<p class="mup-hint"><?php esc_html_e( 'You will be added to the crew automatically.', 'marine-unit-plugin' ); ?></p>
			<?php endif; ?>

			<div class="mup-form__messages" data-mup-form-messages></div>

			<div class="mup-form__actions">
				<button type="submit" class="mup-btn mup-btn--primary">
					<?php echo esc_html( $is_edit ? __( 'Save patrol', 'marine-unit-plugin' ) : __( 'Create patrol', 'marine-unit-plugin' ) ); ?>
				</button>
				<button type="button" class="mup-btn mup-btn--quiet" data-mup-close><?php esc_html_e( 'Cancel', 'marine-unit-plugin' ); ?></button>
			</div>
		</form>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Past patrols the current user crewed. Powers [marine_my_patrols].
	 */
	public function myPatrols( int $user_id ): string {
		$signups = Plugin::instance()->signups()->forUser( $user_id );
		$ids     = array_map( static fn ( Signup $s ): int => $s->patrolId, $signups );
		$patrols = Plugin::instance()->patrols()->findMany( $ids );
		$now     = time();

		$past = [];

		foreach ( $patrols as $patrol ) {
			$range = $patrol->range( wp_timezone() );

			if ( $range instanceof TimeRange && $range->end <= $now ) {
				$past[] = $patrol;
			}
		}

		usort( $past, static fn ( Patrol $a, Patrol $b ): int => strcmp( $b->date, $a->date ) );

		ob_start();
		?>
		<div class="mup-mypatrols">
			<?php if ( ! $past ) : ?>
				<p class="mup-empty"><?php esc_html_e( 'You have not been out on a patrol yet.', 'marine-unit-plugin' ); ?></p>
			<?php else : ?>
				<table class="mup-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'marine-unit-plugin' ); ?></th>
							<th><?php esc_html_e( 'Time', 'marine-unit-plugin' ); ?></th>
							<th><?php esc_html_e( 'Type', 'marine-unit-plugin' ); ?></th>
							<th><?php esc_html_e( 'Vessel', 'marine-unit-plugin' ); ?></th>
							<th><?php esc_html_e( 'Launch point', 'marine-unit-plugin' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $past as $patrol ) : ?>
							<?php $vessel = Plugin::instance()->vessels()->find( $patrol->vesselId ); ?>
							<tr<?php echo $patrol->isCancelled() ? ' class="is-cancelled"' : ''; ?>>
								<td><?php echo esc_html( $this->dateLabel( $patrol->date ) ); ?></td>
								<td><?php echo esc_html( $this->timeRangeLabel( $patrol ) ); ?></td>
								<td><?php echo esc_html( PatrolTypes::label( $patrol->patrolType ) ); ?></td>
								<td><?php echo esc_html( $vessel?->label() ?? '—' ); ?></td>
								<td><?php echo esc_html( $patrol->launchPoint ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, Patrol[]> Date `Y-m-d` => patrols, each day ordered
	 *                                 by start time.
	 */
	private function patrolsByDate( string $from, string $to ): array {
		$patrols = Plugin::instance()->patrols()->betweenDates( $from, $to );
		$grouped = [];

		foreach ( $patrols as $patrol ) {
			$grouped[ $patrol->date ][] = $patrol;
		}

		foreach ( $grouped as &$day ) {
			usort( $day, static fn ( Patrol $a, Patrol $b ): int => strcmp( $a->startTime, $b->startTime ) );
		}

		unset( $day );

		return $grouped;
	}

	private function firstOfMonth( int $year, int $month ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			sprintf( '%d-%d-1 00:00:00', $year, $month ),
			wp_timezone()
		);

		return $date ?: null;
	}

	public function monthTitle( int $year, int $month ): string {
		$first = $this->firstOfMonth( $year, $month );

		return $first instanceof \DateTimeImmutable
			? wp_date( 'F Y', $first->getTimestamp() )
			: '';
	}

	/**
	 * @return string[]
	 */
	private function weekdayLabels( int $start_of_week ): array {
		global $wp_locale;

		$labels = [];

		for ( $i = 0; $i < 7; $i++ ) {
			$index    = ( $start_of_week + $i ) % 7;
			$labels[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $index ) ) : (string) $index;
		}

		return $labels;
	}

	private function dateLabel( string $date ): string {
		$stamp = strtotime( $date );

		return $stamp ? wp_date( Settings::dateFormat(), $stamp ) : $date;
	}

	private function timeRangeLabel( Patrol $patrol ): string {
		return sprintf(
			'%s – %s',
			$this->timeLabel( $patrol->startTime ),
			$this->timeLabel( $patrol->endTime )
		);
	}

	private function timeLabel( string $time ): string {
		$stamp = strtotime( '2000-01-01 ' . $time );

		return $stamp ? wp_date( Settings::timeFormat(), $stamp ) : $time;
	}
}
