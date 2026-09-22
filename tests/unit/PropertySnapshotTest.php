<?php

use Homer\PropertyHiveBufferAutoPost\Property\PropertySnapshot;
use PHPUnit\Framework\TestCase;

class PropertySnapshotTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['phbap_test_options']                  = array();
		$GLOBALS['phbap_test_post_meta']                = array();
		$GLOBALS['phbap_test_attachment_urls']          = array();
		$GLOBALS['phbap_test_attachment_image_urls']    = array();
		$GLOBALS['phbap_test_original_image_url_calls'] = 0;
	}

	public function test_uses_large_attachment_url_instead_of_original_upload() {
		$GLOBALS['phbap_test_post_meta'][77]['_photos']                 = array( 42 );
		$GLOBALS['phbap_test_attachment_urls'][42]                      = 'https://example.com/uploads/listing-scaled.jpg';
		$GLOBALS['phbap_test_attachment_image_urls'][42]['large']       = 'https://example.com/uploads/listing-1024x683.jpg';
		$GLOBALS['phbap_test_options']['propertyhive_images_stored_as'] = 'attachments';

		$method = new ReflectionMethod( PropertySnapshot::class, 'images' );
		$method->setAccessible( true );
		$images = $method->invoke( new PropertySnapshot(), 77 );

		$this->assertSame( 'https://example.com/uploads/listing-1024x683.jpg', $images[0]['url'] );
		$this->assertSame( 42, $images[0]['attachment_id'] );
		$this->assertSame( 0, $GLOBALS['phbap_test_original_image_url_calls'] );
	}
}
