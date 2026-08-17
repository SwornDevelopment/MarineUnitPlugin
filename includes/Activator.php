<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

use MarineUnit\Database\Schema;
use MarineUnit\Vessels\VesselRepository;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		Schema::install();
		Roles::install();

		// Seeds are no-ops when data already exists, so re-activating never
		// duplicates the vessel list or resurrects deleted patrol types.
		( new VesselRepository() )->seed();
		PatrolTypes::seed();

		add_option( Settings::OPTION, Settings::defaults() );
		add_option( 'mup_activated_at', current_time( 'mysql', true ), '', false );

		update_option( 'mup_version', VERSION, false );

		flush_rewrite_rules();
	}
}
