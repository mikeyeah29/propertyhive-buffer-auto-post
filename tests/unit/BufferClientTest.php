<?php

use Homer\PropertyHiveBufferAutoPost\Buffer\Client;
use PHPUnit\Framework\TestCase;

class BufferClientTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['phbap_test_http_request'] = null;
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
}
