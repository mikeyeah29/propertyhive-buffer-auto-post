<?php
/**
 * Plugin service composition.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

use Homer\PropertyHiveBufferAutoPost\Admin\AdminPage;
use Homer\PropertyHiveBufferAutoPost\Content\TemplateRenderer;
use Homer\PropertyHiveBufferAutoPost\Events\EventFactory;
use Homer\PropertyHiveBufferAutoPost\Events\EventRepository;
use Homer\PropertyHiveBufferAutoPost\Media\BufferImageProcessor;
use Homer\PropertyHiveBufferAutoPost\Media\ImageValidator;
use Homer\PropertyHiveBufferAutoPost\Media\OverlayRenderer;
use Homer\PropertyHiveBufferAutoPost\Property\BaselineProcessor;
use Homer\PropertyHiveBufferAutoPost\Property\EventDetector;
use Homer\PropertyHiveBufferAutoPost\Property\PropertySnapshot;
use Homer\PropertyHiveBufferAutoPost\Support\ScheduleCalculator;

class Plugin {
	private static $instance;
	private $booted = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Build services and register hooks. */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'propertyhive-buffer-auto-post', false, dirname( PHBAP_BASENAME ) . '/languages' );
		if ( Database::SCHEMA_VERSION !== get_option( 'phbap_schema_version' ) ) {
			Database::install();
		}

		$dependency = new Dependency();
		$snapshot   = new PropertySnapshot();
		$repository = new EventRepository();
		$logger     = new Logger();
		$factory    = new EventFactory( $snapshot, new TemplateRenderer(), new ScheduleCalculator() );

		$services = array(
			$dependency,
			new AdminPage( $dependency, $factory, $repository, $logger ),
		);
		if ( $dependency->compatible() ) {
			$services[] = new BaselineProcessor( $snapshot );
			$services[] = new Worker( $repository, new BufferImageProcessor(), new ImageValidator(), new OverlayRenderer(), $logger );
			$services[] = new EventDetector( $snapshot, $factory, $repository, $logger );
		}

		foreach ( $services as $service ) {
			if ( $service instanceof Contracts\Hookable ) {
				$service->register_hooks();
			}
		}

		add_filter( 'plugin_action_links_' . PHBAP_BASENAME, array( $this, 'action_links' ) );
		if ( $dependency->compatible() ) {
			$this->ensure_cron();
		}
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=phbap' ) ) . '">' . esc_html__( 'Settings', 'propertyhive-buffer-auto-post' ) . '</a>' );
		return $links;
	}

	private function ensure_cron() {
		if ( 'ready' !== get_option( 'phbap_baseline_status', 'pending' ) && ! wp_next_scheduled( 'phbap_baseline_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'phbap_baseline_batch' );
		}
		if ( ! wp_next_scheduled( 'phbap_recovery_sweep' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'phbap_recovery_sweep' );
		}
	}

	private function __construct() {}
	private function __clone() {}
}
