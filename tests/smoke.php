<?php
/**
 * Standalone smoke test for the plugin's pure logic.
 *
 * Runs without WordPress or PHPUnit — it stubs the handful of WordPress
 * functions the pure classes touch. Intended for quick verification in
 * environments where no WordPress install is available:
 *
 *     php tests/smoke.php
 *
 * Exits non-zero on failure, so it drops straight into CI.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$plugin_dir = dirname( __DIR__ ) . '/';

define( 'MUP_DIR', $plugin_dir );

// --- Minimal WordPress stubs ----------------------------------------------

$GLOBALS['mup_test_options'] = [];

function __( string $text, string $domain = '' ): string {
	return $text;
}

function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['mup_test_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
	$GLOBALS['mup_test_options'][ $key ] = $value;

	return true;
}

function add_option( string $key, mixed $value, string $deprecated = '', mixed $autoload = null ): bool {
	if ( array_key_exists( $key, $GLOBALS['mup_test_options'] ) ) {
		return false;
	}

	$GLOBALS['mup_test_options'][ $key ] = $value;

	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['mup_test_options'][ $key ] );

	return true;
}

require_once $plugin_dir . 'includes/Enum/GarBand.php';
require_once $plugin_dir . 'includes/Enum/VesselStatus.php';
require_once $plugin_dir . 'includes/Enum/SignupStatus.php';
require_once $plugin_dir . 'includes/PatrolTypes.php';

use MarineUnit\Enum\GarBand;
use MarineUnit\Enum\SignupStatus;
use MarineUnit\Enum\VesselStatus;
use MarineUnit\PatrolTypes;

// --- Tiny assertion harness ------------------------------------------------

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

// --- GAR risk banding ------------------------------------------------------
// Thresholds come from the unit's GAR Risk Calculation Worksheet:
// GREEN 1-23, AMBER 24-44, RED 45-60. A total of 0 means nothing was scored.

section( 'GAR risk banding' );
check( 'total 0 is unscored', GarBand::fromTotal( 0 ), GarBand::Unscored );
check( 'a negative total is unscored', GarBand::fromTotal( -5 ), GarBand::Unscored );
check( 'total 1 is green', GarBand::fromTotal( 1 ), GarBand::Green );
check( 'total 23 is green (upper edge)', GarBand::fromTotal( 23 ), GarBand::Green );
check( 'total 24 is amber (lower edge)', GarBand::fromTotal( 24 ), GarBand::Amber );
check( 'total 44 is amber (upper edge)', GarBand::fromTotal( 44 ), GarBand::Amber );
check( 'total 45 is red (lower edge)', GarBand::fromTotal( 45 ), GarBand::Red );
check( 'total 60 is red (maximum)', GarBand::fromTotal( 60 ), GarBand::Red );
check( 'six elements at max sum to 60', GarBand::ELEMENT_MAX * GarBand::ELEMENT_COUNT, 60 );
check( 'red carries the worksheet descriptor', GarBand::Red->descriptor(), 'High Risk' );
check( 'unscored has no descriptor', GarBand::Unscored->descriptor(), '' );

// --- Vessel status ---------------------------------------------------------

section( 'Vessel status' );
check( 'active vessels are bookable', VesselStatus::Active->isBookable(), true );
check( 'out-of-service vessels are not bookable', VesselStatus::OutOfService->isBookable(), false );
check( 'an unknown stored value falls back to active', VesselStatus::tryFromMixed( 'nonsense' ), VesselStatus::Active );
check( 'null falls back to active', VesselStatus::tryFromMixed( null ), VesselStatus::Active );
check( 'options map covers every case', count( VesselStatus::options() ), 2 );

// --- Sign-up status --------------------------------------------------------

section( 'Sign-up status' );
check( 'confirmed occupies a crew slot', SignupStatus::Confirmed->occupiesSlot(), true );
check( 'waitlisted does not occupy a crew slot', SignupStatus::Waitlisted->occupiesSlot(), false );
check( 'an unknown stored value falls back to confirmed', SignupStatus::tryFromMixed( 'nope' ), SignupStatus::Confirmed );

// --- Patrol types ----------------------------------------------------------

section( 'Patrol types' );
check( 'falls back to the seed list when unset', count( PatrolTypes::all() ), 9 );
check( 'seed order matches the paper form', array_key_first( PatrolTypes::all() ), 'le_response' );
check( 'a known slug resolves to its label', PatrolTypes::label( 'marine_patrol' ), 'Marine Patrol' );
check( 'a removed slug still renders on old records', PatrolTypes::label( 'retired_type' ), 'retired_type' );

PatrolTypes::seed();
check( 'seed() writes the option', count( (array) get_option( 'mup_patrol_types' ) ), 9 );

$slug = PatrolTypes::add( 'Night Navigation' );
check( 'add() derives a slug from the label', $slug, 'nightnavigation' );
check( 'the added type exists', PatrolTypes::exists( 'nightnavigation' ), true );

$duplicate = PatrolTypes::add( 'Night Navigation' );
check( 'a duplicate label gets a unique slug', $duplicate, 'nightnavigation_2' );
check( 'both types coexist', count( PatrolTypes::all() ), 11 );

PatrolTypes::remove( 'nightnavigation_2' );
check( 'remove() drops only that type', PatrolTypes::exists( 'nightnavigation_2' ), false );
check( 'the rest of the list survives', count( PatrolTypes::all() ), 10 );

check( 'an empty label is rejected', PatrolTypes::add( '   ' ), '' );
check( 'the rejected add changed nothing', count( PatrolTypes::all() ), 10 );

// Removing every type must stick, rather than the seed list reappearing.
foreach ( array_keys( PatrolTypes::all() ) as $existing ) {
	PatrolTypes::remove( $existing );
}

check( 'an emptied list stays empty', count( PatrolTypes::all() ), 0 );
check( 'an emptied list does not resurrect the seeds', PatrolTypes::exists( 'le_response' ), false );

// --- Summary ---------------------------------------------------------------

echo "\n" . str_repeat( '-', 48 ) . "\n";
printf( "%d passed, %d failed\n", Results::$passed, Results::$failed );

exit( Results::$failed > 0 ? 1 : 0 );
