<?php
/**
 * A valid URL that identified a local post.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Post_Resolution;

/**
 * Hit result from Post_URL_Resolver::resolve().
 */
final class Resolved_Post_URL {

	/**
	 * Construct a hit result.
	 *
	 * @param string        $url  Localized candidate URL.
	 * @param Resolved_Post $post Hydrated post snapshot.
	 * @throws \InvalidArgumentException If the URL is empty.
	 */
	public function __construct(
		public readonly string $url,
		public readonly Resolved_Post $post
	) {
		if ( '' === $url ) {
			throw new \InvalidArgumentException( 'A resolved URL still needs a URL.' );
		}
	}
}
