<?php
/**
 * Safe initial property baselining.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Property;

use Homer\PropertyHiveBufferAutoPost\Contracts\Hookable;

class BaselineProcessor implements Hookable {
	private $snapshot;

	public function __construct( PropertySnapshot $snapshot ) {
		$this->snapshot = $snapshot;
	}

	public function register_hooks() {
		add_action( 'phbap_baseline_batch', array( $this, 'run' ) );
	}

	/** Store current state for existing properties in bounded batches. */
	public function run() {
		global $wpdb;
		$cursor = absint( get_option( 'phbap_baseline_cursor', 0 ) );
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type='property' AND ID > %d ORDER BY ID ASC LIMIT 200",
				$cursor
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $ids ) ) {
			update_option( 'phbap_baseline_status', 'ready', false );
			delete_option( 'phbap_baseline_cursor' );
			return;
		}

		foreach ( $ids as $property_id ) {
			$snapshot = $this->snapshot->get( (int) $property_id );
			if ( $snapshot ) {
				$previous = get_post_meta( $property_id, EventDetector::STATE_META, true );
				$previous = is_array( $previous ) ? $previous : array();
				$state    = array(
					'post_status'      => $snapshot['post_status'],
					'on_market'        => (bool) $snapshot['on_market'],
					'live'             => (bool) $snapshot['live'],
					'department'       => $snapshot['department'],
					'price_actual'     => (float) $snapshot['price_actual'],
					'availability'     => $snapshot['availability'],
					'sold'             => (bool) $snapshot['sold'],
					'new_listing_sent' => ! empty( $previous['new_listing_sent'] ),
					'sold_sent'        => ! empty( $previous['sold_sent'] ),
					'sequence'         => isset( $previous['sequence'] ) ? absint( $previous['sequence'] ) : 0,
					'observed_at_gmt'  => gmdate( 'c' ),
				);
				update_post_meta( $property_id, EventDetector::STATE_META, $state );
			}
			$cursor = (int) $property_id;
		}

		update_option( 'phbap_baseline_cursor', $cursor, false );
		wp_schedule_single_event( time() + 5, 'phbap_baseline_batch' );
	}
}
