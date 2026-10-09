<?php
/**
 * The result of evaluating one flag for one visitor.
 *
 * @package PRC\Primitives\Feature_Flags
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags;

/**
 * Whether the flag is on, and which experiments decided it.
 *
 * `experiment_ids` is empty when the flag fell back to its default.
 */
final class Assignment {

	/**
	 * @param string     $flag           Flag slug.
	 * @param bool       $enabled        Final value.
	 * @param array<int> $experiment_ids Experiments that set the final value.
	 */
	public function __construct(
		public readonly string $flag,
		public readonly bool $enabled,
		public readonly array $experiment_ids = array()
	) {}

	public function from_experiment(): bool {
		return array() !== $this->experiment_ids;
	}
}
