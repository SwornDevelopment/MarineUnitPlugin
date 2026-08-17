<?php
/**
 * Patrol / mission types.
 *
 * Stored as a plain ordered option rather than a table: a type has no
 * properties beyond its name, and the list is short and rarely changed.
 *
 * Each type keeps a stable slug so that renaming a type does not orphan the
 * patrols and reports that reference it.
 *
 * @package MarineUnit
 */

declare( strict_types=1 );

namespace MarineUnit;

defined( 'ABSPATH' ) || exit;

final class PatrolTypes {

	private const OPTION = 'mup_patrol_types';

	/**
	 * Seeded from the Mission Type checkboxes on the unit's existing report
	 * form, in the same order they appear there.
	 */
	private const SEED = [
		'le_response'       => 'LE Response',
		'trt_response'      => 'TRT Response',
		'mtog_response'     => 'MTOG Response',
		'marine_patrol'     => 'Marine Patrol',
		'marine_rescue'     => 'Marine Rescue',
		'dive_support_ops'  => 'Dive Support Ops.',
		'trt_training'      => 'TRT Training',
		'training'          => 'Training',
		'other'             => 'Other',
	];

	/**
	 * The "Other" type pairs with a free-text field on the mission report.
	 */
	public const OTHER_SLUG = 'other';

	/**
	 * @return array<string, string> slug => label, in display order.
	 */
	public static function all(): array {
		$types = get_option( self::OPTION, false );

		/*
		 * Only fall back to the seed list when the option has never been
		 * written. An empty array is a deliberate "the unit removed every
		 * type" and must be respected — treating it as "unset" would keep
		 * resurrecting the seeds.
		 */
		if ( false === $types || ! is_array( $types ) ) {
			return self::SEED;
		}

		return array_map( 'strval', $types );
	}

	public static function label( string $slug ): string {
		return self::all()[ $slug ] ?? $slug;
	}

	public static function exists( string $slug ): bool {
		return array_key_exists( $slug, self::all() );
	}

	/**
	 * Replace the whole list. Order of the passed array is the display order.
	 *
	 * @param array<string, string> $types slug => label.
	 */
	public static function save( array $types ): void {
		$clean = [];

		foreach ( $types as $slug => $label ) {
			$slug  = sanitize_key( (string) $slug );
			$label = sanitize_text_field( (string) $label );

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			$clean[ $slug ] = $label;
		}

		update_option( self::OPTION, $clean );
	}

	/**
	 * Add a type, deriving a unique slug from the label.
	 *
	 * @return string The slug that was created, or '' if the label was empty.
	 */
	public static function add( string $label ): string {
		$label = sanitize_text_field( $label );

		if ( '' === $label ) {
			return '';
		}

		$types = self::all();
		$base  = sanitize_key( $label ) ?: 'type';
		$slug  = $base;
		$n     = 2;

		while ( isset( $types[ $slug ] ) ) {
			$slug = $base . '_' . $n;
			++$n;
		}

		$types[ $slug ] = $label;
		self::save( $types );

		return $slug;
	}

	/**
	 * Remove a type from the list.
	 *
	 * Historic patrols and reports keep their stored slug, so a removed type
	 * still renders on old records via label() falling back to the raw slug.
	 */
	public static function remove( string $slug ): void {
		$types = self::all();

		unset( $types[ $slug ] );

		self::save( $types );
	}

	/**
	 * Write the seed list on first activation. Does nothing if the option
	 * already exists, so re-activating never resurrects a deleted type.
	 */
	public static function seed(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		add_option( self::OPTION, self::SEED );
	}

	public static function deleteOption(): void {
		delete_option( self::OPTION );
	}
}
