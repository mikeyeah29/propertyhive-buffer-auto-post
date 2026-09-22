<?php
/**
 * Create metadata-free JPEGs for Buffer.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Media;

class BufferImageProcessor {
	const MAX_DIMENSION = 1080;
	const JPEG_QUALITY  = 85;
	const CACHE_VERSION = 1;

	/** Re-encode property images as cached, metadata-free JPEGs. */
	public function process( array $images ) {
		$processed = array();
		$errors    = array();

		foreach ( array_slice( $images, 0, 10 ) as $index => $image ) {
			if ( ! empty( $image['skip_processing'] ) ) {
				$processed[] = array(
					'url'           => isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '',
					'attachment_id' => absint( $image['attachment_id'] ?? 0 ),
				);
				continue;
			}

			$result = $this->process_one( $image );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: Property image position, 2: Processing error. */
					__( 'Image %1$d could not be prepared for Buffer: %2$s', 'propertyhive-buffer-auto-post' ),
					absint( $index ) + 1,
					$result->get_error_message()
				);
				continue;
			}
			$processed[] = $result;
		}

		if ( empty( $processed ) && ! empty( $errors ) ) {
			return new \WP_Error( 'buffer_image_processing', implode( ' ', array_slice( $errors, 0, 3 ) ) );
		}
		return $processed;
	}

	/** Create or reuse one processed image. */
	private function process_one( array $image ) {
		$url = isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '';
		if ( ! $url ) {
			return new \WP_Error( 'buffer_image_url', __( 'the source URL is missing.', 'propertyhive-buffer-auto-post' ) );
		}
		if ( ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) && ! class_exists( 'Imagick' ) ) {
			return new \WP_Error( 'buffer_image_editor', __( 'the PHP GD or Imagick extension is required.', 'propertyhive-buffer-auto-post' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'buffer_image_uploads', $uploads['error'] );
		}

		$source    = $this->local_path( $url, $uploads );
		$temporary = '';
		if ( ! $source && ! empty( $image['attachment_id'] ) ) {
			$attached = get_attached_file( absint( $image['attachment_id'] ) );
			$source   = $attached && is_readable( $attached ) ? $attached : '';
		}

		$signature = $url;
		if ( $source ) {
			$signature .= '|' . (string) filemtime( $source ) . '|' . (string) filesize( $source );
		}
		$filename = 'buffer-' . substr( hash( 'sha256', self::CACHE_VERSION . '|' . $signature ), 0, 32 ) . '.jpg';
		$subdir   = '/phbap/buffer';
		$dir      = $uploads['basedir'] . $subdir;
		$target   = trailingslashit( $dir ) . $filename;

		if ( is_readable( $target ) && $this->valid_target( $target ) ) {
			return $this->result( $uploads, $subdir, $filename, $target );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'buffer_image_directory', __( 'the processed-image directory could not be created.', 'propertyhive-buffer-auto-post' ) );
		}

		if ( ! $source ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temporary = download_url( $url, 20 );
			if ( is_wp_error( $temporary ) ) {
				return new \WP_Error( 'buffer_image_download', __( 'the source image could not be downloaded.', 'propertyhive-buffer-auto-post' ) );
			}
			$source = $temporary;
		}

		$result = $this->encode( $source, $target );
		if ( $temporary ) {
			wp_delete_file( $temporary );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->result( $uploads, $subdir, $filename, $target );
	}

	/** Resolve an uploads URL to its local file without accepting path traversal. */
	private function local_path( $url, array $uploads ) {
		$url_path  = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$base_path = rtrim( rawurldecode( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ), '/' );
		if ( ! $url_path || ! $base_path || 0 !== strpos( $url_path, $base_path . '/' ) ) {
			return '';
		}

		$relative = ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
		if ( ! $relative || preg_match( '#(?:^|/)\.\.(?:/|$)#', $relative ) ) {
			return '';
		}

		$candidate = trailingslashit( $uploads['basedir'] ) . $relative;
		$real_file = realpath( $candidate );
		$real_base = realpath( $uploads['basedir'] );
		if ( ! $real_file || ! $real_base || 0 !== strpos( $real_file, trailingslashit( $real_base ) ) ) {
			return '';
		}

		return is_readable( $real_file ) ? $real_file : '';
	}

	/** Decode, orient, resize and encode through an available PHP image extension. */
	private function encode( $source, $target ) {
		$result = new \WP_Error( 'buffer_image_editor', __( 'no compatible PHP image editor is available.', 'propertyhive-buffer-auto-post' ) );
		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ) {
			$result = $this->encode_gd( $source, $target );
		}
		if ( is_wp_error( $result ) && class_exists( 'Imagick' ) ) {
			$result = $this->encode_imagick( $source, $target );
		}
		return $result;
	}

	/** Encode through GD, which naturally discards source metadata. */
	private function encode_gd( $source, $target ) {
		$data = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$base = $data ? imagecreatefromstring( $data ) : false;
		if ( ! $base ) {
			return new \WP_Error( 'buffer_image_read', __( 'the source image is unreadable.', 'propertyhive-buffer-auto-post' ) );
		}

		$base   = $this->orient( $base, $source );
		$width  = imagesx( $base );
		$height = imagesy( $base );
		if ( $width < 1 || $height < 1 ) {
			imagedestroy( $base );
			return new \WP_Error( 'buffer_image_dimensions', __( 'the source dimensions are invalid.', 'propertyhive-buffer-auto-post' ) );
		}

		$scale         = min( 1, self::MAX_DIMENSION / max( $width, $height ) );
		$target_width  = max( 1, (int) round( $width * $scale ) );
		$target_height = max( 1, (int) round( $height * $scale ) );
		$output        = imagecreatetruecolor( $target_width, $target_height );
		if ( ! $output ) {
			imagedestroy( $base );
			return new \WP_Error( 'buffer_image_memory', __( 'PHP could not allocate the processed image.', 'propertyhive-buffer-auto-post' ) );
		}

		$white = imagecolorallocate( $output, 255, 255, 255 );
		imagefill( $output, 0, 0, $white );
		imagecopyresampled( $output, $base, 0, 0, 0, 0, $target_width, $target_height, $width, $height );
		$written = imagejpeg( $output, $target, self::JPEG_QUALITY );
		imagedestroy( $base );
		imagedestroy( $output );

		if ( ! $written || ! $this->valid_target( $target ) ) {
			if ( file_exists( $target ) ) {
				wp_delete_file( $target );
			}
			return new \WP_Error( 'buffer_image_write', __( 'the processed JPEG could not be saved.', 'propertyhive-buffer-auto-post' ) );
		}
		return true;
	}

	/** Encode through Imagick while explicitly removing source profiles and metadata. */
	private function encode_imagick( $source, $target ) {
		$image = null;
		try {
			$image = new \Imagick( $source );
			$image->setIteratorIndex( 0 );
			if ( method_exists( $image, 'autoOrient' ) ) {
				$image->autoOrient();
			} elseif ( method_exists( $image, 'autoOrientImage' ) ) {
				$image->autoOrientImage();
			}
			$image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
			$image->thumbnailImage( self::MAX_DIMENSION, self::MAX_DIMENSION, true, true );
			$image->setImageBackgroundColor( 'white' );
			$image->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
			$image->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			$image->setImageFormat( 'jpeg' );
			$image->setImageCompression( \Imagick::COMPRESSION_JPEG );
			$image->setImageCompressionQuality( self::JPEG_QUALITY );
			$image->stripImage();
			$image->setImagePage( 0, 0, 0, 0 );
			$written = $image->writeImage( $target );
			$image->clear();
		} catch ( \Exception $exception ) {
			if ( $image instanceof \Imagick ) {
				$image->clear();
			}
			$written = false;
		}

		if ( ! $written || ! $this->valid_target( $target ) ) {
			if ( file_exists( $target ) ) {
				wp_delete_file( $target );
			}
			return new \WP_Error( 'buffer_image_write', __( 'the processed JPEG could not be saved.', 'propertyhive-buffer-auto-post' ) );
		}
		return true;
	}

	/** Apply camera orientation before metadata is discarded. */
	private function orient( $image, $source ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $image;
		}
		$details = getimagesize( $source );
		if ( ! is_array( $details ) || IMAGETYPE_JPEG !== (int) $details[2] ) {
			return $image;
		}
		$exif        = @exif_read_data( $source, 'IFD0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$orientation = is_array( $exif ) ? absint( $exif['Orientation'] ?? 1 ) : 1;

		if ( in_array( $orientation, array( 2, 4, 5, 7 ), true ) && function_exists( 'imageflip' ) ) {
			imageflip( $image, in_array( $orientation, array( 2, 5 ), true ) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL );
		}
		$angle = 0;
		if ( in_array( $orientation, array( 3, 4 ), true ) ) {
			$angle = 180;
		} elseif ( in_array( $orientation, array( 5, 6 ), true ) ) {
			$angle = -90;
		} elseif ( in_array( $orientation, array( 7, 8 ), true ) ) {
			$angle = 90;
		}
		if ( $angle ) {
			$rotated = imagerotate( $image, $angle, 0 );
			if ( $rotated ) {
				imagedestroy( $image );
				$image = $rotated;
			}
		}
		return $image;
	}

	/** Verify the cached output is a usable JPEG. */
	private function valid_target( $path ) {
		$details = wp_getimagesize( $path );
		return is_array( $details )
			&& ! empty( $details[0] )
			&& ! empty( $details[1] )
			&& isset( $details['mime'] )
			&& 'image/jpeg' === strtolower( $details['mime'] )
			&& filesize( $path ) <= 8 * MB_IN_BYTES;
	}

	/** Build the image entry consumed by validation and delivery. */
	private function result( array $uploads, $subdir, $filename, $path ) {
		return array(
			'url'           => esc_url_raw( rtrim( $uploads['baseurl'], '/' ) . $subdir . '/' . rawurlencode( $filename ) ),
			'attachment_id' => 0,
			'path'          => $path,
		);
	}
}
