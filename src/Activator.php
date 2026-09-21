<?php
/**
 * Plugin activation.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

class Activator {
	/** Activate the plugin. */
	public static function activate() {
		Database::install();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		update_option( 'phbap_baseline_status', 'pending', false );
		update_option( 'phbap_baseline_cursor', 0, false );
		$dependency = new Dependency();
		if ( $dependency->compatible() ) {
			if ( ! wp_next_scheduled( 'phbap_baseline_batch' ) ) {
				wp_schedule_single_event( time() + 5, 'phbap_baseline_batch' );
			}
			if ( ! wp_next_scheduled( 'phbap_recovery_sweep' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'phbap_recovery_sweep' );
			}
		}
	}
}
