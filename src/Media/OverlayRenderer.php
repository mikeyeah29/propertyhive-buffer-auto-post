<?php
/**
 * Transparent sold-overlay rendering.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Media;

class OverlayRenderer {
	/** Generate a stable public PNG without modifying source media. */
	public function render( $base_url, $overlay_id, $event_key ) {
		$overlay = get_attached_file( absint( $overlay_id ) );
		if ( ! $overlay || ! is_readable( $overlay ) || 'image/png' !== get_post_mime_type( $overlay_id ) ) {
			return new \WP_Error( 'overlay_missing', __( 'A valid transparent PNG sold overlay is required.', 'propertyhive-buffer-auto-post' ) );
		}

		$base_path = '';
		$temporary = '';
		$base_id   = attachment_url_to_postid( $base_url );
		if ( $base_id ) {
			$base_path = get_attached_file( $base_id );
		}
		if ( ! $base_path || ! is_readable( $base_path ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temporary = download_url( $base_url, 20 );
			if ( is_wp_error( $temporary ) ) {
				return $temporary;
			}
			$base_path = $temporary;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'uploads', $uploads['error'] );
		}
		$subdir = '/phbap/' . gmdate( 'Y/m' );
		$dir    = $uploads['basedir'] . $subdir;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'overlay_directory', __( 'The sold-image directory could not be created.', 'propertyhive-buffer-auto-post' ) );
		}
		$filename = 'sold-' . sanitize_file_name( substr( hash( 'sha256', $event_key ), 0, 24 ) ) . '.png';
		$path     = trailingslashit( $dir ) . $filename;

		$result = class_exists( 'Imagick' ) ? $this->render_imagick( $base_path, $overlay, $path ) : $this->render_gd( $base_path, $overlay, $path );
		if ( $temporary ) {
			wp_delete_file( $temporary );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( filesize( $path ) > 8 * MB_IN_BYTES ) {
			wp_delete_file( $path );
			return new \WP_Error( 'overlay_size', __( 'The generated sold image exceeds Instagram’s 8 MB limit.', 'propertyhive-buffer-auto-post' ) );
		}

		return array(
			'path' => $path,
			'url'  => $uploads['baseurl'] . $subdir . '/' . rawurlencode( $filename ),
		);
	}

	private function render_imagick( $base_path, $overlay_path, $target ) {
		try {
			$base = new \Imagick( $base_path );
			$base->setIteratorIndex( 0 );
			if ( method_exists( $base, 'autoOrient' ) ) {
				$base->autoOrient();
			} elseif ( method_exists( $base, 'autoOrientImage' ) ) {
				$base->autoOrientImage();
			}
			$overlay = new \Imagick( $overlay_path );
			$overlay->setIteratorIndex( 0 );
			$overlay->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
			$overlay->resizeImage( $base->getImageWidth(), $base->getImageHeight(), \Imagick::FILTER_LANCZOS, 1, false );
			$base->setImageFormat( 'png' );
			$base->setImageCompressionQuality( 85 );
			$base->compositeImage( $overlay, \Imagick::COMPOSITE_OVER, 0, 0 );
			$base->writeImage( $target );
			$base->clear();
			$overlay->clear();
			return true;
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'overlay_imagick', __( 'The sold overlay could not be rendered.', 'propertyhive-buffer-auto-post' ) );
		}
	}

	private function render_gd( $base_path, $overlay_path, $target ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new \WP_Error( 'overlay_editor', __( 'Imagick or GD is required to render sold images.', 'propertyhive-buffer-auto-post' ) );
		}
		$base_data = file_get_contents( $base_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$base      = $base_data ? imagecreatefromstring( $base_data ) : false;
		$overlay   = imagecreatefrompng( $overlay_path );
		if ( ! $base || ! $overlay ) {
			return new \WP_Error( 'overlay_read', __( 'The sold overlay or property image could not be read.', 'propertyhive-buffer-auto-post' ) );
		}
		$width  = imagesx( $base );
		$height = imagesy( $base );
		$scaled = imagecreatetruecolor( $width, $height );
		imagealphablending( $scaled, false );
		imagesavealpha( $scaled, true );
		imagecopyresampled( $scaled, $overlay, 0, 0, 0, 0, $width, $height, imagesx( $overlay ), imagesy( $overlay ) );
		imagealphablending( $base, true );
		imagecopy( $base, $scaled, 0, 0, 0, 0, $width, $height );
		$result = imagepng( $base, $target, 8 );
		imagedestroy( $base );
		imagedestroy( $overlay );
		imagedestroy( $scaled );
		return $result ? true : new \WP_Error( 'overlay_write', __( 'The sold image could not be saved.', 'propertyhive-buffer-auto-post' ) );
	}
}
