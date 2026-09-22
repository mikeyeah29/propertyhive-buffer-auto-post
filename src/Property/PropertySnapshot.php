<?php
/**
 * Property Hive data snapshot.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Property;

class PropertySnapshot {
	/** Read the final state and reliable content for a property. */
	public function get( $property_id ) {
		$post = get_post( $property_id );
		if ( ! $post || 'property' !== $post->post_type ) {
			return null;
		}

		$department   = (string) get_post_meta( $property_id, '_department', true );
		$availability = wp_get_post_terms( $property_id, 'availability', array( 'fields' => 'slugs' ) );
		$availability = is_wp_error( $availability ) ? array() : array_values( array_map( 'strval', $availability ) );
		$price_actual = (float) get_post_meta( $property_id, '_price_actual', true );
		$sold         = (bool) array_intersect( array( 'sold-stc', 'sold' ), $availability );
		$live         = 'publish' === $post->post_status && 'yes' === get_post_meta( $property_id, '_on_market', true );

		return array(
			'property_id'   => (int) $property_id,
			'post_status'   => (string) $post->post_status,
			'on_market'     => 'yes' === get_post_meta( $property_id, '_on_market', true ),
			'live'          => $live,
			'department'    => $department,
			'is_sales'      => $this->is_sales_department( $department ),
			'price_actual'  => $price_actual,
			'price'         => $this->formatted_price( $property_id, $price_actual ),
			'availability'  => $availability,
			'sold'          => $sold,
			'address'       => $this->address( $property_id ),
			'bedrooms'      => (string) get_post_meta( $property_id, '_bedrooms', true ),
			'property_type' => $this->property_type( $property_id ),
			'property_url'  => (string) get_permalink( $property_id ),
			'images'        => $this->images( $property_id ),
		);
	}

	/** Format an historical numeric price consistently. */
	public function format_numeric_price( $price ) {
		$price = (float) $price;
		return $price > 0 ? '£' . number_format_i18n( $price, 0 ) : '';
	}

	/** Whether a department is a supported sales department. */
	private function is_sales_department( $department ) {
		if ( in_array( $department, array( 'residential-sales', 'commercial' ), true ) ) {
			return true;
		}
		if ( function_exists( 'ph_get_custom_department_based_on' ) ) {
			return in_array( ph_get_custom_department_based_on( $department ), array( 'residential-sales', 'commercial' ), true );
		}
		return false;
	}

	/** Property Hive formatted price with a reliable fallback. */
	private function formatted_price( $property_id, $price_actual ) {
		if ( class_exists( 'PH_Property' ) ) {
			$property = new \PH_Property( (int) $property_id );
			if ( method_exists( $property, 'get_formatted_price' ) ) {
				$value   = $property->get_formatted_price();
				$charset = get_bloginfo( 'charset' );
				$charset = $charset ? $charset : 'UTF-8';
				$value   = html_entity_decode( wp_strip_all_tags( str_ireplace( array( '<br>', '<br/>', '<br />' ), ' ', (string) $value ) ), ENT_QUOTES | ENT_HTML5, $charset );
				$value   = trim( preg_replace( '/\s+/', ' ', $value ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return $this->format_numeric_price( $price_actual );
	}

	/** Human-readable address from Property Hive fields. */
	private function address( $property_id ) {
		$line_one = trim( implode( ' ', array_filter( array( get_post_meta( $property_id, '_address_name_number', true ), get_post_meta( $property_id, '_address_street', true ) ) ) ) );
		$parts    = array(
			$line_one,
			get_post_meta( $property_id, '_address_two', true ),
			get_post_meta( $property_id, '_address_three', true ),
			get_post_meta( $property_id, '_address_four', true ),
			get_post_meta( $property_id, '_address_postcode', true ),
		);
		return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
	}

	/** First reliable property type term. */
	private function property_type( $property_id ) {
		foreach ( array( 'property_type', 'commercial_property_type' ) as $taxonomy ) {
			$terms = wp_get_post_terms( $property_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return (string) reset( $terms );
			}
		}
		return '';
	}

	/** Property Hive attachment images in stored order. */
	private function images( $property_id ) {
		$images = array();
		$urls   = get_post_meta( $property_id, '_photo_urls', true );

		if ( 'urls' === get_option( 'propertyhive_images_stored_as', '' ) && is_array( $urls ) ) {
			foreach ( $urls as $item ) {
				$url = is_array( $item ) && isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '';
				if ( $url ) {
					$images[] = array(
						'url'           => $url,
						'attachment_id' => 0,
					);
				}
			}
		} else {
			$ids = get_post_meta( $property_id, '_photos', true );
			if ( ! is_array( $ids ) && class_exists( 'PH_Property' ) ) {
				$property = new \PH_Property( (int) $property_id );
				$ids      = $property->get_gallery_attachment_ids();
			}
			foreach ( is_array( $ids ) ? $ids : array() as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				$url           = wp_get_attachment_image_url( $attachment_id, 'large' );
				$url           = $url ? $url : wp_get_attachment_url( $attachment_id );
				if ( $url ) {
					$images[] = array(
						'url'           => esc_url_raw( $url ),
						'attachment_id' => $attachment_id,
					);
				}
			}
		}

		return array_slice( $images, 0, 20 );
	}
}
