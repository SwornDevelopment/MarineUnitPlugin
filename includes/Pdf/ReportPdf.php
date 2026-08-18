<?php
/**
 * Lays the Mission Report out as a PDF, following the unit's paper form.
 *
 * Deliberately free of WordPress calls: everything it needs arrives in the
 * context array. That keeps the layout testable by generating a PDF and
 * reading it back, without a WordPress install.
 *
 * Three pages, matching the source form:
 *   1. Sections I–III  (general, vessel/engine, weather, crew)
 *   2. Sections IV–VI  (mission, maintenance, training, signature)
 *   3. GAR Risk Calculation Worksheet
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Pdf;

use MarineUnit\Enum\GarBand;
use MarineUnit\Reports\ReportData;
use MarineUnit\Reports\ReportSchema;

defined( 'ABSPATH' ) || exit;

final class ReportPdf {

	private const MARGIN = 36.0;

	private PdfWriter $pdf;

	private float $y = self::MARGIN;

	private float $right;

	/** @var array<string, mixed> */
	private array $context;

	private ReportData $data;

	/**
	 * @param array<string, mixed> $context unit_name, form_number, vessel,
	 *                                      author, report_id, submitted_at.
	 */
	public function __construct( ReportData $data, array $context = [] ) {
		$this->data    = $data;
		$this->context = $context;
		$this->pdf     = new PdfWriter();
		$this->right   = $this->pdf->pageWidth() - self::MARGIN;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function render( ReportData $data, array $context = [] ): string {
		return ( new self( $data, $context ) )->build();
	}

	public function build(): string {
		$this->pageOne();
		$this->newPage();
		$this->pageTwo();
		$this->newPage();
		$this->garWorksheet();
		$this->footer();

		return $this->pdf->output();
	}

	/* ---------------------------------------------------------------- Page 1 */

	private function pageOne(): void {
		$this->title();

		$this->sectionHeader( __( 'I. General Mission Information', 'marine-unit-plugin' ) );

		$col = ( $this->right - self::MARGIN ) / 3;

		$this->fieldRow(
			[
				[ __( 'Mission Date', 'marine-unit-plugin' ), $this->dateValue( 'mission_date' ), $col ],
				[ __( 'Depart Time', 'marine-unit-plugin' ), $this->data->getString( 'depart_time' ), $col ],
				[ __( 'Return Time', 'marine-unit-plugin' ), $this->data->getString( 'return_time' ), $col ],
			]
		);

		$this->fieldRow(
			[
				[ __( 'Launch Point', 'marine-unit-plugin' ), $this->data->getString( 'launch_point' ), $col * 2 ],
				[ __( 'GAR Risk Score', 'marine-unit-plugin' ), $this->garScoreLabel(), $col ],
			]
		);

		// Mission types, three per row as on the paper form.
		$this->label( __( 'Mission Type', 'marine-unit-plugin' ) );
		$this->y += 12;

		$chosen  = $this->data->getList( 'mission_type' );
		$types   = ReportSchema::missionTypes();
		$percol  = ( $this->right - self::MARGIN ) / 3;
		$index   = 0;

		foreach ( $types as $slug => $label ) {
			$x = self::MARGIN + ( $index % 3 ) * $percol;

			$this->checkbox( $x, $this->y, in_array( $slug, $chosen, true ), $label );

			++$index;

			if ( 0 === $index % 3 ) {
				$this->y += 13;
			}
		}

		if ( 0 !== $index % 3 ) {
			$this->y += 13;
		}

		$other = $this->data->getString( 'mission_type_other' );

		if ( '' !== $other ) {
			$this->fieldRow( [ [ __( 'Other', 'marine-unit-plugin' ), $other, $this->right - self::MARGIN ] ] );
		}

		$this->y += 4;
		$this->wrappedField(
			__( 'Incident Location or Area/s of Operation', 'marine-unit-plugin' ),
			$this->data->getString( 'incident_location' )
		);

		// Vessel and hour meters.
		$this->y += 6;
		$this->fieldRow(
			[
				[ __( 'Vessel Launched', 'marine-unit-plugin' ), (string) ( $this->context['vessel'] ?? '' ), $col * 1.5 ],
				[ __( 'Fuel Usage', 'marine-unit-plugin' ), $this->data->getString( 'fuel_usage' ), $col * 0.75 ],
				[ __( 'Oil Usage', 'marine-unit-plugin' ), $this->data->getString( 'oil_usage' ), $col * 0.75 ],
			]
		);

		$this->meterTable();

		$this->sectionHeader( __( 'II. Operational Weather Conditions', 'marine-unit-plugin' ) );

		$w = ( $this->right - self::MARGIN ) / 6;

		$this->fieldRow(
			[
				[ __( 'Weather', 'marine-unit-plugin' ), $this->data->getString( 'weather_conditions' ), $w * 1.4 ],
				[ __( 'Air Temp', 'marine-unit-plugin' ), $this->data->getString( 'air_temp' ), $w * 0.9 ],
				[ __( 'Water Temp', 'marine-unit-plugin' ), $this->data->getString( 'water_temp' ), $w * 0.9 ],
				[ __( 'Tide H', 'marine-unit-plugin' ), $this->data->getString( 'tide_high' ), $w * 0.9 ],
				[ __( 'Tide L', 'marine-unit-plugin' ), $this->data->getString( 'tide_low' ), $w * 0.9 ],
				[ __( 'Wind', 'marine-unit-plugin' ), $this->data->getString( 'wind_conditions' ), $w * 1.0 ],
			]
		);

		$this->sectionHeader( __( 'III. Crew Information', 'marine-unit-plugin' ) );
		$this->crewTable();
	}

	private function meterTable(): void {
		$this->y += 6;

		$meters = ReportSchema::meters();
		$width  = ( $this->right - self::MARGIN ) / count( $meters );
		$top    = $this->y;

		$x = self::MARGIN;

		foreach ( $meters as $key => $label ) {
			$this->pdf->setFont( PdfWriter::FONT_BOLD, 7.5 );
			$this->pdf->setFillColor( 60, 68, 82 );
			$this->pdf->text( $x + 4, $top + 9, strtoupper( $label ) );

			$total = $this->data->meterTotal( $key );

			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 8.5 );
			$this->pdf->setFillColor( 20, 24, 32 );

			$this->pdf->text(
				$x + 4,
				$top + 22,
				sprintf(
					/* translators: 1: start reading, 2: finish reading. */
					__( 'Start %1$s    Finish %2$s', 'marine-unit-plugin' ),
					$this->blank( $this->data->getString( $key . '_start' ) ),
					$this->blank( $this->data->getString( $key . '_finish' ) )
				)
			);

			$this->pdf->setFont( PdfWriter::FONT_BOLD, 8.5 );
			$this->pdf->text(
				$x + 4,
				$top + 33,
				sprintf(
					/* translators: %s: computed hours. */
					__( 'Total %s', 'marine-unit-plugin' ),
					null === $total ? '—' : (string) $total
				)
			);

			$this->pdf->setDrawColor( 190, 196, 206 );
			$this->pdf->setLineWidth( 0.5 );
			$this->pdf->rect( $x, $top, $width, 40, 'S' );

			$x += $width;
		}

		$this->y = $top + 46;
	}

	private function crewTable(): void {
		$this->y += 4;

		$positions = ReportSchema::positions();
		$crew      = $this->data->getRows( 'crew' );

		$col_num  = 20.0;
		$col_name = ( $this->right - self::MARGIN ) * 0.5;
		$col_id   = ( $this->right - self::MARGIN ) * 0.18;

		$x_num  = self::MARGIN;
		$x_name = $x_num + $col_num;
		$x_id   = $x_name + $col_name;
		$x_pos  = $x_id + $col_id;

		$this->pdf->setFont( PdfWriter::FONT_BOLD, 7.5 );
		$this->pdf->setFillColor( 60, 68, 82 );
		$this->pdf->text( $x_name, $this->y + 8, strtoupper( __( 'Name', 'marine-unit-plugin' ) ) );
		$this->pdf->text( $x_id, $this->y + 8, strtoupper( __( 'ID', 'marine-unit-plugin' ) ) );
		$this->pdf->text( $x_pos, $this->y + 8, strtoupper( __( 'Position', 'marine-unit-plugin' ) ) );

		$this->y += 12;
		$this->rule();

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9 );
		$this->pdf->setFillColor( 20, 24, 32 );

		for ( $i = 0; $i < ReportSchema::CREW_ROWS; $i++ ) {
			$this->ensureSpace( 18 );

			$row  = is_array( $crew[ $i ] ?? null ) ? $crew[ $i ] : [];
			$name = (string) ( $row['name'] ?? '' );
			$id   = (string) ( $row['id'] ?? '' );
			$pos  = (string) ( $row['position'] ?? '' );

			$this->y += 13;

			$this->pdf->setFillColor( 120, 128, 140 );
			$this->pdf->text( $x_num, $this->y, ( $i + 1 ) . '.' );
			$this->pdf->setFillColor( 20, 24, 32 );

			$this->pdf->text( $x_name, $this->y, $name );
			$this->pdf->text( $x_id, $this->y, $id );

			// Row 1 is the Primary Operator on the paper form: a fixed label,
			// not a chosen position.
			$this->pdf->text(
				$x_pos,
				$this->y,
				0 === $i
					? __( 'Primary Operator', 'marine-unit-plugin' )
					: ( $positions[ $pos ] ?? '' )
			);

			$this->pdf->setDrawColor( 226, 230, 236 );
			$this->pdf->setLineWidth( 0.4 );
			$this->pdf->line( self::MARGIN, $this->y + 3, $this->right, $this->y + 3 );
		}

		$this->y += 10;
	}

	/* ---------------------------------------------------------------- Page 2 */

	private function pageTwo(): void {
		$this->sectionHeader( __( 'IV. Mission Information', 'marine-unit-plugin' ) );

		$this->yesNo( __( 'Pre-Trip Inspection completed?', 'marine-unit-plugin' ), $this->data->getBool( 'pretrip_inspection' ) );
		$this->yesNo( __( 'Proper notifications made?', 'marine-unit-plugin' ), $this->data->getBool( 'notifications_made' ) );

		$col = ( $this->right - self::MARGIN ) / 2;

		$this->fieldRow(
			[
				[ __( 'Unit Supervisor', 'marine-unit-plugin' ), $this->data->getString( 'unit_supervisor' ), $col ],
				[ __( 'Communications', 'marine-unit-plugin' ), $this->data->getString( 'communications' ), $col ],
			]
		);

		$this->yesNo( __( 'Calls for Service?', 'marine-unit-plugin' ), $this->data->getBool( 'calls_for_service' ) );

		$calls = $this->data->calls();

		if ( $calls ) {
			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9 );

			foreach ( $calls as $call ) {
				$this->y += 12;
				$this->pdf->setFillColor( 20, 24, 32 );
				$this->pdf->text( self::MARGIN + 10, $this->y, sprintf( '%s   %s', $call['event_no'], $call['description'] ) );
			}

			$this->y += 8;
		}

		$this->yesNo( __( 'Vessels Boarded and/or Inspected?', 'marine-unit-plugin' ), $this->data->getBool( 'vessels_boarded' ) );

		$boarded = $this->data->boardedVessels();

		if ( $boarded ) {
			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9 );

			foreach ( $boarded as $i => $vessel ) {
				$this->y += 12;
				$this->pdf->setFillColor( 20, 24, 32 );
				$this->pdf->text( self::MARGIN + 10, $this->y, sprintf( '%d. %s', $i + 1, $vessel ) );
			}

			$this->y += 8;
		}

		$this->wrappedField( __( 'Notes', 'marine-unit-plugin' ), $this->data->getString( 'mission_notes' ) );

		$this->sectionHeader( __( 'V. Maintenance Information', 'marine-unit-plugin' ) );
		$this->wrappedField( __( 'Maintenance Problems', 'marine-unit-plugin' ), $this->data->getString( 'maintenance_problems' ) );

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 7.5 );
		$this->pdf->setFillColor( 120, 128, 140 );
		$this->y += 4;
		$this->pdf->text( self::MARGIN, $this->y, __( 'Attach CCSO Form 310 if completed.', 'marine-unit-plugin' ) );
		$this->y += 10;

		$this->sectionHeader( __( 'VI. Training', 'marine-unit-plugin' ) );

		$done   = $this->data->getList( 'training' );
		$topics = ReportSchema::trainingTopics();
		$percol = ( $this->right - self::MARGIN ) / 3;
		$index  = 0;

		$this->y += 4;

		foreach ( $topics as $slug => $label ) {
			$x = self::MARGIN + ( $index % 3 ) * $percol;

			$this->checkbox( $x, $this->y, in_array( $slug, $done, true ), $label );

			++$index;

			if ( 0 === $index % 3 ) {
				$this->y += 13;
			}
		}

		if ( 0 !== $index % 3 ) {
			$this->y += 13;
		}

		$this->wrappedField( __( 'Training description', 'marine-unit-plugin' ), $this->data->getString( 'training_description' ) );

		// Signature block sits near the foot of the page as on the form, but
		// only if the content above left room; otherwise it flows normally.
		$pinned = $this->pdf->pageHeight() - 110;

		$this->y = $this->y + 20 < $pinned ? $pinned : $this->y + 20;

		$this->ensureSpace( 60 );
		$this->rule();
		$this->y += 6;

		$this->fieldRow(
			[
				[ __( 'Report Completed By', 'marine-unit-plugin' ), $this->data->getString( 'completed_by' ), $col ],
				[ __( 'ID #', 'marine-unit-plugin' ), $this->data->getString( 'completed_by_id' ), $col / 2 ],
				[ __( 'Date', 'marine-unit-plugin' ), $this->dateValue( 'completed_date' ), $col / 2 ],
			]
		);
	}

	/* ---------------------------------------------------------------- Page 3 */

	private function garWorksheet(): void {
		$this->pdf->setFont( PdfWriter::FONT_BOLD, 12 );
		$this->pdf->setFillColor( 20, 24, 32 );
		$this->pdf->textCenter( $this->pdf->pageWidth() / 2, $this->y + 12, __( 'Risk Calculation Worksheet — GAR Model', 'marine-unit-plugin' ) );
		$this->y += 26;

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 8 );
		$this->pdf->setFillColor( 60, 68, 82 );
		$this->y = $this->pdf->textWrapped( self::MARGIN, $this->y, $this->right - self::MARGIN, ReportSchema::garIntro() );
		$this->y += 8;

		// Each element: name, score, then the worksheet's own guidance text.
		foreach ( ReportSchema::garElements() as $key => $element ) {
			$score = $this->data->garScore( $key );

			// Measure the guidance text so an element never splits across a
			// page break part-way through its own description.
			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 7.2 );
			$lines = count( $this->pdf->wrap( $element['description'], $this->right - self::MARGIN - 40 ) );

			$this->ensureSpace( 25 + ( $lines * 8.6 ) );

			$this->pdf->setFont( PdfWriter::FONT_BOLD, 9 );
			$this->pdf->setFillColor( 20, 24, 32 );
			$this->pdf->text( self::MARGIN, $this->y + 9, strtoupper( $element['label'] ) );

			$this->pdf->setFont( PdfWriter::FONT_BOLD, 11 );
			$this->pdf->textRight( $this->right - 4, $this->y + 9, null === $score ? '—' : (string) $score );

			$this->pdf->setDrawColor( 190, 196, 206 );
			$this->pdf->setLineWidth( 0.5 );
			$this->pdf->rect( $this->right - 30, $this->y, 30, 14, 'S' );

			$this->y += 18;

			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 7.2 );
			$this->pdf->setFillColor( 96, 104, 116 );
			$this->y = $this->pdf->textWrapped( self::MARGIN, $this->y, $this->right - self::MARGIN - 40, $element['description'], 8.6 );
			$this->y += 7;
		}

		// Total and band.
		$total = $this->data->garTotal();
		$band  = $this->data->garBand();

		$this->y += 4;
		$this->ensureSpace( 130 );
		$this->rule();
		$this->y += 14;

		$this->pdf->setFont( PdfWriter::FONT_BOLD, 11 );
		$this->pdf->setFillColor( 20, 24, 32 );
		$this->pdf->text( self::MARGIN, $this->y, __( 'Total Risk Score', 'marine-unit-plugin' ) );

		$this->pdf->setFont( PdfWriter::FONT_BOLD, 16 );
		$this->pdf->textRight( $this->right - 4, $this->y + 3, sprintf( '%d / 60', $total ) );

		$this->y += 12;

		// Band, named as well as coloured — the colour is never the only cue.
		$rgb = $this->bandColor( $band );

		$this->pdf->setFillColor( $rgb[0], $rgb[1], $rgb[2] );
		$this->pdf->rect( self::MARGIN, $this->y, 110, 16, 'F' );

		$this->pdf->setFont( PdfWriter::FONT_BOLD, 9 );
		$this->pdf->setFillColor( 255, 255, 255 );
		$this->pdf->text( self::MARGIN + 6, $this->y + 11, strtoupper( $band->label() ) );

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 8.5 );
		$this->pdf->setFillColor( 40, 46, 58 );
		$this->pdf->text( self::MARGIN + 120, $this->y + 11, $band->guidance() );

		$this->y += 26;

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 7.5 );
		$this->pdf->setFillColor( 96, 104, 116 );
		$this->y = $this->pdf->textWrapped(
			self::MARGIN,
			$this->y,
			$this->right - self::MARGIN,
			__( 'GREEN ZONE (1-23): risk is rated as low. AMBER ZONE (24-44): risk is moderate and you should consider adopting procedures to minimize the risk. RED ZONE (45-60): implement measures to reduce the risk prior to starting the event or evolution.', 'marine-unit-plugin' ),
			9.5
		);

		$this->y += 6;
		$this->y = $this->pdf->textWrapped( self::MARGIN, $this->y, $this->right - self::MARGIN, ReportSchema::garClosing(), 9.5 );
	}

	/**
	 * @return array{0: int, 1: int, 2: int}
	 */
	private function bandColor( GarBand $band ): array {
		return match ( $band ) {
			GarBand::Green    => [ 21, 128, 61 ],
			GarBand::Amber    => [ 180, 83, 9 ],
			GarBand::Red      => [ 185, 28, 28 ],
			GarBand::Unscored => [ 107, 114, 128 ],
		};
	}

	/**
	 * Distance from the page top at which content must stop, leaving room for
	 * the footer.
	 */
	private function contentBottom(): float {
		return $this->pdf->pageHeight() - 40.0;
	}

	/**
	 * Start a new page when the next block will not fit.
	 *
	 * Without this, anything past the page edge is still emitted but drawn off
	 * the media box — it silently disappears rather than failing, which is the
	 * worst way for a record to lose content.
	 */
	private function ensureSpace( float $needed ): void {
		if ( $this->y + $needed <= $this->contentBottom() ) {
			return;
		}

		$this->newPage();
	}

	private function newPage(): void {
		$this->footer();
		$this->pdf->addPage();
		$this->y = self::MARGIN;
	}

	/* --------------------------------------------------------------- Helpers */

	private function title(): void {
		$this->pdf->setFont( PdfWriter::FONT_BOLD, 13 );
		$this->pdf->setFillColor( 20, 24, 32 );
		$this->pdf->textCenter(
			$this->pdf->pageWidth() / 2,
			$this->y + 12,
			(string) ( $this->context['unit_name'] ?? '' )
		);

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9.5 );
		$this->pdf->setFillColor( 90, 98, 112 );
		$this->pdf->textCenter(
			$this->pdf->pageWidth() / 2,
			$this->y + 26,
			__( 'Mission Report', 'marine-unit-plugin' )
		);

		$this->y += 34;
		$this->pdf->setDrawColor( 30, 58, 95 );
		$this->pdf->setLineWidth( 1.6 );
		$this->pdf->line( self::MARGIN, $this->y, $this->right, $this->y );
		$this->y += 14;
	}

	private function sectionHeader( string $title ): void {
		$this->ensureSpace( 50 );
		$this->y += 6;

		$this->pdf->setFillColor( 30, 58, 95 );
		$this->pdf->rect( self::MARGIN, $this->y, $this->right - self::MARGIN, 15, 'F' );

		$this->pdf->setFont( PdfWriter::FONT_BOLD, 9 );
		$this->pdf->setFillColor( 255, 255, 255 );
		$this->pdf->text( self::MARGIN + 6, $this->y + 11, $title );

		$this->y += 22;
	}

	/**
	 * A row of label/value pairs.
	 *
	 * @param array<int, array{0: string, 1: string, 2: float}> $fields
	 */
	private function fieldRow( array $fields ): void {
		$this->ensureSpace( 32 );

		$x   = self::MARGIN;
		$top = $this->y;

		foreach ( $fields as [ $label, $value, $width ] ) {
			$this->pdf->setFont( PdfWriter::FONT_BOLD, 6.8 );
			$this->pdf->setFillColor( 110, 118, 132 );
			$this->pdf->text( $x, $top + 7, strtoupper( $label ) );

			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9.5 );
			$this->pdf->setFillColor( 20, 24, 32 );

			// Truncate rather than overflow into the neighbouring column.
			$this->pdf->text( $x, $top + 19, $this->fit( $this->blank( $value ), $width - 8 ) );

			$this->pdf->setDrawColor( 205, 211, 220 );
			$this->pdf->setLineWidth( 0.5 );
			$this->pdf->line( $x, $top + 22, $x + $width - 8, $top + 22 );

			$x += $width;
		}

		$this->y = $top + 30;
	}

	private function wrappedField( string $label, string $value ): void {
		$text = '' === trim( $value ) ? '-' : $value;

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9 );
		$lines = count( $this->pdf->wrap( $text, $this->right - self::MARGIN ) );

		// Room for the label plus at least the first two lines, so a heading
		// never sits alone at the foot of a page.
		$this->ensureSpace( 24 + ( min( $lines, 2 ) * 11.5 ) );

		$this->y += 10;
		$this->label( $label );
		$this->y += 12;

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9 );
		$this->pdf->setFillColor( 20, 24, 32 );

		$this->y = $this->pdf->textWrapped( self::MARGIN, $this->y, $this->right - self::MARGIN, $text, 11.5 );
		$this->y += 4;
	}

	private function label( string $text ): void {
		$this->pdf->setFont( PdfWriter::FONT_BOLD, 6.8 );
		$this->pdf->setFillColor( 110, 118, 132 );
		$this->pdf->text( self::MARGIN, $this->y, strtoupper( $text ) );
	}

	private function checkbox( float $x, float $y, bool $checked, string $label ): void {
		$this->pdf->setDrawColor( 120, 128, 140 );
		$this->pdf->setLineWidth( 0.6 );
		$this->pdf->rect( $x, $y - 7, 8, 8, 'S' );

		if ( $checked ) {
			$this->pdf->setFillColor( 30, 58, 95 );
			$this->pdf->rect( $x + 2, $y - 5, 4, 4, 'F' );
		}

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 8.5 );
		$this->pdf->setFillColor( 20, 24, 32 );
		$this->pdf->text( $x + 12, $y, $label );
	}

	/**
	 * A Yes/No question. An unanswered question prints as unanswered rather
	 * than silently as "No".
	 */
	private function yesNo( string $question, ?bool $answer ): void {
		$this->ensureSpace( 22 );
		$this->y += 13;

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 9 );
		$this->pdf->setFillColor( 20, 24, 32 );
		$this->pdf->text( self::MARGIN, $this->y, $question );

		$x = $this->right - 130;

		$this->checkbox( $x, $this->y, true === $answer, __( 'Yes', 'marine-unit-plugin' ) );
		$this->checkbox( $x + 50, $this->y, false === $answer, __( 'No', 'marine-unit-plugin' ) );

		if ( null === $answer ) {
			$this->pdf->setFont( PdfWriter::FONT_REGULAR, 7 );
			$this->pdf->setFillColor( 150, 156, 166 );
			$this->pdf->text( $x + 96, $this->y, __( 'not answered', 'marine-unit-plugin' ) );
		}

		$this->y += 4;
	}

	private function rule(): void {
		$this->pdf->setDrawColor( 180, 187, 198 );
		$this->pdf->setLineWidth( 0.6 );
		$this->pdf->line( self::MARGIN, $this->y, $this->right, $this->y );
	}

	private function footer(): void {
		$y = $this->pdf->pageHeight() - 26;

		$this->pdf->setFont( PdfWriter::FONT_REGULAR, 7 );
		$this->pdf->setFillColor( 140, 147, 158 );

		$form = (string) ( $this->context['form_number'] ?? '' );

		if ( '' !== $form ) {
			$this->pdf->text( self::MARGIN, $y, $form );
		}

		$stamp = (string) ( $this->context['submitted_at'] ?? '' );

		if ( '' !== $stamp ) {
			$this->pdf->textRight( $this->right, $y, $stamp );
		}
	}

	/**
	 * Trim a value to fit its column, with an ellipsis, so a long entry never
	 * runs into the next field.
	 */
	private function fit( string $text, float $width ): string {
		if ( $this->pdf->widthOf( $text ) <= $width ) {
			return $text;
		}

		while ( '' !== $text && $this->pdf->widthOf( $text . '...' ) > $width ) {
			$text = substr( $text, 0, -1 );
		}

		return $text . '...';
	}

	private function blank( string $value ): string {
		return '' === trim( $value ) ? '—' : $value;
	}

	private function dateValue( string $key ): string {
		$raw = $this->data->getString( $key );

		if ( '' === $raw ) {
			return '';
		}

		$formatted = $this->context[ $key . '_formatted' ] ?? null;

		return is_string( $formatted ) && '' !== $formatted ? $formatted : $raw;
	}

	private function garScoreLabel(): string {
		$total = $this->data->garTotal();

		if ( 0 === $total ) {
			return '';
		}

		return sprintf( '%d (%s)', $total, $this->data->garBand()->label() );
	}
}
