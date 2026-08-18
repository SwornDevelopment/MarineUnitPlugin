<?php
/**
 * Mission report persistence.
 *
 * Reports are a custom post type. The payload is stored as one JSON meta blob
 * rather than ~90 separate meta rows: only a handful of fields are ever
 * filtered on, and those are duplicated into indexed meta keys. The blob
 * carries a schema version so future field changes can be migrated.
 *
 * A submitted report is a record. It locks on save, and only a Supervisor may
 * amend it — with the change written to the audit trail.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Reports;

use MarineUnit\Database\Schema;
use MarineUnit\Enum\GarBand;
use MarineUnit\Roles;
use MarineUnit\UserProfile;

defined( 'ABSPATH' ) || exit;

final class ReportRepository {

	public const POST_TYPE = 'marine_report';

	private const META_PAYLOAD = '_mup_report_data';

	/** Duplicated out of the payload purely so reports can be filtered. */
	private const META_INDEXED = [
		'mission_date' => '_mup_mission_date',
		'vessel_id'    => '_mup_vessel_id',
		'gar_total'    => '_mup_gar_total',
	];

	public static function registerPostType(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'          => __( 'Mission Reports', 'marine-unit-plugin' ),
					'singular_name' => __( 'Mission Report', 'marine-unit-plugin' ),
				],
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => [ 'title', 'author' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	/**
	 * Store a new report. Returns 0 on failure.
	 */
	public function create( ReportData $data, int $author_id ): int {
		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => $this->buildTitle( $data, $author_id ),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$this->writePayload( (int) $post_id, $data );

		return (int) $post_id;
	}

	public function find( int $id ): ?ReportData {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$raw = get_post_meta( $id, self::META_PAYLOAD, true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? ReportData::fromArray( $decoded ) : null;
	}

	/**
	 * Amend a locked report. Supervisors only — the caller must have checked
	 * Roles::EDIT_REPORT first. Every change is recorded.
	 */
	public function amend( int $id, ReportData $data, int $actor_id ): bool {
		$before = $this->find( $id );

		if ( ! $before instanceof ReportData ) {
			return false;
		}

		$this->writePayload( $id, $data );
		$this->recordAudit( $id, $actor_id, $before, $data );

		return true;
	}

	private function writePayload( int $id, ReportData $data ): void {
		$payload = $data->toArray();

		update_post_meta( $id, self::META_PAYLOAD, wp_json_encode( $payload ) );

		foreach ( self::META_INDEXED as $key => $meta_key ) {
			update_post_meta( $id, $meta_key, $payload[ $key ] ?? '' );
		}

		// Mission types are indexed one row each so they can be filtered on.
		delete_post_meta( $id, '_mup_mission_type' );

		foreach ( (array) ( $payload['mission_type'] ?? [] ) as $type ) {
			add_post_meta( $id, '_mup_mission_type', (string) $type );
		}
	}

	/**
	 * Append a before/after record of what an amendment changed.
	 *
	 * Original values are never destroyed — the audit row keeps them.
	 */
	private function recordAudit( int $id, int $actor_id, ReportData $before, ReportData $after ): void {
		global $wpdb;

		$old = $before->toArray();
		$new = $after->toArray();
		$changes = [];

		foreach ( $new as $key => $value ) {
			if ( ( $old[ $key ] ?? null ) !== $value ) {
				$changes[ $key ] = [
					'from' => $old[ $key ] ?? null,
					'to'   => $value,
				];
			}
		}

		foreach ( $old as $key => $value ) {
			if ( ! array_key_exists( $key, $new ) ) {
				$changes[ $key ] = [
					'from' => $value,
					'to'   => null,
				];
			}
		}

		if ( ! $changes ) {
			return;
		}

		$wpdb->insert(
			Schema::reportAuditTable(),
			[
				'report_id'  => $id,
				'user_id'    => $actor_id,
				'changed_at' => current_time( 'mysql', true ),
				'changes'    => (string) wp_json_encode( $changes ),
			],
			[ '%d', '%d', '%s', '%s' ]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function auditTrail( int $id ): array {
		global $wpdb;

		$table = Schema::reportAuditTable();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"SELECT * FROM {$table} WHERE report_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Reports the given user filed, newest first.
	 *
	 * @return int[] Post ids.
	 */
	public function forAuthor( int $user_id, int $limit = 50 ): array {
		$query = new \WP_Query(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Whether the given user may read a specific report. Delegates to the meta
	 * capability so the rule lives in exactly one place.
	 */
	public function canView( int $report_id, ?int $user_id = null ): bool {
		if ( null === $user_id ) {
			return current_user_can( Roles::VIEW_REPORT, $report_id );
		}

		return user_can( $user_id, Roles::VIEW_REPORT, $report_id );
	}

	/**
	 * Reports are never listed by title in the UI, but a readable title makes
	 * the database far easier to inspect.
	 */
	private function buildTitle( ReportData $data, int $author_id ): string {
		$date = $data->getString( 'mission_date' );
		$who  = UserProfile::displayName( $author_id );

		return trim( sprintf( '%s — %s', $date, $who ) );
	}

	/**
	 * Convenience for display: the stored band, falling back to recomputing.
	 */
	public function band( ReportData $data ): GarBand {
		return $data->garBand();
	}
}
