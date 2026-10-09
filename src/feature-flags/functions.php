<?php
/**
 * Feature flag functions for consumer plugins.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * Register a flag. Call it on `init` or earlier.
 *
 * @param string                                                   $slug Slug such as `block-library/carousel-v2`.
 * @param array{ label?: string, description?: string, default?: bool } $args Flag details.
 * @throws \InvalidArgumentException When the slug is invalid.
 */
function register_feature_flag( string $slug, array $args = array() ): Flag {
	$flag = new Flag(
		$slug,
		(string) ( $args['label'] ?? $slug ),
		(string) ( $args['description'] ?? '' ),
		(bool) ( $args['default'] ?? false )
	);
	FeatureFlags::register( $flag );
	return $flag;
}

/**
 * Whether a flag is on for the current visitor, or for an explicit visitor.
 *
 * An unregistered slug returns false.
 *
 * @param string              $slug    Flag slug.
 * @param VisitorContext|null $visitor Explicit visitor. Evaluations for an explicit visitor are not logged.
 */
function is_feature_enabled( string $slug, ?VisitorContext $visitor = null ): bool {
	$flag = FeatureFlags::get( $slug );
	if ( null === $flag ) {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html( sprintf( 'Feature flag "%s" is not registered.', $slug ) ), '2.2.0' );
		}
		return false;
	}
	return FeatureFlags::evaluate( $flag, $visitor )->enabled;
}

/**
 * Interactivity API context for a block. Merge the result into the array you pass to `wp_interactivity_data_wp_context()`.
 *
 * @param array<string> $slugs Flag slugs the block reads.
 * @return array{ featureFlags: array<string, bool> }
 */
function get_feature_flags_context( array $slugs ): array {
	$flags = array();
	foreach ( $slugs as $slug ) {
		$flags[ $slug ] = is_feature_enabled( $slug );
	}
	return array( 'featureFlags' => $flags );
}

/**
 * Every registered flag, sorted by slug.
 *
 * @return array<string, Flag>
 */
function get_registered_feature_flags(): array {
	return FeatureFlags::all();
}

/**
 * Plug in experiment storage. The prc-feature-flags plugin calls this.
 *
 * @param ExperimentSource|null $source Source, or null to remove it.
 */
function set_experiment_source( ?ExperimentSource $source ): void {
	FeatureFlags::set_source( $source );
}

/**
 * Running experiments whose audience matches the current visitor, or an explicit visitor.
 *
 * @param VisitorContext|null $visitor Explicit visitor, or null for the current visitor.
 * @return array<Experiment>
 */
function get_visitor_experiments( ?VisitorContext $visitor = null ): array {
	$visitor ??= FeatureFlags::current_visitor();
	return array_values(
		array_filter(
			FeatureFlags::experiments(),
			static fn( Experiment $experiment ) => $experiment->running && $experiment->audience->matches( $visitor )
		)
	);
}

/**
 * Assignments made for the current visitor in this request, keyed by flag slug.
 *
 * @return array<string, Assignment>
 */
function get_evaluation_log(): array {
	return FeatureFlags::log();
}
