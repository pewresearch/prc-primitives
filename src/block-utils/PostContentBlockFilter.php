<?php
/**
 * Removes blocks from rendered core/post-content output.
 *
 * @package PRC\Primitives\BlockUtils
 */

namespace PRC\Primitives\BlockUtils;

use WP_Block;

/**
 * Registry of rules that empty a block when it renders inside core/post-content.
 *
 * Each core/post-content render pushes its post ID onto a stack, so nested post
 * content resolves to the innermost post. A rule callback decides per block.
 * Blocks outside post content, the editor, and REST data are not touched.
 */
class PostContentBlockFilter {

	/**
	 * Shared instance.
	 *
	 * @var PostContentBlockFilter|null
	 */
	private static ?PostContentBlockFilter $instance = null;

	/**
	 * Post IDs of the core/post-content blocks currently rendering, innermost last.
	 *
	 * @var int[]
	 */
	private array $post_id_stack = array();

	/**
	 * Rules keyed by block name.
	 *
	 * @var array<string, array<int, array{priority: int, callback: callable}>>
	 */
	private array $rules = array();

	/**
	 * render_block callbacks keyed by block name and priority.
	 *
	 * @var array<string, callable>
	 */
	private array $hooked = array();

	/**
	 * Whether the core/post-content hooks are attached.
	 *
	 * @var bool
	 */
	private bool $tracking = false;

	/**
	 * Get the shared instance.
	 */
	public static function instance(): PostContentBlockFilter {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Remove every hook and rule and discard the shared instance.
	 */
	public static function reset(): void {
		if ( null === self::$instance ) {
			return;
		}

		self::$instance->detach();
		self::$instance = null;
	}

	/**
	 * Register a rule.
	 *
	 * @param string[] $block_names  Block names the rule applies to.
	 * @param callable $should_strip fn( array $block, int $post_id, ?WP_Block $instance ): bool.
	 * @param int      $priority     render_block_{name} priority.
	 */
	public function add_rule( array $block_names, callable $should_strip, int $priority = 10 ): void {
		$this->track_post_content();

		foreach ( $block_names as $block_name ) {
			$this->rules[ $block_name ][] = array(
				'priority' => $priority,
				'callback' => $should_strip,
			);

			$hook_key = $block_name . '|' . $priority;
			if ( isset( $this->hooked[ $hook_key ] ) ) {
				continue;
			}

			$callback                  = function ( $block_content, $block, $instance = null ) use ( $block_name, $priority ) {
				return $this->maybe_strip( $block_content, $block, $instance, $block_name, $priority );
			};
			$this->hooked[ $hook_key ] = $callback;
			add_filter( 'render_block_' . $block_name, $callback, $priority, 3 );
		}
	}

	/**
	 * Push the post ID of a core/post-content block that is about to render.
	 *
	 * Runs last so any short-circuit is visible. A short-circuited block never
	 * reaches its render_block filter, so it must not be pushed.
	 *
	 * @hook pre_render_block
	 *
	 * @param string|null          $pre_render   Short-circuit value.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @param WP_Block|null        $parent_block Parent block.
	 * @return string|null
	 */
	public function enter_post_content( $pre_render, $parsed_block, $parent_block = null ) {
		if ( null !== $pre_render || 'core/post-content' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $pre_render;
		}

		$post_id = 0;
		if ( $parent_block instanceof WP_Block && isset( $parent_block->context['postId'] ) ) {
			$post_id = (int) $parent_block->context['postId'];
		}
		if ( 0 === $post_id ) {
			$post_id = (int) get_the_ID();
		}

		$this->post_id_stack[] = $post_id;

		return $pre_render;
	}

	/**
	 * Pop the post ID of a core/post-content block that finished rendering.
	 *
	 * @hook render_block_core/post-content
	 *
	 * @param string $block_content Rendered HTML.
	 * @return string
	 */
	public function leave_post_content( $block_content ) {
		array_pop( $this->post_id_stack );

		return $block_content;
	}

	/**
	 * Empty the block when it renders inside post content and a rule matches.
	 *
	 * @param string        $block_content Rendered HTML.
	 * @param array         $block         Parsed block.
	 * @param WP_Block|null $instance      Block instance.
	 * @param string        $block_name    Block name this hook belongs to.
	 * @param int           $priority      Priority this hook belongs to.
	 * @return string
	 */
	private function maybe_strip( $block_content, $block, $instance, string $block_name, int $priority ) {
		if ( empty( $this->post_id_stack ) || '' === $block_content ) {
			return $block_content;
		}

		$post_id = (int) end( $this->post_id_stack );
		$block   = is_array( $block ) ? $block : array();

		foreach ( $this->rules[ $block_name ] ?? array() as $rule ) {
			if ( $rule['priority'] !== $priority ) {
				continue;
			}

			$instance = $instance instanceof WP_Block ? $instance : null;
			if ( true === (bool) call_user_func( $rule['callback'], $block, $post_id, $instance ) ) {
				return '';
			}
		}

		return $block_content;
	}

	/**
	 * Attach the core/post-content tracking hooks once.
	 */
	private function track_post_content(): void {
		if ( $this->tracking ) {
			return;
		}

		$this->tracking = true;
		add_filter( 'pre_render_block', array( $this, 'enter_post_content' ), PHP_INT_MAX, 3 );
		add_filter( 'render_block_core/post-content', array( $this, 'leave_post_content' ), 1 );
	}

	/**
	 * Remove the hooks this instance added.
	 */
	private function detach(): void {
		remove_filter( 'pre_render_block', array( $this, 'enter_post_content' ), PHP_INT_MAX );
		remove_filter( 'render_block_core/post-content', array( $this, 'leave_post_content' ), 1 );

		foreach ( $this->hooked as $hook_key => $callback ) {
			list( $block_name, $priority ) = explode( '|', $hook_key );
			remove_filter( 'render_block_' . $block_name, $callback, (int) $priority );
		}
	}
}
