<?php
/**
 * Database installer and table definitions.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_DB {

	private const SCHEMA_VERSION = '1.0.1';
	private const OPTION_KEY     = 'extension_cursor_schema_version';

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::table_names();

		$sql = array();

		$sql[] = "CREATE TABLE {$tables['stock_keys']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_key VARCHAR(191) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'available',
			provider VARCHAR(100) NOT NULL DEFAULT '',
			expire_at DATETIME NULL,
			max_devices INT UNSIGNED NOT NULL DEFAULT 1,
			token_capacity INT UNSIGNED NOT NULL DEFAULT 100,
			note TEXT NULL,
			last_check_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_source_key (source_key),
			KEY idx_status (status),
			KEY idx_provider (provider),
			KEY idx_expire_at (expire_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['groups']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_code VARCHAR(100) NOT NULL,
			name VARCHAR(191) NOT NULL,
			mode VARCHAR(32) NOT NULL DEFAULT 'loop',
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_group_code (group_code),
			KEY idx_mode (mode),
			KEY idx_status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['group_keys']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL,
			stock_key_id BIGINT UNSIGNED NOT NULL,
			sequence INT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_group_sequence (group_id, sequence),
			UNIQUE KEY uq_group_stock_key (group_id, stock_key_id),
			KEY idx_stock_key_id (stock_key_id),
			KEY idx_status (status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['apptook_keys']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			apptook_key VARCHAR(191) NOT NULL,
			group_id BIGINT UNSIGNED NOT NULL,
			key_type VARCHAR(32) NOT NULL DEFAULT 'loop',
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			mode VARCHAR(32) NOT NULL DEFAULT 'real',
			expire_at DATETIME NULL,
			current_group_key_id BIGINT UNSIGNED NULL,
			current_sequence INT UNSIGNED NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_apptook_key (apptook_key),
			KEY idx_group_id (group_id),
			KEY idx_status (status),
			KEY idx_mode (mode),
			KEY idx_expire_at (expire_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['usage_logs']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			apptook_key_id BIGINT UNSIGNED NOT NULL,
			stock_key_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(64) NOT NULL,
			raw_usage DECIMAL(18,6) NULL,
			display_usage DECIMAL(18,6) NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_apptook_key_id (apptook_key_id),
			KEY idx_stock_key_id (stock_key_id),
			KEY idx_event_type (event_type),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['switch_logs']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			apptook_key_id BIGINT UNSIGNED NOT NULL,
			from_stock_key_id BIGINT UNSIGNED NULL,
			to_stock_key_id BIGINT UNSIGNED NULL,
			reason VARCHAR(100) NOT NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_apptook_key_id (apptook_key_id),
			KEY idx_reason (reason),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['simulation_licenses']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			license_code VARCHAR(191) NOT NULL,
			name VARCHAR(191) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			mode VARCHAR(32) NOT NULL DEFAULT 'simulation',
			token_capacity INT UNSIGNED NOT NULL DEFAULT 100,
			current_raw_usage DECIMAL(18,6) NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_license_code (license_code),
			KEY idx_status (status),
			KEY idx_mode (mode),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$tables['simulation_licenses']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			license_code VARCHAR(191) NOT NULL,
			name VARCHAR(191) NOT NULL,
			mode VARCHAR(32) NOT NULL DEFAULT 'simulation',
			status VARCHAR(32) NOT NULL DEFAULT 'active',
			token_capacity INT UNSIGNED NOT NULL DEFAULT 100,
			current_raw_usage DECIMAL(18,6) NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uq_license_code (license_code),
			KEY idx_status (status),
			KEY idx_mode (mode)
		) {$charset_collate};";

		foreach ($sql as $statement) {
			dbDelta($statement);
		}

		update_option(self::OPTION_KEY, self::SCHEMA_VERSION, false);
	}

	/**
	 * @return array<string, string>
	 */
	public static function table_names(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'apptook_';

		return array(
			'stock_keys'         => $prefix . 'stock_keys',
			'groups'             => $prefix . 'groups',
			'group_keys'         => $prefix . 'group_keys',
			'apptook_keys'       => $prefix . 'keys',
			'usage_logs'         => $prefix . 'usage_logs',
			'switch_logs'        => $prefix . 'switch_logs',
			'simulation_licenses' => $prefix . 'simulation_licenses',
		);
	}
}
