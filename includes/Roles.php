<?php
/**
 * Roles and capabilities.
 *
 * Three roles are registered on activation: Crewmate, Operator and Supervisor.
 * If a role already exists its capabilities are updated rather than the role
 * being recreated, so a site that already had e.g. an "Operator" role keeps its
 * users and its other capabilities.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const CREWMATE   = 'marine_crewmate';
	public const OPERATOR   = 'marine_operator';
	public const SUPERVISOR = 'marine_supervisor';

	// Primitive capabilities.
	public const VIEW_CALENDAR    = 'marine_view_calendar';
	public const SIGNUP_PATROL    = 'marine_signup_patrol';
	public const CREATE_PATROL    = 'marine_create_patrol';
	public const EDIT_OWN_PATROL  = 'marine_edit_own_patrol';
	public const EDIT_ANY_PATROL  = 'marine_edit_any_patrol';
	public const MANAGE_SIGNUPS   = 'marine_manage_signups';
	public const SUBMIT_REPORT    = 'marine_submit_report';
	public const VIEW_OWN_REPORTS = 'marine_view_own_reports';
	public const VIEW_ALL_REPORTS = 'marine_view_all_reports';
	public const EDIT_ANY_REPORT  = 'marine_edit_any_report';
	public const MANAGE_VESSELS   = 'marine_manage_vessels';
	public const MANAGE_SETTINGS  = 'marine_manage_settings';
	public const VIEW_ROSTER      = 'marine_view_roster';

	/*
	 * Meta capabilities. These are never assigned to a role — they are resolved
	 * per-object by map_meta_cap(). Always check these (with the object id)
	 * rather than the primitives when an object is involved:
	 *
	 *     current_user_can( Roles::EDIT_PATROL, $patrol_id )
	 */
	public const EDIT_PATROL       = 'marine_edit_patrol';
	public const CANCEL_PATROL     = 'marine_cancel_patrol';
	public const MANAGE_PATROL_CREW = 'marine_manage_patrol_crew';
	public const VIEW_REPORT       = 'marine_view_report';
	public const EDIT_REPORT       = 'marine_edit_report';

	/**
	 * @return array<string, string[]> role slug => primitive capabilities.
	 */
	public static function definitions(): array {
		$crewmate = [
			self::VIEW_CALENDAR,
			self::SIGNUP_PATROL,
			self::SUBMIT_REPORT,
			self::VIEW_OWN_REPORTS,
		];

		$operator = array_merge(
			$crewmate,
			[
				self::CREATE_PATROL,
				self::EDIT_OWN_PATROL,
				// Granted broadly, then narrowed to their own patrols by
				// map_meta_cap() via MANAGE_PATROL_CREW.
				self::MANAGE_SIGNUPS,
			]
		);

		$supervisor = array_merge(
			$operator,
			[
				self::EDIT_ANY_PATROL,
				self::VIEW_ALL_REPORTS,
				self::EDIT_ANY_REPORT,
				self::MANAGE_VESSELS,
				self::MANAGE_SETTINGS,
				self::VIEW_ROSTER,
			]
		);

		return [
			self::CREWMATE   => $crewmate,
			self::OPERATOR   => $operator,
			self::SUPERVISOR => $supervisor,
		];
	}

	/**
	 * Every primitive capability the plugin defines.
	 *
	 * @return string[]
	 */
	public static function allCapabilities(): array {
		$caps = [];

		foreach ( self::definitions() as $role_caps ) {
			$caps = array_merge( $caps, $role_caps );
		}

		return array_values( array_unique( $caps ) );
	}

	public static function displayNames(): array {
		return [
			self::CREWMATE   => __( 'Crewmate', 'marine-unit-plugin' ),
			self::OPERATOR   => __( 'Operator', 'marine-unit-plugin' ),
			self::SUPERVISOR => __( 'Supervisor', 'marine-unit-plugin' ),
		];
	}

	/**
	 * Create or update the plugin roles and grant every capability to
	 * Administrators. Safe to run repeatedly.
	 */
	public static function install(): void {
		$names = self::displayNames();

		foreach ( self::definitions() as $slug => $caps ) {
			$granted = array_fill_keys( $caps, true );
			$role    = get_role( $slug );

			if ( $role instanceof \WP_Role ) {
				// Role pre-exists (ours from a previous version, or the site's).
				// Remove only capabilities we own, then re-add the current set,
				// leaving any third-party capabilities on the role untouched.
				foreach ( self::allCapabilities() as $cap ) {
					$role->remove_cap( $cap );
				}

				foreach ( array_keys( $granted ) as $cap ) {
					$role->add_cap( $cap );
				}

				continue;
			}

			add_role( $slug, $names[ $slug ], $granted );
		}

		self::grantToAdministrators();
	}

	private static function grantToAdministrators(): void {
		$admin = get_role( 'administrator' );

		if ( ! $admin instanceof \WP_Role ) {
			return;
		}

		foreach ( self::allCapabilities() as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Remove roles and capabilities. Called on uninstall only — never on
	 * deactivation, which would silently strip access from every member.
	 */
	public static function uninstall(): void {
		foreach ( array_keys( self::definitions() ) as $slug ) {
			remove_role( $slug );
		}

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::allCapabilities() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Resolve meta capabilities against a specific object.
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User being checked.
	 * @param array    $args    $args[0] is the object id, when supplied.
	 *
	 * @return string[]
	 */
	public static function mapMetaCap( array $caps, string $cap, int $user_id, array $args ): array {
		$object_id = isset( $args[0] ) ? (int) $args[0] : 0;

		return match ( $cap ) {
			self::EDIT_PATROL,
			self::CANCEL_PATROL,
			self::MANAGE_PATROL_CREW => self::mapPatrolCap( $user_id, $object_id ),
			self::VIEW_REPORT        => self::mapViewReportCap( $user_id, $object_id ),
			self::EDIT_REPORT        => [ self::EDIT_ANY_REPORT ],
			default                  => $caps,
		};
	}

	/**
	 * Editing, cancelling and crewing a patrol: the author needs only the
	 * "own" capability; everyone else needs the "any" capability.
	 *
	 * @return string[]
	 */
	private static function mapPatrolCap( int $user_id, int $object_id ): array {
		if ( $object_id <= 0 ) {
			return [ self::EDIT_ANY_PATROL ];
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post ) {
			// Nothing to act on. `do_not_allow` is WordPress's explicit denial.
			return [ 'do_not_allow' ];
		}

		if ( (int) $post->post_author === $user_id ) {
			return [ self::EDIT_OWN_PATROL ];
		}

		return [ self::EDIT_ANY_PATROL ];
	}

	/**
	 * A report is visible to its author, to Supervisors and to Administrators.
	 * Deliberately not visible to the Operator who ran the patrol.
	 *
	 * @return string[]
	 */
	private static function mapViewReportCap( int $user_id, int $object_id ): array {
		if ( $object_id <= 0 ) {
			return [ self::VIEW_ALL_REPORTS ];
		}

		$post = get_post( $object_id );

		if ( ! $post instanceof \WP_Post ) {
			return [ 'do_not_allow' ];
		}

		if ( (int) $post->post_author === $user_id ) {
			return [ self::VIEW_OWN_REPORTS ];
		}

		return [ self::VIEW_ALL_REPORTS ];
	}
}
