<?php
/**
 * Lifecycle hooks fired for each post status move, and the async jobs they queue.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Tests;

use PRC\Primitives\Post_Publish_Pipeline\Tests\Support\Async_Queue;
use WP_UnitTestCase;

use function PRC\Primitives\Post_Publish_Pipeline\enqueue_async_event;
use function PRC\Primitives\Post_Publish_Pipeline\get_async_editor_id;

final class LifecycleTest extends WP_UnitTestCase {

	/**
	 * @var array<int, string>
	 */
	private array $fired = array();

	private int $post_id = 0;

	private int $editor_id = 0;

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'short-read', array( 'public' => true ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		Async_Queue::$jobs = array();
		add_action( 'all', array( $this, 'record' ) );
	}

	public function tear_down(): void {
		unregister_post_type( 'short-read' );
		Async_Queue::$jobs = array();
		parent::tear_down();
	}

	public function record( string $hook, ...$args ): void {
		if ( ! preg_match( '/^prc_platform_(async_)?on_|^prc_platform_post_publish_pipeline_async_event_enqueued$/', $hook ) ) {
			return;
		}
		$parts         = array_map( array( $this, 'describe' ), $args );
		$this->fired[] = $hook . '(' . implode( ', ', $parts ) . ')';
	}

	private function describe( mixed $arg ): string {
		return match ( true ) {
			is_object( $arg )                           => 'post:' . $arg->post_status,
			is_bool( $arg )                             => $arg ? 'true' : 'false',
			null === $arg                               => 'null',
			is_int( $arg ) && $arg === $this->post_id   => '{post}',
			is_int( $arg ) && $arg === $this->editor_id => '{editor}',
			default                                     => var_export( $arg, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		};
	}

	/**
	 * @param string               $status Post status.
	 * @param array<string, mixed> $extra  Extra wp_insert_post() args.
	 */
	private function insert_then_clear( string $status, array $extra = array() ): int {
		$this->post_id     = (int) wp_insert_post(
			array_merge(
				array(
					'post_type'   => 'short-read',
					'post_status' => $status,
					'post_title'  => 'Pipeline subject',
				),
				$extra
			)
		);
		$this->fired       = array();
		Async_Queue::$jobs = array();
		return $this->post_id;
	}

	private function set_status( string $status ): void {
		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function take_fired(): array {
		$fired       = $this->fired;
		$this->fired = array();
		return $fired;
	}

	/**
	 * @return array<int, array{hook: string, args: array<string, mixed>, group: string, unique: bool}>
	 */
	private function job( string $event ): array {
		return array(
			array(
				'hook'   => 'prc_platform_post_publish_pipeline_async_dispatch',
				'args'   => array(
					'post_id'   => $this->post_id,
					'event'     => $event,
					'editor_id' => $this->editor_id,
				),
				'group'  => 'prc-post-publish-pipeline',
				'unique' => true,
			),
		);
	}

	public function test_new_post_fires_init_then_first_transition(): void {
		$this->post_id = -1;
		add_action(
			'prc_platform_on_post_init',
			function ( $post ) {
				$this->post_id = (int) $post->ID;
			},
			0
		);

		wp_insert_post(
			array(
				'post_type'   => 'short-read',
				'post_status' => 'auto-draft',
				'post_title'  => 'Pipeline subject',
			)
		);

		$this->assertSame(
			array(
				'prc_platform_on_post_init(post:auto-draft)',
				'prc_platform_on_short-read_init(post:auto-draft)',
				"prc_platform_on_status_transition(post:auto-draft, 'auto-draft', null, false)",
				"prc_platform_on_short-read_status_transition(post:auto-draft, 'auto-draft', null, false)",
			),
			$this->take_fired()
		);
		$this->assertSame( array(), Async_Queue::$jobs );
	}

	public function test_auto_draft_to_draft_fires_only_the_transition(): void {
		$this->insert_then_clear( 'auto-draft' );

		$this->set_status( 'draft' );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:draft, 'draft', 'auto-draft', false)",
				"prc_platform_on_short-read_status_transition(post:draft, 'draft', 'auto-draft', false)",
			),
			$this->take_fired()
		);
	}

	public function test_draft_save_fires_incremental_save(): void {
		$this->insert_then_clear( 'draft' );

		$this->set_status( 'draft' );

		$this->assertSame(
			array(
				'prc_platform_on_incremental_save(post:draft)',
				'prc_platform_on_short-read_incremental_save(post:draft)',
			),
			$this->take_fired()
		);
		$this->assertSame( array(), Async_Queue::$jobs );
	}

	public function test_draft_to_publish_fires_publish_and_queues_async_publish(): void {
		$this->insert_then_clear( 'draft' );

		$this->set_status( 'publish' );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:publish, 'publish', 'draft', false)",
				"prc_platform_on_short-read_status_transition(post:publish, 'publish', 'draft', false)",
				'prc_platform_on_incremental_save(post:publish)',
				'prc_platform_on_short-read_incremental_save(post:publish)',
				'prc_platform_on_publish(post:publish, false)',
				'prc_platform_on_short-read_publish(post:publish, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'publish', {editor})",
			),
			$this->take_fired()
		);
		$this->assertSame( $this->job( 'publish' ), Async_Queue::$jobs );

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_publish(post:publish, false)',
				'prc_platform_async_on_short-read_publish(post:publish, false)',
			),
			$this->take_fired()
		);
	}

	public function test_published_save_fires_update(): void {
		$this->insert_then_clear( 'publish' );

		$this->set_status( 'publish' );

		$this->assertSame(
			array(
				'prc_platform_on_incremental_save(post:publish)',
				'prc_platform_on_short-read_incremental_save(post:publish)',
				'prc_platform_on_update(post:publish, false)',
				'prc_platform_on_short-read_update(post:publish, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'update', {editor})",
			),
			$this->take_fired()
		);
		$this->assertSame( $this->job( 'update' ), Async_Queue::$jobs );

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_update(post:publish, false)',
				'prc_platform_async_on_short-read_update(post:publish, false)',
			),
			$this->take_fired()
		);
	}

	public function test_publish_to_draft_fires_unpublish(): void {
		$this->insert_then_clear( 'publish' );

		$this->set_status( 'draft' );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:draft, 'draft', 'publish', false)",
				"prc_platform_on_short-read_status_transition(post:draft, 'draft', 'publish', false)",
				'prc_platform_on_incremental_save(post:draft)',
				'prc_platform_on_short-read_incremental_save(post:draft)',
				'prc_platform_on_unpublish(post:draft, false)',
				'prc_platform_on_short-read_unpublish(post:draft, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'unpublish', {editor})",
			),
			$this->take_fired()
		);

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_unpublish(post:draft, false)',
				'prc_platform_async_on_short-read_unpublish(post:draft, false)',
			),
			$this->take_fired()
		);
	}

	public function test_trash_fires_trash(): void {
		$this->insert_then_clear( 'publish' );

		wp_trash_post( $this->post_id );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:trash, 'trash', 'publish', false)",
				"prc_platform_on_short-read_status_transition(post:trash, 'trash', 'publish', false)",
				'prc_platform_on_trash(post:trash, false)',
				'prc_platform_on_short-read_trash(post:trash, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'trash', {editor})",
			),
			$this->take_fired()
		);

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_trash(post:trash, false)',
				'prc_platform_async_on_short-read_trash(post:trash, false)',
			),
			$this->take_fired()
		);
	}

	public function test_untrash_to_draft_fires_untrash_and_drops_the_async_job(): void {
		$this->insert_then_clear( 'publish' );
		wp_trash_post( $this->post_id );
		$this->fired       = array();
		Async_Queue::$jobs = array();

		wp_untrash_post( $this->post_id );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:draft, 'draft', 'trash', false)",
				"prc_platform_on_short-read_status_transition(post:draft, 'draft', 'trash', false)",
				'prc_platform_on_untrash(post:draft, false)',
				'prc_platform_on_short-read_untrash(post:draft, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'untrash', {editor})",
			),
			$this->take_fired()
		);

		Async_Queue::run_all();

		$this->assertSame( array(), $this->take_fired() );
	}

	public function test_trash_to_publish_fires_untrash(): void {
		$this->insert_then_clear( 'trash' );

		$this->set_status( 'publish' );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:publish, 'publish', 'trash', false)",
				"prc_platform_on_short-read_status_transition(post:publish, 'publish', 'trash', false)",
				'prc_platform_on_untrash(post:publish, false)',
				'prc_platform_on_short-read_untrash(post:publish, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'untrash', {editor})",
			),
			$this->take_fired()
		);

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_untrash(post:publish, false)',
				'prc_platform_async_on_short-read_untrash(post:publish, false)',
			),
			$this->take_fired()
		);
	}

	public function test_scheduled_post_publishing_fires_publish(): void {
		$this->insert_then_clear( 'future', array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );

		wp_publish_post( $this->post_id );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:publish, 'publish', 'future', false)",
				"prc_platform_on_short-read_status_transition(post:publish, 'publish', 'future', false)",
				'prc_platform_on_publish(post:publish, false)',
				'prc_platform_on_short-read_publish(post:publish, false)',
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'publish', {editor})",
			),
			$this->take_fired()
		);
	}

	public function test_publish_to_private_fires_only_the_transition(): void {
		$this->insert_then_clear( 'publish' );

		$this->set_status( 'private' );

		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:private, 'private', 'publish', false)",
				"prc_platform_on_short-read_status_transition(post:private, 'private', 'publish', false)",
			),
			$this->take_fired()
		);
		$this->assertSame( array(), Async_Queue::$jobs );
	}

	public function test_block_content_sets_has_blocks(): void {
		$this->insert_then_clear( 'draft', array( 'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );

		$this->set_status( 'publish' );

		$this->assertContains( 'prc_platform_on_publish(post:publish, true)', $this->take_fired() );
		Async_Queue::run_all();
		$this->assertContains( 'prc_platform_async_on_publish(post:publish, true)', $this->take_fired() );
	}

	public function test_should_process_filter_skips_every_hook(): void {
		$this->insert_then_clear( 'draft' );
		add_filter( 'prc_platform_post_publish_pipeline_should_process', '__return_false' );

		$this->set_status( 'publish' );

		$this->assertSame( array(), $this->take_fired() );
		$this->assertSame( array(), Async_Queue::$jobs );
	}

	public function test_post_types_filter_controls_which_types_are_tracked(): void {
		$this->insert_then_clear( 'draft', array( 'post_type' => 'page' ) );

		$this->set_status( 'publish' );
		$this->assertSame( array(), $this->take_fired() );

		add_filter(
			'prc_platform_post_publish_pipeline_post_types',
			static fn( array $types ) => array_merge( $types, array( 'page' ) )
		);
		$this->set_status( 'draft' );

		$this->assertContains( 'prc_platform_on_page_unpublish(post:draft, false)', $this->take_fired() );
	}

	public function test_should_enqueue_async_filter_skips_only_the_async_job(): void {
		$this->insert_then_clear( 'draft' );
		$seen = array();
		add_filter(
			'prc_platform_post_publish_pipeline_should_enqueue_async',
			static function ( $should, $post_id, $event, $post_type ) use ( &$seen ) {
				$seen[] = array( $post_id, $event, $post_type );
				return false;
			},
			10,
			4
		);

		$this->set_status( 'publish' );

		$this->assertSame( array( array( $this->post_id, 'publish', 'short-read' ) ), $seen );
		$this->assertSame(
			array(
				"prc_platform_on_status_transition(post:publish, 'publish', 'draft', false)",
				"prc_platform_on_short-read_status_transition(post:publish, 'publish', 'draft', false)",
				'prc_platform_on_incremental_save(post:publish)',
				'prc_platform_on_short-read_incremental_save(post:publish)',
				'prc_platform_on_publish(post:publish, false)',
				'prc_platform_on_short-read_publish(post:publish, false)',
			),
			$this->take_fired()
		);
		$this->assertSame( array(), Async_Queue::$jobs );
	}

	public function test_stale_async_job_is_dropped_when_status_moved_on(): void {
		$this->insert_then_clear( 'draft' );
		$this->set_status( 'publish' );
		$this->set_status( 'draft' );
		$this->fired = array();

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_unpublish(post:draft, false)',
				'prc_platform_async_on_short-read_unpublish(post:draft, false)',
			),
			$this->take_fired()
		);
	}

	public function test_async_editor_id_is_set_only_during_dispatch(): void {
		$this->insert_then_clear( 'draft' );
		$this->set_status( 'publish' );
		$seen = null;
		add_action(
			'prc_platform_async_on_publish',
			static function () use ( &$seen ) {
				$seen = get_async_editor_id();
			}
		);
		wp_set_current_user( 0 );

		Async_Queue::run_all();

		$this->assertSame( $this->editor_id, $seen );
		$this->assertSame( 0, get_async_editor_id() );
	}

	public function test_enqueue_async_event_accepts_only_async_events_on_real_posts(): void {
		$this->insert_then_clear( 'draft' );

		enqueue_async_event( $this->post_id, 'post_init' );
		enqueue_async_event( PHP_INT_MAX, 'publish' );
		enqueue_async_event( 0, 'publish' );
		$this->assertSame( array(), Async_Queue::$jobs );

		enqueue_async_event( $this->post_id, 'incremental_save' );
		enqueue_async_event( $this->post_id, 'incremental_save' );

		$this->assertSame( $this->job( 'incremental_save' ), Async_Queue::$jobs );
		$this->assertSame(
			array(
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'incremental_save', {editor})",
				"prc_platform_post_publish_pipeline_async_event_enqueued({post}, 'incremental_save', {editor})",
			),
			$this->take_fired()
		);

		Async_Queue::run_all();

		$this->assertSame(
			array(
				'prc_platform_async_on_incremental_save(post:draft, false)',
				'prc_platform_async_on_short-read_incremental_save(post:draft, false)',
			),
			$this->take_fired()
		);
	}

	public function test_post_object_carries_label_canonical_url_and_visibility(): void {
		$this->insert_then_clear( 'draft' );
		update_post_meta( $this->post_id, '_postVisibility', 'hidden_from_search' );
		$posts = array();
		add_action(
			'prc_platform_on_publish',
			static function ( $post ) use ( &$posts ) {
				$posts[] = $post;
			}
		);

		$this->set_status( 'publish' );
		update_post_meta( $this->post_id, '_redirect', 'https://example.com/elsewhere/' );
		$this->set_status( 'draft' );
		$this->set_status( 'publish' );

		$this->assertCount( 2, $posts );
		$this->assertSame( 'Short Read', $posts[0]->label );
		$this->assertSame( get_permalink( $this->post_id ), $posts[0]->canonical_url );
		$this->assertSame( 'hidden_from_search', $posts[0]->visibility );
		$this->assertSame( 'Pipeline subject', $posts[0]->post_title );
		$this->assertSame( 'https://example.com/elsewhere/', $posts[1]->canonical_url );
	}
}
