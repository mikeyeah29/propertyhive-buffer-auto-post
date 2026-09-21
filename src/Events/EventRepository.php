<?php
/**
 * Event and destination persistence.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Events;

use Homer\PropertyHiveBufferAutoPost\Database;

class EventRepository {
	/** Persist an event and its channel deliveries. */
	public function create( $event_key, array $payload, array $channels ) {
		global $wpdb;
		$events              = Database::table( 'events' );
		$now                 = gmdate( 'Y-m-d H:i:s' );
		$payload['channels'] = array_values(
			array_map(
				static function ( $channel ) {
					return array(
						'id'      => sanitize_text_field( $channel['id'] ?? '' ),
						'name'    => sanitize_text_field( $channel['name'] ?? $channel['displayName'] ?? '' ),
						'service' => sanitize_key( $channel['service'] ?? '' ),
					);
				},
				$channels
			)
		);

		$inserted = $wpdb->insert(
			$events,
			array(
				'event_key'      => sanitize_text_field( $event_key ),
				'property_id'    => absint( $payload['property_id'] ),
				'event_type'     => sanitize_key( $payload['event_type'] ),
				'source'         => sanitize_key( $payload['source'] ),
				'payload'        => wp_json_encode( $payload ),
				'status'         => 'pending',
				'created_at_gmt' => $now,
				'updated_at_gmt' => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$events} WHERE event_key = %s", $event_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $existing ) {
				return new \WP_Error( 'event_storage', __( 'The event could not be stored.', 'propertyhive-buffer-auto-post' ) );
			}
			$event_id = (int) $existing;
		} else {
			$event_id = (int) $wpdb->insert_id;
		}

		$deliveries = Database::table( 'deliveries' );
		foreach ( $channels as $channel ) {
			if ( empty( $channel['id'] ) ) {
				continue;
			}
			$wpdb->insert(
				$deliveries,
				array(
					'event_id'         => $event_id,
					'channel_id'       => sanitize_text_field( $channel['id'] ),
					'channel_name'     => sanitize_text_field( $channel['name'] ?? $channel['displayName'] ?? '' ),
					'service'          => sanitize_key( $channel['service'] ?? '' ),
					'status'           => 'pending',
					'attempts'         => 0,
					'next_attempt_gmt' => $now,
					'created_at_gmt'   => $now,
					'updated_at_gmt'   => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		$this->schedule_worker();
		return $event_id;
	}

	/** Fetch an event and decode its payload. */
	public function get_event( $event_id ) {
		global $wpdb;
		$table = Database::table( 'events' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			$row->payload_data = json_decode( $row->payload, true );
		}
		return $row;
	}

	/** Update a versioned payload, for example after generating sold media. */
	public function update_payload( $event_id, array $payload ) {
		global $wpdb;
		return $wpdb->update(
			Database::table( 'events' ),
			array(
				'payload'        => wp_json_encode( $payload ),
				'updated_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => absint( $event_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Find due deliveries. */
	public function due_deliveries( $limit = 10 ) {
		global $wpdb;
		$table = Database::table( 'deliveries' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// Table name is constructed from $wpdb->prefix and a fixed suffix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('pending','retry') AND (next_attempt_gmt IS NULL OR next_attempt_gmt <= %s) ORDER BY id ASC LIMIT %d",
				$now,
				absint( $limit )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Mark one delivery as being processed and increment its attempt number. */
	public function claim( $delivery_id ) {
		global $wpdb;
		$table = Database::table( 'deliveries' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		// Table name is constructed from $wpdb->prefix and a fixed suffix.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status='processing', attempts=attempts+1, started_at_gmt=%s, updated_at_gmt=%s WHERE id=%d AND status IN ('pending','retry')",
				$now,
				$now,
				absint( $delivery_id )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === $result;
	}

	/** Update a delivery result. */
	public function update_delivery( $delivery_id, array $values ) {
		global $wpdb;
		$allowed = array( 'status', 'next_attempt_gmt', 'buffer_post_id', 'due_at_gmt', 'error_message', 'started_at_gmt' );
		$data    = array( 'updated_at_gmt' => gmdate( 'Y-m-d H:i:s' ) );
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$data[ $key ] = $values[ $key ];
			}
		}
		return $wpdb->update( Database::table( 'deliveries' ), $data, array( 'id' => absint( $delivery_id ) ) );
	}

	/** Deliveries for an event. */
	public function deliveries_for_event( $event_id ) {
		global $wpdb;
		$table = Database::table( 'deliveries' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id=%d ORDER BY id", $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Roll up an event's status after a delivery changes. */
	public function refresh_event_status( $event_id ) {
		global $wpdb;
		$rows     = $this->deliveries_for_event( $event_id );
		$statuses = wp_list_pluck( $rows, 'status' );
		$status   = 'processing';
		if ( empty( $statuses ) ) {
			$status = 'failed';
		} elseif ( ! array_diff( $statuses, array( 'succeeded' ) ) ) {
			$status = 'succeeded';
		} elseif ( ! array_diff( $statuses, array( 'succeeded', 'failed', 'uncertain' ) ) ) {
			$status = in_array( 'uncertain', $statuses, true ) ? 'uncertain' : 'failed';
		} elseif ( in_array( 'retry', $statuses, true ) || in_array( 'pending', $statuses, true ) ) {
			$status = 'pending';
		}
		$wpdb->update(
			Database::table( 'events' ),
			array(
				'status'         => $status,
				'updated_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => absint( $event_id ) )
		);
	}

	/** Schedule the next background pass. */
	public function schedule_worker( $timestamp = null ) {
		$timestamp = $timestamp ? $timestamp : time() + 5;
		if ( ! wp_next_scheduled( 'phbap_process_queue' ) ) {
			wp_schedule_single_event( $timestamp, 'phbap_process_queue' );
		}
	}
}
