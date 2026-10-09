<?php
/**
 * A set of flag overrides for an audience.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * An experiment turns flags on or off for the visitors its audience matches, while it runs.
 */
final class Experiment {

	/**
	 * @param int               $id       Storage ID, for example a post ID.
	 * @param string            $title    Human title.
	 * @param bool              $running  Only running experiments affect flags.
	 * @param array<string,bool> $flags    Flag slug to value.
	 * @param Audience          $audience Who the experiment targets.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly bool $running,
		public readonly array $flags,
		public readonly Audience $audience
	) {}

	/**
	 * Parse a stored flag map. Keys that are not valid slugs are dropped.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string,bool>
	 */
	public static function parse_flags( mixed $raw ): array {
		$flags = array();
		foreach ( (array) $raw as $slug => $value ) {
			if ( is_string( $slug ) && 1 === preg_match( Flag::SLUG_PATTERN, $slug ) ) {
				$flags[ $slug ] = (bool) $value;
			}
		}
		return $flags;
	}
}
