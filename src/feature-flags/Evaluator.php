<?php
/**
 * Pure flag evaluation.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * Decide a flag from the running experiments that match a visitor.
 *
 * Off wins. When any matching experiment sets the flag off, it is off, so any experiment can act as a kill switch.
 */
final class Evaluator {

	/**
	 * @param Flag              $flag        Flag to evaluate.
	 * @param array<Experiment> $experiments Candidate experiments.
	 * @param VisitorContext    $visitor     Visitor.
	 */
	public static function evaluate( Flag $flag, array $experiments, VisitorContext $visitor ): Assignment {
		$on  = array();
		$off = array();
		foreach ( $experiments as $experiment ) {
			if ( ! $experiment->running || ! array_key_exists( $flag->slug, $experiment->flags ) ) {
				continue;
			}
			if ( ! $experiment->audience->matches( $visitor ) ) {
				continue;
			}
			if ( $experiment->flags[ $flag->slug ] ) {
				$on[] = $experiment->id;
			} else {
				$off[] = $experiment->id;
			}
		}

		if ( array() !== $off ) {
			return new Assignment( $flag->slug, false, $off );
		}
		if ( array() !== $on ) {
			return new Assignment( $flag->slug, true, $on );
		}
		return new Assignment( $flag->slug, $flag->default_value );
	}
}
