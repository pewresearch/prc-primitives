<?php
/**
 * Post_URL_Resolver maps pasted URLs to local posts.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Tests;

use PRC\Primitives\Post_Publish_Pipeline\Post_Resolution\Post_URL_Resolver;
use PRC\Primitives\Post_Publish_Pipeline\Post_Resolution\Resolved_Post;
use PRC\Primitives\Post_Publish_Pipeline\Post_Resolution\Resolved_Post_URL;
use PRC\Primitives\Post_Publish_Pipeline\Post_Resolution\Unresolved_Post_URL;
use WP_UnitTestCase;

final class PostUrlResolverTest extends WP_UnitTestCase {

	private int $published = 0;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
		$this->published = self::factory()->post->create(
			array(
				'post_title'  => '25 years after 9/11',
				'post_name'   => 'slug',
				'post_date'   => '2026-09-03 10:00:00',
				'post_status' => 'publish',
			)
		);
	}

	private function resolved_id( string $candidate ): ?int {
		$result = Post_URL_Resolver::resolve( $candidate );
		return $result instanceof Resolved_Post_URL ? $result->post->post_id : null;
	}

	private function unresolved_reason( string $candidate ): ?string {
		$result = Post_URL_Resolver::resolve( $candidate );
		return $result instanceof Unresolved_Post_URL ? $result->reason : null;
	}

	public function test_production_hosts_and_slack_tokens_resolve_to_the_local_post(): void {
		$this->assertSame( $this->published, $this->resolved_id( 'https://www.pewresearch.org/2026/09/03/slug/' ) );
		$this->assertSame( $this->published, $this->resolved_id( 'https://pewresearch.org/2026/09/03/slug/' ) );
		$this->assertSame( $this->published, $this->resolved_id( '<https://www.pewresearch.org/2026/09/03/slug/>' ) );
		$this->assertSame( $this->published, $this->resolved_id( '<https://www.pewresearch.org/2026/09/03/slug/|25 years after 9/11>' ) );
	}

	public function test_resolved_post_snapshot(): void {
		$result = Post_URL_Resolver::resolve( 'https://www.pewresearch.org/2026/09/03/slug/' );

		$this->assertInstanceOf( Resolved_Post_URL::class, $result );
		$this->assertSame( 'http://example.org/2026/09/03/slug/', $result->url );
		$this->assertSame(
			array(
				'post_id'         => $this->published,
				'post_type'       => 'post',
				'post_type_label' => 'Post',
				'title'           => '25 years after 9/11',
				'status'          => 'publish',
				'url'             => 'http://example.org/2026/09/03/slug/',
			),
			$result->post->to_array()
		);
	}

	public function test_query_string_ids_resolve(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertSame( $draft, $this->resolved_id( "https://www.pewresearch.org/?preview_id={$draft}" ) );
		$this->assertSame( $draft, $this->resolved_id( "https://www.pewresearch.org/wp-admin/post.php?post={$draft}&action=edit" ) );
		$this->assertSame( $draft, $this->resolved_id( "https://www.pewresearch.org/?p={$draft}" ) );
	}

	public function test_unpublished_dated_permalink_resolves_by_date_and_slug(): void {
		$draft = self::factory()->post->create(
			array(
				'post_name'   => 'unpublished-slug',
				'post_date'   => '2026-09-03 10:00:00',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( $draft, $this->resolved_id( 'https://www.pewresearch.org/politics/2026/09/03/unpublished-slug/' ) );
	}

	public function test_undated_paths_are_not_slug_matched(): void {
		self::factory()->post->create(
			array(
				'post_name'   => 'topic',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( Unresolved_Post_URL::REASON_NOT_FOUND, $this->unresolved_reason( 'http://example.org/topic/' ) );
		$this->assertSame( Unresolved_Post_URL::REASON_NOT_FOUND, $this->unresolved_reason( 'http://example.org/topic/about/' ) );
	}

	public function test_misses_report_why(): void {
		$this->assertSame( Unresolved_Post_URL::REASON_FOREIGN_HOST, $this->unresolved_reason( 'https://www.nytimes.com/2026/09/03/world/example.html' ) );
		$this->assertSame( Unresolved_Post_URL::REASON_NOT_FOUND, $this->unresolved_reason( 'https://www.pewresearch.org/does-not-exist/' ) );
	}

	public function test_non_urls_return_null(): void {
		$this->assertNull( Post_URL_Resolver::resolve( 'not a url' ) );
		$this->assertNull( Post_URL_Resolver::resolve( '' ) );
		$this->assertNull( Post_URL_Resolver::resolve( '<not-a-url>' ) );
		$this->assertInstanceOf( Unresolved_Post_URL::class, Post_URL_Resolver::resolve( 'https://www.nytimes.com/' ) );
	}

	public function test_extract_candidates_and_unwrap_in_text(): void {
		$this->assertSame(
			array(
				'https://www.pewresearch.org/religion/2026/09/03/slug/',
				'https://pewresearch.org/other/',
			),
			Post_URL_Resolver::extract_candidates( 'see <https://www.pewresearch.org/religion/2026/09/03/slug/|this> and https://pewresearch.org/other/.' )
		);
		$this->assertSame(
			'copy for https://www.pewresearch.org/religion/2026/09/03/slug/ please',
			Post_URL_Resolver::unwrap_in_text( 'copy for <https://www.pewresearch.org/religion/2026/09/03/slug/|this report> please' )
		);
	}

	public function test_resolved_post_round_trips_through_an_array(): void {
		$post = new Resolved_Post( 7, 'post', 'Post', 'Title', 'draft', 'http://example.org/?p=7' );

		$this->assertEquals( $post, Resolved_Post::from_array( $post->to_array() ) );
		$this->assertNull( Resolved_Post::from_array( array( 'post_type' => 'post' ) ) );
	}
}
