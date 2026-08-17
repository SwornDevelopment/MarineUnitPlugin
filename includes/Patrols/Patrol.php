<?php
/**
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Patrols;

use MarineUnit\Enum\PatrolStatus;
use MarineUnit\Support\TimeRange;

defined( 'ABSPATH' ) || exit;

final class Patrol {

	public function __construct(
		public readonly int $id,
		public readonly int $authorId,
		public readonly string $date,
		public readonly string $startTime,
		public readonly string $endTime,
		public readonly string $launchPoint,
		public readonly int $vesselId,
		public readonly string $patrolType,
		public readonly int $maxCrew,
		public readonly string $notes,
		public readonly PatrolStatus $status,
	) {}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		return new self(
			id: (int) ( $data['id'] ?? 0 ),
			authorId: (int) ( $data['author_id'] ?? 0 ),
			date: (string) ( $data['date'] ?? '' ),
			startTime: (string) ( $data['start_time'] ?? '' ),
			endTime: (string) ( $data['end_time'] ?? '' ),
			launchPoint: (string) ( $data['launch_point'] ?? '' ),
			vesselId: (int) ( $data['vessel_id'] ?? 0 ),
			patrolType: (string) ( $data['patrol_type'] ?? '' ),
			maxCrew: max( 1, (int) ( $data['max_crew'] ?? 1 ) ),
			notes: (string) ( $data['notes'] ?? '' ),
			status: PatrolStatus::tryFromMixed( $data['status'] ?? null ),
		);
	}

	/**
	 * The patrol's window, used for both conflict checks.
	 */
	public function range( ?\DateTimeZone $timezone = null ): ?TimeRange {
		return TimeRange::fromDateAndTimes( $this->date, $this->startTime, $this->endTime, $timezone );
	}

	public function isCancelled(): bool {
		return PatrolStatus::Cancelled === $this->status;
	}

	public function acceptsSignups(): bool {
		return $this->status->acceptsSignups();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'           => $this->id,
			'author_id'    => $this->authorId,
			'date'         => $this->date,
			'start_time'   => $this->startTime,
			'end_time'     => $this->endTime,
			'launch_point' => $this->launchPoint,
			'vessel_id'    => $this->vesselId,
			'patrol_type'  => $this->patrolType,
			'max_crew'     => $this->maxCrew,
			'notes'        => $this->notes,
			'status'       => $this->status->value,
		];
	}
}
