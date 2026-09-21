<?php
/**
 * Plugin deactivation.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

class Deactivator {
	/** Deactivate recurring work without deleting user data. */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'phbap_process_queue' );
		wp_clear_scheduled_hook( 'phbap_baseline_batch' );
		wp_clear_scheduled_hook( 'phbap_recovery_sweep' );
		wp_clear_scheduled_hook( 'phbap_cleanup_media' );
		delete_option( 'phbap_worker_lock' );
		update_option( 'phbap_baseline_status', 'pending', false );
	}
}
