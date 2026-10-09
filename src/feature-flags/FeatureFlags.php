<?php
/**
 * Flag registry and per-request evaluation state.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * Holds registered flags, the experiment source, and the assignments made for the current visitor.
 *
 * Use the functions in functions.php. This class exists so tests can reset state.
 */
final class FeatureFlags {

	/**
	 * @var array<string, Flag>
	 */
	private static array $flags = array();

	private static ?ExperimentSource $source = null;

	/**
	 * @var array<Experiment>|null
	 */
	private static ?array $experiments = null;

	/**
	 * Assignments for the current visitor, keyed by flag slug.
	 *
	 * @var array<string, Assignment>
	 */
	private static array $log = array();

	public static function register( Flag $flag ): void {
		self::$flags[ $flag->slug ] = $flag;
	}

	public static function get( string $slug ): ?Flag {
		return self::$flags[ $slug ] ?? null;
	}

	/**
	 * @return array<string, Flag>
	 */
	public static function all(): array {
		ksort( self::$flags );
		return self::$flags;
	}

	public static function set_source( ?ExperimentSource $source ): void {
		self::$source      = $source;
		self::$experiments = null;
	}

	/**
	 * Drop the cached experiment list, for example after an experiment changes in the same request.
	 */
	public static function flush_experiments(): void {
		self::$experiments = null;
	}

	/**
	 * @return array<Experiment>
	 */
	public static function experiments(): array {
		if ( null === self::$experiments ) {
			self::$experiments = null === self::$source ? array() : self::$source->running_experiments();
		}
		return self::$experiments;
	}

	public static function current_visitor(): VisitorContext {
		$groups = null === self::$source ? array() : self::$source->current_groups();
		return VisitorContext::for_user( get_current_user_id(), $groups );
	}

	/**
	 * Evaluate a flag. Only evaluations for the current visitor are logged.
	 *
	 * @param Flag                $flag    Flag.
	 * @param VisitorContext|null $visitor Explicit visitor, or null for the current visitor.
	 */
	public static function evaluate( Flag $flag, ?VisitorContext $visitor = null ): Assignment {
		$assignment = Evaluator::evaluate( $flag, self::experiments(), $visitor ?? self::current_visitor() );
		if ( null === $visitor ) {
			self::$log[ $flag->slug ] = $assignment;
		}
		return $assignment;
	}

	/**
	 * @return array<string, Assignment>
	 */
	public static function log(): array {
		return self::$log;
	}

	public static function reset(): void {
		self::$flags       = array();
		self::$source      = null;
		self::$experiments = null;
		self::$log         = array();
	}
}
