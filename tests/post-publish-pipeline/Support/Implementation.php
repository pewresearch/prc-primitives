<?php
/**
 * Selects the pipeline implementation the tests run against.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Tests\Support;

/**
 * Set PRC_POST_PUBLISH_PIPELINE_LEGACY to the legacy prc-platform plugin file to run the
 * same tests against it. Otherwise the tests run against this package.
 */
final class Implementation {

	public static function legacy_file(): ?string {
		$file = getenv( 'PRC_POST_PUBLISH_PIPELINE_LEGACY' );
		return is_string( $file ) && '' !== $file ? $file : null;
	}

	public static function boot(): void {
		$legacy = self::legacy_file();
		if ( null !== $legacy ) {
			require_once $legacy;
			return;
		}
		\PRC\Primitives\Post_Publish_Pipeline\boot();
	}

	public static function enqueue_async_event( int $post_id, string $event ): void {
		if ( null !== self::legacy_file() ) {
			\PRC\Platform\Post_Publish_Pipeline\enqueue_async_event( $post_id, $event );
			return;
		}
		\PRC\Primitives\Post_Publish_Pipeline\enqueue_async_event( $post_id, $event );
	}

	public static function async_editor_id(): int {
		if ( null !== self::legacy_file() ) {
			return \PRC\Platform\Post_Publish_Pipeline\Bootstrap::get_async_editor_id();
		}
		return \PRC\Primitives\Post_Publish_Pipeline\get_async_editor_id();
	}
}
