<?php
/**
 * Plugin container. Wires the modules up on `plugins_loaded`.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

use MarineUnit\Admin\AdminMenu;
use MarineUnit\Database\Schema;
use MarineUnit\Patrols\ConflictChecker;
use MarineUnit\Patrols\PatrolRepository;
use MarineUnit\Signups\SignupRepository;
use MarineUnit\Signups\SignupService;
use MarineUnit\Vessels\VesselRepository;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	private ?VesselRepository $vessels = null;

	private ?PatrolRepository $patrols = null;

	private ?SignupRepository $signups = null;

	private ?ConflictChecker $conflicts = null;

	private ?SignupService $signupService = null;

	private function __construct() {}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function vessels(): VesselRepository {
		return $this->vessels ??= new VesselRepository();
	}

	public function patrols(): PatrolRepository {
		return $this->patrols ??= new PatrolRepository();
	}

	public function signups(): SignupRepository {
		return $this->signups ??= new SignupRepository();
	}

	public function conflicts(): ConflictChecker {
		return $this->conflicts ??= new ConflictChecker( $this->patrols(), $this->signups() );
	}

	public function signupService(): SignupService {
		return $this->signupService ??= new SignupService(
			$this->patrols(),
			$this->signups(),
			$this->conflicts()
		);
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'marine-unit-plugin', false, dirname( MUP_BASENAME ) . '/languages' );

		// Post types must be registered on `init` — registering earlier trips
		// WordPress's own _doing_it_wrong notice.
		add_action( 'init', [ PatrolRepository::class, 'registerPostType' ] );

		// Deleting a patrol must not strand its crew rows.
		add_action(
			'before_delete_post',
			function ( int $post_id, \WP_Post $post ): void {
				if ( PatrolRepository::POST_TYPE === $post->post_type ) {
					$this->signupService()->onPatrolDeleted( $post_id );
				}
			},
			10,
			2
		);

		// Picks up schema changes when the plugin is updated in place, which
		// does not fire the activation hook.
		add_action( 'admin_init', [ $this, 'maybeUpgrade' ] );

		add_filter( 'map_meta_cap', [ $this, 'mapMetaCap' ], 10, 4 );

		( new UserProfile() )->register();

		if ( is_admin() ) {
			( new AdminMenu() )->register();
		}
	}

	public function maybeUpgrade(): void {
		Schema::maybeUpgrade();

		if ( get_option( 'mup_version' ) !== VERSION ) {
			// Capabilities may have changed between releases.
			Roles::install();
			update_option( 'mup_version', VERSION, false );
		}
	}

	/**
	 * @param string[] $caps
	 * @param string   $cap
	 * @param int      $user_id
	 * @param array    $args
	 *
	 * @return string[]
	 */
	public function mapMetaCap( array $caps, string $cap, int $user_id, array $args ): array {
		return Roles::mapMetaCap( $caps, $cap, $user_id, $args );
	}
}
