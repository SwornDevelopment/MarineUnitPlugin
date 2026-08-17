<?php
/**
 * Plugin Name:       Marine Unit
 * Plugin URI:        https://github.com/SwornDevelopment/MarineUnitPlugin
 * Description:       Patrol calendar with crew sign-ups and waitlisting, plus a digital Marine Unit Mission Report.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Sworn Development
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marine-unit-plugin
 * Domain Path:       /languages
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

/**
 * Database schema version. Bump whenever Database\Schema changes so that
 * upgrades run dbDelta on existing installs.
 */
const DB_VERSION = 1;

define( 'MUP_FILE', __FILE__ );
define( 'MUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MUP_URL', plugin_dir_url( __FILE__ ) );
define( 'MUP_BASENAME', plugin_basename( __FILE__ ) );

require_once MUP_DIR . 'includes/Autoloader.php';

Autoloader::register();

register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivator::class, 'deactivate' ] );

/**
 * Boot the plugin.
 *
 * Deliberately hooked to `plugins_loaded` rather than run inline so that other
 * plugins and the theme have registered whatever they need first.
 */
add_action( 'plugins_loaded', static function (): void {
	Plugin::instance()->boot();
} );
