<?php
/**
 * REST fields the pipeline adds to tracked post types.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Tests;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class RestFieldsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type(
			'quiz',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		register_taxonomy( 'formats', array( 'post', 'quiz' ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unregister_taxonomy( 'formats' );
		unregister_post_type( 'quiz' );
		parent::tear_down();
	}

	/**
	 * @param string               $route  REST route.
	 * @param array<string, mixed> $params Query params.
	 * @return array<mixed>
	 */
	private function get( string $route, array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * @param string        $post_type Post type.
	 * @param array<string> $formats   Formats term slugs.
	 */
	private function label_for( string $post_type, array $formats ): string {
		$post_id = self::factory()->post->create( array( 'post_type' => $post_type ) );
		wp_set_object_terms( $post_id, $formats, 'formats' );
		$route = 'quiz' === $post_type ? '/wp/v2/quiz/' : '/wp/v2/posts/';
		return $this->get( $route . $post_id )['label'];
	}

	public function test_label_comes_from_the_formats_term_on_the_primary_site(): void {
		$this->assertSame( 'Report', $this->label_for( 'post', array( 'report' ) ) );
		$this->assertSame( 'Short Read', $this->label_for( 'post', array( 'short-read' ) ) );
		$this->assertSame( 'Report', $this->label_for( 'post', array() ) );
	}

	public function test_post_type_fallback_label_overrides_the_formats_term(): void {
		$this->assertSame( 'Quiz', $this->label_for( 'quiz', array( 'report' ) ) );
	}

	public function test_canonical_url_prefers_the_redirect_meta(): void {
		$post_id = self::factory()->post->create();
		$this->assertSame( get_permalink( $post_id ), $this->get( "/wp/v2/posts/{$post_id}" )['canonical_url'] );

		update_post_meta( $post_id, '_redirect', 'https://example.com/moved/' );
		$this->assertSame( 'https://example.com/moved/', $this->get( "/wp/v2/posts/{$post_id}" )['canonical_url'] );
	}

	public function test_word_count_counts_rendered_words(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => '<p>Three <strong>short</strong> words</p>' ) );

		$this->assertSame( 3, $this->get( "/wp/v2/posts/{$post_id}" )['word_count'] );
	}

	public function test_post_parent_is_exposed_and_queryable(): void {
		$parent = self::factory()->post->create();
		$child  = self::factory()->post->create( array( 'post_parent' => $parent ) );
		self::factory()->post->create();

		$this->assertSame( $parent, $this->get( "/wp/v2/posts/{$child}" )['post_parent'] );
		$this->assertSame( array( $child ), array_column( $this->get( '/wp/v2/posts', array( 'post_parent' => $parent ) ), 'id' ) );
	}

	public function test_untracked_post_types_get_no_fields(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$data = $this->get( "/wp/v2/pages/{$page_id}" );

		$this->assertSame( $page_id, $data['id'] );
		$this->assertArrayNotHasKey( 'label', $data );
		$this->assertArrayNotHasKey( 'canonical_url', $data );
	}
}
