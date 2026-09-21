<?php
/**
 * Property Hive dependency status.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

use Homer\PropertyHiveBufferAutoPost\Contracts\Hookable;

class Dependency implements Hookable {
	/** Whether the installed Property Hive version is supported. */
	public function compatible() {
		return defined( 'PH_VERSION' ) && version_compare( PH_VERSION, PHBAP_MIN_PROPERTYHIVE_VERSION, '>=' );
	}

	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function notice() {
		if ( $this->compatible() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$message = ! defined( 'PH_VERSION' )
			? __( 'PropertyHive Buffer Auto Post requires Property Hive to be installed and active. Automatic event listeners are disabled.', 'propertyhive-buffer-auto-post' )
			: sprintf(
				/* translators: %s: minimum Property Hive version. */
				__( 'PropertyHive Buffer Auto Post requires Property Hive %s or newer. Automatic event listeners are disabled.', 'propertyhive-buffer-auto-post' ),
				PHBAP_MIN_PROPERTYHIVE_VERSION
			);
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
