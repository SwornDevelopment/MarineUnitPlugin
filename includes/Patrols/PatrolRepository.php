<?php
/**
 * Patrol persistence.
 *
 * Patrols are a custom post type: they benefit from authorship, revisions and
 * the standard capability plumbing. Their fields live in prefixed post meta,
 * with the date stored as `Y-m-d` so calendar range queries stay simple.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Patrols;

use MarineUnit\Enum\PatrolStatus;

defined( 'ABSPATH' ) || exit;

final class PatrolRepository {

	public const POST_TYPE = 'marine_patrol';

	private const META = [
		'date'         => '_mup_date',
		'start_time'   => '_mup_start_time',
		'end_time'     => '_mup_end_time',
		'launch_point' => '_mup_launch_point',
		'vessel_id'    => '_mup_vessel_id',
		'patrol_type'  => '_mup_patrol_type',
		'max_crew'     => '_mup_max_crew',
		'notes'        => '_mup_notes',
		'status'       => '_mup_status',
	];

	/**
	 * Register the post type. Non-public and with no admin UI: patrols are
	 * created from the front-end calendar and managed by the plugin's own
	 * screens, never through the standard post editor.
	 */
	public static function registerPostType(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'          => __( 'Patrols', 'marine-unit-plugin' ),
					'singular_name' => __( 'Patrol', 'marine-unit-plugin' ),
				],
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'hierarchical'    => false,
				'supports'        => [ 'title', 'author' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}

	public function find( int $id ): ?Patrol {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return $this->hydrate( $post );
	}

	private function hydrate( \WP_Post $post ): Patrol {
		$data = [
			'id'        => $post->ID,
			'author_id' => (int) $post->post_author,
		];

		foreach ( self::META as $key => $meta_key ) {
			$data[ $key ] = get_post_meta( $post->ID, $meta_key, true );
		}

		return Patrol::fromArray( $data );
	}

	/**
	 * Create a patrol.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return int New patrol id, or 0 on failure.
	 */
	public function create( array $data, int $author_id ): int {
		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_author' => $author_id,
				'post_title'  => $this->buildTitle( $data ),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$this->writeMeta( (int) $post_id, $data );

		return (int) $post_id;
	}

	/**
	 * @param array<string, mixed> $data Only the keys present are written.
	 */
	public function update( int $id, array $data ): bool {
		$patrol = $this->find( $id );

		if ( ! $patrol instanceof Patrol ) {
			return false;
		}

		$this->writeMeta( $id, $data );

		// Keep the title in step with the patrol's current date and time.
		$fresh = $this->find( $id );

		if ( $fresh instanceof Patrol ) {
			wp_update_post(
				[
					'ID'         => $id,
					'post_title' => $this->buildTitle( $fresh->toArray() ),
				]
			);
		}

		return true;
	}

	/**
	 * Cancelling keeps the patrol on the calendar, visibly cancelled, rather
	 * than making it vanish on crew who had signed up.
	 */
	public function cancel( int $id ): bool {
		return $this->update( $id, [ 'status' => PatrolStatus::Cancelled->value ] );
	}

	public function reinstate( int $id ): bool {
		return $this->update( $id, [ 'status' => PatrolStatus::Active->value ] );
	}

	public function delete( int $id ): bool {
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function writeMeta( int $id, array $data ): void {
		foreach ( self::META as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			$value = $data[ $key ];

			$value = match ( $key ) {
				'vessel_id' => (int) $value,
				'max_crew'  => max( 1, (int) $value ),
				'notes'     => sanitize_textarea_field( (string) $value ),
				'status'    => PatrolStatus::tryFromMixed( $value )->value,
				default     => sanitize_text_field( (string) $value ),
			};

			update_post_meta( $id, $meta_key, $value );
		}
	}

	/**
	 * Patrols are never listed by title in the UI, but a readable title makes
	 * the database far easier to inspect.
	 *
	 * @param array<string, mixed> $data
	 */
	private function buildTitle( array $data ): string {
		return trim(
			sprintf(
				'%s %s-%s',
				(string) ( $data['date'] ?? '' ),
				(string) ( $data['start_time'] ?? '' ),
				(string) ( $data['end_time'] ?? '' )
			)
		);
	}

	/**
	 * Patrols between two dates, inclusive. Drives the calendar month grid.
	 *
	 * @param string $from `Y-m-d`.
	 * @param string $to   `Y-m-d`.
	 *
	 * @return Patrol[]
	 */
	public function betweenDates( string $from, string $to, bool $include_cancelled = true ): array {
		$args = [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => self::META['date'],
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => self::META['date'],
					'value'   => [ $from, $to ],
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				],
			],
		];

		if ( ! $include_cancelled ) {
			$args['meta_query'][] = [
				'key'     => self::META['status'],
				'value'   => PatrolStatus::Cancelled->value,
				'compare' => '!=',
			];
		}

		return $this->runQuery( $args );
	}

	/**
	 * Every patrol sharing a vessel on a given date, excluding one patrol.
	 * Used by the vessel double-booking check.
	 *
	 * A patrol that begins the previous day can run into this one, so the
	 * previous day is included in the scan.
	 *
	 * @return Patrol[]
	 */
	public function forVesselAroundDate( int $vessel_id, string $date, int $exclude_patrol_id = 0 ): array {
		if ( $vessel_id <= 0 ) {
			return [];
		}

		$from = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) ?: time() );
		$to   = gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ?: time() );

		$patrols = $this->runQuery(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'post__not_in'   => $exclude_patrol_id > 0 ? [ $exclude_patrol_id ] : [],
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => self::META['vessel_id'],
						'value'   => $vessel_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					],
					[
						'key'     => self::META['date'],
						'value'   => [ $from, $to ],
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					],
				],
			]
		);

		// A cancelled patrol releases its boat.
		return array_values(
			array_filter( $patrols, static fn ( Patrol $p ): bool => ! $p->isCancelled() )
		);
	}

	/**
	 * Patrols a user is signed up to, within a date window. Used by the
	 * sign-up overlap check and by the "my patrols" view.
	 *
	 * @param int[] $patrol_ids
	 *
	 * @return Patrol[]
	 */
	public function findMany( array $patrol_ids ): array {
		$patrol_ids = array_values( array_unique( array_filter( array_map( 'intval', $patrol_ids ) ) ) );

		if ( ! $patrol_ids ) {
			return [];
		}

		return $this->runQuery(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post__in'       => $patrol_ids,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'post__in',
			]
		);
	}

	/**
	 * @param array<string, mixed> $args
	 *
	 * @return Patrol[]
	 */
	private function runQuery( array $args ): array {
		$query = new \WP_Query( $args );

		return array_map( [ $this, 'hydrate' ], $query->posts );
	}
}
