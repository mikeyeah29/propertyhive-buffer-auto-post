<?php
/**
 * Plugin Name: PropertyHive Buffer Auto Post
 * Plugin URI: https://rockettwd.co.uk/
 * Description: Sends Property Hive listing events to selected Facebook and Instagram channels through Buffer.
 * Version: 1.0.6
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Rockett Web Design
 * Author URI: https://rockettwd.co.uk/
 * Text Domain: propertyhive-buffer-auto-post
 * Domain Path: /languages
 *
 * @package PropertyHiveBufferAutoPost
 */

defined( 'ABSPATH' ) || exit;

define( 'PHBAP_VERSION', '1.0.6' );
define( 'PHBAP_MIN_PROPERTYHIVE_VERSION', '2.0.16' );
define( 'PHBAP_FILE', __FILE__ );
define( 'PHBAP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PHBAP_URL', plugin_dir_url( __FILE__ ) );
define( 'PHBAP_BASENAME', plugin_basename( __FILE__ ) );

require_once PHBAP_PATH . 'src/autoload.php';

register_activation_hook( PHBAP_FILE, array( \Homer\PropertyHiveBufferAutoPost\Activator::class, 'activate' ) );
register_deactivation_hook( PHBAP_FILE, array( \Homer\PropertyHiveBufferAutoPost\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Homer\PropertyHiveBufferAutoPost\Plugin::instance()->boot();
	}
);
