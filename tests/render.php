<?php
/**
 * Render test for the calendar month grid.
 *
 * Executes the real Renderer against WordPress stubs and parses the result as
 * a DOM. Without a WordPress install this is the closest we can get to
 * "the page actually builds": it catches undefined functions, unbalanced
 * markup and off-by-one errors in the grid.
 *
 *     php tests/render.php
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$plugin_dir = dirname( __DIR__ ) . '/';

define( 'MUP_DIR', $plugin_dir );
define( 'MUP_URL', 'https://example.test/wp-content/plugins/marine-unit-plugin/' );
define( 'MUP_BASENAME', 'marine-unit-plugin/marine-unit-plugin.php' );

// --- WordPress stubs -------------------------------------------------------

// wpdb result-format constants.
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );

$GLOBALS['mup_options'] = [
	'start_of_week' => 0,
	'date_format'   => 'F j, Y',
];

function __( string $t, string $d = '' ): string { return $t; }
function esc_html( string $t ): string { return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $t ): string { return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( string $t ): string { return htmlspecialchars( $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( string $t ): string { return $t; }
function esc_html__( string $t, string $d = '' ): string { return esc_html( $t ); }
function esc_attr__( string $t, string $d = '' ): string { return esc_attr( $t ); }
function esc_html_e( string $t, string $d = '' ): void { echo esc_html( $t ); }
function esc_attr_e( string $t, string $d = '' ): void { echo esc_attr( $t ); }
function get_option( string $k, mixed $default = false ): mixed { return $GLOBALS['mup_options'][ $k ] ?? $default; }
function update_option( string $k, mixed $v, mixed $a = null ): bool { $GLOBALS['mup_options'][ $k ] = $v; return true; }
function add_option( string $k, mixed $v, string $x = '', mixed $a = null ): bool { return true; }
function delete_option( string $k ): bool { return true; }
function get_bloginfo( string $k = '' ): string { return 'Marine Unit'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function current_time( string $format, bool $gmt = false ): string { return gmdate( $format ); }
function wp_date( string $format, ?int $stamp = null, $tz = null ): string { return gmdate( $format, $stamp ?? time() ); }
function current_user_can( string $cap, ...$args ): bool { return $GLOBALS['mup_caps'][ $cap ] ?? false; }
function get_current_user_id(): int { return 1; }
function is_user_logged_in(): bool { return true; }
function sanitize_text_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_textarea_field( string $v ): string { return trim( strip_tags( $v ) ); }
function sanitize_key( string $k ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ); }
function selected( mixed $a, mixed $b = true, bool $echo = true ): string {
	$out = (string) $a === (string) $b || ( true === $b && $a ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $out; }
	return $out;
}
function checked( mixed $a, mixed $b = true, bool $echo = true ): string {
	$out = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $out; }
	return $out;
}
function add_query_arg( ...$args ): string { return '#'; }
function wp_login_url( string $r = '' ): string { return '/wp-login.php'; }
function admin_url( string $p = '' ): string { return '/wp-admin/' . $p; }
function get_permalink( mixed $p = null ): string { return 'https://example.test/calendar/'; }
function get_user_meta( int $id, string $key = '', bool $single = false ): mixed { return $GLOBALS['mup_usermeta'][ $key ] ?? ''; }
function get_userdata( int $id ): object { return (object) [ 'ID' => $id, 'display_name' => 'Test Officer' ]; }
function user_can( mixed $user, string $cap, ...$args ): bool { return current_user_can( $cap, ...$args ); }
function home_url(): string { return 'https://example.test/'; }

$GLOBALS['mup_caps'] = [];
$GLOBALS['mup_usermeta'] = [];

/** Minimal locale object for weekday labels. */
class Stub_Locale {
	public function get_weekday( int $i ): string {
		return [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ][ $i ];
	}
	public function get_weekday_abbrev( string $day ): string {
		return substr( $day, 0, 3 );
	}
}

$GLOBALS['wp_locale'] = new Stub_Locale();

/** WP_Query stub: the calendar under test has no patrols. */
class WP_Query {
	public array $posts = [];
	public function __construct( array $args = [] ) {}
}

class WP_Post {}

/** $wpdb stub — unused on an empty month, present so nothing fatals. */
class Stub_Wpdb {
	public string $prefix = 'wp_';
	public function get_charset_collate(): string { return ''; }
	public function get_results( ...$a ): array { return []; }
	public function get_var( ...$a ): mixed { return 0; }
	public function get_col( ...$a ): array { return []; }
	public function prepare( string $q, ...$a ): string { return $q; }
}

$GLOBALS['wpdb'] = new Stub_Wpdb();

require_once $plugin_dir . 'includes/Autoloader.php';

MarineUnit\Autoloader::register();

use MarineUnit\Frontend\Renderer;

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

/** Parse a fragment and return a DOMXPath, failing loudly on broken markup. */
function xpath_for( string $html ): DOMXPath {
	$doc = new DOMDocument();

	libxml_use_internal_errors( true );
	$doc->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>', LIBXML_NOERROR );
	libxml_clear_errors();

	return new DOMXPath( $doc );
}

function count_nodes( DOMXPath $xp, string $query ): int {
	$found = $xp->query( $query );

	return $found instanceof DOMNodeList ? $found->length : 0;
}

$renderer = new Renderer();

// ---------------------------------------------------------------------------
section( 'Month grid renders' );

// August 2026 begins on a Saturday and has 31 days.
$html = $renderer->monthGrid( 2026, 8 );

check( 'produces output', '' !== trim( $html ), true );

$xp = xpath_for( $html );

check( 'renders seven weekday headers', count_nodes( $xp, '//div[@class="mup-grid__weekday"]' ), 7 );
check( 'renders 31 day cells', count_nodes( $xp, '//span[contains(@class,"mup-grid__daynum")]' ), 31 );
check( 'renders six leading blanks for a Saturday start', count_nodes( $xp, '//div[contains(@class,"mup-grid__cell--empty")]' ), 6 );
check( 'exposes a table role for assistive tech', count_nodes( $xp, '//div[@role="table"]' ), 1 );
check( 'includes the stacked mobile list', count_nodes( $xp, '//div[@class="mup-list"]' ), 1 );
check( 'an empty month says so', count_nodes( $xp, '//p[@class="mup-empty"]' ), 1 );

// February 2026 begins on a Sunday: no blanks with a Sunday-start week.
$feb = xpath_for( $renderer->monthGrid( 2026, 2 ) );

check( 'February 2026 renders 28 days', count_nodes( $feb, '//span[contains(@class,"mup-grid__daynum")]' ), 28 );
check( 'February 2026 needs no leading blanks', count_nodes( $feb, '//div[contains(@class,"mup-grid__cell--empty")]' ), 0 );

// A leap February.
$leap = xpath_for( $renderer->monthGrid( 2028, 2 ) );
check( 'February 2028 renders 29 days', count_nodes( $leap, '//span[contains(@class,"mup-grid__daynum")]' ), 29 );

section( 'Respects the site start-of-week setting' );

$GLOBALS['mup_options']['start_of_week'] = 1; // Monday.

$monday = xpath_for( $renderer->monthGrid( 2026, 2 ) );
check( 'a Sunday 1st needs six blanks on a Monday-start week', count_nodes( $monday, '//div[contains(@class,"mup-grid__cell--empty")]' ), 6 );

$GLOBALS['mup_options']['start_of_week'] = 0;

// ---------------------------------------------------------------------------
section( 'Calendar shell' );

$shell = xpath_for( $renderer->calendar( 2026, 8 ) );

check( 'has a month title', count_nodes( $shell, '//*[@data-mup-title]' ), 1 );
check( 'has previous and next controls', count_nodes( $shell, '//button[@data-mup-nav]' ), 3 );
check( 'has a modal dialog', count_nodes( $shell, '//div[@role="dialog"]' ), 1 );
check( 'the dialog is marked modal', count_nodes( $shell, '//div[@aria-modal="true"]' ), 1 );
check( 'has a polite live region', count_nodes( $shell, '//*[@aria-live="polite"][@role="status"]' ), 1 );
check( 'hides the add button without the capability', count_nodes( $shell, '//button[@data-mup-new-patrol]' ), 0 );

$GLOBALS['mup_caps']['marine_create_patrol'] = true;

$operator = xpath_for( $renderer->calendar( 2026, 8 ) );
check( 'shows the add button to an Operator', count_nodes( $operator, '//button[@data-mup-new-patrol]' ), 1 );

// ---------------------------------------------------------------------------
section( 'Patrol form' );

$form = xpath_for( $renderer->patrolForm() );

check( 'has a date field', count_nodes( $form, '//input[@name="date"]' ), 1 );
check( 'has depart and return times', count_nodes( $form, '//input[@type="time"]' ), 2 );
check( 'has a launch point', count_nodes( $form, '//input[@name="launch_point"]' ), 1 );
check( 'has a vessel select', count_nodes( $form, '//select[@name="vessel_id"]' ), 1 );
check( 'has a patrol type select', count_nodes( $form, '//select[@name="patrol_type"]' ), 1 );
check( 'has a max crew field', count_nodes( $form, '//input[@name="max_crew"]' ), 1 );
check( 'offers all nine seeded patrol types', count_nodes( $form, '//select[@name="patrol_type"]/option' ), 9 );
check( 'defaults max crew to 4', $form->query( '//input[@name="max_crew"]' )->item( 0 )->getAttribute( 'value' ), '4' );

// Accessibility: every control must be reachable by a label pointing at a real
// id. Counting labels alone would pass even if one pointed nowhere.
$controls = $form->query( '//input[not(@type="hidden")] | //select | //textarea' );
$orphans  = [];

foreach ( $controls as $control ) {
	$id = $control->getAttribute( 'id' );

	if ( '' === $id || 0 === count_nodes( $form, '//label[@for="' . $id . '"]' ) ) {
		$orphans[] = $control->getAttribute( 'name' ) ?: '(unnamed)';
	}
}

check( 'every visible control has a label bound to its id', $orphans, [] );
check( 'the form has the expected number of controls', $controls->length, 8 );

// ---------------------------------------------------------------------------
section( 'Escaping' );

// A patrol type containing markup must come back escaped, not executed.
$GLOBALS['mup_options']['mup_patrol_types'] = [ 'x' => '<script>alert(1)</script>' ];

$escaped = $renderer->patrolForm();

check( 'markup in stored data is escaped', str_contains( $escaped, '<script>alert(1)</script>' ), false );
check( 'and appears as text instead', str_contains( $escaped, '&lt;script&gt;' ), true );

unset( $GLOBALS['mup_options']['mup_patrol_types'] );

// ---------------------------------------------------------------------------
section( 'Mission report form' );

$GLOBALS['mup_caps']['marine_submit_report'] = true;

$report = xpath_for( ( new MarineUnit\Frontend\ReportRenderer() )->form( 1 ) );

// All six numbered sections from the paper form, plus GAR and the signature
// block.
check( 'renders eight sections', count_nodes( $report, '//section[contains(@class,"mup-section")]' ), 8 );

foreach (
	[
		'I. General Mission Information',
		'II. Operational Weather Conditions',
		'III. Crew Information',
		'IV. Mission Information',
		'V. Maintenance Information',
		'VI. Training',
		'GAR Risk Calculation Worksheet',
	] as $heading
) {
	check(
		"has the section: {$heading}",
		count_nodes( $report, '//h3[normalize-space()="' . $heading . '"]' ),
		1
	);
}

section( 'Report fields match the paper form' );

check( 'ten crew name rows', count_nodes( $report, '//input[@data-mup-crew-name]' ), 10 );
check( 'ten crew ID rows', count_nodes( $report, '//input[@data-mup-crew-id]' ), 10 );
// Row 1 is the fixed Primary Operator and has no position control.
check( 'nine crew position selects', count_nodes( $report, '//select[@data-mup-crew-position]' ), 9 );
check( 'row 1 is labelled Primary Operator', count_nodes( $report, '//span[@class="mup-fixed"]' ), 1 );

check( 'four calls-for-service event numbers', count_nodes( $report, '//input[contains(@name,"[event_no]")]' ), 4 );
check( 'four vessels-boarded rows', count_nodes( $report, '//input[contains(@name,"[vessel]")]' ), 4 );
check( 'nine training checkboxes', count_nodes( $report, '//input[@name="training[]"]' ), 9 );
check( 'nine mission type checkboxes', count_nodes( $report, '//input[@name="mission_type[]"]' ), 9 );
check( 'six GAR score inputs', count_nodes( $report, '//input[@data-mup-gar]' ), 6 );
check( 'three hour meters with start and finish', count_nodes( $report, '//input[@data-mup-meter]' ), 6 );
check( 'three computed meter totals', count_nodes( $report, '//output[@data-mup-meter-total]' ), 3 );

section( 'The reused-field defects are not reproduced' );

// The source form drove both questions from CheckBox14/15, so answering one
// answered the other. Each must now be its own control.
foreach ( [ 'pretrip_inspection', 'notifications_made', 'calls_for_service', 'vessels_boarded' ] as $question ) {
	check(
		"{$question} has its own yes/no pair",
		count_nodes( $report, '//input[@name="' . $question . '"]' ),
		2
	);
}

// Radios rather than two checkboxes, so Yes and No are genuinely exclusive.
check( 'yes/no answers are exclusive radios', count_nodes( $report, '//input[@name="calls_for_service"][@type="radio"]' ), 2 );

// Nine independently named training checkboxes, each with a distinct value.
$values = [];

foreach ( $report->query( '//input[@name="training[]"]' ) as $box ) {
	$values[] = $box->getAttribute( 'value' );
}

check( 'each training topic has a distinct value', count( array_unique( $values ) ), 9 );

section( 'GAR presentation' );

check( 'shows a live total', count_nodes( $report, '//output[@data-mup-gar-total]' ), 1 );
check( 'names the band in text, not only colour', count_nodes( $report, '//*[@data-mup-gar-band]' ), 1 );
check( 'shows the three band ranges', count_nodes( $report, '//span[contains(@class,"mup-gar__seg")]' ), 3 );
check( 'every GAR element has collapsible guidance', count_nodes( $report, '//details[@class="mup-gar__help"]' ), 6 );

section( 'Report accessibility' );

$controls = $report->query( '//input[not(@type="hidden")][not(@type="radio")][not(@type="checkbox")] | //select | //textarea' );
$orphans  = [];

foreach ( $controls as $control ) {
	$id = $control->getAttribute( 'id' );

	if ( '' === $id || 0 === count_nodes( $report, '//label[@for="' . $id . '"]' ) ) {
		$orphans[] = $control->getAttribute( 'name' ) ?: '(unnamed)';
	}
}

check( 'every text control has a label bound to its id', $orphans, [] );
check( 'checkbox and radio groups sit in fieldsets with legends', count_nodes( $report, '//fieldset/legend' ), 6 );

echo "\n" . str_repeat( '-', 48 ) . "\n";
printf( "%d passed, %d failed\n", Results::$passed, Results::$failed );

exit( Results::$failed > 0 ? 1 : 0 );
