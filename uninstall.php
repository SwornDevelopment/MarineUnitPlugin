<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted, never on deactivation. Data is removed
 * only when the unit has explicitly opted in via the "Delete all data on
 * uninstall" setting — an accidental delete must not destroy the unit's patrol
 * and mission report records.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Autoloader.php';

if ( ! defined( 'MUP_DIR' ) ) {
	define( 'MUP_DIR', plugin_dir_path( __FILE__ ) );
}

\MarineUnit\Autoloader::register();

$settings = get_option( \MarineUnit\Settings::OPTION, [] );
$purge    = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

// Roles and capabilities always go: they are plugin scaffolding, not unit data,
// and leaving orphaned capabilities behind on every role is untidy.
\MarineUnit\Roles::uninstall();

if ( ! $purge ) {
	return;
}

\MarineUnit\Database\Schema::drop();
\MarineUnit\UserProfile::deleteAllMeta();
\MarineUnit\PatrolTypes::deleteOption();
\MarineUnit\Settings::deleteOption();

delete_option( 'mup_db_version' );
delete_option( 'mup_version' );
delete_option( 'mup_activated_at' );

/*
 * Patrols and mission reports are custom post types registered from Phase 2
 * onward. Deleting them here keeps uninstall complete as those land.
 */
$post_types = [ 'marine_patrol', 'marine_report' ];

foreach ( $post_types as $post_type ) {
	$posts = get_posts(
		[
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'suppress_filters' => true,
		]
	);

	foreach ( $posts as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}
