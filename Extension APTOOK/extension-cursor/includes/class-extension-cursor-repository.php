<?php
/**
 * Data access layer for licences and keys.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Repository {

	public function get_dashboard_stats(): array {
		global $wpdb;

		return array(
			'keys_ready' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->keys_table()}"),
			'licences_available' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->licences_table()} l WHERE l.status = 'available' AND NOT EXISTS (SELECT 1 FROM {$this->key_licences_table()} kl WHERE kl.licence_id = l.id AND kl.status = 'active')"),
			'mapped_licences' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->key_licences_table()} WHERE status = 'active'"),
			'needs_review' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->licences_table()} WHERE status IN ('expired','revoked')"),
		);
	}

	public function get_licences_for_ui(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT l.id, l.token, l.token_limit, l.duration_days, l.note, l.status FROM {$this->licences_table()} l WHERE l.status = 'available' AND NOT EXISTS (SELECT 1 FROM {$this->key_licences_table()} kl WHERE kl.licence_id = l.id AND kl.status = 'active') ORDER BY l.id DESC", ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_all_licences_for_ui(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT l.id, l.token, l.token_limit, l.duration_days, l.note, l.status FROM {$this->licences_table()} l ORDER BY l.id DESC", ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_keys_for_ui(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT k.id, k.key_code, k.status, k.note, k.expiry_date, k.created_at FROM {$this->keys_table()} k WHERE NOT EXISTS (SELECT 1 FROM {$this->key_licences_table()} kl WHERE kl.apptook_key_id = k.id AND kl.status = 'active') ORDER BY k.id DESC", ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_all_keys_for_ui(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT k.id, k.key_code, k.status, k.note, k.expiry_date, k.created_at FROM {$this->keys_table()} k ORDER BY k.id DESC", ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_available_keys_for_ui(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT k.id, k.key_code, k.status, k.note, k.expiry_date, k.created_at FROM {$this->keys_table()} k WHERE NOT EXISTS (SELECT 1 FROM {$this->key_licences_table()} kl WHERE kl.apptook_key_id = k.id AND kl.status = 'active') ORDER BY k.id DESC", ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_monitor_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT k.id, k.key_code, k.status, k.expiry_date, COUNT(kl.id) AS licence_count FROM {$this->keys_table()} k LEFT JOIN {$this->key_licences_table()} kl ON kl.apptook_key_id = k.id AND kl.status = 'active' GROUP BY k.id, k.key_code, k.status, k.expiry_date ORDER BY k.id DESC", ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_assigned_licences_for_key(int $key_id): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT l.id, l.token, l.token_limit, l.duration_days, l.note, l.status, kl.id AS relation_id, kl.sort_order FROM {$this->key_licences_table()} kl INNER JOIN {$this->licences_table()} l ON l.id = kl.licence_id WHERE kl.apptook_key_id = %d AND kl.status = 'active' ORDER BY kl.sort_order ASC, kl.id ASC", $key_id), ARRAY_A);
		return is_array($rows) ? $rows : array();
	}

	public function get_monitor_detail(?int $key_id = null): array {
		global $wpdb;
		$key_row = null;
		if ($key_id !== null) {
			$key_row = $wpdb->get_row($wpdb->prepare("SELECT id, key_code, status, expiry_date FROM {$this->keys_table()} WHERE id = %d", $key_id), ARRAY_A);
		}
		if (! is_array($key_row)) {
			$key_row = $wpdb->get_row("SELECT id, key_code, status, expiry_date FROM {$this->keys_table()} ORDER BY id DESC LIMIT 1", ARRAY_A);
		}
		if (! is_array($key_row)) {
			return array('key' => null, 'licences' => array(), 'total_capacity' => 0, 'total_raw_use' => 0, 'remaining' => 0, 'usage' => 0);
		}
		$licences = $wpdb->get_results($wpdb->prepare("SELECT l.id, l.token, l.token_limit, l.duration_days, l.note, COALESCE(r.expiry_date, NULL) AS runtime_expiry, COALESCE(r.raw_use, 0) AS raw_use FROM {$this->key_licences_table()} kl INNER JOIN {$this->licences_table()} l ON l.id = kl.licence_id LEFT JOIN {$this->runtime_table()} r ON r.licence_id = l.id WHERE kl.apptook_key_id = %d AND kl.status = 'active' ORDER BY kl.sort_order ASC, kl.id ASC", $key_row['id']), ARRAY_A);
		$total_capacity = 0; $total_raw_use = 0;
		foreach ((array) $licences as $licence) { $total_capacity += (int) ($licence['token_limit'] ?? 0); $total_raw_use += (int) ($licence['raw_use'] ?? 0); }
		$remaining = max(0, $total_capacity - $total_raw_use);
		$usage = $total_capacity > 0 ? (int) round(($total_raw_use / $total_capacity) * 100) : 0;
		return array('key' => $key_row, 'licences' => is_array($licences) ? $licences : array(), 'total_capacity' => $total_capacity, 'total_raw_use' => $total_raw_use, 'remaining' => $remaining, 'usage' => $usage);
	}

	public function insert_licence(array $row): bool {
		global $wpdb;
		return (bool) $wpdb->insert($this->licences_table(), array(
			'token' => sanitize_text_field((string) ($row['token'] ?? '')),
			'token_limit' => absint($row['token_limit'] ?? 0),
			'duration_days' => absint($row['duration_days'] ?? 1),
			'note' => sanitize_textarea_field((string) ($row['note'] ?? '')),
			'status' => 'available',
		), array('%s','%d','%d','%s','%s'));
	}

	public function insert_multiple_licences(array $rows): array {
		$results = array();
		foreach ($rows as $row) {
			$results[] = array('ok' => $this->insert_licence($row), 'token' => (string) ($row['token'] ?? ''));
		}
		return $results;
	}

	public function insert_key(array $row): bool {
		global $wpdb;
		$data = array(
			'key_code' => sanitize_text_field((string) ($row['key_code'] ?? '')),
			'status' => 'inactive',
			'note' => sanitize_textarea_field((string) ($row['note'] ?? '')),
		);
		$formats = array('%s', '%s', '%s');
		if (! empty($row['expiry_date'])) {
			$data['expiry_date'] = sanitize_text_field((string) $row['expiry_date']);
			$formats[] = '%s';
		}
		return (bool) $wpdb->insert($this->keys_table(), $data, $formats);
	}

	public function update_key(int $id, array $row): bool {
		global $wpdb;
		$data = array(
			'key_code' => sanitize_text_field((string) ($row['key_code'] ?? '')),
			'note' => sanitize_textarea_field((string) ($row['note'] ?? '')),
		);
		$formats = array('%s', '%s');
		if (! empty($row['expiry_date'])) {
			$data['expiry_date'] = sanitize_text_field((string) $row['expiry_date']);
			$formats[] = '%s';
		}
		return (bool) $wpdb->update($this->keys_table(), $data, array('id' => $id), $formats, array('%d'));
	}

	public function assign_licences_to_key(int $key_id, array $licence_ids): array {
		global $wpdb;
		$results = array();
		$sort_order = 1;
		foreach ($licence_ids as $licence_id) {
			$licence_id = absint($licence_id);
			$existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->key_licences_table()} WHERE apptook_key_id = %d AND licence_id = %d LIMIT 1", $key_id, $licence_id));
			if ($existing_id > 0) {
				$ok = (bool) $wpdb->update($this->key_licences_table(), array('status' => 'active', 'sort_order' => $sort_order++), array('id' => $existing_id), array('%s','%d'), array('%d'));
			} else {
				$ok = (bool) $wpdb->insert($this->key_licences_table(), array(
					'apptook_key_id' => $key_id,
					'licence_id' => $licence_id,
					'status' => 'active',
					'sort_order' => $sort_order++,
				), array('%d','%d','%s','%d'));
			}
			if ($ok) {
				$updated = $wpdb->update($this->licences_table(), array('status' => 'assigned'), array('id' => $licence_id), array('%s'), array('%d'));
				if ($updated === false && $wpdb->last_error) {
					$ok = false;
				}
			}
			$results[] = array('licence_id' => $licence_id, 'ok' => $ok, 'existing_id' => $existing_id, 'last_error' => $wpdb->last_error);
		}
		return $results;
	}

	public function unassign_licences_from_key(int $key_id, array $licence_ids): int {
		global $wpdb;
		$changed = 0;
		foreach ($licence_ids as $licence_id) {
			$licence_id = absint($licence_id);
			$changed += (int) $wpdb->update($this->key_licences_table(), array('status' => 'inactive', 'unassigned_at' => current_time('mysql')), array('apptook_key_id' => $key_id, 'licence_id' => $licence_id, 'status' => 'active'), array('%s','%s'), array('%d','%d','%s'));
			$wpdb->update($this->licences_table(), array('status' => 'available'), array('id' => $licence_id), array('%s'), array('%d'));
		}
		return $changed;
	}

	public function replace_licences_for_key(int $key_id, array $licence_ids): array {
		global $wpdb;
		$wpdb->update($this->key_licences_table(), array('status' => 'inactive', 'unassigned_at' => current_time('mysql')), array('apptook_key_id' => $key_id, 'status' => 'active'), array('%s','%s'), array('%d','s'));
		return $this->assign_licences_to_key($key_id, $licence_ids);
	}

	public function insert_runtime_snapshot(array $row): bool {
		global $wpdb;
		return (bool) $wpdb->insert($this->runtime_table(), array(
			'licence_id' => absint($row['licence_id'] ?? 0),
			'apptook_key_id' => ! empty($row['apptook_key_id']) ? absint($row['apptook_key_id']) : null,
			'expiry_date' => ! empty($row['expiry_date']) ? sanitize_text_field((string) $row['expiry_date']) : null,
			'raw_use' => absint($row['raw_use'] ?? 0),
			'payload_json' => ! empty($row['payload_json']) ? wp_json_encode($row['payload_json']) : null,
		), array('%d','%d','%s','%d','%s'));
	}

	public function delete_licence(int $id): bool {
		global $wpdb;
		$licence_id = absint($id);
		if ($licence_id < 1) {
			return false;
		}

		$wpdb->delete($this->runtime_table(), array('licence_id' => $licence_id), array('%d'));
		$wpdb->delete($this->key_licences_table(), array('licence_id' => $licence_id), array('%d'));
		return (bool) $wpdb->delete($this->licences_table(), array('id' => $licence_id), array('%d'));
	}

	public function delete_key(int $id): bool {
		global $wpdb;
		$key_id = absint($id);
		if ($key_id < 1) {
			return false;
		}

		$active_licence_ids = $wpdb->get_col($wpdb->prepare("SELECT licence_id FROM {$this->key_licences_table()} WHERE apptook_key_id = %d AND status = 'active'", $key_id));
		$wpdb->query('START TRANSACTION');
		try {
			$wpdb->delete($this->runtime_table(), array('apptook_key_id' => $key_id), array('%d'));
			$wpdb->delete($this->key_licences_table(), array('apptook_key_id' => $key_id), array('%d'));
			$deleted = (bool) $wpdb->delete($this->keys_table(), array('id' => $key_id), array('%d'));
			if (! $deleted) {
				throw new RuntimeException('Could not delete key.');
			}
			if (! empty($active_licence_ids)) {
				$placeholders = implode(',', array_fill(0, count($active_licence_ids), '%d'));
				$sql = $wpdb->prepare("UPDATE {$this->licences_table()} SET status = 'available' WHERE id IN ($placeholders)", array_map('absint', $active_licence_ids));
				$wpdb->query($sql);
			}
			$wpdb->query('COMMIT');
			return true;
		} catch (Throwable $e) {
			$wpdb->query('ROLLBACK');
			return false;
		}
	}

	private function licences_table(): string { global $wpdb; return $wpdb->prefix . 'extension_cursor_licences'; }
	private function keys_table(): string { global $wpdb; return $wpdb->prefix . 'extension_cursor_keys'; }
	private function key_licences_table(): string { global $wpdb; return $wpdb->prefix . 'extension_cursor_key_licences'; }
	private function runtime_table(): string { global $wpdb; return $wpdb->prefix . 'extension_cursor_licence_runtime'; }
}
