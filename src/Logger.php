<?php
/**
 * Sanitised delivery logging.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

class Logger {
	/** Add a log row and retain only the newest 100. */
	public function add( array $entry ) {
		global $wpdb;
		$table = Database::table( 'logs' );

		$wpdb->insert(
			$table,
			array(
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'property_id'    => absint( $entry['property_id'] ?? 0 ),
				'event_type'     => sanitize_key( $entry['event_type'] ?? 'unknown' ),
				'source'         => sanitize_key( $entry['source'] ?? 'automatic' ),
				'channel_id'     => sanitize_text_field( $entry['channel_id'] ?? '' ),
				'channel_name'   => sanitize_text_field( $entry['channel_name'] ?? '' ),
				'service'        => sanitize_key( $entry['service'] ?? '' ),
				'status'         => sanitize_key( $entry['status'] ?? 'failed' ),
				'attempt'        => absint( $entry['attempt'] ?? 0 ),
				'buffer_post_id' => sanitize_text_field( $entry['buffer_post_id'] ?? '' ),
				'message'        => $this->sanitize_message( $entry['message'] ?? '' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		// Table names cannot be passed as SQL values; Database::table() only uses $wpdb->prefix and a fixed suffix.
		$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY id DESC LIMIT 100) recent)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Recent log rows. */
	public function recent( $limit = 100 ) {
		global $wpdb;
		$table = Database::table( 'logs' );
		$limit = min( 100, max( 1, absint( $limit ) ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Clear visible logs only. */
	public function clear() {
		global $wpdb;
		$table = Database::table( 'logs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Remove secrets and bound message size. */
	private function sanitize_message( $message ) {
		$message = wp_strip_all_tags( (string) $message );
		$message = preg_replace( '/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer [redacted]', $message );
		$message = preg_replace( '/buf_[A-Za-z0-9._-]+/i', '[redacted]', $message );
		$message = trim( (string) $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 1000 ) : substr( $message, 0, 1000 );
	}
}
