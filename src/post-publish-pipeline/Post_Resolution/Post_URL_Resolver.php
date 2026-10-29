<?php
/**
 * Map a pasted URL to a local post.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Post_Resolution;

/**
 * Maps a pasted URL to a local post.
 */
final class Post_URL_Resolver {

	/**
	 * Production and staging hosts that map onto home_url().
	 *
	 * @var string[]
	 */
	private const ALIAS_HOSTS = array(
		'www.pewresearch.org',
		'pewresearch.org',
		'alpha.pewresearch.org',
		'beta.pewresearch.org',
		'canary.pewresearch.org',
	);

	/**
	 * Accept a plain URL, `<url>`, or `<url|label>`.
	 *
	 * Null means the candidate is not a valid HTTP(S) URL.
	 *
	 * @param string $candidate Plain URL or Slack token.
	 * @return Resolved_Post_URL|Unresolved_Post_URL|null
	 */
	public static function resolve( string $candidate ): Resolved_Post_URL|Unresolved_Post_URL|null {
		$url = self::normalize_candidate( $candidate );
		if ( null === $url ) {
			return null;
		}

		$localized = self::localize_url( $url );
		if ( null === $localized ) {
			return new Unresolved_Post_URL( $url, Unresolved_Post_URL::REASON_FOREIGN_HOST );
		}

		$post_id  = self::post_id_from_url( $localized );
		$resolved = $post_id > 0 ? self::hydrate( $post_id, $localized ) : null;
		if ( $resolved instanceof Resolved_Post_URL ) {
			return $resolved;
		}

		return new Unresolved_Post_URL( $localized, Unresolved_Post_URL::REASON_NOT_FOUND );
	}

	/**
	 * Replace Slack `<url>` / `<url|label>` tokens with bare URLs.
	 *
	 * @param string $text Message text that may contain Slack URL tokens.
	 */
	public static function unwrap_in_text( string $text ): string {
		$unwrapped = preg_replace( '#<(https?://[^>|]+)(?:\|[^>]*)?>#', '$1', $text );
		return is_string( $unwrapped ) ? $unwrapped : $text;
	}

	/**
	 * Collect Slack tokens and bare http(s) URLs in document order.
	 *
	 * @param string $text Message text.
	 * @return string[]
	 */
	public static function extract_candidates( string $text ): array {
		$found = array();
		if ( preg_match_all( '#<(https?://[^>|]+)(?:\|[^>]*)?>|https?://[^\s<>]+#', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$candidate = isset( $match[1] ) && '' !== $match[1] ? $match[1] : rtrim( $match[0], '.,;:)' );
				if ( ! in_array( $candidate, $found, true ) ) {
					$found[] = $candidate;
				}
			}
		}
		return $found;
	}

	/**
	 * Unwrap a Slack token and require an http(s) URL.
	 *
	 * @param string $candidate Raw candidate from extract_candidates() or resolve().
	 */
	private static function normalize_candidate( string $candidate ): ?string {
		$raw = trim( $candidate );
		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '#^<(https?://[^>|]+)(?:\|[^>]*)?>$#', $raw, $matches ) ) {
			$raw = $matches[1];
		}

		$parts = self::parse_url( $raw );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Rewrite an alias host onto home_url(), including a missing site path prefix.
	 *
	 * @param string $url Normalized http(s) URL.
	 */
	private static function localize_url( string $url ): ?string {
		$parts = self::parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$home       = function_exists( 'home_url' ) ? home_url() : '';
		$home_parts = self::parse_url( $home );
		$home_host  = is_array( $home_parts ) ? (string) ( $home_parts['host'] ?? '' ) : '';
		$home_path  = is_array( $home_parts ) ? rtrim( (string) ( $home_parts['path'] ?? '' ), '/' ) : '';
		$scheme     = is_array( $home_parts ) && ! empty( $home_parts['scheme'] )
			? (string) $home_parts['scheme']
			: 'https';

		if ( '' === $home_host || ! self::is_local_or_alias_host( (string) $parts['host'], $home_host ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '' !== $home_path && $path !== $home_path && ! str_starts_with( $path, $home_path . '/' ) ) {
			$path = $home_path . '/' . ltrim( $path, '/' );
		}

		$localized = $scheme . '://' . $home_host . $path;
		if ( ! empty( $parts['query'] ) ) {
			$localized .= '?' . $parts['query'];
		}

		return $localized;
	}

	/**
	 * Whether the incoming host is this site or a known pewresearch.org alias.
	 *
	 * @param string $incoming Incoming host.
	 * @param string $home     Host from home_url().
	 */
	private static function is_local_or_alias_host( string $incoming, string $home ): bool {
		if ( self::normalize_host( $incoming ) === self::normalize_host( $home ) ) {
			return true;
		}

		$incoming_host = strtolower( $incoming );
		$incoming_bare = self::normalize_host( $incoming );
		foreach ( self::ALIAS_HOSTS as $alias ) {
			if ( strtolower( $alias ) === $incoming_host || self::normalize_host( $alias ) === $incoming_bare ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercase the host and drop a leading www.
	 *
	 * @param string $host Hostname.
	 */
	private static function normalize_host( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Resolve a localized URL to a post ID, or 0.
	 *
	 * @param string $url Localized URL on this site.
	 */
	private static function post_id_from_url( string $url ): int {
		$parts = self::parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return 0;
		}

		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query_args );
			foreach ( array( 'post', 'preview_id', 'p', 'page_id', 'attachment_id' ) as $key ) {
				if ( ! empty( $query_args[ $key ] ) && is_numeric( $query_args[ $key ] ) ) {
					return (int) $query_args[ $key ];
				}
			}

			$site_editor_id = self::site_editor_post_id( $parts, $query_args );
			if ( $site_editor_id > 0 ) {
				return $site_editor_id;
			}
		}

		$permalink_id = self::permalink_post_id( $url );
		if ( $permalink_id > 0 ) {
			return $permalink_id;
		}

		$attachment_id = self::attachment_post_id( $url );
		if ( $attachment_id > 0 ) {
			return $attachment_id;
		}

		return self::unpublished_permalink_post_id( $url );
	}

	/**
	 * Resolve a Site Editor URL to a post ID.
	 *
	 * @param array<string,mixed> $parts      Parsed URL parts.
	 * @param array<string,mixed> $query_args Parsed query args.
	 */
	private static function site_editor_post_id( array $parts, array $query_args ): int {
		$path           = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$is_site_editor = str_ends_with( $path, 'site-editor.php' );
		$post_type_arg  = ( ! empty( $query_args['postType'] ) && is_string( $query_args['postType'] ) )
			? $query_args['postType']
			: '';

		if ( ! empty( $query_args['p'] ) && is_string( $query_args['p'] ) ) {
			$editor_path = rawurldecode( $query_args['p'] );
			$editor_path = '/' . ltrim( $editor_path, '/' );
			$from_path   = self::site_editor_path_to_post_id( $editor_path, $is_site_editor );
			if ( $from_path > 0 ) {
				return $from_path;
			}
		}

		if ( empty( $query_args['postId'] ) ) {
			return 0;
		}

		return self::site_editor_entity_id_to_post_id( (string) $query_args['postId'], $post_type_arg );
	}

	/**
	 * Resolve `/postType/id` Site Editor paths.
	 *
	 * @param string $editor_path    Path from the `p` query arg.
	 * @param bool   $is_site_editor Whether the URL is site-editor.php.
	 */
	private static function site_editor_path_to_post_id( string $editor_path, bool $is_site_editor ): int {
		$editor_path = explode( '?', $editor_path, 2 )[0];

		if ( ! preg_match( '#^/(?P<type>[a-z0-9_-]+)/(?P<rest>.+)$#i', $editor_path, $matches ) ) {
			return 0;
		}

		$post_type = $matches['type'];
		$rest      = trim( $matches['rest'], '/' );
		$known     = array( 'wp_block', 'page', 'wp_template', 'wp_template_part', 'post' );
		if ( ! $is_site_editor && ! in_array( $post_type, $known, true ) ) {
			return 0;
		}

		if ( is_numeric( $rest ) ) {
			$post = function_exists( 'get_post' ) ? get_post( (int) $rest ) : null;
			if ( ! is_object( $post ) || (string) $post->post_type !== $post_type ) {
				return 0;
			}
			return (int) $post->ID;
		}

		return self::block_template_id_to_post_id( self::normalize_block_template_id( $rest ), $post_type );
	}

	/**
	 * Resolve a numeric or theme//slug entity id.
	 *
	 * @param string $entity_id Raw postId query value.
	 * @param string $post_type Optional post type from the query.
	 */
	private static function site_editor_entity_id_to_post_id( string $entity_id, string $post_type ): int {
		$entity_id = rawurldecode( $entity_id );
		if ( is_numeric( $entity_id ) ) {
			$post_id = (int) $entity_id;
			if ( $post_id <= 0 ) {
				return 0;
			}
			if ( '' !== $post_type ) {
				$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
				if ( ! is_object( $post ) || (string) $post->post_type !== $post_type ) {
					return 0;
				}
			}
			return $post_id;
		}

		$type = '' !== $post_type ? $post_type : 'wp_template_part';
		return self::block_template_id_to_post_id( self::normalize_block_template_id( $entity_id ), $type );
	}

	/**
	 * Normalize a template id to theme//slug.
	 *
	 * @param string $rest Path remainder or postId value.
	 */
	private static function normalize_block_template_id( string $rest ): string {
		$rest = rawurldecode( $rest );
		$rest = trim( $rest, '/' );
		if ( str_contains( $rest, '//' ) ) {
			return $rest;
		}
		if ( str_contains( $rest, '/' ) ) {
			$parts = explode( '/', $rest, 2 );
			return $parts[0] . '//' . $parts[1];
		}
		$theme = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '';
		return '' !== $theme ? $theme . '//' . $rest : $rest;
	}

	/**
	 * Look up a block template wp_id.
	 *
	 * @param string $template_id   theme//slug id.
	 * @param string $template_type wp_template or wp_template_part.
	 */
	private static function block_template_id_to_post_id( string $template_id, string $template_type ): int {
		if ( ! in_array( $template_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			return 0;
		}
		if ( ! function_exists( 'get_block_template' ) ) {
			return 0;
		}
		$template = get_block_template( $template_id, $template_type );
		if ( ! is_object( $template ) || empty( $template->wp_id ) ) {
			return 0;
		}
		return (int) $template->wp_id;
	}

	/**
	 * Resolve a published permalink.
	 *
	 * @param string $localized_url Localized URL on this site.
	 */
	private static function permalink_post_id( string $localized_url ): int {
		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			return (int) wpcom_vip_url_to_postid( $localized_url );
		}
		if ( function_exists( 'url_to_postid' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- VIP helper used when present.
			return (int) url_to_postid( $localized_url );
		}
		return 0;
	}

	/**
	 * Resolve an attachment file URL.
	 *
	 * @param string $localized_url Localized URL on this site.
	 */
	private static function attachment_post_id( string $localized_url ): int {
		if ( function_exists( 'wpcom_vip_attachment_url_to_postid' ) ) {
			return (int) wpcom_vip_attachment_url_to_postid( $localized_url );
		}
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return 0;
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid -- VIP helper used when present.
		return (int) attachment_url_to_postid( $localized_url );
	}

	/**
	 * Resolve a pretty permalink that is not yet published.
	 *
	 * @param string $localized_url Localized URL on this site.
	 */
	private static function unpublished_permalink_post_id( string $localized_url ): int {
		if ( ! class_exists( '\WP_Query' ) ) {
			return 0;
		}

		$parts     = self::parse_url( $localized_url );
		$path      = is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
		$home_path = function_exists( 'home_url' ) ? (string) ( self::parse_url( home_url(), PHP_URL_PATH ) ?? '' ) : '';
		$home_path = rtrim( $home_path, '/' );
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$query_vars = self::permalink_query_from_path( $path );
		if ( null === $query_vars ) {
			return 0;
		}

		$query = new \WP_Query(
			array_merge(
				$query_vars,
				array(
					'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
					'post_type'      => 'any',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			)
		);
		if ( empty( $query->posts[0] ) || ! is_object( $query->posts[0] ) ) {
			return 0;
		}

		return (int) $query->posts[0]->ID;
	}

	/**
	 * Build WP_Query vars from a dated pretty-permalink path.
	 *
	 * Matches `YYYY/MM/DD/slug` and `{team}/YYYY/MM/DD/slug`. Other paths
	 * return null so section fronts and archives are not treated as slugs.
	 *
	 * @param string $parent_path Path after home_url().
	 * @return array<string,int|string>|null
	 */
	private static function permalink_query_from_path( string $parent_path ): ?array {
		$parent_path = trim( $parent_path, '/' );
		if ( '' === $parent_path ) {
			return null;
		}

		if ( preg_match( '#^(?:[^/]+/)?([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/([^/]+)$#', $parent_path, $matches ) ) {
			return array(
				'year'     => (int) $matches[1],
				'monthnum' => (int) $matches[2],
				'day'      => (int) $matches[3],
				'name'     => $matches[4],
			);
		}

		return null;
	}

	/**
	 * Hydrate a post ID into a resolved URL result.
	 *
	 * @param int    $post_id         Local post ID.
	 * @param string $normalized_url Localized candidate URL.
	 */
	private static function hydrate( int $post_id, string $normalized_url ): ?Resolved_Post_URL {
		if ( ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! is_object( $post ) || (int) $post->ID <= 0 ) {
			return null;
		}

		$post_type       = (string) $post->post_type;
		$post_type_label = self::post_type_label( $post );
		$title           = function_exists( 'get_the_title' ) ? (string) get_the_title( $post ) : (string) ( $post->post_title ?? '' );
		$status          = (string) ( $post->post_status ?? '' );
		$canonical       = function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : '';
		$url             = '' !== $canonical ? $canonical : $normalized_url;

		try {
			return new Resolved_Post_URL(
				$normalized_url,
				new Resolved_Post(
					(int) $post->ID,
					$post_type,
					$post_type_label,
					$title,
					$status,
					$url
				)
			);
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Singular post type label for the resolved snapshot.
	 *
	 * @param object $post WP_Post-like object.
	 */
	private static function post_type_label( object $post ): string {
		$post_type = (string) $post->post_type;
		if ( 'wp_block' === $post_type ) {
			return function_exists( '__' ) ? __( 'Pattern', 'prc-post-publish-pipeline' ) : 'Pattern';
		}
		if ( 'wp_template' === $post_type ) {
			return function_exists( '__' ) ? __( 'Template', 'prc-post-publish-pipeline' ) : 'Template';
		}
		if ( 'wp_template_part' === $post_type ) {
			return function_exists( '__' ) ? __( 'Template part', 'prc-post-publish-pipeline' ) : 'Template part';
		}

		if ( ! function_exists( 'get_post_type_object' ) ) {
			return $post_type;
		}

		$post_type_object = get_post_type_object( $post_type );
		return (string) ( $post_type_object->labels->singular_name ?? $post_type );
	}

	/**
	 * Parse a URL with wp_parse_url() when WordPress is loaded.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* component or -1 for all parts.
	 * @return array<string,mixed>|string|int|null|false
	 */
	private static function parse_url( string $url, int $component = -1 ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url, $component );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not always loaded in unit tests.
		return parse_url( $url, $component );
	}
}
