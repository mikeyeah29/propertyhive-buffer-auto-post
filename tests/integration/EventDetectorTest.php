<?php

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	return;
}

use Homer\PropertyHiveBufferAutoPost\Content\TemplateRenderer;
use Homer\PropertyHiveBufferAutoPost\Database;
use Homer\PropertyHiveBufferAutoPost\Events\EventFactory;
use Homer\PropertyHiveBufferAutoPost\Events\EventRepository;
use Homer\PropertyHiveBufferAutoPost\Logger;
use Homer\PropertyHiveBufferAutoPost\Property\EventDetector;
use Homer\PropertyHiveBufferAutoPost\Property\PropertySnapshot;
use Homer\PropertyHiveBufferAutoPost\Settings;
use Homer\PropertyHiveBufferAutoPost\Support\ScheduleCalculator;

class EventDetectorTest extends WP_UnitTestCase {
	private $detector;

	public function set_up() {
		parent::set_up();
		register_post_type( 'property', array( 'public' => true ) );
		register_taxonomy( 'availability', array( 'property' ) );
		register_taxonomy( 'property_type', array( 'property' ) );
		Database::install();
		update_option( 'phbap_baseline_status', 'ready', false );
		update_option(
			Settings::CHANNEL_OPTION,
			array(
				array(
					'id'          => 'channel-1',
					'name'        => 'Facebook',
					'displayName' => 'Facebook',
					'service'     => 'facebook',
				),
			),
			false
		);
		$settings                           = Settings::defaults();
		$settings['event_new_listing']      = true;
		$settings['event_price_reduction']  = true;
		$settings['event_sold']             = true;
		$settings['buffer_api_key']         = 'test-key';
		$settings['buffer_organisation_id'] = 'org-1';
		$settings['buffer_channel_ids']     = array( 'channel-1' );
		$settings['sold_overlay_id']        = 99;
		update_option( Settings::OPTION, $settings, false );

		$snapshot       = new PropertySnapshot();
		$repository     = new EventRepository();
		$factory        = new EventFactory( $snapshot, new TemplateRenderer(), new ScheduleCalculator() );
		$this->detector = new EventDetector( $snapshot, $factory, $repository, new Logger() );
	}

	public function test_first_observation_is_a_baseline_not_an_event() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $id, '_department', 'residential-sales' );
		update_post_meta( $id, '_price_actual', 500000 );
		$this->detector->evaluate( $id );
		global $wpdb;
		$table = Database::table( 'events' );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_price_reduction_creates_only_one_event_for_repeated_evaluation() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, '_department', 'residential-sales' );
		update_post_meta( $id, '_on_market', 'yes' );
		update_post_meta( $id, '_price_actual', 500000 );
		$this->detector->evaluate( $id );
		update_post_meta( $id, '_price_actual', 475000 );
		$this->detector->evaluate( $id );
		$this->detector->evaluate( $id );
		global $wpdb;
		$table = Database::table( 'events' );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE event_type='price_reduction'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}
}
