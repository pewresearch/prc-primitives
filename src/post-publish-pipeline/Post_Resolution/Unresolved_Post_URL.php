<?php
/**
 * A valid URL that did not identify a local post.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Post_Resolution;

/**
 * Miss result from Post_URL_Resolver::resolve().
 */
final class Unresolved_Post_URL {

	public const REASON_FOREIGN_HOST = 'foreign_host';
	public const REASON_NOT_FOUND    = 'not_found';

	/**
	 * Construct a miss result.
	 *
	 * @param string $url    Candidate URL.
	 * @param string $reason `foreign_host` or `not_found`.
	 * @throws \InvalidArgumentException If the URL is empty or the reason is unknown.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $reason
	) {
		if ( '' === $url ) {
			throw new \InvalidArgumentException( 'An unresolved URL still needs a URL.' );
		}
		if ( ! in_array( $reason, array( self::REASON_FOREIGN_HOST, self::REASON_NOT_FOUND ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown unresolved reason.' );
		}
	}
}
