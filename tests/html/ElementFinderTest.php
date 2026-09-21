<?php
/**
 * @package pewresearch/wp-html-processors
 */

declare(strict_types=1);

namespace PRC\Html\Tests;

use PHPUnit\Framework\TestCase;
use PRC\Html\ElementFinder;

/**
 * @covers \PRC\Html\ElementFinder
 */
final class ElementFinderTest extends TestCase {

	public function test_get_markup_returns_inner_html_for_matching_id(): void {
		$html = '<div><section id="target"><p>Inside</p></section></div>';

		$finder = new ElementFinder( $html, 'section', 'target' );
		$markup = $finder->get_markup( 'inside' );

		$this->assertSame( '<p>Inside</p>', $markup );
	}

	public function test_get_markup_returns_null_when_not_found(): void {
		$html = '<div><p>No section</p></div>';

		$finder = new ElementFinder( $html, 'section', 'missing' );
		$markup = $finder->get_markup();

		$this->assertNull( $markup );
	}
}
