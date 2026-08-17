<?php
/**
 * Sign-up persistence.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Signups;

use MarineUnit\Database\Schema;
use MarineUnit\Enum\SignupStatus;

defined( 'ABSPATH' ) || exit;

final class SignupRepository {

	/**
	 * @return Signup[]
	 */
	public function forPatrol( int $patrol_id, bool $for_update = false ): array {
		global $wpdb;

		$table = Schema::signupsTable();
		$lock  = $for_update ? ' FOR UPDATE' : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and lock clause are internal constants.
				"SELECT * FROM {$table} WHERE patrol_id = %d ORDER BY waitlist_position ASC, id ASC{$lock}",
				$patrol_id
			),
			ARRAY_A
		);

		return array_map( [ Signup::class, 'fromRow' ], $rows ?: [] );
	}

	public function find( int $patrol_id, int $user_id ): ?Signup {
		global $wpdb;

		$table = Schema::signupsTable();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"SELECT * FROM {$table} WHERE patrol_id = %d AND user_id = %d",
				$patrol_id,
				$user_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? Signup::fromRow( $row ) : null;
	}

	/**
	 * Every patrol id the user is signed up to. Feeds the overlap check.
	 *
	 * @return int[]
	 */
	public function patrolIdsForUser( int $user_id ): array {
		global $wpdb;

		$table = Schema::signupsTable();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"SELECT patrol_id FROM {$table} WHERE user_id = %d",
				$user_id
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * @return Signup[]
	 */
	public function forUser( int $user_id ): array {
		global $wpdb;

		$table = Schema::signupsTable();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC",
				$user_id
			),
			ARRAY_A
		);

		return array_map( [ Signup::class, 'fromRow' ], $rows ?: [] );
	}

	/**
	 * Insert a sign-up.
	 *
	 * Returns 0 when the unique key rejects a duplicate, which is exactly what
	 * a double-submitted form produces. The caller treats that as "already
	 * signed up" rather than an error.
	 */
	public function insert( int $patrol_id, int $user_id, SignupStatus $status, int $position ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::signupsTable(),
			[
				'patrol_id'         => $patrol_id,
				'user_id'           => $user_id,
				'status'            => $status->value,
				'waitlist_position' => $position,
				'created_at'        => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%d', '%s' ]
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	public function setStatus( int $signup_id, SignupStatus $status ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			Schema::signupsTable(),
			[ 'status' => $status->value ],
			[ 'id' => $signup_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param int[] $signup_ids
	 */
	public function promoteMany( array $signup_ids ): int {
		$promoted = 0;

		foreach ( $signup_ids as $signup_id ) {
			if ( $this->setStatus( (int) $signup_id, SignupStatus::Confirmed ) ) {
				++$promoted;
			}
		}

		return $promoted;
	}

	public function delete( int $patrol_id, int $user_id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete(
			Schema::signupsTable(),
			[
				'patrol_id' => $patrol_id,
				'user_id'   => $user_id,
			],
			[ '%d', '%d' ]
		);
	}

	public function deleteForPatrol( int $patrol_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( Schema::signupsTable(), [ 'patrol_id' => $patrol_id ], [ '%d' ] );
	}

	public function countConfirmed( int $patrol_id ): int {
		global $wpdb;

		$table = Schema::signupsTable();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				"SELECT COUNT(*) FROM {$table} WHERE patrol_id = %d AND status = %s",
				$patrol_id,
				SignupStatus::Confirmed->value
			)
		);
	}

	public function beginTransaction(): void {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
	}

	public function commit(): void {
		global $wpdb;

		$wpdb->query( 'COMMIT' );
	}

	public function rollback(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
	}
}
