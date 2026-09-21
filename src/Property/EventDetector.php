<?php
/**
 * Coalesced Property Hive event detection.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Property;

use Homer\PropertyHiveBufferAutoPost\Contracts\Hookable;
use Homer\PropertyHiveBufferAutoPost\Events\EventFactory;
use Homer\PropertyHiveBufferAutoPost\Events\EventRepository;
use Homer\PropertyHiveBufferAutoPost\Logger;
use Homer\PropertyHiveBufferAutoPost\Settings;

class EventDetector implements Hookable {
	const STATE_META = '_phbap_state';

	private $snapshot;
	private $factory;
	private $repository;
	private $logger;
	private $dirty              = array();
	private $publish_candidates = array();

	public function __construct( PropertySnapshot $snapshot, EventFactory $factory, EventRepository $repository, Logger $logger ) {
		$this->snapshot   = $snapshot;
		$this->factory    = $factory;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/** Register all state-change observation hooks. */
	public function register_hooks() {
		add_action( 'transition_post_status', array( $this, 'status_transition' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'terms_changed' ), 10, 6 );
		add_action( 'save_post_property', array( $this, 'post_saved' ), 200, 3 );
		add_action( 'shutdown', array( $this, 'evaluate_dirty' ), 5 );
	}

	/** Capture publish transitions before later meta writes finish. */
	public function status_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof \WP_Post || 'property' !== $post->post_type ) {
			return;
		}
		$this->dirty[ $post->ID ] = true;
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->publish_candidates[ $post->ID ] = true;
		}
	}

	/** Observe only fields which affect event eligibility. */
	public function meta_changed( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( in_array( $meta_key, array( '_department', '_on_market', '_price_actual' ), true ) && 'property' === get_post_type( $object_id ) ) {
			$this->dirty[ absint( $object_id ) ] = true;
		}
	}

	/** Observe availability taxonomy transitions. */
	public function terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $tt_ids, $append, $old_tt_ids );
		if ( 'availability' === $taxonomy && 'property' === get_post_type( $object_id ) ) {
			$this->dirty[ absint( $object_id ) ] = true;
		}
	}

	/** Late save hook catches admin, quick-edit and most importer writes. */
	public function post_saved( $post_id, $post, $update ) {
		unset( $post, $update );
		if ( ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			$this->dirty[ absint( $post_id ) ] = true;
		}
	}

	/** Evaluate each property once, after the request's writes have settled. */
	public function evaluate_dirty() {
		if ( 'ready' !== get_option( 'phbap_baseline_status', 'pending' ) ) {
			return;
		}
		foreach ( array_keys( $this->dirty ) as $property_id ) {
			$this->evaluate( $property_id );
		}
		$this->dirty = array();
	}

	/** Compare stored and current state, queueing qualifying events. */
	public function evaluate( $property_id ) {
		$current = $this->snapshot->get( $property_id );
		if ( ! $current ) {
			return;
		}

		$previous = get_post_meta( $property_id, self::STATE_META, true );
		$previous = is_array( $previous ) ? $previous : array();
		$state    = $this->state_from_snapshot( $current, $previous );

		if ( empty( $previous ) ) {
			if ( ! empty( $this->publish_candidates[ $property_id ] ) && $current['is_sales'] && $current['live'] && ! $current['sold'] ) {
				$this->maybe_create_event( 'new_listing', $current, $state, 0 );
			}
			update_post_meta( $property_id, self::STATE_META, $state );
			return;
		}

		if ( $current['is_sales'] ) {
			if ( empty( $state['new_listing_sent'] ) && $current['live'] && ! $current['sold'] && empty( $previous['live'] ) ) {
				$this->maybe_create_event( 'new_listing', $current, $state, 0 );
			}

			$old_price = isset( $previous['price_actual'] ) ? (float) $previous['price_actual'] : 0;
			if ( $current['live'] && ! $current['sold'] && $old_price > 0 && $current['price_actual'] > 0 && $current['price_actual'] < $old_price ) {
				$this->maybe_create_event( 'price_reduction', $current, $state, $old_price );
			}

			if ( empty( $state['sold_sent'] ) && $current['sold'] && empty( $previous['sold'] ) && 'publish' === $current['post_status'] ) {
				$this->maybe_create_event( 'sold', $current, $state, 0 );
			}
		}

		update_post_meta( $property_id, self::STATE_META, $state );
	}

	/** Queue an enabled event and update permanent flags. */
	private function maybe_create_event( $event_type, array $current, array &$state, $previous_price ) {
		if ( ! Settings::event_enabled( $event_type ) ) {
			return;
		}
		$channels = Settings::selected_channels();
		if ( empty( $channels ) ) {
			$this->logger->add(
				array(
					'property_id' => $current['property_id'],
					'event_type'  => $event_type,
					'source'      => 'automatic',
					'status'      => 'failed',
					'message'     => __( 'No Buffer channels are selected; the event was not queued.', 'propertyhive-buffer-auto-post' ),
				)
			);
			return;
		}

		$payload = $this->factory->create( $current['property_id'], $event_type, $previous_price, 'automatic' );
		if ( is_wp_error( $payload ) ) {
			$this->logger->add(
				array(
					'property_id' => $current['property_id'],
					'event_type'  => $event_type,
					'source'      => 'automatic',
					'status'      => 'failed',
					'message'     => $payload->get_error_message(),
				)
			);
			return;
		}

		$state['sequence'] = isset( $state['sequence'] ) ? (int) $state['sequence'] + 1 : 1;
		$key               = sprintf( '%d:%s:%d', $current['property_id'], $event_type, $state['sequence'] );
		$event_id          = $this->repository->create( $key, $payload, $channels );
		if ( is_wp_error( $event_id ) ) {
			$this->logger->add(
				array(
					'property_id' => $current['property_id'],
					'event_type'  => $event_type,
					'source'      => 'automatic',
					'status'      => 'failed',
					'message'     => $event_id->get_error_message(),
				)
			);
			return;
		}

		if ( 'new_listing' === $event_type ) {
			$state['new_listing_sent'] = true;
		} elseif ( 'sold' === $event_type ) {
			$state['sold_sent'] = true;
		}
	}

	/** Merge observation state while preserving permanent flags and sequence. */
	public function state_from_snapshot( array $snapshot, array $previous = array() ) {
		return array(
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
	}
}
