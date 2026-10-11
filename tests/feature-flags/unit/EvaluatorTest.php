<?php
/**
 * Evaluator decides flags from experiments and a visitor.
 *
 * @package PRC\Primitives\Feature_Flags\Tests
 */

declare(strict_types=1);

namespace PRC\Primitives\Feature_Flags\Tests;

use PHPUnit\Framework\TestCase;
use PRC\Primitives\Feature_Flags\Assignment;
use PRC\Primitives\Feature_Flags\Audience;
use PRC\Primitives\Feature_Flags\Evaluator;
use PRC\Primitives\Feature_Flags\Experiment;
use PRC\Primitives\Feature_Flags\Flag;
use PRC\Primitives\Feature_Flags\VisitorContext;

/**
 * @covers \PRC\Primitives\Feature_Flags\Evaluator
 * @covers \PRC\Primitives\Feature_Flags\Audience
 */
final class EvaluatorTest extends TestCase {

	private const SLUG = 'block-library/carousel-v2';

	private static function experiment( int $id, bool $value, Audience $audience, bool $running = true ): Experiment {
		return new Experiment( $id, "Experiment {$id}", $running, array( self::SLUG => $value ), $audience );
	}

	public function test_no_experiments_returns_the_default(): void {
		$this->assertEquals(
			new Assignment( self::SLUG, true, array() ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel', '', true ), array(), new VisitorContext() )
		);
	}

	public function test_role_match_turns_the_flag_on(): void {
		$experiments = array( self::experiment( 7, true, new Audience( roles: array( 'editor' ) ) ) );

		$this->assertEquals(
			new Assignment( self::SLUG, true, array( 7 ) ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 3, array( 'editor' ) ) )
		);
		$this->assertEquals(
			new Assignment( self::SLUG, false, array() ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 4, array( 'subscriber' ) ) )
		);
	}

	public function test_user_id_match_turns_the_flag_on(): void {
		$experiments = array( self::experiment( 8, true, new Audience( user_ids: array( 42 ) ) ) );

		$this->assertTrue( Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 42 ) )->enabled );
		$this->assertFalse( Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 43 ) )->enabled );
	}

	public function test_logged_out_visitor_never_matches_user_id_zero(): void {
		$experiments = array( self::experiment( 8, true, new Audience( user_ids: array( 0 ) ) ) );

		$this->assertFalse( Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext() )->enabled );
	}

	public function test_group_match_turns_the_flag_on(): void {
		$experiments = array( self::experiment( 9, true, new Audience( groups: array( 'prc_user_accounts' ) ) ) );

		$this->assertEquals(
			new Assignment( self::SLUG, true, array( 9 ) ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 0, array(), array( 'prc_user_accounts' ) ) )
		);
	}

	public function test_off_wins_over_on(): void {
		$experiments = array(
			self::experiment( 1, true, new Audience( everyone: true ) ),
			self::experiment( 2, false, new Audience( roles: array( 'editor' ) ) ),
			self::experiment( 3, false, new Audience( user_ids: array( 5 ) ) ),
		);

		$this->assertEquals(
			new Assignment( self::SLUG, false, array( 2, 3 ) ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 5, array( 'editor' ) ) )
		);
		$this->assertEquals(
			new Assignment( self::SLUG, true, array( 1 ) ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext( 6, array( 'author' ) ) )
		);
	}

	public function test_paused_experiment_is_ignored(): void {
		$experiments = array( self::experiment( 4, true, new Audience( everyone: true ), false ) );

		$this->assertEquals(
			new Assignment( self::SLUG, false, array() ),
			Evaluator::evaluate( new Flag( self::SLUG, 'Carousel' ), $experiments, new VisitorContext() )
		);
	}

	public function test_experiment_that_does_not_set_the_flag_is_ignored(): void {
		$other = new Experiment( 5, 'Other', true, array( 'other/flag' => false ), new Audience( everyone: true ) );

		$this->assertTrue( Evaluator::evaluate( new Flag( self::SLUG, 'Carousel', '', true ), array( $other ), new VisitorContext() )->enabled );
	}

	public function test_audience_from_array_drops_bad_values(): void {
		$this->assertSame(
			array(
				'everyone' => false,
				'roles'    => array( 'editor' ),
				'user_ids' => array( 3, 9 ),
				'groups'   => array( 'prc_user_accounts' ),
			),
			Audience::from_array(
				array(
					'roles'    => array( 'editor', 5, ' ', 'editor' ),
					'user_ids' => array( '3', 0, 9, 'abc' ),
					'groups'   => array( 'prc_user_accounts' ),
					'extra'    => true,
				)
			)->to_array()
		);
	}

	public function test_parse_flags_drops_invalid_slugs(): void {
		$this->assertSame(
			array(
				'a/b' => true,
				'c-d' => false,
			),
			Experiment::parse_flags(
				array(
					'a/b'     => 1,
					'c-d'     => 0,
					'Bad Key' => true,
					3         => true,
				)
			)
		);
	}
}
