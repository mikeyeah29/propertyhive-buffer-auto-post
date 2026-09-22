<?php
/**
 * Background Buffer delivery and media cleanup.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

use Homer\PropertyHiveBufferAutoPost\Buffer\Client;
use Homer\PropertyHiveBufferAutoPost\Contracts\Hookable;
use Homer\PropertyHiveBufferAutoPost\Events\EventRepository;
use Homer\PropertyHiveBufferAutoPost\Media\BufferImageProcessor;
use Homer\PropertyHiveBufferAutoPost\Media\ImageValidator;
use Homer\PropertyHiveBufferAutoPost\Media\OverlayRenderer;

class Worker implements Hookable {
	private $repository;
	private $processor;
	private $validator;
	private $overlay;
	private $logger;

	public function __construct( EventRepository $repository, BufferImageProcessor $processor, ImageValidator $validator, OverlayRenderer $overlay, Logger $logger ) {
		$this->repository = $repository;
		$this->processor  = $processor;
		$this->validator  = $validator;
		$this->overlay    = $overlay;
		$this->logger     = $logger;
	}

	public function register_hooks() {
		add_action( 'phbap_process_queue', array( $this, 'process' ) );
		add_action( 'phbap_recovery_sweep', array( $this, 'recovery_sweep' ) );
		add_action( 'phbap_cleanup_media', array( $this, 'cleanup_media' ) );
	}

	/** Deliver a bounded batch. */
	public function process() {
		if ( ! $this->acquire_lock() ) {
			return;
		}

		foreach ( $this->repository->due_deliveries( 10 ) as $delivery ) {
			if ( ! $this->repository->claim( $delivery->id ) ) {
				continue;
			}
			$delivery->attempts = (int) $delivery->attempts + 1;
			$this->deliver( $delivery );
		}

		$this->release_lock();
		if ( ! empty( $this->repository->due_deliveries( 1 ) ) ) {
			$this->repository->schedule_worker( time() + 5 );
		}
	}

	/** Recover abandoned work conservatively and wake due jobs. */
	public function recovery_sweep() {
		global $wpdb;
		$table   = Database::table( 'deliveries' );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$message = __( 'The worker stopped during delivery. The result is uncertain and was not retried.', 'propertyhive-buffer-auto-post' );
		// Table name is constructed from $wpdb->prefix and a fixed suffix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status='processing' AND started_at_gmt < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $stale as $delivery ) {
			$event = $this->repository->get_event( $delivery->event_id );
			$this->finish( $delivery, $event, 'uncertain', $message );
		}
		if ( ! empty( $this->repository->due_deliveries( 1 ) ) ) {
			$this->repository->schedule_worker();
		}
		$this->cleanup_media();
	}

	/** Deliver one destination. */
	private function deliver( $delivery ) {
		$event = $this->repository->get_event( $delivery->event_id );
		if ( ! $event || ! is_array( $event->payload_data ) ) {
			$this->finish( $delivery, null, 'failed', __( 'The queued event payload is missing.', 'propertyhive-buffer-auto-post' ) );
			return;
		}

		$payload  = $event->payload_data;
		$channels = ! empty( $payload['channels'] ) ? (array) $payload['channels'] : array(
			array(
				'id'      => $delivery->channel_id,
				'service' => $delivery->service,
			),
		);
		if ( empty( $payload['validated_images'] ) ) {
			$processed = $this->processor->process( (array) $payload['images'] );
			if ( is_wp_error( $processed ) ) {
				$this->finish( $delivery, $event, 'failed', $processed->get_error_message() );
				return;
			}
			$payload['images'] = $processed;
			$images            = $this->validator->validate( $processed, $channels );
			if ( is_wp_error( $images ) ) {
				$this->finish( $delivery, $event, 'failed', $images->get_error_message() );
				return;
			}
			$payload['validated_images'] = $images;

			if ( 'sold' === $payload['event_type'] ) {
				if ( empty( $payload['overlay_id'] ) ) {
					$this->finish( $delivery, $event, 'failed', __( 'A sold overlay has not been configured.', 'propertyhive-buffer-auto-post' ) );
					return;
				}
				$base_path = '';
				foreach ( $processed as $processed_image ) {
					if ( $images[0] === $processed_image['url'] ) {
						$base_path = isset( $processed_image['path'] ) ? $processed_image['path'] : '';
						break;
					}
				}
				$derived = $this->overlay->render( $images[0], $payload['overlay_id'], $event->event_key, $base_path );
				if ( is_wp_error( $derived ) ) {
					$this->finish( $delivery, $event, 'failed', $derived->get_error_message() );
					return;
				}
				if ( 'https' !== wp_parse_url( $derived['url'], PHP_URL_SCHEME ) ) {
					wp_delete_file( $derived['path'] );
					$this->finish( $delivery, $event, 'failed', __( 'The generated sold image does not have a public HTTPS URL.', 'propertyhive-buffer-auto-post' ) );
					return;
				}
				$payload['derived_path']        = $derived['path'];
				$payload['derived_url']         = $derived['url'];
				$payload['validated_images'][0] = $derived['url'];
			}
			$this->repository->update_payload( $event->id, $payload );
		}

		$client = new Client( Settings::api_key() );
		$result = $client->create_post( $payload, $delivery->channel_id, $delivery->service );
		if ( 'succeeded' === $result['result'] ) {
			$this->repository->update_delivery(
				$delivery->id,
				array(
					'status'           => 'succeeded',
					'buffer_post_id'   => $result['post_id'],
					'due_at_gmt'       => $this->normalise_due_at( $result['due_at'] ),
					'error_message'    => '',
					'next_attempt_gmt' => null,
				)
			);
			$this->log( $delivery, $event, 'succeeded', $result['message'], $result['post_id'] );
		} elseif ( 'retry' === $result['result'] && $delivery->attempts < 3 ) {
			$delays = array(
				1 => 300,
				2 => 1800,
			);
			$delay  = max( $delays[ $delivery->attempts ], absint( $result['retry_after'] ?? 0 ) );
			$this->repository->update_delivery(
				$delivery->id,
				array(
					'status'           => 'retry',
					'next_attempt_gmt' => gmdate( 'Y-m-d H:i:s', time() + min( 21600, $delay ) ),
					'error_message'    => $result['message'],
				)
			);
			$this->repository->schedule_worker( time() + min( 21600, $delay ) );
			$this->log( $delivery, $event, 'retry', $result['message'] );
		} else {
			$status = 'uncertain' === $result['result'] ? 'uncertain' : 'failed';
			$this->finish( $delivery, $event, $status, $result['message'] );
			return;
		}

		$this->repository->refresh_event_status( $event->id );
		if ( ! wp_next_scheduled( 'phbap_cleanup_media' ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'phbap_cleanup_media' );
		}
	}

	private function finish( $delivery, $event, $status, $message ) {
		$this->repository->update_delivery(
			$delivery->id,
			array(
				'status'           => $status,
				'error_message'    => $message,
				'next_attempt_gmt' => null,
			)
		);
		$this->log( $delivery, $event, $status, $message );
		if ( $event ) {
			$this->repository->refresh_event_status( $event->id );
		}
	}

	private function log( $delivery, $event, $status, $message, $post_id = '' ) {
		$this->logger->add(
			array(
				'property_id'    => $event ? $event->property_id : 0,
				'event_type'     => $event ? $event->event_type : 'unknown',
				'source'         => $event ? $event->source : 'automatic',
				'channel_id'     => $delivery->channel_id,
				'channel_name'   => $delivery->channel_name,
				'service'        => $delivery->service,
				'status'         => $status,
				'attempt'        => $delivery->attempts,
				'buffer_post_id' => $post_id,
				'message'        => $message,
			)
		);
	}

	/** Remove derived files only after every provider post is terminal. */
	public function cleanup_media() {
		global $wpdb;
		$table = Database::table( 'events' );
		// The path is JSON-encoded and may contain escaped slashes, so inspect recent sold events in PHP.
		$events = $wpdb->get_results( "SELECT * FROM {$table} WHERE event_type='sold' ORDER BY id DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$client = new Client( Settings::api_key() );

		foreach ( $events as $event ) {
			$payload = json_decode( $event->payload, true );
			if ( empty( $payload['derived_path'] ) ) {
				continue;
			}
			$terminal = true;
			foreach ( $this->repository->deliveries_for_event( $event->id ) as $delivery ) {
				if ( 'succeeded' === $delivery->status && $delivery->buffer_post_id ) {
					$provider_status = $client->post_status( $delivery->buffer_post_id );
					if ( is_wp_error( $provider_status ) || ! in_array( $provider_status, array( 'sent', 'error' ), true ) ) {
						$terminal = false;
						break;
					}
				} elseif ( ! in_array( $delivery->status, array( 'failed', 'uncertain' ), true ) ) {
					$terminal = false;
					break;
				}
			}
			if ( $terminal ) {
				if ( is_readable( $payload['derived_path'] ) ) {
					wp_delete_file( $payload['derived_path'] );
				}
				$payload['derived_path'] = '';
				$payload['derived_url']  = '';
				$this->repository->update_payload( $event->id, $payload );
			}
		}
	}

	private function acquire_lock() {
		$lock = (int) get_option( 'phbap_worker_lock', 0 );
		if ( $lock && $lock > time() - 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		if ( $lock ) {
			delete_option( 'phbap_worker_lock' );
		}
		return add_option( 'phbap_worker_lock', time(), '', false );
	}

	private function release_lock() {
		delete_option( 'phbap_worker_lock' );
	}

	private function normalise_due_at( $value ) {
		if ( ! $value ) {
			return null;
		}
		$timestamp = strtotime( $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}
}
