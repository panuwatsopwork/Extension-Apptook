<?php
/**
 * Database schema and lifecycle helpers.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Database {

	public static function activate(): void {
		self::create_or_update_tables();
		update_option('extension_cursor_db_version', EXT_CURSOR_DB_VERSION);
	}

	public static function create_or_update_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		$licences_table = $prefix . 'extension_cursor_licences';
		$key_table = $prefix . 'extension_cursor_keys';
		$key_licences_table = $prefix . 'extension_cursor_key_licences';
		$runtime_table = $prefix . 'extension_cursor_licence_runtime';

		$sql = array();

		$sql[] = "CREATE TABLE {$licences_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token VARCHAR(190) NOT NULL,
			token_limit INT UNSIGNED NOT NULL DEFAULT 1,
			duration_days INT UNSIGNED NOT NULL DEFAULT 1,
			note TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'available',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$key_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			key_code VARCHAR(190) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			note TEXT NULL,
			expiry_date DATE NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY key_code (key_code),
			KEY status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$key_licences_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			apptook_key_id BIGINT(20) UNSIGNED NOT NULL,
			licence_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			sort_order INT UNSIGNED NOT NULL DEFAULT 1,
			assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			unassigned_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY apptook_key_id (apptook_key_id),
			KEY licence_id (licence_id),
			KEY status (status),
			KEY sort_order (sort_order)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$runtime_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			licence_id BIGINT(20) UNSIGNED NOT NULL,
			apptook_key_id BIGINT(20) UNSIGNED NULL,
			expiry_date DATE NULL,
			raw_use BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			payload_json LONGTEXT NULL,
			synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY licence_id (licence_id),
			KEY apptook_key_id (apptook_key_id),
			KEY synced_at (synced_at)
		) {$charset_collate};";

		foreach ($sql as $statement) {
			dbDelta($statement);
		}
	}
}
