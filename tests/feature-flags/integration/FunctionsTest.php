<?php
/**
 * Consumer functions read flags through a registered experiment source.
 *
 * @package PRC\Primitives\Feature_Flags\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags\Tests;

use PRC\Primitives\Feature_Flags\Assignment;
use PRC\Primitives\Feature_Flags\Audience;
use PRC\Primitives\Feature_Flags\Experiment;
use PRC\Primitives\Feature_Flags\ExperimentSource;
use PRC\Primitives\Feature_Flags\FeatureFlags;
use PRC\Primitives\Feature_Flags\VisitorContext;
use WP_UnitTestCase;

use function PRC\Primitives\Feature_Flags\get_evaluation_log;
use function PRC\Primitives\Feature_Flags\get_feature_flags_context;
use function PRC\Primitives\Feature_Flags\get_registered_feature_flags;
use function PRC\Primitives\Feature_Flags\is_feature_enabled;
use function PRC\Primitives\Feature_Flags\register_feature_flag;
use function PRC\Primitives\Feature_Flags\set_experiment_source;

/**
 * @covers \PRC\Primitives\Feature_Flags\FeatureFlags
 */
final class FunctionsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		FeatureFlags::reset();
		parent::tear_down();
	}

	private static function source( array $experiments, array $groups = array() ): ExperimentSource {
		return new class( $experiments, $groups ) implements ExperimentSource {
			public function __construct( private array $experiments, private array $groups ) {}

			public function running_experiments(): array {
				return $this->experiments;
			}

			public function current_groups(): array {
				return $this->groups;
			}
		};
	}

	public function test_without_a_source_flags_use_their_default(): void {
		register_feature_flag( 'demo/on', array( 'default' => true ) );
		register_feature_flag( 'demo/off' );

		$this->assertTrue( is_feature_enabled( 'demo/on' ) );
		$this->assertFalse( is_feature_enabled( 'demo/off' ) );
	}

	public function test_unregistered_flag_is_false(): void {
		$this->setExpectedIncorrectUsage( 'PRC\Primitives\Feature_Flags\is_feature_enabled' );

		$this->assertFalse( is_feature_enabled( 'demo/missing' ) );
	}

	public function test_current_editor_gets_the_experiment_and_it_is_logged(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		register_feature_flag( 'demo/carousel', array( 'label' => 'Carousel' ) );
		set_experiment_source(
			self::source( array( new Experiment( 11, 'Editors', true, array( 'demo/carousel' => true ), new Audience( roles: array( 'editor' ) ) ) ) )
		);

		$this->assertTrue( is_feature_enabled( 'demo/carousel' ) );
		$this->assertEquals(
			array( 'demo/carousel' => new Assignment( 'demo/carousel', true, array( 11 ) ) ),
			get_evaluation_log()
		);
	}

	public function test_explicit_visitor_is_evaluated_but_not_logged(): void {
		register_feature_flag( 'demo/carousel' );
		set_experiment_source(
			self::source( array( new Experiment( 12, 'User 5', true, array( 'demo/carousel' => true ), new Audience( user_ids: array( 5 ) ) ) ) )
		);

		$this->assertTrue( is_feature_enabled( 'demo/carousel', new VisitorContext( 5 ) ) );
		$this->assertSame( array(), get_evaluation_log() );
	}

	public function test_group_from_source_reaches_logged_out_visitor(): void {
		wp_set_current_user( 0 );
		register_feature_flag( 'demo/gated' );
		set_experiment_source(
			self::source(
				array( new Experiment( 13, 'Accounts', true, array( 'demo/gated' => true ), new Audience( groups: array( 'prc_user_accounts' ) ) ) ),
				array( 'prc_user_accounts' )
			)
		);

		$this->assertTrue( is_feature_enabled( 'demo/gated' ) );
	}

	public function test_interactivity_context_shape(): void {
		register_feature_flag( 'demo/on', array( 'default' => true ) );
		register_feature_flag( 'demo/off' );

		$this->assertSame(
			array(
				'featureFlags' => array(
					'demo/on'  => true,
					'demo/off' => false,
				),
			),
			get_feature_flags_context( array( 'demo/on', 'demo/off' ) )
		);
	}

	public function test_registered_flags_are_sorted_by_slug(): void {
		register_feature_flag( 'zeta/flag' );
		register_feature_flag( 'alpha/flag', array( 'label' => 'Alpha' ) );

		$this->assertSame( array( 'alpha/flag', 'zeta/flag' ), array_keys( get_registered_feature_flags() ) );
		$this->assertSame( 'Alpha', get_registered_feature_flags()['alpha/flag']->label );
	}

	public function test_invalid_slug_throws(): void {
		$this->expectException( \InvalidArgumentException::class );

		register_feature_flag( 'Not A Slug' );
	}
}
