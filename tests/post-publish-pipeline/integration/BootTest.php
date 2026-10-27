<?php
/**
 * boot() registers the pipeline once however often it is called.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Tests;

use PRC\Primitives\Post_Publish_Pipeline\Tests\Support\Async_Queue;
use PRC\Primitives\Post_Publish_Pipeline\Tests\Support\Implementation;
use WP_UnitTestCase;

use function PRC\Primitives\Post_Publish_Pipeline\boot;

final class BootTest extends WP_UnitTestCase {

	public function test_repeat_boot_fires_each_hook_once(): void {
		if ( null !== Implementation::legacy_file() ) {
			$this->markTestSkipped( 'The legacy plugin has no boot().' );
		}
		$post_id           = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		Async_Queue::$jobs = array();
		$fired             = 0;
		add_action(
			'prc_platform_on_publish',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		boot();
		boot();
		wp_publish_post( $post_id );

		$this->assertSame( 1, $fired );
		$this->assertCount( 1, Async_Queue::$jobs );
	}
}
