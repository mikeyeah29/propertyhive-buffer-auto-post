<?php

use Homer\PropertyHiveBufferAutoPost\Buffer\Client;
use PHPUnit\Framework\TestCase;

class BufferClientTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['phbap_test_http_request']  = null;
		$GLOBALS['phbap_test_http_response'] = null;
	}

	public function test_empty_variables_are_encoded_as_a_graphql_object() {
		$GLOBALS['phbap_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						'account' => array(
							'organizations' => array(
								array(
									'id'   => 'org-1',
									'name' => 'Homer Estates',
								),
							),
						),
					),
				)
			),
		);

		$organizations = ( new Client( 'test-key' ) )->organisations();

		$this->assertCount( 1, $organizations );
		$this->assertStringContainsString( '"variables":{}', $GLOBALS['phbap_test_http_request']['args']['body'] );
	}

	public function test_non_success_graphql_message_is_returned() {
		$GLOBALS['phbap_test_http_response'] = array(
			'response' => array( 'code' => 400 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'errors' => array(
						array( 'message' => 'GraphQL variables must be an object.' ),
					),
				)
			),
		);

		$result = ( new Client( 'test-key' ) )->organisations();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'GraphQL variables must be an object.', $result->get_error_message() );
	}

	public function test_mutation_error_includes_safe_response_and_asset_diagnostics() {
		$GLOBALS['phbap_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'x-request-id' => 'request-123' ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						'createPost' => array(
							'__typename' => 'InvalidInputError',
							'message'    => 'Image could not be read from its URL.',
						),
					),
				)
			),
		);
		$payload                             = array(
			'caption'          => 'Test',
			'delivery_mode'    => 'queue',
			'validated_images' => array( 'https://example.com/uploads/listing-1024x683.jpg' ),
			'save_to_draft'    => true,
		);

		$result = ( new Client( 'test-key' ) )->create_post( $payload, 'channel-1', 'instagram' );

		$this->assertSame( 'failed', $result['result'] );
		$this->assertStringContainsString( 'HTTP 200', $result['message'] );
		$this->assertStringContainsString( 'type=InvalidInputError', $result['message'] );
		$this->assertStringContainsString( 'request=request-123', $result['message'] );
		$this->assertStringContainsString( 'assets=1', $result['message'] );
		$this->assertStringContainsString( 'listing-1024x683.jpg', $result['message'] );
	}
}
