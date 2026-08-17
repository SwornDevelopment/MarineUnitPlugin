<?php
/**
 * A small success/failure result.
 *
 * Sign-up operations have three outcomes that all need to reach the UI —
 * confirmed, waitlisted, and refused with a reason — plus warnings that do not
 * stop the operation (a double-booked vessel). Exceptions are the wrong shape
 * for that; a result object is not.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit\Support;

defined( 'ABSPATH' ) || exit;

final class Result {

	/**
	 * @param bool                 $ok      Whether the operation succeeded.
	 * @param string               $code    Machine-readable outcome code.
	 * @param string               $message Human-readable, translated.
	 * @param array<string, mixed> $data    Extra context for the caller.
	 * @param string[]             $warnings Non-blocking notices.
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $code,
		public readonly string $message,
		public readonly array $data = [],
		public readonly array $warnings = [],
	) {}

	/**
	 * @param array<string, mixed> $data
	 * @param string[]             $warnings
	 */
	public static function success( string $code, string $message = '', array $data = [], array $warnings = [] ): self {
		return new self( true, $code, $message, $data, $warnings );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function failure( string $code, string $message, array $data = [] ): self {
		return new self( false, $code, $message, $data );
	}

	public function failed(): bool {
		return ! $this->ok;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->data[ $key ] ?? $fallback;
	}

	public function hasWarnings(): bool {
		return [] !== $this->warnings;
	}

	/**
	 * Return a copy carrying the given warnings.
	 *
	 * @param string[] $warnings
	 */
	public function withWarnings( array $warnings ): self {
		return new self( $this->ok, $this->code, $this->message, $this->data, $warnings );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'ok'       => $this->ok,
			'code'     => $this->code,
			'message'  => $this->message,
			'data'     => $this->data,
			'warnings' => $this->warnings,
		];
	}
}
