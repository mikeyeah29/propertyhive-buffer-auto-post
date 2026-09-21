<?php
/**
 * Database schema and table names.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost;

class Database {
	const SCHEMA_VERSION = '1.0.0';

	/** Get a plugin table name. */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'phbap_' . $name;
	}

	/** Create or update plugin tables. */
	public static function install() {
		global $wpdb;

		$charset    = $wpdb->get_charset_collate();
		$events     = self::table( 'events' );
		$deliveries = self::table( 'deliveries' );
		$logs       = self::table( 'logs' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_key varchar(191) NOT NULL,
				property_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(32) NOT NULL,
				source varchar(24) NOT NULL DEFAULT 'automatic',
				payload longtext NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'pending',
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_key (event_key),
				KEY property_event (property_id,event_type),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$deliveries} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_id bigint(20) unsigned NOT NULL,
				channel_id varchar(191) NOT NULL,
				channel_name varchar(191) NOT NULL DEFAULT '',
				service varchar(32) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_gmt datetime NULL,
				buffer_post_id varchar(191) NULL,
				due_at_gmt datetime NULL,
				error_message text NULL,
				started_at_gmt datetime NULL,
				created_at_gmt datetime NOT NULL,
				updated_at_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_channel (event_id,channel_id),
				KEY due_status (status,next_attempt_gmt)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at_gmt datetime NOT NULL,
				property_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(32) NOT NULL,
				source varchar(24) NOT NULL,
				channel_id varchar(191) NOT NULL DEFAULT '',
				channel_name varchar(191) NOT NULL DEFAULT '',
				service varchar(32) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL,
				attempt smallint(5) unsigned NOT NULL DEFAULT 0,
				buffer_post_id varchar(191) NULL,
				message text NULL,
				PRIMARY KEY  (id),
				KEY created_at_gmt (created_at_gmt)
			) {$charset};"
		);

		update_option( 'phbap_schema_version', self::SCHEMA_VERSION, false );
	}
}
