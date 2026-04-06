<?php
/**
 * External database connector and business-table sync.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_External_DB {

	private static ?self $instance = null;

	private ?wpdb $db = null;

	private string $last_error = '';

	public function get_last_error(): string {
		return $this->last_error;
	}

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_configured(): bool {
		$password = defined('APPTOOK_EXT_DB_PASSWORD') ? (string) APPTOOK_EXT_DB_PASSWORD : '';
		return defined('APPTOOK_EXT_DB_HOST')
			&& defined('APPTOOK_EXT_DB_NAME')
			&& defined('APPTOOK_EXT_DB_USER')
			&& defined('APPTOOK_EXT_DB_PASSWORD')
			&& APPTOOK_EXT_DB_HOST !== ''
			&& APPTOOK_EXT_DB_NAME !== ''
			&& APPTOOK_EXT_DB_USER !== ''
			&& $password !== ''
			&& $password !== 'PUT_YOUR_DB_PASSWORD_HERE';
	}

	private function get_db(): ?wpdb {
		if (! $this->is_configured()) {
			$this->last_error = 'External DB config missing or placeholder password.';
			return null;
		}
		if ($this->db instanceof wpdb) {
			return $this->db;
		}

		$host = (string) APPTOOK_EXT_DB_HOST;
		if (defined('APPTOOK_EXT_DB_PORT') && (int) APPTOOK_EXT_DB_PORT > 0 && strpos($host, ':') === false) {
			$host .= ':' . (int) APPTOOK_EXT_DB_PORT;
		}

		$this->db = new wpdb(
			(string) APPTOOK_EXT_DB_USER,
			(string) APPTOOK_EXT_DB_PASSWORD,
			(string) APPTOOK_EXT_DB_NAME,
			$host
		);
		$this->db->show_errors(false);
		if (! empty($this->db->error)) {
			$this->last_error = (string) $this->db->error;
			return null;
		}
		return $this->db;
	}

	public function table(string $suffix): string {
		$prefix = defined('APPTOOK_EXT_DB_PREFIX') && APPTOOK_EXT_DB_PREFIX !== ''
			? (string) APPTOOK_EXT_DB_PREFIX
			: 'apptook_';
		return $prefix . $suffix;
	}

	public function maybe_create_tables(): void {
		$db = $this->get_db();
		if (! $db) {
			if ($this->last_error !== '') {
				error_log('[Apptook DS] External DB connect failed: ' . $this->last_error);
			}
			return;
		}

		$charset_collate = $db->get_charset_collate();

		$products = $this->table('products');
		$orders = $this->table('orders');
		$order_logs = $this->table('order_logs');
		$product_logs = $this->table('product_logs');

		$db->query("CREATE TABLE IF NOT EXISTS {$products} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED NOT NULL,
			slug VARCHAR(190) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			excerpt TEXT NULL,
			content LONGTEXT NULL,
			permalink VARCHAR(500) NOT NULL DEFAULT '',
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			status VARCHAR(30) NOT NULL DEFAULT 'inactive',
			wp_post_status VARCHAR(30) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id (wp_post_id),
			KEY status (status)
		) {$charset_collate}");

		$db->query("CREATE TABLE IF NOT EXISTS {$orders} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_order_post_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			status VARCHAR(30) NOT NULL,
			slip_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			license_key TEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wp_order_post_id (wp_order_post_id),
			KEY customer_id (customer_id),
			KEY product_id (product_id),
			KEY status (status)
		) {$charset_collate}");

		$db->query("CREATE TABLE IF NOT EXISTS {$order_logs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_order_post_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(80) NOT NULL,
			old_status VARCHAR(30) NULL,
			new_status VARCHAR(30) NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY wp_order_post_id (wp_order_post_id),
			KEY event (event)
		) {$charset_collate}");

		$db->query("CREATE TABLE IF NOT EXISTS {$product_logs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(80) NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY wp_post_id (wp_post_id),
			KEY event (event)
		) {$charset_collate}");
	}

	public function maybe_create_marketplace_tables(): bool {
		$db = $this->get_db();
		if (! $db) {
			return false;
		}

		$charset_collate = $db->get_charset_collate();
		$product_durations = $this->table('product_durations');
		$product_types     = $this->table('product_types');

		$ok1 = $db->query("CREATE TABLE IF NOT EXISTS {$product_durations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED NOT NULL,
			months INT UNSIGNED NOT NULL DEFAULT 1,
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY wp_post_id (wp_post_id),
			KEY months (months),
			KEY is_active (is_active)
		) {$charset_collate}");

		$ok2 = $db->query("CREATE TABLE IF NOT EXISTS {$product_types} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED NOT NULL,
			type_key VARCHAR(64) NOT NULL DEFAULT '',
			type_label VARCHAR(190) NOT NULL DEFAULT '',
			price_modifier DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY wp_post_id (wp_post_id),
			KEY type_key (type_key),
			KEY is_active (is_active)
		) {$charset_collate}");

		return $ok1 !== false && $ok2 !== false;
	}

	public function ensure_manual_setup(): bool {
		if (! $this->is_configured()) {
			$this->last_error = 'External DB config missing.';
			return false;
		}
		$this->maybe_create_tables();
		return $this->maybe_create_marketplace_tables();
	}

	public function sync_product(int $post_id): bool {
		$db = $this->get_db();
		$post = get_post($post_id);
		if (! $db || ! $post || $post->post_type !== 'apptook_product') {
			return false;
		}

		$status = $post->post_status === 'publish' ? 'active' : 'inactive';
		$data = array(
			'wp_post_id' => $post_id,
			'slug' => (string) $post->post_name,
			'title' => get_the_title($post_id),
			'excerpt' => (string) $post->post_excerpt,
			'content' => (string) $post->post_content,
			'permalink' => (string) get_permalink($post_id),
			'price' => (float) get_post_meta($post_id, '_apptook_price', true),
			'status' => $status,
			'wp_post_status' => (string) $post->post_status,
			'updated_at' => current_time('mysql', true),
		);

		$table = $this->table('products');
		$exists = (int) $db->get_var($db->prepare("SELECT id FROM {$table} WHERE wp_post_id = %d", $post_id));
		if ($exists > 0) {
			$ok = $db->update($table, $data, array('wp_post_id' => $post_id));
		} else {
			$ok = $db->insert($table, $data);
		}

		$this->add_product_log($post_id, $ok === false ? 'product_sync_failed' : 'product_sync_success');
		return $ok !== false;
	}

	public function upsert_order_from_wp(int $order_id): bool {
		$db = $this->get_db();
		$order = get_post($order_id);
		if (! $db || ! $order || $order->post_type !== 'apptook_order') {
			return false;
		}

		$data = array(
			'wp_order_post_id' => $order_id,
			'customer_id' => (int) get_post_meta($order_id, '_apptook_customer_id', true),
			'product_id' => (int) get_post_meta($order_id, '_apptook_product_id', true),
			'amount' => (float) get_post_meta($order_id, '_apptook_amount', true),
			'status' => (string) get_post_meta($order_id, '_apptook_status', true),
			'slip_id' => (int) get_post_meta($order_id, '_apptook_slip_id', true),
			'license_key' => (string) get_post_meta($order_id, '_apptook_license_key', true),
			'updated_at' => current_time('mysql', true),
		);

		$table = $this->table('orders');
		$exists = (int) $db->get_var($db->prepare("SELECT id FROM {$table} WHERE wp_order_post_id = %d", $order_id));
		if ($exists > 0) {
			$ok = $db->update($table, $data, array('wp_order_post_id' => $order_id));
		} else {
			$ok = $db->insert($table, $data);
		}
		return $ok !== false;
	}

	public function add_order_log(int $order_id, string $event, string $old_status = '', string $new_status = '', string $note = ''): bool {
		$db = $this->get_db();
		if (! $db) {
			return false;
		}
		$ok = $db->insert(
			$this->table('order_logs'),
			array(
				'wp_order_post_id' => $order_id,
				'event' => $event,
				'old_status' => $old_status,
				'new_status' => $new_status,
				'actor_user_id' => get_current_user_id(),
				'note' => $note,
				'created_at' => current_time('mysql', true),
			)
		);
		return $ok !== false;
	}

	public function add_product_log(int $post_id, string $event, string $note = ''): bool {
		$db = $this->get_db();
		if (! $db) {
			return false;
		}
		$ok = $db->insert(
			$this->table('product_logs'),
			array(
				'wp_post_id' => $post_id,
				'event' => $event,
				'actor_user_id' => get_current_user_id(),
				'note' => $note,
				'created_at' => current_time('mysql', true),
			)
		);
		return $ok !== false;
	}

	public function sync_product_purchase_options(int $post_id, array $durations, array $types): bool {
		$db = $this->get_db();
		if (! $db) {
			return false;
		}

		$durations_table = $this->table('product_durations');
		$types_table = $this->table('product_types');
		$now = current_time('mysql', true);

		$db->delete($durations_table, array('wp_post_id' => $post_id));
		$db->delete($types_table, array('wp_post_id' => $post_id));

		foreach ($durations as $row) {
			$db->insert($durations_table, array(
				'wp_post_id' => $post_id,
				'months' => (int) ($row['months'] ?? 1),
				'price' => (float) ($row['price'] ?? 0),
				'is_default' => ! empty($row['is_default']) ? 1 : 0,
				'is_active' => ! isset($row['is_active']) || (int) $row['is_active'] === 1 ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			));
		}

		foreach ($types as $row) {
			$db->insert($types_table, array(
				'wp_post_id' => $post_id,
				'type_key' => (string) ($row['type_key'] ?? ''),
				'type_label' => (string) ($row['type_label'] ?? ''),
				'price_modifier' => (float) ($row['price_modifier'] ?? 0),
				'is_default' => ! empty($row['is_default']) ? 1 : 0,
				'is_active' => ! isset($row['is_active']) || (int) $row['is_active'] === 1 ? 1 : 0,
				'created_at' => $now,
				'updated_at' => $now,
			));
		}

		return true;
	}

	public function get_product_purchase_options(int $post_id): array {
		$db = $this->get_db();
		if (! $db) {
			return array('durations' => array(), 'types' => array());
		}

		$durations_table = $this->table('product_durations');
		$types_table = $this->table('product_types');

		$durations = $db->get_results(
			$db->prepare("SELECT months, price, is_default, is_active FROM {$durations_table} WHERE wp_post_id = %d AND is_active = 1 ORDER BY months ASC", $post_id),
			ARRAY_A
		);
		$types = $db->get_results(
			$db->prepare("SELECT type_key, type_label, price_modifier, is_default, is_active FROM {$types_table} WHERE wp_post_id = %d AND is_active = 1 ORDER BY id ASC", $post_id),
			ARRAY_A
		);

		return array(
			'durations' => is_array($durations) ? $durations : array(),
			'types' => is_array($types) ? $types : array(),
		);
	}

}
