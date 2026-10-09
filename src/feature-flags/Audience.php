<?php
/**
 * Who an experiment targets.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * A visitor matches when the audience targets everyone, or when any role, user ID, or group overlaps.
 */
final class Audience {

	/**
	 * @param bool          $everyone Target every visitor, including logged-out visitors.
	 * @param array<string> $roles    WP role slugs.
	 * @param array<int>    $user_ids WP user IDs.
	 * @param array<string> $groups   VIP Vary_Cache group names.
	 */
	public function __construct(
		public readonly bool $everyone = false,
		public readonly array $roles = array(),
		public readonly array $user_ids = array(),
		public readonly array $groups = array()
	) {}

	/**
	 * Parse stored meta. Unknown keys and wrong types are dropped.
	 *
	 * @param mixed $raw Stored value.
	 */
	public static function from_array( mixed $raw ): self {
		$raw = is_array( $raw ) ? $raw : array();
		return new self(
			! empty( $raw['everyone'] ),
			self::strings( $raw['roles'] ?? array() ),
			array_values( array_unique( array_filter( array_map( 'intval', (array) ( $raw['user_ids'] ?? array() ) ) ) ) ),
			self::strings( $raw['groups'] ?? array() )
		);
	}

	/**
	 * @return array{ everyone: bool, roles: array<string>, user_ids: array<int>, groups: array<string> }
	 */
	public function to_array(): array {
		return array(
			'everyone' => $this->everyone,
			'roles'    => $this->roles,
			'user_ids' => $this->user_ids,
			'groups'   => $this->groups,
		);
	}

	public function is_empty(): bool {
		return ! $this->everyone && array() === $this->roles && array() === $this->user_ids && array() === $this->groups;
	}

	public function matches( VisitorContext $visitor ): bool {
		if ( $this->everyone ) {
			return true;
		}
		if ( $visitor->user_id > 0 && in_array( $visitor->user_id, $this->user_ids, true ) ) {
			return true;
		}
		return array() !== array_intersect( $this->roles, $visitor->roles )
			|| array() !== array_intersect( $this->groups, $visitor->groups );
	}

	/**
	 * @param mixed $values Raw list.
	 * @return array<string>
	 */
	private static function strings( mixed $values ): array {
		$values = array_filter( (array) $values, 'is_string' );
		return array_values( array_unique( array_filter( array_map( 'trim', $values ) ) ) );
	}
}
