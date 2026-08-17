<?php
/**
 * Custom table definitions.
 *
 * Sign-ups, vessels and the report audit trail live in real tables rather than
 * post meta: waitlist ordering, the sign-up overlap check and the roster are
 * all relational queries that post meta serves badly, and sign-ups are
 * high-churn.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function signupsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'mup_signups';
	}

	public static function vesselsTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'mup_vessels';
	}

	public static function reportAuditTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'mup_report_audit';
	}

	/**
	 * Create or migrate tables. Idempotent — dbDelta only applies differences.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$signups = self::signupsTable();
		$vessels = self::vesselsTable();
		$audit   = self::reportAuditTable();

		/*
		 * The unique key on (patrol_id, user_id) is what makes signing up
		 * idempotent: a double-submitted form cannot create two rows.
		 */
		$sql = [];

		$sql[] = "CREATE TABLE {$signups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			patrol_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			waitlist_position int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY patrol_user (patrol_id,user_id),
			KEY user_id (user_id),
			KEY patrol_status (patrol_id,status,waitlist_position)
		) {$charset};";

		$sql[] = "CREATE TABLE {$vessels} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			registration varchar(191) NOT NULL DEFAULT '',
			capacity smallint(5) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY sort_order (sort_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			report_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			changed_at datetime NOT NULL,
			changes longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY report_id (report_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'mup_db_version', \MarineUnit\DB_VERSION, false );
	}

	/**
	 * Run pending migrations when the plugin is updated in place (i.e. without
	 * the activation hook firing).
	 */
	public static function maybeUpgrade(): void {
		$installed = (int) get_option( 'mup_db_version', 0 );

		if ( $installed === \MarineUnit\DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Drop every table. Uninstall only, and only when the operator has opted
	 * in to data deletion.
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( [ self::signupsTable(), self::vesselsTable(), self::reportAuditTable() ] as $table ) {
			// Table names cannot be parameterised; they are built from
			// $wpdb->prefix and hard-coded suffixes, never from user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
