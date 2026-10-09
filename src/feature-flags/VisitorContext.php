<?php
/**
 * Who is asking for a flag.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * The WP user, their roles, and the VIP cache groups the visitor belongs to.
 *
 * A logged-out visitor has user ID 0 and no roles.
 */
final class VisitorContext {

	/**
	 * @param int           $user_id WP user ID, 0 when logged out.
	 * @param array<string> $roles   WP role slugs.
	 * @param array<string> $groups  VIP Vary_Cache group names.
	 */
	public function __construct(
		public readonly int $user_id = 0,
		public readonly array $roles = array(),
		public readonly array $groups = array()
	) {}

	/**
	 * Build the context for a WP user. Groups come from the caller.
	 *
	 * @param int           $user_id WP user ID.
	 * @param array<string> $groups  VIP Vary_Cache group names.
	 */
	public static function for_user( int $user_id, array $groups = array() ): self {
		$user  = $user_id > 0 ? get_userdata( $user_id ) : false;
		$roles = $user ? array_values( (array) $user->roles ) : array();
		return new self( $user ? $user_id : 0, $roles, array_values( $groups ) );
	}
}
