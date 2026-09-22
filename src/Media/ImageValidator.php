<?php
/**
 * Destination-aware image validation.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Media;

class ImageValidator {
	/** Return up to ten usable public image URLs. */
	public function validate( array $images, array $channels ) {
		$services      = array_map( 'strtolower', wp_list_pluck( $channels, 'service' ) );
		$has_instagram = in_array( 'instagram', $services, true );
		$max_bytes     = $has_instagram ? 8 * MB_IN_BYTES : 10 * MB_IN_BYTES;
		$valid         = array();
		$rejections    = array();

		foreach ( $images as $index => $image ) {
			$position = absint( $index ) + 1;
			$url      = isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '';
			if ( ! $url ) {
				$rejections[] = sprintf(
					/* translators: %d: Property image position. */
					__( 'Image %d has no usable URL.', 'propertyhive-buffer-auto-post' ),
					$position
				);
				continue;
			}
			if ( ! $this->is_public_https_url( $url ) ) {
				$rejections[] = sprintf(
					/* translators: %d: Property image position. */
					__( 'Image %d does not use a public HTTPS URL. Buffer downloads images from their URLs and cannot access HTTP-only or local development addresses such as .test sites.', 'propertyhive-buffer-auto-post' ),
					$position
				);
				continue;
			}
			$details = $this->details( $url, $image );
			if ( is_wp_error( $details ) ) {
				$rejections[] = sprintf(
					/* translators: %d: Property image position. */
					__( 'Image %d could not be downloaded or read.', 'propertyhive-buffer-auto-post' ),
					$position
				);
				continue;
			}
			if ( $details['size'] > $max_bytes || $details['width'] < 320 || $details['height'] < 1 || $details['width'] > 8192 || $details['height'] > 8192 ) {
				$rejections[] = sprintf(
					/* translators: %d: Property image position. */
					__( 'Image %d is outside the permitted file-size or dimension limits.', 'propertyhive-buffer-auto-post' ),
					$position
				);
				continue;
			}
			if ( ! in_array( $details['mime'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true ) ) {
				$rejections[] = sprintf(
					/* translators: %d: Property image position. */
					__( 'Image %d has an unsupported file type.', 'propertyhive-buffer-auto-post' ),
					$position
				);
				continue;
			}
			$ratio = $details['width'] / $details['height'];
			if ( $has_instagram && ( $ratio < 0.8 || $ratio > 1.91 ) ) {
				$rejections[] = sprintf(
					/* translators: %d: Property image position. */
					__( 'Image %d has an aspect ratio unsupported by Instagram.', 'propertyhive-buffer-auto-post' ),
					$position
				);
				continue;
			}
			$valid[] = $url;
			if ( count( $valid ) >= 10 ) {
				break;
			}
		}

		if ( empty( $valid ) ) {
			$message = __( 'No property images meet the selected Buffer channel requirements.', 'propertyhive-buffer-auto-post' );
			if ( ! empty( $rejections ) ) {
				$message .= ' ' . implode( ' ', array_slice( $rejections, 0, 3 ) );
			}
			return new \WP_Error( 'no_images', $message );
		}
		return $valid;
	}

	/** Reject URL forms that Buffer cannot resolve from its own infrastructure. */
	private function is_public_https_url( $url ) {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || preg_match( '/(?:^localhost$|\.(?:test|local|localhost|invalid)$)/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return true;
	}

	/** Read local or remote image metadata without retaining a download. */
	private function details( $url, array $image ) {
		$temporary = '';
		$path      = ! empty( $image['path'] ) && is_readable( $image['path'] ) ? $image['path'] : '';
		if ( ! $path && ! empty( $image['attachment_id'] ) ) {
			$path = get_attached_file( absint( $image['attachment_id'] ) );
		}
		if ( ! $path || ! is_readable( $path ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temporary = download_url( $url, 20 );
			if ( is_wp_error( $temporary ) ) {
				return $temporary;
			}
			$path = $temporary;
		}

		$size  = filesize( $path );
		$image = wp_getimagesize( $path );
		if ( $temporary ) {
			wp_delete_file( $temporary );
		}
		if ( ! is_array( $image ) || empty( $image[0] ) || empty( $image[1] ) ) {
			return new \WP_Error( 'image_dimensions', __( 'An image has unreadable dimensions.', 'propertyhive-buffer-auto-post' ) );
		}
		return array(
			'width'  => (int) $image[0],
			'height' => (int) $image[1],
			'mime'   => isset( $image['mime'] ) ? strtolower( $image['mime'] ) : '',
			'size'   => (int) $size,
		);
	}
}
