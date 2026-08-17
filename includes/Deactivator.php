<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	/**
	 * Deactivation is deliberately almost a no-op.
	 *
	 * Roles, capabilities, tables and settings all survive, because
	 * deactivating a plugin is routinely done to debug an unrelated problem —
	 * stripping every member's access at that moment would be its own outage.
	 * Destructive cleanup belongs in uninstall.php, behind an explicit opt-in.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
