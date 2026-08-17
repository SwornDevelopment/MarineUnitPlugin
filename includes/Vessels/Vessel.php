<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Vessels;

use MarineUnit\Enum\VesselStatus;

defined( 'ABSPATH' ) || exit;

final class Vessel {

	/*
	 * Per-property `readonly` rather than a readonly class, so the declared
	 * PHP 8.1 floor in the plugin header stays honest. Readonly classes are 8.2.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $registration,
		public readonly ?int $capacity,
		public readonly VesselStatus $status,
		public readonly int $sortOrder,
	) {}

	/**
	 * @param array<string, mixed> $row Raw database row.
	 */
	public static function fromRow( array $row ): self {
		$capacity = $row['capacity'] ?? null;

		return new self(
			id: (int) ( $row['id'] ?? 0 ),
			name: (string) ( $row['name'] ?? '' ),
			registration: (string) ( $row['registration'] ?? '' ),
			capacity: null === $capacity ? null : (int) $capacity,
			status: VesselStatus::tryFromMixed( $row['status'] ?? null ),
			sortOrder: (int) ( $row['sort_order'] ?? 0 ),
		);
	}

	public function isBookable(): bool {
		return $this->status->isBookable();
	}

	/**
	 * Name as shown in dropdowns and on the calendar, including the
	 * registration number when one is recorded.
	 */
	public function label(): string {
		if ( '' === $this->registration ) {
			return $this->name;
		}

		return sprintf( '%s (%s)', $this->name, $this->registration );
	}
}
