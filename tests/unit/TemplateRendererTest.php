<?php

use Homer\PropertyHiveBufferAutoPost\Content\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererTest extends TestCase {
	public function test_replaces_supported_placeholders_and_preserves_lines() {
		$renderer = new TemplateRenderer();
		$result   = $renderer->render(
			"{address}\nWas {previous_price}, now {price}\n{property_url}",
			array(
				'address'        => '1 High Street',
				'previous_price' => '£500,000',
				'price'          => '£475,000',
				'property_url'   => 'https://example.test/property/1',
			)
		);
		$this->assertSame( "1 High Street\nWas £500,000, now £475,000\nhttps://example.test/property/1", $result );
	}

	public function test_reports_unknown_placeholders() {
		$renderer = new TemplateRenderer();
		$this->assertSame( array( '{agent_email}' ), $renderer->invalid_placeholders( '{address} {agent_email}' ) );
	}

	public function test_render_names_unsupported_placeholders() {
		$renderer = new TemplateRenderer();
		$result   = $renderer->render( 'New listing: {property_title}', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'The caption template contains unsupported placeholders: {property_title}', $result->get_error_message() );
	}

	public function test_missing_reliable_values_become_empty_text() {
		$renderer = new TemplateRenderer();
		$this->assertSame( 'Bedrooms:', $renderer->render( 'Bedrooms: {bedrooms}', array() ) );
	}
}
