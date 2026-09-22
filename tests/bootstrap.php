<?php
/** PHPUnit bootstrap for pure unit tests and the optional WordPress test suite. */

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( $wp_tests_dir && file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	require_once $wp_tests_dir . '/includes/functions.php';
	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/propertyhive-buffer-auto-post.php';
		}
	);
	require $wp_tests_dir . '/includes/bootstrap.php';
	return;
}

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
defined( 'PHBAP_PATH' ) || define( 'PHBAP_PATH', dirname( __DIR__ ) . '/' );
defined( 'MB_IN_BYTES' ) || define( 'MB_IN_BYTES', 1024 * 1024 );

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code; }
		public function get_error_message() {
			return $this->message; }
	}
}

$GLOBALS['phbap_test_http_response']            = null;
$GLOBALS['phbap_test_http_request']             = null;
$GLOBALS['phbap_test_options']                  = array();
$GLOBALS['phbap_test_post_meta']                = array();
$GLOBALS['phbap_test_attachment_urls']          = array();
$GLOBALS['phbap_test_attachment_image_urls']    = array();
$GLOBALS['phbap_test_original_image_url_calls'] = 0;
$GLOBALS['phbap_test_attached_files']           = array();
$GLOBALS['phbap_test_upload_dir']               = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $fallback = false ) {
		return array_key_exists( $key, $GLOBALS['phbap_test_options'] ) ? $GLOBALS['phbap_test_options'][ $key ] : $fallback;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['phbap_test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		return $GLOBALS['phbap_test_attachment_urls'][ $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( $attachment_id, $size = 'thumbnail' ) {
		return $GLOBALS['phbap_test_attachment_image_urls'][ $attachment_id ][ $size ] ?? false;
	}
}

if ( ! function_exists( 'wp_get_original_image_url' ) ) {
	function wp_get_original_image_url( $attachment_id ) {
		unset( $attachment_id );
		++$GLOBALS['phbap_test_original_image_url_calls'];
		return 'https://example.com/uploads/listing-original.jpg';
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		return $GLOBALS['phbap_test_attached_files'][ $attachment_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return $GLOBALS['phbap_test_upload_dir'];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_getimagesize' ) ) {
	function wp_getimagesize( $path ) {
		return getimagesize( $path );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $path ) {
		return file_exists( $path ) ? unlink( $path ) : true; // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		unset( $hook );
		return $value;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	function wp_safe_remote_post( $url, $args ) {
		$GLOBALS['phbap_test_http_request'] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $GLOBALS['phbap_test_http_response'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $name ) {
		return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $items, $field ) {
		return array_map(
			static function ( $item ) use ( $field ) {
				return is_array( $item ) && isset( $item[ $field ] ) ? $item[ $field ] : null;
			},
			$items
		);
	}
}

require_once PHBAP_PATH . 'src/autoload.php';
