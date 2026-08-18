<?php
/**
 * Tests for the mission report's computed values and validation.
 *
 *     php tests/report.php
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$plugin_dir = dirname( __DIR__ ) . '/';

define( 'MUP_DIR', $plugin_dir );

$GLOBALS['mup_options'] = [];

function __( string $t, string $d = '' ): string { return $t; }
function get_option( string $k, mixed $default = false ): mixed { return $GLOBALS['mup_options'][ $k ] ?? $default; }
function update_option( string $k, mixed $v, mixed $a = null ): bool { $GLOBALS['mup_options'][ $k ] = $v; return true; }
function add_option( string $k, mixed $v, string $x = '', mixed $a = null ): bool { return true; }
function delete_option( string $k ): bool { return true; }
function sanitize_key( string $k ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( string $v ): string { return trim( strip_tags( $v ) ); }

require_once $plugin_dir . 'includes/Enum/GarBand.php';
require_once $plugin_dir . 'includes/PatrolTypes.php';
require_once $plugin_dir . 'includes/Reports/ReportSchema.php';
require_once $plugin_dir . 'includes/Reports/ReportData.php';
require_once $plugin_dir . 'includes/Reports/ReportSanitizer.php';

use MarineUnit\Enum\GarBand;
use MarineUnit\Reports\ReportData;
use MarineUnit\Reports\ReportSanitizer;
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

/** A submission that passes validation, so tests can vary one thing at a time. */
function valid_input( array $overrides = [] ): array {
	return array_merge(
		[
			'mission_date'   => '2026-01-08',
			'depart_time'    => '17:00',
			'return_time'    => '21:00',
			'launch_point'   => 'Smallwood State Park',
			'mission_type'   => [ 'marine_patrol' ],
			'vessel_id'      => 2,
			'completed_by'   => 'Reporting Officer',
			'completed_date' => '2026-01-08',
			'crew'           => [ [ 'name' => 'Primary Operator' ] ],
		],
		$overrides
	);
}

// ---------------------------------------------------------------------------
section( 'Meter totals' );

// The sample report's starboard engine: 1589.9 to 1592.9 is exactly 3 hours.
$meters = ReportData::fromArray(
	[
		'engine_stbd_start'  => '1589.9',
		'engine_stbd_finish' => '1592.9',
	]
);

check( 'starboard total is exactly 3.0', $meters->meterTotal( 'engine_stbd' ), 3.0 );

// Meter readings are decimals, and some pairs do not subtract cleanly in
// binary floating point: 1000.3 - 1000.0 is 0.29999999999995 raw. Rounding the
// total is what stops a reading like that being stored and printed as
// 0.29999999999995 hours.
check( 'raw subtraction really can drift', ( 1000.3 - 1000.0 ) === 0.3, false );
check(
	'the stored total is rounded clean',
	ReportData::fromArray( [ 'generator_start' => '1000.0', 'generator_finish' => '1000.3' ] )->meterTotal( 'generator' ),
	0.3
);

$port = ReportData::fromArray(
	[
		'engine_port_start'  => '3012.20',
		'engine_port_finish' => '3015.20',
	]
);

check( 'port total is exactly 3.0', $port->meterTotal( 'engine_port' ), 3.0 );

check(
	'a missing finish reading gives no total',
	ReportData::fromArray( [ 'generator_start' => '100' ] )->meterTotal( 'generator' ),
	null
);
check(
	'a missing start reading gives no total',
	ReportData::fromArray( [ 'generator_finish' => '100' ] )->meterTotal( 'generator' ),
	null
);
check(
	'both blank gives no total',
	ReportData::fromArray( [] )->meterTotal( 'generator' ),
	null
);
check(
	'a finish below the start gives no total rather than a negative',
	ReportData::fromArray( [ 'generator_start' => '100', 'generator_finish' => '90' ] )->meterTotal( 'generator' ),
	null
);
check(
	'identical readings give zero hours',
	ReportData::fromArray( [ 'generator_start' => '100', 'generator_finish' => '100' ] )->meterTotal( 'generator' ),
	0.0
);

// A blank meter is not a zero reading.
check( 'a blank meter reads as null', ReportData::fromArray( [ 'generator_start' => '' ] )->meter( 'generator_start' ), null );
check( 'a zero meter reads as zero', ReportData::fromArray( [ 'generator_start' => '0' ] )->meter( 'generator_start' ), 0.0 );

// ---------------------------------------------------------------------------
section( 'GAR scoring' );

$gar = ReportData::fromArray(
	[
		'gar_supervision'    => '1',
		'gar_planning'       => '2',
		'gar_team_selection' => '3',
		'gar_team_fitness'   => '1',
		'gar_environment'    => '4',
		'gar_complexity'     => '3',
	]
);

// These are the sample report's actual scores: 1+2+3+1+4+3 = 14, green.
check( 'totals the six elements', $gar->garTotal(), 14 );
check( 'bands the total', $gar->garBand(), GarBand::Green );
check( 'reports a complete worksheet', $gar->garComplete(), true );

check( 'an empty worksheet totals zero', ReportData::fromArray( [] )->garTotal(), 0 );
check( 'an empty worksheet is unscored, not green', ReportData::fromArray( [] )->garBand(), GarBand::Unscored );
check( 'an empty worksheet is incomplete', ReportData::fromArray( [] )->garComplete(), false );

$partial = ReportData::fromArray( [ 'gar_supervision' => '5' ] );
check( 'a partial worksheet still totals', $partial->garTotal(), 5 );
check( 'a partial worksheet is incomplete', $partial->garComplete(), false );

$maxed = ReportData::fromArray( array_fill_keys( array_keys( ReportSchema::garElements() ), '10' ) );
check( 'all sixes at maximum total 60', $maxed->garTotal(), 60 );
check( 'a maximum worksheet is red', $maxed->garBand(), GarBand::Red );

// Out-of-range values are clamped, not trusted.
check( 'a score above 10 is clamped', ReportData::fromArray( [ 'gar_planning' => '99' ] )->garScore( 'gar_planning' ), 10 );
check( 'a negative score is clamped to 0', ReportData::fromArray( [ 'gar_planning' => '-4' ] )->garScore( 'gar_planning' ), 0 );
check( 'a non-numeric score is unscored', ReportData::fromArray( [ 'gar_planning' => 'high' ] )->garScore( 'gar_planning' ), null );

// ---------------------------------------------------------------------------
section( 'Computed fields are always recalculated' );

// A caller claiming a total must not be believed.
$lying = ReportData::fromArray(
	[
		'engine_port_start'  => '100',
		'engine_port_finish' => '105',
		'engine_port_total'  => '9999',
		'gar_supervision'    => '2',
		'gar_total'          => '9999',
		'gar_risk_score'     => '9999',
	]
);

$stored = $lying->toArray();

check( 'a submitted meter total is overwritten', $stored['engine_port_total'], 5.0 );
check( 'a submitted GAR total is overwritten', $stored['gar_total'], 2 );
check( 'the page-1 risk score mirrors the worksheet total', $stored['gar_risk_score'], $stored['gar_total'] );
check( 'the band is stored alongside', $stored['gar_band'], 'green' );
check( 'the schema version is stamped', $stored['_schema_version'], ReportData::SCHEMA_VERSION );
check( 'an uncomputable total stores as blank', ReportData::fromArray( [] )->toArray()['generator_total'], '' );

// ---------------------------------------------------------------------------
section( 'Row collections' );

$rows = ReportData::fromArray(
	[
		'crew'    => [
			[ 'name' => 'Cpl Reporting', 'id' => '689', 'position' => 'operator' ],
			[ 'name' => '', 'id' => '', 'position' => '' ],
			[ 'name' => 'Sgt Second', 'id' => '644', 'position' => 'crewman' ],
		],
		'calls'   => [
			[ 'event_no' => 'E1', 'description' => 'Disabled vessel' ],
			[ 'event_no' => '', 'description' => '' ],
		],
		'boarded' => [ [ 'vessel' => 'MD 1234 AB' ], [ 'vessel' => '' ] ],
	]
);

check( 'blank crew rows are dropped', count( $rows->crew() ), 2 );
check( 'crew row 1 is the Primary Operator regardless of submitted position', $rows->crew()[0]['position'], 'primary_operator' );
check( 'later crew rows keep their position', $rows->crew()[1]['position'], 'crewman' );
check( 'crew IDs are kept', $rows->crew()[1]['id'], '644' );
check( 'blank call rows are dropped', count( $rows->calls() ), 1 );
check( 'blank boarded rows are dropped', count( $rows->boardedVessels() ), 1 );
check( 'a call with only a description is kept', count( ReportData::fromArray( [ 'calls' => [ [ 'description' => 'Assist' ] ] ] )->calls() ), 1 );

// ---------------------------------------------------------------------------
section( 'Sanitising a submission' );

$sanitizer = new ReportSanitizer();
$data      = $sanitizer->sanitize( valid_input() );

check( 'a complete submission validates', $sanitizer->isValid(), true );
check( 'no errors are reported', $sanitizer->errors(), [] );
check( 'the mission date survives', $data->getString( 'mission_date' ), '2026-01-08' );

section( 'Required fields' );

foreach (
	[
		'mission_date'   => 'the mission date',
		'completed_by'   => 'who completed it',
		'completed_date' => 'the completion date',
	] as $field => $label
) {
	$s = new ReportSanitizer();
	$s->sanitize( valid_input( [ $field => '' ] ) );

	check( "a missing {$label} is rejected", $s->isValid(), false );
}

$s = new ReportSanitizer();
$s->sanitize( valid_input( [ 'mission_type' => [] ] ) );
check( 'no mission type is rejected', $s->isValid(), false );

$s = new ReportSanitizer();
$s->sanitize( valid_input( [ 'vessel_id' => 0 ] ) );
check( 'no vessel is rejected', $s->isValid(), false );

$s = new ReportSanitizer();
$s->sanitize( valid_input( [ 'crew' => [] ] ) );
check( 'no crew is rejected', $s->isValid(), false );

$s = new ReportSanitizer();
$s->sanitize( valid_input( [ 'crew' => [ [ 'name' => '   ' ] ] ] ) );
check( 'a whitespace-only crew name does not count', $s->isValid(), false );

section( 'Meter validation' );

$s = new ReportSanitizer();
$s->sanitize( valid_input( [ 'engine_port_start' => '3015', 'engine_port_finish' => '3012' ] ) );
check( 'a finish below the start is rejected', $s->isValid(), false );
check( 'and names the meter', str_contains( $s->errors()[0], 'Engine Hours Port' ), true );

$s = new ReportSanitizer();
$s->sanitize( valid_input( [ 'engine_port_start' => '3012' ] ) );
check( 'a start with no finish is allowed', $s->isValid(), true );

section( 'Field coercion' );

$s = new ReportSanitizer();
$d = $s->sanitize(
	valid_input(
		[
			'depart_time'      => '1700',
			'return_time'      => '9:30',
			'engine_port_start' => '.50',
			'gar_planning'     => '99',
			'mission_type'     => [ 'marine_patrol', 'not_a_real_type' ],
			'training'         => [ 'mob', 'fabricated' ],
			'pretrip_inspection' => '1',
			'calls_for_service' => '0',
		]
	)
);

check( 'a four-digit paper-form time is normalised', $d->getString( 'depart_time' ), '17:00' );
check( 'a single-digit hour is padded', $d->getString( 'return_time' ), '09:30' );
check( 'a leading-dot decimal is accepted', $d->getString( 'engine_port_start' ), '0.5' );
check( 'an out-of-range GAR score is clamped on input', $d->getString( 'gar_planning' ), '10' );
check( 'an unknown mission type is discarded', $d->getList( 'mission_type' ), [ 'marine_patrol' ] );
check( 'an unknown training topic is discarded', $d->getList( 'training' ), [ 'mob' ] );
check( 'yes is recorded as true', $d->getBool( 'pretrip_inspection' ), true );
check( 'no is recorded as false', $d->getBool( 'calls_for_service' ), false );
check( 'an unanswered question stays null, not false', $d->getBool( 'vessels_boarded' ), null );

$s = new ReportSanitizer();
$d = $s->sanitize( valid_input( [ 'depart_time' => '25:00', 'return_time' => 'lunchtime' ] ) );
check( 'an impossible hour is discarded', $d->getString( 'depart_time' ), '' );
check( 'junk in a time field is discarded', $d->getString( 'return_time' ), '' );

$s = new ReportSanitizer();
$d = $s->sanitize( valid_input( [ 'mission_date' => '2026-02-31' ] ) );
check( 'an impossible calendar date is rejected', $d->getString( 'mission_date' ), '' );

section( 'Fixed row counts' );

$s = new ReportSanitizer();
$d = $s->sanitize( valid_input() );

check( 'always ten crew rows are stored', count( $d->getRows( 'crew' ) ), ReportSchema::CREW_ROWS );
check( 'always four call rows are stored', count( $d->getRows( 'calls' ) ), ReportSchema::CALL_ROWS );
check( 'always four boarded rows are stored', count( $d->getRows( 'boarded' ) ), ReportSchema::BOARDED_ROWS );

// Extra rows beyond the form's fixed count are ignored.
$s = new ReportSanitizer();
$d = $s->sanitize( valid_input( [ 'crew' => array_fill( 0, 25, [ 'name' => 'Extra' ] ) ] ) );
check( 'submitting extra crew rows does not grow the table', count( $d->getRows( 'crew' ) ), ReportSchema::CREW_ROWS );

section( 'Schema integrity' );

check( 'six GAR elements', count( ReportSchema::garElements() ), 6 );
check( 'nine training topics', count( ReportSchema::trainingTopics() ), 9 );
check( 'four crew positions', count( ReportSchema::positions() ), 4 );
check( 'three hour meters', count( ReportSchema::meters() ), 3 );
check( 'four independent yes/no questions', count( ReportSchema::boolFields() ), 4 );
check( 'every GAR element carries guidance text', array_filter( ReportSchema::garElements(), static fn ( $e ) => '' === $e['description'] ), [] );

echo "\n" . str_repeat( '-', 48 ) . "\n";
printf( "%d passed, %d failed\n", Results::$passed, Results::$failed );

exit( Results::$failed > 0 ? 1 : 0 );
