<?php
/**
 * Admin page and AJAX handlers.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Admin {

	private static ?self $instance = null;
	private Extension_Cursor_Service $service;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->service = new Extension_Cursor_Service();

		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('wp_ajax_extension_cursor_save_licence', array($this, 'ajax_save_licence'));
		add_action('wp_ajax_extension_cursor_import_licences', array($this, 'ajax_import_licences'));
		add_action('wp_ajax_extension_cursor_delete_licence', array($this, 'ajax_delete_licence'));
		add_action('wp_ajax_extension_cursor_delete_key', array($this, 'ajax_delete_key'));
		add_action('wp_ajax_extension_cursor_list_licences', array($this, 'ajax_list_licences'));
		add_action('wp_ajax_extension_cursor_save_key', array($this, 'ajax_save_key'));
		add_action('wp_ajax_extension_cursor_assign_licences', array($this, 'ajax_assign_licences'));
		add_action('wp_ajax_extension_cursor_unassign_licences', array($this, 'ajax_unassign_licences'));
		add_action('wp_ajax_extension_cursor_replace_licences', array($this, 'ajax_replace_licences'));
		add_action('wp_ajax_extension_cursor_save_runtime_snapshot', array($this, 'ajax_save_runtime_snapshot'));
		add_action('wp_ajax_extension_cursor_dashboard_snapshot', array($this, 'ajax_dashboard_snapshot'));
		add_action('wp_ajax_extension_cursor_debug_available_licences', array($this, 'ajax_debug_available_licences'));
		add_action('wp_ajax_extension_cursor_debug_assignment_state', array($this, 'ajax_debug_assignment_state'));
	}

	public function register_admin_menu(): void {
		add_menu_page(__('Extension Cursor', 'extension-cursor'), __('Extension Cursor', 'extension-cursor'), 'manage_options', 'extension-cursor-admin', array($this, 'render_admin_page'), 'dashicons-admin-generic', 58);
	}

	public function enqueue_assets(string $hook): void {
		if ($hook !== 'toplevel_page_extension-cursor-admin') {
			return;
		}

		$css_version = Extension_Cursor_Loader::asset_version('assets/admin.css');
		$js_version = Extension_Cursor_Loader::asset_version('assets/js/admin.js');
		wp_enqueue_style('extension-cursor-admin', EXT_CURSOR_URL . 'assets/admin.css', array(), $css_version);
		wp_enqueue_script('extension-cursor-admin-api', EXT_CURSOR_URL . 'assets/js/ec-api.js', array('jquery'), $js_version, true);
		wp_enqueue_script('extension-cursor-admin-ui', EXT_CURSOR_URL . 'assets/js/ec-ui.js', array('jquery', 'extension-cursor-admin-api'), $js_version, true);
		wp_enqueue_script('extension-cursor-admin-renderers', EXT_CURSOR_URL . 'assets/js/ec-renderers.js', array('jquery', 'extension-cursor-admin-ui'), $js_version, true);
		wp_enqueue_script('extension-cursor-admin-state', EXT_CURSOR_URL . 'assets/js/ec-state.js', array('jquery'), $js_version, true);
		wp_enqueue_script('extension-cursor-admin-monitor-edit', EXT_CURSOR_URL . 'assets/js/monitor-edit.js', array('jquery', 'extension-cursor-admin-api', 'extension-cursor-admin-ui', 'extension-cursor-admin-renderers'), $js_version, true);
		wp_enqueue_script('extension-cursor-admin-actions', EXT_CURSOR_URL . 'assets/js/ec-actions.js', array('jquery', 'extension-cursor-admin-api', 'extension-cursor-admin-ui', 'extension-cursor-admin-renderers', 'extension-cursor-admin-state', 'extension-cursor-admin-monitor-edit'), $js_version, true);
		wp_enqueue_script('extension-cursor-admin', EXT_CURSOR_URL . 'assets/js/admin.js', array('jquery', 'extension-cursor-admin-actions'), $js_version, true);
		wp_localize_script('extension-cursor-admin', 'ExtensionCursorAdmin', array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('extension_cursor_admin_nonce')));
	}

	public function render_admin_page(): void {
		if (! current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to access this page.', 'extension-cursor')); }
		$stats = $this->service->get_dashboard_stats();
		$monitor_rows = $this->service->get_monitor_rows();
		$monitor_detail = $this->service->get_monitor_detail();
		$licences = $this->service->get_licences_for_ui();
		$keys = $this->service->get_keys_for_ui();
		$available_keys = $keys;
		$all_keys = $this->service->get_all_keys_for_ui();
		$all_licences = $this->service->get_all_licences_for_ui();
		include EXT_CURSOR_PATH . 'views/admin-page.php';
	}

	public function ajax_save_licence(): void {
		$this->assert_permissions();
		$token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
		$token_limit = isset($_POST['token_limit']) ? absint($_POST['token_limit']) : 0;
		$duration_days = isset($_POST['duration_days']) ? absint($_POST['duration_days']) : 1;
		$note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
		if ($token === '' || $token_limit < 1) { wp_send_json_error(array('message' => 'Token and token limit are required.'), 400); }
		$results = $this->service->import_licences(array(array('token' => $token, 'token_limit' => $token_limit, 'duration_days' => $duration_days, 'note' => $note)));
		if (empty($results) || empty($results[0]['ok'])) { wp_send_json_error(array('message' => 'Could not save licence.'), 500); }
		wp_send_json_success(array('message' => 'Licence saved.', 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui(), 'licences' => $this->service->get_licences_for_ui(), 'monitor_rows' => $this->service->get_monitor_rows(), 'monitor' => $this->service->get_monitor_detail()));
	}

	public function ajax_import_licences(): void {
		$this->assert_permissions();
		$raw_rows = isset($_POST['rows']) ? wp_unslash($_POST['rows']) : '';
		$rows = json_decode((string) $raw_rows, true);
		if (! is_array($rows) || empty($rows)) { wp_send_json_error(array('message' => 'No rows provided.'), 400); }
		$results = $this->service->import_licences($rows);
		wp_send_json_success(array('message' => 'Import complete.', 'results' => $results));
	}

	public function ajax_delete_licence(): void {
		$this->assert_permissions();
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if ($id < 1) { wp_send_json_error(array('message' => 'Invalid ID.'), 400); }
		if (! $this->service->delete_licence($id)) { wp_send_json_error(array('message' => 'Could not delete licence.'), 500); }
		wp_send_json_success(array('message' => 'Licence deleted.', 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui(), 'licences' => $this->service->get_licences_for_ui(), 'monitor_rows' => $this->service->get_monitor_rows(), 'monitor' => $this->service->get_monitor_detail()));
	}

	public function ajax_delete_key(): void {
		$this->assert_permissions();
		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		if ($id < 1) { wp_send_json_error(array('message' => 'Invalid ID.'), 400); }
		if (! $this->service->delete_key($id)) { wp_send_json_error(array('message' => 'Could not delete key.'), 500); }
		wp_send_json_success(array('message' => 'Key deleted.', 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui(), 'licences' => $this->service->get_licences_for_ui(), 'monitor_rows' => $this->service->get_monitor_rows(), 'monitor' => $this->service->get_monitor_detail()));
	}

	public function ajax_list_licences(): void {
		$this->assert_permissions();
		wp_send_json_success(array('rows' => $this->service->get_licences_for_ui()));
	}

	public function ajax_save_key(): void {
		$this->assert_permissions();
		$row = array('key_code' => isset($_POST['key_code']) ? wp_unslash($_POST['key_code']) : '', 'note' => isset($_POST['note']) ? wp_unslash($_POST['note']) : '', 'expiry_date' => isset($_POST['expiry_date']) ? wp_unslash($_POST['expiry_date']) : null);
		$ok = $this->service->save_key($row);
		if (! $ok) { wp_send_json_error(array('message' => 'Could not save key.'), 500); }
		wp_send_json_success(array('message' => 'Key saved.', 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui()));
	}

	public function ajax_assign_licences(): void {
		$this->assert_permissions();
		$key_id = isset($_POST['key_id']) ? absint($_POST['key_id']) : 0;
		$raw_licence_ids = isset($_POST['licence_ids']) ? (array) $_POST['licence_ids'] : array();
		$licence_ids = array_map('absint', $raw_licence_ids);
		if ($key_id < 1 || empty($licence_ids)) { wp_send_json_error(array('message' => 'Key and licences are required.', 'received' => array('key_id' => $key_id, 'licence_ids' => $licence_ids, 'raw_licence_ids' => $raw_licence_ids)), 400); }
		$results = $this->service->assign_licences_to_key($key_id, $licence_ids);
		global $wpdb;
		$current_relations = $wpdb->get_results($wpdb->prepare("SELECT id, apptook_key_id, licence_id, status, sort_order FROM {$wpdb->prefix}extension_cursor_key_licences WHERE apptook_key_id = %d ORDER BY id DESC", $key_id), ARRAY_A);
		wp_send_json_success(array('message' => 'Assign complete.', 'received' => array('key_id' => $key_id, 'licence_ids' => $licence_ids, 'raw_licence_ids' => $raw_licence_ids), 'results' => $results, 'current_relations' => is_array($current_relations) ? $current_relations : array(), 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui(), 'licences' => $this->service->get_licences_for_ui(), 'monitor_rows' => $this->service->get_monitor_rows(), 'monitor' => $this->service->get_monitor_detail($key_id)));
	}

	public function ajax_unassign_licences(): void {
		$this->assert_permissions();
		$key_id = isset($_POST['key_id']) ? absint($_POST['key_id']) : 0;
		$licence_ids = isset($_POST['licence_ids']) ? array_map('absint', (array) $_POST['licence_ids']) : array();
		if ($key_id < 1 || empty($licence_ids)) { wp_send_json_error(array('message' => 'Key and licences are required.'), 400); }
		$changed = $this->service->unassign_licences_from_key($key_id, $licence_ids);
		wp_send_json_success(array('message' => 'Unassign complete.', 'changed' => $changed, 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui(), 'licences' => $this->service->get_licences_for_ui(), 'monitor_rows' => $this->service->get_monitor_rows(), 'monitor' => $this->service->get_monitor_detail($key_id)));
	}

	public function ajax_replace_licences(): void {
		$this->assert_permissions();
		$key_id = isset($_POST['key_id']) ? absint($_POST['key_id']) : 0;
		$licence_ids = isset($_POST['licence_ids']) ? array_map('absint', (array) $_POST['licence_ids']) : array();
		if ($key_id < 1 || empty($licence_ids)) { wp_send_json_error(array('message' => 'Key and licences are required.'), 400); }
		$results = $this->service->replace_licences_for_key($key_id, $licence_ids);
		wp_send_json_success(array('message' => 'Replace complete.', 'results' => $results, 'snapshot' => $this->service->get_dashboard_stats(), 'keys' => $this->service->get_keys_for_ui(), 'licences' => $this->service->get_licences_for_ui(), 'monitor_rows' => $this->service->get_monitor_rows(), 'monitor' => $this->service->get_monitor_detail($key_id)));
	}

	public function ajax_save_runtime_snapshot(): void {
		$this->assert_permissions();
		$row = array(
			'licence_id' => isset($_POST['licence_id']) ? absint($_POST['licence_id']) : 0,
			'apptook_key_id' => isset($_POST['apptook_key_id']) ? absint($_POST['apptook_key_id']) : 0,
			'expiry_date' => isset($_POST['expiry_date']) ? wp_unslash($_POST['expiry_date']) : null,
			'raw_use' => isset($_POST['raw_use']) ? absint($_POST['raw_use']) : 0,
			'payload_json' => isset($_POST['payload_json']) ? json_decode(wp_unslash($_POST['payload_json']), true) : array(),
		);
		if ($row['licence_id'] < 1) { wp_send_json_error(array('message' => 'Licence is required.'), 400); }
		if (! $this->service->save_runtime_snapshot($row)) { wp_send_json_error(array('message' => 'Could not save runtime snapshot.'), 500); }
		wp_send_json_success(array('message' => 'Runtime snapshot saved.', 'monitor' => $this->service->get_monitor_detail($row['apptook_key_id'] ?: null), 'snapshot' => $this->service->get_dashboard_stats()));
	}

	public function ajax_dashboard_snapshot(): void {
		$this->assert_permissions();
		$key_id = isset($_POST['key_id']) ? absint($_POST['key_id']) : 0;
		global $wpdb;
		$all_keys = $wpdb->get_results("SELECT id, key_code, status, note, expiry_date, created_at FROM {$wpdb->prefix}extension_cursor_keys ORDER BY id DESC", ARRAY_A);
		$active_relations = $wpdb->get_results("SELECT id, apptook_key_id, licence_id, status, sort_order FROM {$wpdb->prefix}extension_cursor_key_licences WHERE status = 'active' ORDER BY id DESC", ARRAY_A);
		$payload = array(
			'snapshot' => $this->service->get_dashboard_stats(),
			'keys' => $this->service->get_keys_for_ui(),
			'licences' => $this->service->get_licences_for_ui(),
			'available_licences' => $this->service->get_licences_for_ui(),
			'monitor_rows' => $this->service->get_monitor_rows(),
			'monitor' => $this->service->get_monitor_detail($key_id > 0 ? $key_id : null),
			'debug' => array(
				'key_id' => $key_id,
				'keys_count' => is_array($all_keys) ? count($all_keys) : 0,
				'active_relations_count' => is_array($active_relations) ? count($active_relations) : 0,
				'all_keys' => is_array($all_keys) ? $all_keys : array(),
				'active_relations' => is_array($active_relations) ? $active_relations : array(),
			),
		);
		wp_send_json_success($payload);
	}

	public function ajax_debug_available_licences(): void {
		$this->assert_permissions();
		global $wpdb;
		$rows = $wpdb->get_results("SELECT id, token, token_limit, duration_days, status FROM {$wpdb->prefix}extension_cursor_licences WHERE status = 'available' ORDER BY id DESC", ARRAY_A);
		wp_send_json_success(array('rows' => is_array($rows) ? $rows : array()));
	}

	public function ajax_debug_assignment_state(): void {
		$this->assert_permissions();
		global $wpdb;
		$licences = $wpdb->get_results("SELECT id, token, status FROM {$wpdb->prefix}extension_cursor_licences ORDER BY id DESC", ARRAY_A);
		$relations = $wpdb->get_results("SELECT id, apptook_key_id, licence_id, status, sort_order FROM {$wpdb->prefix}extension_cursor_key_licences ORDER BY id DESC", ARRAY_A);
		$keys = $wpdb->get_results("SELECT id, key_code, status FROM {$wpdb->prefix}extension_cursor_keys ORDER BY id DESC", ARRAY_A);
		wp_send_json_success(array('licences' => is_array($licences) ? $licences : array(), 'relations' => is_array($relations) ? $relations : array(), 'keys' => is_array($keys) ? $keys : array()));
	}

	private function assert_permissions(): void { check_ajax_referer('extension_cursor_admin_nonce', 'nonce'); if (! current_user_can('manage_options')) { wp_send_json_error(array('message' => 'Forbidden.'), 403); } }
}
