<?php
/**
 * Plugin settings.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

use Homer\PropertyHiveBufferAutoPost\Content\TemplateRenderer;

class Settings {
	const OPTION         = 'phbap_settings';
	const ORGS_OPTION    = 'phbap_buffer_organisations';
	const CHANNEL_OPTION = 'phbap_buffer_channels';

	/** Defaults. */
	public static function defaults() {
		return array(
			'event_new_listing'        => false,
			'event_price_reduction'    => false,
			'event_sold'               => false,
			'buffer_api_key'           => '',
			'buffer_organisation_id'   => '',
			'buffer_channel_ids'       => array(),
			'delivery_mode'            => 'queue',
			'daily_time'               => '10:00',
			'new_listing_template'     => "New listing: {address}\n{price}\n{bedrooms} bedrooms · {property_type}\n{property_url}",
			'price_reduction_template' => "Price reduced: {address}\nWas {previous_price}, now {price}\n{property_url}",
			'sold_template'            => "Sold: {address}\nAnother successful sale from Homer Estates.\n{property_url}",
			'sold_overlay_id'          => 0,
		);
	}

	/** All settings merged with defaults. */
	public static function all() {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	/** Get one setting. */
	public static function get( $key, $fallback = null ) {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/** Return the API key, preferring wp-config.php. */
	public static function api_key() {
		if ( defined( 'PHBAP_BUFFER_API_KEY' ) && is_string( PHBAP_BUFFER_API_KEY ) ) {
			return trim( PHBAP_BUFFER_API_KEY );
		}
		return trim( (string) self::get( 'buffer_api_key', '' ) );
	}

	/** Whether a config constant owns the key. */
	public static function api_key_is_constant() {
		return defined( 'PHBAP_BUFFER_API_KEY' );
	}

	/** Return selected channel records from the last successful refresh. */
	public static function selected_channels() {
		$selected = array_map( 'strval', (array) self::get( 'buffer_channel_ids', array() ) );
		$channels = get_option( self::CHANNEL_OPTION, array() );
		$return   = array();

		foreach ( is_array( $channels ) ? $channels : array() as $channel ) {
			if ( isset( $channel['id'] ) && in_array( (string) $channel['id'], $selected, true ) ) {
				$return[] = $channel;
			}
		}
		return $return;
	}

	/** Whether an automatic event is enabled. */
	public static function event_enabled( $event_type ) {
		$map = array(
			'new_listing'     => 'event_new_listing',
			'price_reduction' => 'event_price_reduction',
			'sold'            => 'event_sold',
		);
		return isset( $map[ $event_type ] ) && (bool) self::get( $map[ $event_type ], false );
	}

	/** Sanitize and save submitted settings. */
	public static function save( $input, $section = 'settings' ) {
		$input    = wp_unslash( is_array( $input ) ? $input : array() );
		$current  = self::all();
		$defaults = self::defaults();
		$renderer = new TemplateRenderer();
		$errors   = array();

		$templates = array(
			'new_listing_template'     => 'templates' === $section && isset( $input['new_listing_template'] ) ? sanitize_textarea_field( $input['new_listing_template'] ) : $current['new_listing_template'],
			'price_reduction_template' => 'templates' === $section && isset( $input['price_reduction_template'] ) ? sanitize_textarea_field( $input['price_reduction_template'] ) : $current['price_reduction_template'],
			'sold_template'            => 'templates' === $section && isset( $input['sold_template'] ) ? sanitize_textarea_field( $input['sold_template'] ) : $current['sold_template'],
		);

		foreach ( $templates as $name => $template ) {
			$invalid = $renderer->invalid_placeholders( $template );
			if ( ! empty( $invalid ) ) {
				$errors[] = sprintf(
					/* translators: 1: template key, 2: unsupported placeholders. */
					__( '%1$s contains unsupported placeholders: %2$s', 'propertyhive-buffer-auto-post' ),
					$name,
					implode( ', ', $invalid )
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'invalid_templates', implode( ' ', $errors ) );
		}

		$time = 'settings' === $section && isset( $input['daily_time'] ) ? sanitize_text_field( $input['daily_time'] ) : $current['daily_time'];
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = $defaults['daily_time'];
		}

		$mode = 'settings' === $section && isset( $input['delivery_mode'] ) ? sanitize_key( $input['delivery_mode'] ) : $current['delivery_mode'];
		if ( ! in_array( $mode, array( 'queue', 'daily' ), true ) ) {
			$mode = 'queue';
		}

		$key = $current['buffer_api_key'];
		if ( 'settings' === $section && ! self::api_key_is_constant() ) {
			if ( ! empty( $input['remove_buffer_api_key'] ) ) {
				$key = '';
			} elseif ( ! empty( $input['buffer_api_key'] ) ) {
				$key = sanitize_text_field( $input['buffer_api_key'] );
			}
		}

		$overlay_id = 'templates' === $section && isset( $input['sold_overlay_id'] ) ? absint( $input['sold_overlay_id'] ) : (int) $current['sold_overlay_id'];
		if ( $overlay_id && 'image/png' !== get_post_mime_type( $overlay_id ) ) {
			return new \WP_Error( 'invalid_overlay', __( 'The sold overlay must be a PNG Media Library image.', 'propertyhive-buffer-auto-post' ) );
		}

		$settings = array(
			'event_new_listing'        => 'settings' === $section ? ! empty( $input['event_new_listing'] ) : $current['event_new_listing'],
			'event_price_reduction'    => 'settings' === $section ? ! empty( $input['event_price_reduction'] ) : $current['event_price_reduction'],
			'event_sold'               => 'settings' === $section ? ! empty( $input['event_sold'] ) : $current['event_sold'],
			'buffer_api_key'           => $key,
			'buffer_organisation_id'   => 'settings' === $section && isset( $input['buffer_organisation_id'] ) ? sanitize_text_field( $input['buffer_organisation_id'] ) : $current['buffer_organisation_id'],
			'buffer_channel_ids'       => 'settings' === $section ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $input['buffer_channel_ids'] ?? array() ) ) ) ) ) : $current['buffer_channel_ids'],
			'delivery_mode'            => $mode,
			'daily_time'               => $time,
			'new_listing_template'     => $templates['new_listing_template'],
			'price_reduction_template' => $templates['price_reduction_template'],
			'sold_template'            => $templates['sold_template'],
			'sold_overlay_id'          => $overlay_id,
		);

		$events_enabled = $settings['event_new_listing'] || $settings['event_price_reduction'] || $settings['event_sold'];
		if ( $events_enabled && '' === trim( self::api_key_is_constant() ? self::api_key() : $settings['buffer_api_key'] ) ) {
			return new \WP_Error( 'missing_key', __( 'Add a Buffer API key before enabling automatic events.', 'propertyhive-buffer-auto-post' ) );
		}
		if ( $events_enabled && ( empty( $settings['buffer_organisation_id'] ) || empty( $settings['buffer_channel_ids'] ) ) ) {
			return new \WP_Error( 'missing_channels', __( 'Select a Buffer organisation and at least one Facebook or Instagram channel before enabling automatic events.', 'propertyhive-buffer-auto-post' ) );
		}
		if ( $settings['event_sold'] && empty( $settings['sold_overlay_id'] ) ) {
			return new \WP_Error( 'missing_overlay', __( 'Choose a sold PNG overlay on the Templates tab before enabling sold posts.', 'propertyhive-buffer-auto-post' ) );
		}

		update_option( self::OPTION, $settings, false );
		return true;
	}
}
