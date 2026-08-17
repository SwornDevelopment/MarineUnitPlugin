<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Signups;

use MarineUnit\Enum\SignupStatus;

defined( 'ABSPATH' ) || exit;

final class Signup {

	public function __construct(
		public readonly int $id,
		public readonly int $patrolId,
		public readonly int $userId,
		public readonly SignupStatus $status,
		public readonly int $waitlistPosition,
		public readonly string $createdAt,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow( array $row ): self {
		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			patrolId: (int) ( $row['patrol_id'] ?? 0 ),
			userId: (int) ( $row['user_id'] ?? 0 ),
			status: SignupStatus::tryFromMixed( $row['status'] ?? null ),
			waitlistPosition: (int) ( $row['waitlist_position'] ?? 0 ),
			createdAt: (string) ( $row['created_at'] ?? '' ),
		);
	}

	public function isConfirmed(): bool {
		return $this->status->occupiesSlot();
	}
}
