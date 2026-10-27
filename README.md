# PRC Primitives

Shared PHP library for PRC Platform plugins. It holds block rendering helpers, HTML processors built on `WP_HTML_Tag_Processor`, a delay/cancel helper for Action Scheduler jobs, a URL to post ID resolver, term data store relationships that keep a post type and a taxonomy in 1:1 sync, feature flags, and the post publish pipeline lifecycle hooks.

The package changes infrequently. Plugins such as email builder and Apple News depend on it as a stable layer.

## Require

```json
{
	"require": {
		"prc/primitives": "^2.0"
	},
	"repositories": {
		"prc-primitives": {
			"type": "vcs",
			"url": "https://origin.cursor.com/pewresearch/prc-primitives.git"
		}
	}
}
```

Then run `composer update prc/primitives`. Clone access to `origin.cursor.com` needs Origin git credentials (`origin auth login` locally).

Every public symbol lives under `PRC\Primitives\<Module>`. The package replaces `prc/block-utils`, `prc/wp-html-processors`, `prc/url-helper`, and `prc/term-data-store`. Drop those from `require` when you add `prc/primitives`.

## Upgrade from 1.x

2.0 moves every namespace. There are no aliases for the 1.x names.

| 1.x | 2.x |
| --- | --- |
| `PRC\BlockUtils` | `PRC\Primitives\BlockUtils` |
| `PRC\Html` | `PRC\Primitives\HTML_Processors` |
| `PRC\DelayedAction` | `PRC\Primitives\DelayedAction` |
| `PRC\TDS` | `PRC\Primitives\TDS` |
| `PRC\URL_Helper` | `PRC\Primitives\URL_Helper\URL_Helper` |

The codemod ships with the package. Run it over your code after `composer update`, then run it again with `--check` to confirm nothing is left:

```bash
php vendor/prc/primitives/bin/migrate-namespaces.php src/
php vendor/prc/primitives/bin/migrate-namespaces.php --check src/
```

It rewrites PHP, strings with doubled backslashes, JSON, and Markdown. It skips `vendor/`, `node_modules/`, `build/`, lock files, and changelogs. Add `--skip=<path substring>` for anything else to leave alone.

## Trees

| Directory | Namespace | What it contains |
| --- | --- | --- |
| `src/block-utils/` | `PRC\Primitives\BlockUtils` | `classNames`, `find_block` / `find_blocks`, gap and spacing helpers, `Pagination`, `strip_block_from_post_content`, device and URL helpers |
| `src/html-processors/` | `PRC\Primitives\HTML_Processors` | `TableProcessor`, `HeadingProcessor`, `ElementFinder`, and the `parse_*` functions |
| `src/delayed-action/` | `PRC\Primitives\DelayedAction` | Queue and cancel a unique delayed Action Scheduler job |
| `src/url-helper/` | `PRC\Primitives\URL_Helper` | `URL_Helper`, which resolves a preview link, wp-admin edit link, or canonical URL to a post ID |
| `src/term-data-store/` | `PRC\Primitives\TDS` | `add_relationship`, `get_related_post`, `get_related_term`, and the sync hooks. Forked from 10up/term-data-store; see `src/term-data-store/LICENSE` |
| `src/feature-flags/` | `PRC\Primitives\Feature_Flags` | `register_feature_flag`, `is_feature_enabled`, `get_feature_flags_context`, the pure `Evaluator`, and the `ExperimentSource` storage contract |
| `src/post-publish-pipeline/` | `PRC\Primitives\Post_Publish_Pipeline` | `boot`, the `prc_platform_on_*` and `prc_platform_async_on_*` lifecycle hooks, `enqueue_async_event`, the pipeline REST fields, and `Post_Resolution\Post_URL_Resolver` |

Callers keep their own post meta and hook callbacks. `DelayedAction` only talks to the scheduler.

```php
use PRC\Primitives\DelayedAction\ActionSchedulerGateway;
use PRC\Primitives\DelayedAction\DelayedAction;
use PRC\Primitives\URL_Helper\URL_Helper;
use function PRC\Primitives\TDS\add_relationship;
use function PRC\Primitives\BlockUtils\classNames;
use function PRC\Primitives\HTML_Processors\parse_table_block_into_array;

$class = classNames( 'foo', array( 'is-active' => $active ) );
$data  = parse_table_block_into_array( $html );

$delayed = new DelayedAction( new ActionSchedulerGateway() );
$queued  = $delayed->queue( 'prc_example_send', array( $post_id ), 'prc-example', 600 );
$cancel  = $delayed->cancel( 'prc_example_send', array( $post_id ), 'prc-example' );

$post_id = ( new URL_Helper( $url ) )->post_id;

add_action( 'init', fn() => add_relationship( 'staff', 'bylines' ), 20 );
```

`queue()` returns `{ queued: true, scheduled_at: int }` or a `WP_Error`. Codes are `action_scheduler_unavailable` and `schedule_failed`. `cancel()` returns `{ cancelled: true }` or a `WP_Error`. Codes are `no_pending`, `in_progress`, and `action_scheduler_unavailable`.

`URL_Helper::$post_id` is an `int` or a `WP_Error` with code `404`. It is `null` when the input is not a valid URL.

Call `add_relationship()` after both the post type and the taxonomy are registered. It throws `Invalid_Input_Exception` when either is missing or already paired. Pass `false` as the third argument to keep post permalinks unchanged. Post and term meta keys are `tds_term_id` and `tds_post_id`.

### Strip a block from post content

`strip_block_from_post_content()` empties a block when it renders inside `core/post-content` and your callback returns `true`. Blocks in the template, the editor, and REST data are not touched.

```php
use function PRC\Primitives\BlockUtils\strip_block_from_post_content;

strip_block_from_post_content(
	'core/post-date',
	fn( array $block, int $post_id, ?\WP_Block $instance ): bool => 'prc_email_campaign' === get_post_type( $post_id )
);
```

Pass one block name or an array of names. The optional third argument is the `render_block_{name}` priority. The callback gets the parsed block, the ID of the innermost post being rendered, and the `WP_Block` instance. The primitive does not check post types. Put that condition in the callback. Register rules before the page renders, for example on `init`. Several rules can share a block, and any rule that returns `true` removes it.

The rule does not affect a block that a `pre_render_block` filter short-circuits, at any priority. The primitive tracks post content from `render_block_context`, which WordPress only runs for blocks that will render. Call `PostContentBlockFilter::reset()` in test teardown to remove the hooks.

### Feature flags

Register a flag on `init`, then check it where the behavior changes.

```php
use function PRC\Primitives\Feature_Flags\register_feature_flag;
use function PRC\Primitives\Feature_Flags\is_feature_enabled;

add_action(
	'init',
	fn() => register_feature_flag(
		'block-library/carousel-v2',
		array(
			'label'       => 'Carousel v2',
			'description' => 'Swap the carousel for the v2 slider.',
			'default'     => false,
		)
	)
);

if ( is_feature_enabled( 'block-library/carousel-v2' ) ) {
	// New behavior.
}
```

A slug is lowercase letters, digits, and dashes, with at most one slash. An unregistered slug returns `false` and triggers `_doing_it_wrong`.

Experiments decide the value. An experiment is a set of `slug => bool` overrides plus an audience. The audience is everyone, every logged-in WP user, a list of WP roles, a list of WP user IDs, or a list of VIP Vary_Cache groups. Logged-out visitors never match the logged-in audience. The library does not store experiments. The `prc-feature-flags` plugin stores them as a post type and calls `set_experiment_source()`. Without a source, every flag returns its default.

When more than one running experiment matches the visitor and sets the same flag, off wins. Any experiment can therefore act as a kill switch.

A block that needs a flag in the browser passes it through the Interactivity API context:

```php
use function PRC\Primitives\Feature_Flags\get_feature_flags_context;

echo wp_interactivity_data_wp_context(
	array_merge(
		array( 'items' => $items ),
		get_feature_flags_context( array( 'block-library/carousel-v2' ) )
	)
);
```

The block reads `context.featureFlags[ 'block-library/carousel-v2' ]`. The value is rendered into cached HTML. It is correct for logged-in users, because VIP bypasses the page cache for them, and for each Vary_Cache group, because each group gets its own cached variant. Logged-out visitors outside every group only match experiments that target everyone.

`get_evaluation_log()` returns every assignment made for the current visitor in this request, keyed by slug. Each `Assignment` names the experiments that decided it. Pass a `VisitorContext` to `is_feature_enabled()` to evaluate for someone else, for example in WP-CLI. Those evaluations are not logged.

`log_error()` also sends the error to Sentry when the wp-sentry plugin is active and has a DSN.

Call `FeatureFlags::reset()` in test teardown.

### Post publish pipeline

The pipeline turns each `wp_after_insert_post` into one set of PRC lifecycle hooks. Nothing registers until the site calls `boot()`. The PRC Platform mu-plugin loader calls it once. Later calls do nothing.

```php
\PRC\Primitives\Post_Publish_Pipeline\boot();
```

The pipeline tracks `post`, `feature`, `quiz`, `fact-sheet`, `short-read`, `events`, `mini-course`, `press-release`, `block_module`, `collections`, and `prc_newsletter`. Filter `prc_platform_post_publish_pipeline_post_types` to change the list. It skips autosaves, revisions, and writes made while `DOING_AUTOSAVE` or `DOING_AJAX` is true. WP-CLI writes still fire the hooks, but under WP-CLI the post object keeps placeholder `label` and `canonical_url` values.

Sync hooks fire during the save request. Each has a per-post-type variant, for example `prc_platform_on_short-read_publish`.

| Hook | Fires when | Args |
| --- | --- | --- |
| `prc_platform_on_post_init` | A new post is inserted as `auto-draft`. Only the ID is meaningful. The variant is `prc_platform_on_{post_type}_init`. | `$post` |
| `prc_platform_on_status_transition` | The status differs from the prior status, including the first insert | `$post`, `$current`, `$prior` (null on insert), `$has_blocks` |
| `prc_platform_on_incremental_save` | Both the prior and the current status are `draft` or `publish` | `$post` |
| `prc_platform_on_publish` | `draft` or `future` moves to `publish` | `$post`, `$has_blocks` |
| `prc_platform_on_update` | Any other save that leaves the post in `publish` | `$post`, `$has_blocks` |
| `prc_platform_on_unpublish` | `publish` moves to `draft` | `$post`, `$has_blocks` |
| `prc_platform_on_trash` | The post moves to `trash` | `$post`, `$has_blocks` |
| `prc_platform_on_untrash` | `trash` moves to `publish` or `draft` | `$post`, `$has_blocks` |

`$post` is a copy of the `WP_Post` with three more fields: `label`, `canonical_url`, and `visibility` (the `_postVisibility` meta, or `false`). Filter `prc_platform_wp_post_object` to add fields. The pipeline fills its own at priority 1.

Publish, update, unpublish, trash, and untrash also queue one Action Scheduler job, `prc_platform_post_publish_pipeline_async_dispatch` in group `prc-post-publish-pipeline`. The job loads the post again and fires `prc_platform_async_on_{event}` and `prc_platform_async_on_{post_type}_{event}` with `$post` and `$has_blocks`. It drops itself when the status has moved on, for example a publish job for a post that is now a draft. Async hooks have no prior status. `get_async_editor_id()` returns the user whose save queued the running job.

To queue an async event for a write that skipped the sync hooks, call `enqueue_async_event()`. It also accepts `incremental_save`, which the pipeline never queues itself.

```php
\PRC\Primitives\Post_Publish_Pipeline\enqueue_async_event( $post_id, 'publish' );
```

| Hook | Type | Use |
| --- | --- | --- |
| `prc_platform_post_publish_pipeline_should_process` | Filter (`$should`, `$post_id`, `$post_after`, `$is_update`, `$post_before`) | Return `false` to skip every sync hook and async job for one write |
| `prc_platform_post_publish_pipeline_should_enqueue_async` | Filter (`$should`, `$post_id`, `$event`, `$post_type`) | Return `false` to skip the async job. Sync hooks still fire. |
| `prc_platform_post_publish_pipeline_async_event_enqueued` | Action (`$post_id`, `$event`, `$editor_id`) | Queue a dedicated job that must not wait behind the shared one |

Tracked post types also get four REST fields. `label` is the content format: the first `formats` term on the primary site (`PRC_PRIMARY_SITE_ID`, default 1), the first `category` elsewhere, and `Report` when there is none. Post types such as `quiz` and `short-read` always use a fixed label. `canonical_url` is the `_redirect` meta when set, otherwise the permalink. `post_parent` and `word_count` round out the set, and `/wp/v2/posts?post_parent=<id>` filters by parent.

`Post_Resolution\Post_URL_Resolver::resolve()` maps a pasted URL or Slack `<url|label>` token to a `Resolved_Post_URL` or an `Unresolved_Post_URL` with reason `foreign_host` or `not_found`. It returns `null` for text that is not an http(s) URL. URLs on `pewresearch.org` and its staging hosts resolve against `home_url()`.

## Tests

```bash
composer install
bash bin/install-wp-tests.sh wordpress_test root <password> 127.0.0.1 latest true
composer test
```

Refresh the design-system palette fixture from a sibling `prc-platform` checkout:

```bash
bash bin/sync-design-system.sh
```
