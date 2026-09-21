<?php
/**
 * @package pewresearch/wp-html-processors
 */

declare(strict_types=1);

namespace PRC\Html\Tests;

use PHPUnit\Framework\TestCase;
use PRC\Html\TableProcessor;

use function PRC\Html\parse_table_block_into_array;

/**
 * @covers \PRC\Html\TableProcessor
 */
final class TableProcessorTest extends TestCase {

	public function test_parse_table_block_into_array_extracts_headers_and_rows(): void {
		$html = '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>';

		$data = parse_table_block_into_array( $html );

		$this->assertIsArray( $data );
		$this->assertSame( array( 'A', 'B' ), $data['header'] );
		$this->assertSame( array( array( '1', '2' ) ), $data['rows'] );
		$this->assertSame( array(), $data['footer'] );
	}

	public function test_parse_table_returns_wp_error_when_no_headers(): void {
		$html = '<table><tbody><tr><td>Only</td></tr></tbody></table>';

		$data = parse_table_block_into_array( $html );

		$this->assertInstanceOf( \WP_Error::class, $data );
		$this->assertSame( 'no_table_headers', $data->get_error_code() );
	}

	public function test_table_processor_class_can_be_instantiated(): void {
		$html = '<table><thead><tr><th>X</th></tr></thead><tbody><tr><td>Y</td></tr></tbody></table>';
		$p    = new TableProcessor( $html );
		$out  = $p->get_data();
		$this->assertIsArray( $out );
		$this->assertSame( array( 'X' ), $out['header'] );
	}
}
