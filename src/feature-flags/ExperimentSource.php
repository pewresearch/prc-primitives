<?php
/**
 * Where experiments come from.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * Storage adapter. The prc-feature-flags plugin implements it with a post type.
 */
interface ExperimentSource {

	/**
	 * Experiments that are running now.
	 *
	 * @return array<Experiment>
	 */
	public function running_experiments(): array;

	/**
	 * VIP Vary_Cache groups the current visitor belongs to.
	 *
	 * @return array<string>
	 */
	public function current_groups(): array;
}
