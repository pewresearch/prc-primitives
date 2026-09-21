# PRC Primitives

Shared PHP library for PRC Platform plugins. It holds block rendering helpers, HTML processors built on `WP_HTML_Tag_Processor`, and a delay/cancel helper for Action Scheduler jobs.

The package changes infrequently. Plugins such as email builder and Apple News depend on it as a stable layer.

## Require

```json
{
	"require": {
		"prc/primitives": "^1.0"
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

Namespaces stay the same after the fold from `prc/block-utils` and `prc/wp-html-processors`. Existing `use` statements do not change.

## Trees

| Directory | Namespace | What it contains |
| --- | --- | --- |
| `src/block-utils/` | `PRC\BlockUtils` | `classNames`, `find_block` / `find_blocks`, gap and spacing helpers, `Pagination`, device and URL helpers |
| `src/html-processors/` | `PRC\Html` | `TableProcessor`, `HeadingProcessor`, `ElementFinder`, and the `parse_*` functions |
| `src/delayed-action/` | `PRC\DelayedAction` | Queue and cancel a unique delayed Action Scheduler job |

Callers keep their own post meta and hook callbacks. `DelayedAction` only talks to the scheduler.

```php
use PRC\DelayedAction\ActionSchedulerGateway;
use PRC\DelayedAction\DelayedAction;
use function PRC\BlockUtils\classNames;
use function PRC\Html\parse_table_block_into_array;

$class = classNames( 'foo', array( 'is-active' => $active ) );
$data  = parse_table_block_into_array( $html );

$delayed = new DelayedAction( new ActionSchedulerGateway() );
$queued  = $delayed->queue( 'prc_example_send', array( $post_id ), 'prc-example', 600 );
$cancel  = $delayed->cancel( 'prc_example_send', array( $post_id ), 'prc-example' );
```

`queue()` returns `{ queued: true, scheduled_at: int }` or a `WP_Error`. Codes are `action_scheduler_unavailable` and `schedule_failed`. `cancel()` returns `{ cancelled: true }` or a `WP_Error`. Codes are `no_pending`, `in_progress`, and `action_scheduler_unavailable`.

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
