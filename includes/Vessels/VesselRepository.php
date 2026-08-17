<?php
/**
 * Vessel persistence.
 *
 * Vessels are never hard-deleted from the UI: patrols and mission reports
 * reference them by id, and destroying a vessel row would orphan historic
 * records. "Removing" a vessel marks it out of service, which takes it out of
 * the patrol dropdown while leaving every past record intact.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Vessels;

use MarineUnit\Database\Schema;
use MarineUnit\Enum\VesselStatus;

defined( 'ABSPATH' ) || exit;

final class VesselRepository {

	/**
	 * Seeded on first activation, taken from the unit's existing mission report
	 * form dropdown.
	 */
	private const SEED = [
		'SeaArk SB1',
		'Zodiac SB2',
		'Zodiac B16A',
		'Parker B16',
		'Other',
		'Other Agency',
	];

	/**
	 * @return Vessel[]
	 */
	public function all( bool $bookable_only = false ): array {
		global $wpdb;

		$table = Schema::vesselsTable();

		if ( $bookable_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
					"SELECT * FROM {$table} WHERE status = %s ORDER BY sort_order ASC, name ASC",
					VesselStatus::Active->value
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC", ARRAY_A );
		}

		return array_map( [ Vessel::class, 'fromRow' ], $rows ?: [] );
	}

	public function find( int $id ): ?Vessel {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Schema::vesselsTable();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return is_array( $row ) ? Vessel::fromRow( $row ) : null;
	}

	/**
	 * @param array{name:string,registration?:string,capacity?:?int,status?:string,sort_order?:int} $data
	 *
	 * @return int New vessel id, or 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			Schema::vesselsTable(),
			[
				'name'         => $data['name'],
				'registration' => $data['registration'] ?? '',
				'capacity'     => $data['capacity'] ?? null,
				'status'       => $data['status'] ?? VesselStatus::Active->value,
				'sort_order'   => $data['sort_order'] ?? $this->nextSortOrder(),
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param array<string, mixed> $data Only the keys present are updated.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$allowed = [
			'name'         => '%s',
			'registration' => '%s',
			'capacity'     => '%d',
			'status'       => '%s',
			'sort_order'   => '%d',
		];

		$values  = [];
		$formats = [];

		foreach ( $allowed as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$values[ $key ] = $data[ $key ];
				$formats[]      = $format;
			}
		}

		if ( ! $values ) {
			return false;
		}

		$values['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		return false !== $wpdb->update( Schema::vesselsTable(), $values, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	/**
	 * Take a vessel out of service. This is what the "remove" action does.
	 */
	public function retire( int $id ): bool {
		return $this->update( $id, [ 'status' => VesselStatus::OutOfService->value ] );
	}

	public function restore( int $id ): bool {
		return $this->update( $id, [ 'status' => VesselStatus::Active->value ] );
	}

	/**
	 * Permanently delete. Not exposed in the UI — reserved for uninstall and
	 * for tests.
	 */
	public function forceDelete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::vesselsTable(), [ 'id' => $id ], [ '%d' ] );
	}

	public function count(): int {
		global $wpdb;

		$table = Schema::vesselsTable();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private function nextSortOrder(): int {
		global $wpdb;

		$table = Schema::vesselsTable();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return ( (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$table}" ) ) + 10;
	}

	/**
	 * Populate the unit's vessels on first activation. Does nothing if any
	 * vessel already exists, so re-activating never duplicates the list or
	 * resurrects one the unit deleted.
	 */
	public function seed(): void {
		if ( $this->count() > 0 ) {
			return;
		}

		$order = 0;

		foreach ( self::SEED as $name ) {
			$order += 10;

			$this->create(
				[
					'name'       => $name,
					'sort_order' => $order,
				]
			);
		}
	}

	/**
	 * value => label map for <select> rendering.
	 *
	 * @return array<int, string>
	 */
	public function options( bool $bookable_only = true ): array {
		$options = [];

		foreach ( $this->all( $bookable_only ) as $vessel ) {
			$options[ $vessel->id ] = $vessel->label();
		}

		return $options;
	}
}
