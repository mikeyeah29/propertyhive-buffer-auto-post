<?php
/**
 * Buffer GraphQL API client.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Buffer;

class Client {
	private $api_key;
	private $endpoint;

	public function __construct( $api_key ) {
		$this->api_key  = trim( (string) $api_key );
		$default        = defined( 'PHBAP_BUFFER_API_URL' ) ? PHBAP_BUFFER_API_URL : 'https://api.buffer.com';
		$this->endpoint = esc_url_raw( apply_filters( 'phbap_buffer_api_url', $default ) );
	}

	/** Retrieve Buffer organisations as a connection test. */
	public function organisations() {
		$query = 'query PhbapOrganisations { account { organizations { id name ownerEmail } } }';
		$data  = $this->request( $query, array(), 'query' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['account']['organizations'] ) && is_array( $data['account']['organizations'] ) ? $data['account']['organizations'] : array();
	}

	/** Retrieve Facebook and Instagram channels for an organisation. */
	public function channels( $organisation_id ) {
		$query = 'query PhbapChannels($input: ChannelsInput!) { channels(input: $input) { id name displayName service avatar isQueuePaused } }';
		$data  = $this->request( $query, array( 'input' => array( 'organizationId' => (string) $organisation_id ) ), 'query' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$channels = isset( $data['channels'] ) && is_array( $data['channels'] ) ? $data['channels'] : array();
		return array_values(
			array_filter(
				$channels,
				static function ( $channel ) {
					return isset( $channel['service'] ) && in_array( strtolower( (string) $channel['service'] ), array( 'facebook', 'instagram' ), true );
				}
			)
		);
	}

	/** Create one post for one Buffer channel. */
	public function create_post( array $payload, $channel_id, $service ) {
		$input = array(
			'text'           => (string) $payload['caption'],
			'channelId'      => (string) $channel_id,
			'schedulingType' => 'automatic',
			'mode'           => 'daily' === $payload['delivery_mode'] ? 'customScheduled' : 'addToQueue',
			'assets'         => array_map(
				static function ( $url ) {
					return array( 'image' => array( 'url' => (string) $url ) );
				},
				(array) $payload['validated_images']
			),
			'source'         => 'propertyhive-buffer-auto-post',
		);
		if ( ! empty( $payload['due_at'] ) && 'daily' === $payload['delivery_mode'] ) {
			$input['dueAt'] = $payload['due_at'];
		}
		if ( ! empty( $payload['save_to_draft'] ) ) {
			$input['saveToDraft'] = true;
		}
		if ( 'instagram' === strtolower( (string) $service ) ) {
			$input['metadata'] = array(
				'instagram' => array(
					'type'              => 'post',
					'shouldShareToFeed' => true,
				),
			);
		}

		$query    = 'mutation PhbapCreatePost($input: CreatePostInput!) { createPost(input: $input) { __typename ... on PostActionSuccess { post { id dueAt status } } ... on MutationError { message } } }';
		$response = $this->request_raw( $query, array( 'input' => $input ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'result'  => 'uncertain',
				'message' => $response->get_error_message(),
			);
		}

		if ( 429 === $response['code'] ) {
			return array(
				'result'      => 'retry',
				'message'     => __( 'Buffer rate-limited the request.', 'propertyhive-buffer-auto-post' ),
				'retry_after' => $response['retry_after'],
			);
		}
		if ( in_array( $response['code'], array( 401, 403 ), true ) ) {
			return array(
				'result'  => 'failed',
				'message' => __( 'Buffer rejected the API key or its permissions.', 'propertyhive-buffer-auto-post' ),
			);
		}
		if ( $response['code'] >= 500 ) {
			return array(
				'result'  => 'uncertain',
				'message' => __( 'Buffer returned a server error; delivery may have been accepted, so it was not retried.', 'propertyhive-buffer-auto-post' ),
			);
		}

		$body = $response['body'];
		if ( ! empty( $body['errors'] ) ) {
			$message = $this->error_message( $body['errors'] );
			$codes   = array();
			foreach ( $body['errors'] as $error ) {
				if ( ! empty( $error['extensions']['code'] ) ) {
					$codes[] = (string) $error['extensions']['code'];
				}
			}
			if ( in_array( 'RATE_LIMIT_EXCEEDED', $codes, true ) || preg_match( '/rate|thrott|too many/i', $message ) ) {
				return array(
					'result'      => 'retry',
					'message'     => $message,
					'retry_after' => 300,
				);
			}
			if ( in_array( 'UNEXPECTED', $codes, true ) ) {
				return array(
					'result'  => 'uncertain',
					'message' => __( 'Buffer returned an unexpected error; delivery may have been accepted, so it was not retried.', 'propertyhive-buffer-auto-post' ),
				);
			}
			return array(
				'result'  => 'failed',
				'message' => $message,
			);
		}

		$result = $body['data']['createPost'] ?? array();
		if ( isset( $result['__typename'] ) && 'PostActionSuccess' === $result['__typename'] && ! empty( $result['post']['id'] ) ) {
			return array(
				'result'          => 'succeeded',
				'post_id'         => sanitize_text_field( $result['post']['id'] ),
				'due_at'          => sanitize_text_field( $result['post']['dueAt'] ?? '' ),
				'provider_status' => sanitize_key( $result['post']['status'] ?? '' ),
				'message'         => __( 'Buffer accepted the post.', 'propertyhive-buffer-auto-post' ),
			);
		}
		if ( ! empty( $result['message'] ) ) {
			return array(
				'result'  => 'failed',
				'message' => sanitize_text_field( $result['message'] ),
			);
		}
		return array(
			'result'  => 'uncertain',
			'message' => __( 'Buffer returned an unexpected response; the post was not retried.', 'propertyhive-buffer-auto-post' ),
		);
	}

	/** Retrieve a post status for derived-media cleanup. */
	public function post_status( $post_id ) {
		$query = 'query PhbapPost($input: PostInput!) { post(input: $input) { id status dueAt } }';
		$data  = $this->request( $query, array( 'input' => array( 'id' => (string) $post_id ) ), 'query' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return isset( $data['post']['status'] ) ? sanitize_key( $data['post']['status'] ) : '';
	}

	/** Execute a GraphQL query and return its data object. */
	private function request( $query, array $variables, $operation ) {
		unset( $operation );
		$response = $this->request_raw( $query, $variables );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['code'] ) {
			if ( ! empty( $response['body']['errors'] ) ) {
				return new \WP_Error( 'buffer_graphql', $this->error_message( $response['body']['errors'] ) );
			}
			/* translators: %d: HTTP response status code. */
			return new \WP_Error( 'buffer_http', sprintf( __( 'Buffer returned HTTP %d.', 'propertyhive-buffer-auto-post' ), $response['code'] ) );
		}
		if ( ! empty( $response['body']['errors'] ) ) {
			return new \WP_Error( 'buffer_graphql', $this->error_message( $response['body']['errors'] ) );
		}
		if ( ! isset( $response['body']['data'] ) || ! is_array( $response['body']['data'] ) ) {
			return new \WP_Error( 'buffer_response', __( 'Buffer returned an unreadable response.', 'propertyhive-buffer-auto-post' ) );
		}
		return $response['body']['data'];
	}

	/** Execute a GraphQL request without exposing request details. */
	private function request_raw( $query, array $variables ) {
		if ( '' === $this->api_key ) {
			return new \WP_Error( 'buffer_key', __( 'A Buffer API key is required.', 'propertyhive-buffer-auto-post' ) );
		}
		$response = wp_safe_remote_post(
			$this->endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						// GraphQL requires an object here; PHP's empty array encodes as JSON [].
						'variables' => $variables ? $variables : new \stdClass(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'buffer_transport', __( 'The Buffer request did not complete. Its delivery state is unknown.', 'propertyhive-buffer-auto-post' ) );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			if ( 429 === $code || in_array( $code, array( 401, 403 ), true ) || $code >= 500 ) {
				$decoded = array();
			} else {
				return new \WP_Error( 'buffer_json', __( 'Buffer returned an unreadable response.', 'propertyhive-buffer-auto-post' ) );
			}
		}
		$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		return array(
			'code'        => $code,
			'body'        => $decoded,
			'retry_after' => $retry > 0 ? min( 21600, $retry ) : 300,
		);
	}

	/** Collapse GraphQL errors to a safe human-readable message. */
	private function error_message( array $errors ) {
		$messages = array();
		foreach ( $errors as $error ) {
			if ( isset( $error['message'] ) ) {
				$messages[] = sanitize_text_field( $error['message'] );
			}
		}
		return $messages ? implode( ' ', $messages ) : __( 'Buffer rejected the request.', 'propertyhive-buffer-auto-post' );
	}
}
