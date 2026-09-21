<?php
/**
 * Immutable event payload creation.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Events;

use Homer\PropertyHiveBufferAutoPost\Content\TemplateRenderer;
use Homer\PropertyHiveBufferAutoPost\Property\PropertySnapshot;
use Homer\PropertyHiveBufferAutoPost\Settings;
use Homer\PropertyHiveBufferAutoPost\Support\ScheduleCalculator;

class EventFactory {
	private $snapshot;
	private $renderer;
	private $scheduler;

	public function __construct( PropertySnapshot $snapshot, TemplateRenderer $renderer, ScheduleCalculator $scheduler ) {
		$this->snapshot  = $snapshot;
		$this->renderer  = $renderer;
		$this->scheduler = $scheduler;
	}

	/** Build a payload from current property data. */
	public function create( $property_id, $event_type, $previous_price = 0, $source = 'automatic' ) {
		$data = $this->snapshot->get( $property_id );
		if ( ! $data ) {
			return new \WP_Error( 'property_missing', __( 'The selected Property Hive property no longer exists.', 'propertyhive-buffer-auto-post' ) );
		}

		$template_keys = array(
			'new_listing'     => 'new_listing_template',
			'price_reduction' => 'price_reduction_template',
			'sold'            => 'sold_template',
		);
		if ( ! isset( $template_keys[ $event_type ] ) ) {
			return new \WP_Error( 'event_type', __( 'The selected event type is not supported.', 'propertyhive-buffer-auto-post' ) );
		}

		$values  = array(
			'property_id'    => (string) $property_id,
			'address'        => $data['address'],
			'price'          => $data['price'],
			'previous_price' => $this->snapshot->format_numeric_price( $previous_price ),
			'bedrooms'       => $data['bedrooms'],
			'property_type'  => $data['property_type'],
			'property_url'   => $data['property_url'],
		);
		$caption = $this->renderer->render( Settings::get( $template_keys[ $event_type ] ), $values );
		if ( is_wp_error( $caption ) ) {
			return $caption;
		}

		$mode   = Settings::get( 'delivery_mode', 'queue' );
		$due_at = '';
		if ( 'daily' === $mode ) {
			$due_at = $this->scheduler->next_daily_iso( Settings::get( 'daily_time', '10:00' ), wp_timezone() );
		}

		return array(
			'version'        => 1,
			'property_id'    => (int) $property_id,
			'event_type'     => $event_type,
			'source'         => $source,
			'caption'        => $caption,
			'values'         => $values,
			'images'         => $data['images'],
			'overlay_id'     => 'sold' === $event_type ? absint( Settings::get( 'sold_overlay_id', 0 ) ) : 0,
			'derived_path'   => '',
			'derived_url'    => '',
			'delivery_mode'  => $mode,
			'due_at'         => $due_at,
			'save_to_draft'  => 'manual_test' === $source,
			'created_at_gmt' => gmdate( 'c' ),
		);
	}
}
