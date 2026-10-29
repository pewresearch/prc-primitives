<?php
/**
 * Pending Action Scheduler jobs recorded by the as_enqueue_async_action() stand-in.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Tests\Support;

final class Async_Queue {

	/**
	 * @var array<int, array{hook: string, args: array<string, mixed>, group: string, unique: bool}>
	 */
	public static array $jobs = array();

	/**
	 * @param string               $hook   Hook to fire.
	 * @param array<string, mixed> $args   Job args.
	 * @param string               $group  Job group.
	 * @param bool                 $unique Whether the job is unique.
	 */
	public static function enqueue( string $hook, array $args, string $group, bool $unique ): int {
		$job = array(
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'unique' => $unique,
		);
		if ( $unique ) {
			foreach ( self::$jobs as $pending ) {
				if ( $pending['hook'] === $hook && $pending['args'] === $args && $pending['group'] === $group ) {
					return 0;
				}
			}
		}
		self::$jobs[] = $job;
		return count( self::$jobs );
	}

	public static function run_all(): void {
		$jobs       = self::$jobs;
		self::$jobs = array();
		foreach ( $jobs as $job ) {
			do_action_ref_array( $job['hook'], array_values( $job['args'] ) );
		}
	}
}
