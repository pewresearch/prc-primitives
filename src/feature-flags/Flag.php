<?php
/**
 * A feature flag registered in code.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

use InvalidArgumentException;

/**
 * A named switch with a default value. Experiments override the default.
 */
final class Flag {

	/**
	 * Lowercase letters, digits, dashes, and at most one slash, for example `block-library/carousel-v2`.
	 */
	public const SLUG_PATTERN = '/^[a-z0-9-]+(\/[a-z0-9-]+)?$/';

	/**
	 * @param string $slug        Unique slug.
	 * @param string $label       Human label for the admin UI.
	 * @param string $description What turning the flag on changes.
	 * @param bool   $default_value Value when no running experiment sets the flag.
	 * @throws InvalidArgumentException When the slug does not match SLUG_PATTERN.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $label,
		public readonly string $description = '',
		public readonly bool $default_value = false
	) {
		if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid feature flag slug "%s".', $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * @return array{ slug: string, label: string, description: string, default: bool }
	 */
	public function to_array(): array {
		return array(
			'slug'        => $this->slug,
			'label'       => $this->label,
			'description' => $this->description,
			'default'     => $this->default_value,
		);
	}
}
