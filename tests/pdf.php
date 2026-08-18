<?php
/**
 * Tests for PDF generation.
 *
 * Generates a real report PDF and asserts against its structure and extracted
 * content. Without a WordPress install this is what proves the document is
 * valid and that nothing silently fell off a page.
 *
 *     php tests/pdf.php
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$plugin_dir = dirname( __DIR__ ) . '/';

define( 'MUP_DIR', $plugin_dir );

$GLOBALS['mup_options'] = [];

function __( string $t, string $d = '' ): string { return $t; }
function get_option( string $k, mixed $d = false ): mixed { return $GLOBALS['mup_options'][ $k ] ?? $d; }
function update_option( string $k, mixed $v, mixed $a = null ): bool { return true; }
function add_option( string $k, mixed $v, string $x = '', mixed $a = null ): bool { return true; }
function delete_option( string $k ): bool { return true; }
function sanitize_key( string $k ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( string $v ): string { return trim( strip_tags( $v ) ); }

require_once $plugin_dir . 'includes/Autoloader.php';

MarineUnit\Autoloader::register();

use MarineUnit\Pdf\PdfWriter;
use MarineUnit\Pdf\ReportPdf;
use MarineUnit\Reports\ReportData;
use MarineUnit\Reports\ReportSchema;

final class Results {
	public static int $passed = 0;
	public static int $failed = 0;
}

function check( string $label, mixed $actual, mixed $expected ): void {
	if ( $actual === $expected ) {
		++Results::$passed;
		echo "  ok   {$label}\n";

		return;
	}

	++Results::$failed;

	printf(
		"  FAIL %s — expected %s, got %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

function section( string $title ): void {
	echo "\n{$title}\n";
}

/**
 * Pull the readable text out of a generated PDF's content streams.
 *
 * Enough to prove content reached the page without needing a PDF parser.
 */
function pdf_text( string $pdf ): string {
	$text = '';

	if ( preg_match_all( '/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/', $pdf, $matches ) ) {
		foreach ( $matches[1] as $chunk ) {
			$text .= str_replace( [ '\\(', '\\)', '\\\\' ], [ '(', ')', '\\' ], $chunk ) . "\n";
		}
	}

	return $text;
}

/**
 * Split a PDF into per-page content streams, in page order.
 *
 * @return string[]
 */
function pdf_pages( string $pdf ): array {
	$pages = [];

	if ( preg_match_all( '/stream\n(.*?)\nendstream/s', $pdf, $matches ) ) {
		$pages = $matches[1];
	}

	return $pages;
}

// ---------------------------------------------------------------------------
section( 'PdfWriter produces a valid document' );

$writer = new PdfWriter();
$writer->setFont( PdfWriter::FONT_BOLD, 12 );
$writer->text( 40, 60, 'Hello' );
$out = $writer->output();

check( 'starts with a PDF header', str_starts_with( $out, '%PDF-1.4' ), true );
check( 'ends with EOF', str_ends_with( $out, '%%EOF' ), true );
check( 'declares a catalog', str_contains( $out, '/Type /Catalog' ), true );
check( 'declares a page tree', str_contains( $out, '/Type /Pages' ), true );
check( 'has an xref table', str_contains( $out, "\nxref\n" ), true );
check( 'has a trailer with a root', str_contains( $out, '/Root 1 0 R' ), true );
check( 'embeds no font files', str_contains( $out, '/FontFile' ), false );
check( 'uses the standard Helvetica', str_contains( $out, '/BaseFont /Helvetica' ), true );

section( 'Text handling' );

$escaper = new PdfWriter();
$escaper->text( 10, 10, 'Parens (like this) and a backslash \\ here' );
$escaped = $escaper->output();

check( 'escapes opening parens', str_contains( $escaped, '\\(like this\\)' ), true );
check( 'escapes backslashes', str_contains( $escaped, '\\\\' ), true );

$widths = new PdfWriter();
$widths->setFont( PdfWriter::FONT_REGULAR, 10 );

check( 'measures a known string', round( $widths->widthOf( 'iiii' ), 2 ), 8.88 );
check( 'wider glyphs measure wider', $widths->widthOf( 'MMMM' ) > $widths->widthOf( 'iiii' ), true );
check( 'an empty string has no width', $widths->widthOf( '' ), 0.0 );

$lines = $widths->wrap( str_repeat( 'word ', 60 ), 200.0 );
check( 'long text wraps to several lines', count( $lines ) > 1, true );
check( 'no wrapped line exceeds the column', array_filter( $lines, static fn ( $l ) => $widths->widthOf( $l ) > 200.0 ), [] );
check( 'a single over-long word still emits a line', count( $widths->wrap( str_repeat( 'x', 400 ), 50.0 ) ), 1 );
check( 'explicit newlines are honoured', count( $widths->wrap( "one\ntwo\nthree", 500.0 ) ), 3 );

// ---------------------------------------------------------------------------
section( 'Mission report PDF' );

// Mirrors the structure of the unit's completed sample. Personnel names are
// placeholders — real ones never leave the source document.
$data = ReportData::fromArray(
	[
		'mission_date'         => '2026-01-08',
		'depart_time'          => '17:00',
		'return_time'          => '21:00',
		'launch_point'         => 'Smallwood State Park',
		'mission_type'         => [ 'marine_patrol' ],
		'incident_location'    => 'Potomac River, IAO Quantico Marine Base',
		'engine_port_start'    => '3012.2',
		'engine_port_finish'   => '3015.2',
		'engine_stbd_start'    => '1589.9',
		'engine_stbd_finish'   => '1592.9',
		'fuel_usage'           => '.50',
		'oil_usage'            => '.25',
		'weather_conditions'   => 'Clear',
		'air_temp'             => '43',
		'water_temp'           => '43',
		'tide_high'            => '1000',
		'tide_low'             => '0330',
		'wind_conditions'      => '5-10',
		'crew'                 => [
			[ 'name' => 'Officer Alpha', 'id' => '733', 'position' => '' ],
			[ 'name' => 'Officer Bravo', 'id' => '644', 'position' => 'operator' ],
			[ 'name' => 'Officer Charlie', 'id' => '689', 'position' => 'crewman' ],
		],
		'pretrip_inspection'   => true,
		'notifications_made'   => true,
		'unit_supervisor'      => '644',
		'communications'       => 'D1',
		'calls_for_service'    => false,
		'maintenance_problems' => 'Fuel tank access hatch lid is broken behind the console.',
		'training'             => [ 'other' ],
		'training_description' => 'Night navigation and operating at night',
		'gar_supervision'      => '1',
		'gar_planning'         => '2',
		'gar_team_selection'   => '3',
		'gar_team_fitness'     => '1',
		'gar_environment'      => '4',
		'gar_complexity'       => '3',
		'completed_by'         => 'Officer Charlie',
		'completed_by_id'      => '689',
		'completed_date'       => '2026-01-08',
	]
);

$pdf = ReportPdf::render(
	$data,
	[
		'unit_name'    => "Charles County Sheriff's Office Marine Unit",
		'form_number'  => 'CCSO Form 311',
		'vessel'       => 'Zodiac SB2',
		'submitted_at' => 'Submitted 2026-01-08',
	]
);

$pages = pdf_pages( $pdf );
$text  = pdf_text( $pdf );

check( 'the report is three pages', count( $pages ), 3 );
check( 'the document is valid', str_starts_with( $pdf, '%PDF' ) && str_ends_with( $pdf, '%%EOF' ), true );

section( 'Every section reaches the page' );

foreach (
	[
		'I. General Mission Information',
		'II. Operational Weather Conditions',
		'III. Crew Information',
		'IV. Mission Information',
		'V. Maintenance Information',
		'VI. Training',
	] as $heading
) {
	check( "prints: {$heading}", str_contains( $text, $heading ), true );
}

section( 'Report values reach the page' );

foreach (
	[
		'Smallwood State Park',
		'Zodiac SB2',
		'Potomac River, IAO Quantico Marine Base',
		'Officer Alpha',
		'Officer Charlie',
		'Clear',
		'CCSO Form 311',
		"Charles County Sheriff's Office Marine Unit",
	] as $value
) {
	check( "prints the value: {$value}", str_contains( $text, $value ), true );
}

check( 'row 1 is labelled Primary Operator', str_contains( $text, 'Primary Operator' ), true );
check( 'computed engine hours are printed', str_contains( $text, 'Total 3' ), true );
check( 'an unread meter prints a dash, not zero', str_contains( $text, 'Start -    Finish -' ), true );

section( 'GAR worksheet' );

check( 'the total is printed', str_contains( $text, '14 / 60' ), true );
check( 'the band is named, not only coloured', str_contains( $text, 'GREEN' ), true );
check( 'the page-1 risk score matches the worksheet', str_contains( $text, '14 (Green)' ), true );

// The full guidance text must survive: an earlier version silently drew the
// closing paragraphs past the page edge, where they vanished.
foreach (
	[
		'GREEN ZONE',
		'AMBER ZONE',
		'RED ZONE',
		'not the most important part of risk assessment',
		'Fatigue normally becomes a factor after 18 hours',
	] as $probe
) {
	check( "guidance survives to the page: {$probe}", str_contains( $text, $probe ), true );
}

foreach ( ReportSchema::garElements() as $element ) {
	check(
		'prints element: ' . $element['label'],
		str_contains( $text, strtoupper( $element['label'] ) ),
		true
	);
}

section( 'Overflow is paginated, never dropped' );

// A report stuffed with long text must grow pages rather than lose content.
$long = ReportData::fromArray(
	array_merge(
		[
			'mission_date'         => '2026-01-08',
			'completed_by'         => 'Officer Charlie',
			'completed_date'       => '2026-01-08',
			'mission_notes'        => str_repeat( 'Extended narrative of the patrol. ', 300 ),
			'maintenance_problems' => str_repeat( 'Maintenance detail. ', 300 ),
			'training_description' => str_repeat( 'Training detail. ', 200 ),
		],
		array_fill_keys( array_keys( ReportSchema::garElements() ), '10' )
	)
);

$long_pdf   = ReportPdf::render( $long, [ 'unit_name' => 'Unit', 'form_number' => 'F1' ] );
$long_pages = pdf_pages( $long_pdf );
$long_text  = pdf_text( $long_pdf );

check( 'a long report grows beyond three pages', count( $long_pages ) > 3, true );
check( 'the maximum GAR score still bands red', str_contains( $long_text, 'RED' ), true );
check( 'the total still prints', str_contains( $long_text, '60 / 60' ), true );
check( 'the closing guidance still survives', str_contains( $long_text, 'not the most important part of risk assessment' ), true );
check( 'the signature block still prints', str_contains( $long_text, 'Officer Charlie' ), true );

section( 'Unanswered questions are not printed as answered' );

$blank = ReportData::fromArray(
	[
		'mission_date'   => '2026-01-08',
		'completed_by'   => 'Nobody',
		'completed_date' => '2026-01-08',
	]
);

$blank_text = pdf_text( ReportPdf::render( $blank, [ 'unit_name' => 'Unit' ] ) );

check( 'an unanswered yes/no is marked as such', str_contains( $blank_text, 'not answered' ), true );
check( 'an unscored worksheet shows no total', str_contains( $blank_text, '0 / 60' ), true );

echo "\n" . str_repeat( '-', 48 ) . "\n";
printf( "%d passed, %d failed\n", Results::$passed, Results::$failed );

exit( Results::$failed > 0 ? 1 : 0 );
