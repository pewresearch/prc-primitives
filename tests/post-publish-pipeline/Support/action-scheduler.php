<?php
/**
 * Action Scheduler stand-in. The package does not ship Action Scheduler, so jobs go to Async_Queue.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

use PRC\Primitives\Post_Publish_Pipeline\Tests\Support\Async_Queue;

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * @param string               $hook     Hook to fire.
	 * @param array<string, mixed> $args     Job args.
	 * @param string               $group    Job group.
	 * @param bool                 $unique   Whether the job is unique.
	 * @param int                  $priority Unused.
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ): int {
		unset( $priority );
		return Async_Queue::enqueue( (string) $hook, (array) $args, (string) $group, (bool) $unique );
	}
}
