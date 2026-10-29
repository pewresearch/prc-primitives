<?php
/**
 * Post publish pipeline functions for consumer plugins.
 *
 * @package PRC\Primitives\Post_Publish_Pipeline
 */

declare(strict_types=1);

namespace PRC\Primitives\Post_Publish_Pipeline;

/**
 * Register the pipeline hooks. Call it once, early, from the site's mu-plugin loader. Later calls do nothing.
 */
function boot(): void {
	Pipeline::boot();
}

/**
 * Queue an async tier event for a post, for writes that suppressed the sync tier.
 *
 * @param int    $post_id Post ID.
 * @param string $event   Lifecycle event: publish, update, unpublish, untrash, trash, or incremental_save.
 */
function enqueue_async_event( int $post_id, string $event ): void {
	Pipeline::enqueue_async_event( $post_id, $event );
}

/**
 * User ID of the editor whose write queued the running `prc_platform_async_on_*` hook, or 0 outside one.
 */
function get_async_editor_id(): int {
	return Pipeline::get_async_editor_id();
}
