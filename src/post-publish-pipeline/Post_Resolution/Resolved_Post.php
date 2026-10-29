<?php
/**
 * A local post after URL resolution.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline\Post_Resolution;

/**
 * Resolved post fields used by Slack and Workspaces REST.
 */
final class Resolved_Post {

	/**
	 * Construct a resolved post snapshot.
	 *
	 * @param int    $post_id         Local post ID.
	 * @param string $post_type       Post type slug.
	 * @param string $post_type_label Singular label.
	 * @param string $title           Post title.
	 * @param string $status          Post status.
	 * @param string $url             Canonical permalink.
	 * @throws \InvalidArgumentException If the ID, type, or URL is empty.
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $post_type,
		public readonly string $post_type_label,
		public readonly string $title,
		public readonly string $status,
		public readonly string $url
	) {
		if ( $post_id <= 0 || '' === $post_type || '' === $url ) {
			throw new \InvalidArgumentException( 'A resolved post needs an ID, type, and URL.' );
		}
	}

	/**
	 * Whether this post is an attachment.
	 */
	public function is_attachment(): bool {
		return 'attachment' === $this->post_type;
	}

	/**
	 * Serialize for session storage.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'post_id'         => $this->post_id,
			'post_type'       => $this->post_type,
			'post_type_label' => $this->post_type_label,
			'title'           => $this->title,
			'status'          => $this->status,
			'url'             => $this->url,
		);
	}

	/**
	 * Restore from session storage.
	 *
	 * @param array<string,mixed> $data Stored fields.
	 */
	public static function from_array( array $data ): ?self {
		$post_id = (int) ( $data['post_id'] ?? 0 );
		$type    = (string) ( $data['post_type'] ?? '' );
		$url     = (string) ( $data['url'] ?? '' );
		if ( $post_id <= 0 || '' === $type || '' === $url ) {
			return null;
		}

		try {
			return new self(
				$post_id,
				$type,
				(string) ( $data['post_type_label'] ?? $type ),
				(string) ( $data['title'] ?? '' ),
				(string) ( $data['status'] ?? '' ),
				$url
			);
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}
}
