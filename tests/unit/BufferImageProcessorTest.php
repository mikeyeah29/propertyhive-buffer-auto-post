<?php

use Homer\PropertyHiveBufferAutoPost\Media\BufferImageProcessor;
use PHPUnit\Framework\TestCase;

class BufferImageProcessorTest extends TestCase {
	private $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/phbap-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory . '/2026/09', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$GLOBALS['phbap_test_upload_dir'] = array(
			'basedir' => $this->directory,
			'baseurl' => 'https://example.com/wp-content/uploads',
			'error'   => false,
		);
	}

	protected function tearDown(): void {
		$this->remove_directory( $this->directory );
	}

	public function test_creates_and_reuses_a_resized_metadata_free_jpeg() {
		$source = $this->directory . '/2026/09/property.jpg';
		$image  = imagecreatetruecolor( 1600, 1200 );
		$colour = imagecolorallocate( $image, 20, 100, 180 );
		imagefill( $image, 0, 0, $colour );
		imagejpeg( $image, $source, 90 );
		imagedestroy( $image );
		$this->insert_jpeg_comment( $source, 'CAMERA-METADATA-MARKER' );

		$processor = new BufferImageProcessor();
		$result    = $processor->process(
			array(
				array(
					'url'           => 'https://example.com/wp-content/uploads/2026/09/property.jpg',
					'attachment_id' => 42,
				),
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertStringStartsWith( 'https://example.com/wp-content/uploads/phbap/buffer/buffer-', $result[0]['url'] );
		$this->assertFileExists( $result[0]['path'] );
		$details = getimagesize( $result[0]['path'] );
		$this->assertSame( 1080, $details[0] );
		$this->assertSame( 810, $details[1] );
		$this->assertSame( 'image/jpeg', $details['mime'] );
		$this->assertStringNotContainsString( 'CAMERA-METADATA-MARKER', file_get_contents( $result[0]['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$modified = filemtime( $result[0]['path'] );
		clearstatcache( true, $result[0]['path'] );
		$cached = $processor->process(
			array(
				array(
					'url'           => 'https://example.com/wp-content/uploads/2026/09/property.jpg',
					'attachment_id' => 42,
				),
			)
		);
		$this->assertSame( $result[0]['path'], $cached[0]['path'] );
		$this->assertSame( $modified, filemtime( $cached[0]['path'] ) );
	}

	public function test_leaves_the_external_control_image_on_its_original_host() {
		$processor = new BufferImageProcessor();
		$result    = $processor->process(
			array(
				array(
					'url'             => 'https://images.example.net/control.jpg',
					'attachment_id'   => 0,
					'skip_processing' => true,
				),
			)
		);

		$this->assertSame( 'https://images.example.net/control.jpg', $result[0]['url'] );
		$this->assertArrayNotHasKey( 'path', $result[0] );
	}

	private function insert_jpeg_comment( $path, $comment ) {
		$data    = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$segment = "\xFF\xFE" . pack( 'n', strlen( $comment ) + 2 ) . $comment;
		file_put_contents( $path, substr( $data, 0, 2 ) . $segment . substr( $data, 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
