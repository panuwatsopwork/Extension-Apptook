<?php
/**
 * REST API for local WP DB operations (Phase 2).
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_API {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes(): void {
		register_rest_route(
			'extension-cursor/v1',
			'/stock-keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_stock_keys'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/stock-keys/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'import_stock_keys'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_groups'),
					'permission_callback' => array($this, 'permission_check'),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'create_group'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/apptook-keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_apptook_keys'),
					'permission_callback' => array($this, 'permission_check'),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'create_apptook_key'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/runtime/login',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'runtime_login'),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/runtime/loop-next',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'runtime_loop_next'),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/runtime/dashboard-sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'runtime_dashboard_sync'),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/runtime/reset-sim',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'runtime_reset_sim'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/runtime/monitor',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'runtime_monitor'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/groups/(?P<id>\d+)/keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array($this, 'list_group_keys'),
					'permission_callback' => array($this, 'permission_check'),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'attach_group_keys'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/group-keys/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array($this, 'delete_group_key'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/groups/(?P<id>\d+)/keys/reorder',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'reorder_group_key'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);

		register_rest_route(
			'extension-cursor/v1',
			'/groups/(?P<id>\d+)/keys/resequence',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array($this, 'resequence_group_keys'),
					'permission_callback' => array($this, 'permission_check'),
				),
			)
		);
	}

	public function permission_check(WP_REST_Request $request): bool {
		$nonce = $request->get_header('X-WP-Nonce');
		if (! wp_verify_nonce((string) $nonce, 'wp_rest')) {
			return false;
		}

		return current_user_can('manage_options');
	}

	public function list_stock_keys(): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();

		$rows = $wpdb->get_results(
			"SELECT id, source_key, status, provider, expire_at, max_devices, token_capacity, note, created_at
			 FROM {$tables['stock_keys']}
			 ORDER BY id DESC
			 LIMIT 500",
			ARRAY_A
		);

		return new WP_REST_Response(array('ok' => true, 'items' => $rows), 200);
	}

	public function import_stock_keys(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();

		$keys_text = isset($data['keysText']) ? (string) $data['keysText'] : '';
		$keys      = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n|,/', $keys_text ?: '')))));
		if (empty($keys)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Please provide at least one stock key.'), 400);
		}

		$provider      = isset($data['provider']) ? sanitize_text_field((string) $data['provider']) : '';
		$expire_at_raw = isset($data['expireAt']) ? sanitize_text_field((string) $data['expireAt']) : '';
		$max_devices   = isset($data['maxDevices']) ? max(1, (int) $data['maxDevices']) : 1;
		$capacity      = isset($data['tokenCapacity']) ? max(1, (int) $data['tokenCapacity']) : 100;
		$note          = isset($data['note']) ? sanitize_textarea_field((string) $data['note']) : '';
		$now           = current_time('mysql');
		$expire_at     = $expire_at_raw ? gmdate('Y-m-d H:i:s', strtotime($expire_at_raw . ' 23:59:59')) : null;

		$created = 0;
		$skipped = 0;

		foreach ($keys as $key) {
			$inserted = $wpdb->insert(
				$tables['stock_keys'],
				array(
					'source_key'      => $key,
					'status'          => 'available',
					'provider'        => $provider,
					'expire_at'       => $expire_at,
					'max_devices'     => $max_devices,
					'token_capacity'  => $capacity,
					'note'            => $note,
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
			);

			if ($inserted) {
				$created++;
			} else {
				$skipped++;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => sprintf('Imported %d stock keys, skipped %d duplicates.', $created, $skipped),
				'created' => $created,
				'skipped' => $skipped,
			),
			200
		);
	}

	public function list_groups(): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();

		$rows = $wpdb->get_results(
			"SELECT id, group_code, name, mode, status, note, created_at
			 FROM {$tables['groups']}
			 ORDER BY id DESC",
			ARRAY_A
		);

		return new WP_REST_Response(array('ok' => true, 'items' => $rows), 200);
	}

	public function create_group(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();

		$group_code = isset($data['groupCode']) ? sanitize_key((string) $data['groupCode']) : '';
		$name       = isset($data['name']) ? sanitize_text_field((string) $data['name']) : '';
		$mode       = isset($data['mode']) && (string) $data['mode'] === 'single' ? 'single' : 'loop';
		$note       = isset($data['note']) ? sanitize_textarea_field((string) $data['note']) : '';

		if (! $group_code || ! $name) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Group code and name are required.'), 400);
		}

		$now      = current_time('mysql');
		$inserted = $wpdb->insert(
			$tables['groups'],
			array(
				'group_code' => $group_code,
				'name'       => $name,
				'mode'       => $mode,
				'status'     => 'active',
				'note'       => $note,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
		);

		if (! $inserted) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Cannot create group. Group code may already exist.'), 400);
		}

		return new WP_REST_Response(array('ok' => true, 'message' => 'Group created successfully.', 'id' => (int) $wpdb->insert_id), 200);
	}

	public function list_apptook_keys(): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();

		$rows = $wpdb->get_results(
			"SELECT k.id, k.apptook_key, k.group_id, g.group_code, g.name AS group_name, k.key_type, k.status, k.expire_at, k.current_sequence, k.note, k.created_at
			 FROM {$tables['apptook_keys']} k
			 LEFT JOIN {$tables['groups']} g ON g.id = k.group_id
			 ORDER BY k.id DESC",
			ARRAY_A
		);

		return new WP_REST_Response(array('ok' => true, 'items' => $rows), 200);
	}

	public function create_apptook_key(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();

		$apptook_key = isset($data['apptookKey']) ? sanitize_text_field((string) $data['apptookKey']) : '';
		$group_id    = isset($data['groupId']) ? (int) $data['groupId'] : 0;
		$key_type    = isset($data['keyType']) && (string) $data['keyType'] === 'single' ? 'single' : 'loop';
		$expire_raw  = isset($data['expireAt']) ? sanitize_text_field((string) $data['expireAt']) : '';
		$note        = isset($data['note']) ? sanitize_textarea_field((string) $data['note']) : '';

		if (! $apptook_key || ! $group_id) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key and group are required.'), 400);
		}

		$group_exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['groups']} WHERE id = %d LIMIT 1", $group_id));
		if (! $group_exists) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Selected group was not found.'), 400);
		}

		$exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['apptook_keys']} WHERE apptook_key = %s LIMIT 1", $apptook_key));
		if ($exists) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key already exists.'), 400);
		}

		$now       = current_time('mysql');
		$expire_at = $expire_raw ? gmdate('Y-m-d H:i:s', strtotime($expire_raw . ' 23:59:59')) : null;
		$inserted  = $wpdb->insert(
			$tables['apptook_keys'],
			array(
				'apptook_key'           => $apptook_key,
				'group_id'              => $group_id,
				'key_type'              => $key_type,
				'status'                => 'active',
				'expire_at'             => $expire_at,
				'current_group_key_id'  => null,
				'current_sequence'      => 0,
				'note'                  => $note,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array('%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
		);

		if (! $inserted) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Cannot create APTOOK key.'), 400);
		}

		return new WP_REST_Response(array('ok' => true, 'message' => 'APTOOK key created successfully.', 'id' => (int) $wpdb->insert_id), 200);
	}

	public function runtime_login(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();

		$apptook_key = isset($data['apptookKey']) ? sanitize_text_field((string) $data['apptookKey']) : '';
		$device_id   = isset($data['deviceId']) ? sanitize_text_field((string) $data['deviceId']) : '';

		if (! $apptook_key) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'apptookKey is required.'), 400);
		}

		$key_row = $this->get_valid_apptook_key($apptook_key);
		if (! $key_row) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key not found / inactive / expired.'), 404);
		}

		$group_id      = (int) ($key_row['group_id'] ?? 0);
		if ($this->is_apptook_exhausted((int) $key_row['id'], $group_id, 95.0)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK usage exhausted (all licenses >= 95%).'), 403);
		}

		$group_key_row = $this->get_first_group_key($group_id);
		if (! $group_key_row || empty($group_key_row['source_key'])) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'No stock key available for this group.'), 400);
		}

		$wpdb->update(
			$tables['apptook_keys'],
			array(
				'current_group_key_id' => (int) $group_key_row['id'],
				'current_sequence'     => (int) $group_key_row['sequence'],
				'updated_at'           => current_time('mysql'),
			),
			array('id' => (int) $key_row['id']),
			array('%d', '%d', '%s'),
			array('%d')
		);

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'message'     => 'Runtime login success.',
				'apptookKey'  => $apptook_key,
				'deviceId'    => $device_id,
				'keyType'     => (string) ($key_row['key_type'] ?? 'loop'),
				'groupId'     => $group_id,
				'sourceKey'   => (string) $group_key_row['source_key'],
				'sequence'    => (int) $group_key_row['sequence'],
			),
			200
		);
	}

	public function runtime_loop_next(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();

		$apptook_key = isset($data['apptookKey']) ? sanitize_text_field((string) $data['apptookKey']) : '';
		$reason      = isset($data['reason']) ? sanitize_text_field((string) $data['reason']) : 'manual_switch';
		$device_id   = isset($data['deviceId']) ? sanitize_text_field((string) $data['deviceId']) : '';

		if (! $apptook_key) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'apptookKey is required.'), 400);
		}

		$key_row = $this->get_valid_apptook_key($apptook_key);
		if (! $key_row) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key not found / inactive / expired.'), 404);
		}

		$group_id       = (int) ($key_row['group_id'] ?? 0);
		if ($this->is_apptook_exhausted((int) $key_row['id'], $group_id, 95.0)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK usage exhausted (all licenses >= 95%).'), 403);
		}
		$current_key_id = (int) ($key_row['current_group_key_id'] ?? 0);

		$current_group_key = null;
		if ($current_key_id > 0) {
			$current_group_key = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT gk.id, gk.sequence, sk.id AS stock_key_id, sk.source_key
					 FROM {$tables['group_keys']} gk
					 LEFT JOIN {$tables['stock_keys']} sk ON sk.id = gk.stock_key_id
					 WHERE gk.id = %d AND gk.group_id = %d",
					$current_key_id,
					$group_id
				),
				ARRAY_A
			);
		}

		$next_group_key = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT gk.id, gk.sequence, sk.id AS stock_key_id, sk.source_key
				 FROM {$tables['group_keys']} gk
				 LEFT JOIN {$tables['stock_keys']} sk ON sk.id = gk.stock_key_id
				 WHERE gk.group_id = %d AND gk.sequence > %d
				 ORDER BY gk.sequence ASC
				 LIMIT 1",
				$group_id,
				(int) ($key_row['current_sequence'] ?? 0)
			),
			ARRAY_A
		);

		if (! $next_group_key) {
			$next_group_key = $this->get_first_group_key($group_id);
		}

		if (! $next_group_key || empty($next_group_key['source_key'])) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'No next stock key available in group.'), 400);
		}

		$wpdb->update(
			$tables['apptook_keys'],
			array(
				'current_group_key_id' => (int) $next_group_key['id'],
				'current_sequence'     => (int) $next_group_key['sequence'],
				'updated_at'           => current_time('mysql'),
			),
			array('id' => (int) $key_row['id']),
			array('%d', '%d', '%s'),
			array('%d')
		);

		$wpdb->insert(
			$tables['switch_logs'],
			array(
				'apptook_key_id'    => (int) $key_row['id'],
				'from_stock_key_id' => isset($current_group_key['stock_key_id']) ? (int) $current_group_key['stock_key_id'] : null,
				'to_stock_key_id'   => isset($next_group_key['stock_key_id']) ? (int) $next_group_key['stock_key_id'] : null,
				'reason'            => $reason,
				'payload'           => wp_json_encode(array('deviceId' => $device_id, 'fromSequence' => $key_row['current_sequence'], 'toSequence' => $next_group_key['sequence'])),
				'created_at'        => current_time('mysql'),
			),
			array('%d', '%d', '%d', '%s', '%s', '%s')
		);

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'message'    => 'Switched to next stock key.',
				'apptookKey' => $apptook_key,
				'groupId'    => $group_id,
				'sourceKey'  => (string) $next_group_key['source_key'],
				'sequence'   => (int) $next_group_key['sequence'],
				'reason'     => $reason,
			),
			200
		);
	}

	public function runtime_dashboard_sync(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();

		$apptook_key = isset($data['apptookKey']) ? sanitize_text_field((string) $data['apptookKey']) : '';
		$device_id   = isset($data['deviceId']) ? sanitize_text_field((string) $data['deviceId']) : '';
		$raw_usage   = isset($data['rawUsage']) ? (float) $data['rawUsage'] : null;
		$display_usage = isset($data['displayUsage']) ? (float) $data['displayUsage'] : null;

		if (! $apptook_key) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'apptookKey is required.'), 400);
		}

		$key_row = $this->get_valid_apptook_key($apptook_key);
		if (! $key_row) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key not found / inactive / expired.'), 404);
		}

		$group_id = (int) ($key_row['group_id'] ?? 0);
		$current_group_key_id = (int) ($key_row['current_group_key_id'] ?? 0);
		$current_sequence = (int) ($key_row['current_sequence'] ?? 0);

		if ($this->is_apptook_exhausted((int) $key_row['id'], $group_id, 95.0)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK usage exhausted (all licenses >= 95%).'), 403);
		}

		if ($current_group_key_id <= 0) {
			$first_key = $this->get_first_group_key($group_id);
			if ($first_key) {
				$current_group_key_id = (int) $first_key['id'];
				$current_sequence = (int) $first_key['sequence'];
				$wpdb->update(
					$tables['apptook_keys'],
					array(
						'current_group_key_id' => $current_group_key_id,
						'current_sequence'     => $current_sequence,
						'updated_at'           => current_time('mysql'),
					),
					array('id' => (int) $key_row['id']),
					array('%d', '%d', '%s'),
					array('%d')
				);
			}
		}

		$current_key_row = null;
		if ($current_group_key_id > 0) {
			$current_key_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT gk.id, gk.sequence, sk.id AS stock_key_id, sk.source_key, sk.token_capacity
					 FROM {$tables['group_keys']} gk
					 LEFT JOIN {$tables['stock_keys']} sk ON sk.id = gk.stock_key_id
					 WHERE gk.id = %d AND gk.group_id = %d",
					$current_group_key_id,
					$group_id
				),
				ARRAY_A
			);
		}

		$total_source_keys = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(1) FROM {$tables['group_keys']} WHERE group_id = %d", $group_id)
		);

		$current_stock_key_id = isset($current_key_row['stock_key_id']) ? (int) $current_key_row['stock_key_id'] : 0;
		$current_capacity = isset($current_key_row['token_capacity']) ? (float) $current_key_row['token_capacity'] : 0.0;
		$effective_raw_usage = $raw_usage;
		if ($effective_raw_usage !== null && $current_capacity > 0) {
			$effective_raw_usage = min((float) $effective_raw_usage, $current_capacity);
		}

		$wpdb->insert(
			$tables['usage_logs'],
			array(
				'apptook_key_id' => (int) $key_row['id'],
				'stock_key_id'   => $current_stock_key_id > 0 ? $current_stock_key_id : null,
				'event_type'     => 'dashboard_sync',
				'raw_usage'      => $effective_raw_usage,
				'display_usage'  => $display_usage,
				'payload'        => wp_json_encode(array('deviceId' => $device_id)),
				'created_at'     => current_time('mysql'),
			),
			array('%d', '%d', '%s', '%f', '%f', '%s', '%s')
		);

		if ($this->is_apptook_exhausted((int) $key_row['id'], $group_id, 95.0)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK usage exhausted (all licenses >= 95%).'), 403);
		}

		$monitor = $this->build_runtime_monitor_payload((int) $key_row['id'], $group_id);

		return new WP_REST_Response(
			array(
				'ok'                => true,
				'message'           => 'Dashboard sync success.',
				'apptookKey'        => $apptook_key,
				'groupId'           => $group_id,
				'keyType'           => (string) ($key_row['key_type'] ?? 'loop'),
				'currentSourceKey'  => isset($monitor['currentSourceKey']) ? (string) $monitor['currentSourceKey'] : '',
				'currentSequence'   => isset($monitor['currentSequence']) ? (int) $monitor['currentSequence'] : $current_sequence,
				'totalSourceKeys'   => $total_source_keys,
				'apptookUsagePercent' => isset($monitor['apptookUsagePercent']) ? (float) $monitor['apptookUsagePercent'] : 0.0,
			),
			200
		);
	}

	public function runtime_monitor(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$apptook_key = sanitize_text_field((string) $request->get_param('apptookKey'));

		if ($apptook_key === '') {
			return new WP_REST_Response(array('ok' => false, 'message' => 'apptookKey query param is required.'), 400);
		}

		$key_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, apptook_key, group_id, key_type, status, expire_at, current_group_key_id, current_sequence
				 FROM {$tables['apptook_keys']}
				 WHERE apptook_key = %s
				 LIMIT 1",
				$apptook_key
			),
			ARRAY_A
		);

		if (! $key_row) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key not found.'), 404);
		}

		$payload = $this->build_runtime_monitor_payload((int) $key_row['id'], (int) $key_row['group_id']);

		return new WP_REST_Response(
			array_merge(
				array(
					'ok'         => true,
					'apptookKey' => (string) $key_row['apptook_key'],
					'groupId'    => (int) $key_row['group_id'],
					'keyType'    => (string) $key_row['key_type'],
					'status'     => (string) $key_row['status'],
				),
				$payload
			),
			200
		);
	}

	public function runtime_reset_sim(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();
		$body   = $request->get_json_params();
		$data   = is_array($body) ? $body : array();
		$apptook_key = isset($data['apptookKey']) ? sanitize_text_field((string) $data['apptookKey']) : '';

		if ($apptook_key === '') {
			return new WP_REST_Response(array('ok' => false, 'message' => 'apptookKey is required.'), 400);
		}

		$key_row = $wpdb->get_row(
			$wpdb->prepare("SELECT id, group_id FROM {$tables['apptook_keys']} WHERE apptook_key = %s LIMIT 1", $apptook_key),
			ARRAY_A
		);
		if (! $key_row) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'APTOOK key not found.'), 404);
		}

		$apptook_key_id = (int) $key_row['id'];
		$group_id = (int) $key_row['group_id'];
		$first = $this->get_first_group_key($group_id);

		$wpdb->query($wpdb->prepare("DELETE FROM {$tables['usage_logs']} WHERE apptook_key_id = %d", $apptook_key_id));
		$wpdb->query($wpdb->prepare("DELETE FROM {$tables['switch_logs']} WHERE apptook_key_id = %d", $apptook_key_id));

		$wpdb->update(
			$tables['apptook_keys'],
			array(
				'current_group_key_id' => $first ? (int) $first['id'] : 0,
				'current_sequence'     => $first ? (int) $first['sequence'] : 0,
				'updated_at'           => current_time('mysql'),
			),
			array('id' => $apptook_key_id),
			array('%d', '%d', '%s'),
			array('%d')
		);

		return new WP_REST_Response(array('ok' => true, 'message' => 'Simulation data reset completed.'), 200);
	}

	private function build_runtime_monitor_payload(int $apptook_key_id, int $group_id): array {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();

		$key_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT current_group_key_id, current_sequence
				 FROM {$tables['apptook_keys']}
				 WHERE id = %d
				 LIMIT 1",
				$apptook_key_id
			),
			ARRAY_A
		);

		$current_group_key_id = (int) ($key_row['current_group_key_id'] ?? 0);
		$current_sequence = (int) ($key_row['current_sequence'] ?? 0);

		$licenses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gk.id AS group_key_id, gk.sequence, sk.id AS stock_key_id, sk.source_key, sk.token_capacity,
						COALESCE((
							SELECT ul2.raw_usage
							FROM {$tables['usage_logs']} ul2
							WHERE ul2.apptook_key_id = %d
							  AND ul2.stock_key_id = sk.id
							  AND ul2.event_type = 'dashboard_sync'
							ORDER BY ul2.id DESC
							LIMIT 1
						), 0) AS consumed_raw
				 FROM {$tables['group_keys']} gk
				 INNER JOIN {$tables['stock_keys']} sk ON sk.id = gk.stock_key_id
				 WHERE gk.group_id = %d
				 ORDER BY gk.sequence ASC",
				$apptook_key_id,
				$group_id
			),
			ARRAY_A
		);

		$total_capacity = 0.0;
		$total_consumed = 0.0;
		$current_source_key = '';

		$normalized = array_map(
			function (array $row) use (&$total_capacity, &$total_consumed, $current_group_key_id, &$current_source_key): array {
				$capacity = (float) ($row['token_capacity'] ?? 0);
				$consumed = min((float) ($row['consumed_raw'] ?? 0), $capacity > 0 ? $capacity : (float) ($row['consumed_raw'] ?? 0));
				$percent = $capacity > 0 ? min(100.0, ($consumed / $capacity) * 100.0) : 0.0;
				$is_current = (int) $row['group_key_id'] === $current_group_key_id;
				if ($is_current) {
					$current_source_key = (string) $row['source_key'];
				}

				$total_capacity += $capacity;
				$total_consumed += $consumed;

				return array(
					'groupKeyId'      => (int) $row['group_key_id'],
					'sequence'        => (int) $row['sequence'],
					'stockKeyId'      => (int) $row['stock_key_id'],
					'sourceKey'       => (string) $row['source_key'],
					'tokenCapacity'   => $capacity,
					'consumedRaw'     => round($consumed, 6),
					'usagePercent'    => round($percent, 2),
					'isCurrent'       => $is_current,
				);
			},
			is_array($licenses) ? $licenses : array()
		);

		$apptook_usage_percent = $total_capacity > 0 ? min(100.0, ($total_consumed / $total_capacity) * 100.0) : 0.0;

		return array(
			'currentSequence'     => $current_sequence,
			'currentSourceKey'    => $current_source_key,
			'totalTokenCapacity'  => round($total_capacity, 6),
			'totalConsumedRaw'    => round($total_consumed, 6),
			'apptookUsagePercent' => round($apptook_usage_percent, 2),
			'licenses'            => $normalized,
		);
	}

	private function is_apptook_exhausted(int $apptook_key_id, int $group_id, float $threshold_percent): bool {
		$payload = $this->build_runtime_monitor_payload($apptook_key_id, $group_id);
		$licenses = isset($payload['licenses']) && is_array($payload['licenses']) ? $payload['licenses'] : array();
		if (empty($licenses)) {
			return false;
		}

		foreach ($licenses as $license) {
			$percent = isset($license['usagePercent']) ? (float) $license['usagePercent'] : 0.0;
			if ($percent < $threshold_percent) {
				return false;
			}
		}

		return true;
	}

	private function get_valid_apptook_key(string $apptook_key): ?array {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();

		$key_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, apptook_key, group_id, key_type, status, expire_at, current_group_key_id, current_sequence
				 FROM {$tables['apptook_keys']}
				 WHERE apptook_key = %s LIMIT 1",
				$apptook_key
			),
			ARRAY_A
		);
		if (! $key_row) {
			return null;
		}
		if (($key_row['status'] ?? '') !== 'active') {
			return null;
		}
		if (! empty($key_row['expire_at'])) {
			$expire_ts = strtotime((string) $key_row['expire_at']);
			if ($expire_ts && $expire_ts < time()) {
				return null;
			}
		}

		return $key_row;
	}

	private function get_first_group_key(int $group_id): ?array {
		global $wpdb;
		$tables = Extension_Cursor_DB::table_names();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT gk.id, gk.sequence, sk.id AS stock_key_id, sk.source_key
				 FROM {$tables['group_keys']} gk
				 LEFT JOIN {$tables['stock_keys']} sk ON sk.id = gk.stock_key_id
				 WHERE gk.group_id = %d
				 ORDER BY gk.sequence ASC
				 LIMIT 1",
				$group_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : null;
	}

	public function list_group_keys(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables   = Extension_Cursor_DB::table_names();
		$group_id = (int) $request->get_param('id');

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gk.id, gk.group_id, gk.stock_key_id, gk.sequence, gk.status, sk.source_key
				 FROM {$tables['group_keys']} gk
				 LEFT JOIN {$tables['stock_keys']} sk ON sk.id = gk.stock_key_id
				 WHERE gk.group_id = %d
				 ORDER BY gk.sequence ASC",
				$group_id
			),
			ARRAY_A
		);

		return new WP_REST_Response(array('ok' => true, 'items' => $rows), 200);
	}

	public function attach_group_keys(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables   = Extension_Cursor_DB::table_names();
		$group_id = (int) $request->get_param('id');
		$body     = $request->get_json_params();
		$data     = is_array($body) ? $body : array();

		$ids = isset($data['stockKeyIds']) && is_array($data['stockKeyIds'])
			? array_values(array_unique(array_filter(array_map('intval', $data['stockKeyIds']))))
			: array();

		if (empty($ids)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Please provide stock key IDs.'), 400);
		}

		$last_sequence = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence), 0) FROM {$tables['group_keys']} WHERE group_id = %d", $group_id));
		$now           = current_time('mysql');
		$attached      = 0;

		foreach ($ids as $stock_key_id) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(1) FROM {$tables['group_keys']} WHERE group_id = %d AND stock_key_id = %d",
					$group_id,
					$stock_key_id
				)
			);
			if ($exists > 0) {
				continue;
			}

			$last_sequence++;
			$inserted = $wpdb->insert(
				$tables['group_keys'],
				array(
					'group_id'     => $group_id,
					'stock_key_id' => $stock_key_id,
					'sequence'     => $last_sequence,
					'status'       => 'active',
					'note'         => '',
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array('%d', '%d', '%d', '%s', '%s', '%s', '%s')
			);

			if ($inserted) {
				$attached++;
			}
		}

		return new WP_REST_Response(array('ok' => true, 'message' => sprintf('Attached %d keys to group.', $attached), 'attached' => $attached), 200);
	}

	public function delete_group_key(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables        = Extension_Cursor_DB::table_names();
		$group_key_id  = (int) $request->get_param('id');

		$record = $wpdb->get_row(
			$wpdb->prepare("SELECT id, group_id, sequence FROM {$tables['group_keys']} WHERE id = %d", $group_key_id),
			ARRAY_A
		);

		if (! $record) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Group key not found.'), 404);
		}

		$group_id = (int) $record['group_id'];
		$sequence = (int) $record['sequence'];

		$wpdb->delete($tables['group_keys'], array('id' => $group_key_id), array('%d'));
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['group_keys']} SET sequence = sequence - 1, updated_at = %s WHERE group_id = %d AND sequence > %d",
				current_time('mysql'),
				$group_id,
				$sequence
			)
		);

		return new WP_REST_Response(array('ok' => true, 'message' => 'Key removed from group.'), 200);
	}

	public function reorder_group_key(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables   = Extension_Cursor_DB::table_names();
		$group_id = (int) $request->get_param('id');
		$body     = $request->get_json_params();
		$data     = is_array($body) ? $body : array();

		$group_key_id = isset($data['groupKeyId']) ? (int) $data['groupKeyId'] : 0;
		$direction    = isset($data['direction']) ? sanitize_key((string) $data['direction']) : '';
		if (! $group_key_id || ! in_array($direction, array('up', 'down'), true)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Invalid reorder payload.'), 400);
		}

		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, group_id, sequence FROM {$tables['group_keys']} WHERE id = %d AND group_id = %d",
				$group_key_id,
				$group_id
			),
			ARRAY_A
		);
		if (! $current) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Group key not found.'), 404);
		}

		$current_sequence = (int) $current['sequence'];
		$target_sequence  = $direction === 'up' ? $current_sequence - 1 : $current_sequence + 1;
		if ($target_sequence <= 0) {
			return new WP_REST_Response(array('ok' => true, 'message' => 'Already at top.'), 200);
		}

		$target = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, sequence FROM {$tables['group_keys']} WHERE group_id = %d AND sequence = %d",
				$group_id,
				$target_sequence
			),
			ARRAY_A
		);
		if (! $target) {
			return new WP_REST_Response(array('ok' => true, 'message' => 'Already at edge.'), 200);
		}

		$now = current_time('mysql');
		$temp_sequence = 999999;

		$step1 = $wpdb->update(
			$tables['group_keys'],
			array('sequence' => $temp_sequence, 'updated_at' => $now),
			array('id' => $group_key_id),
			array('%d', '%s'),
			array('%d')
		);
		$step2 = $wpdb->update(
			$tables['group_keys'],
			array('sequence' => $current_sequence, 'updated_at' => $now),
			array('id' => (int) $target['id']),
			array('%d', '%s'),
			array('%d')
		);
		$step3 = $wpdb->update(
			$tables['group_keys'],
			array('sequence' => $target_sequence, 'updated_at' => $now),
			array('id' => $group_key_id),
			array('%d', '%s'),
			array('%d')
		);

		if ($step1 === false || $step2 === false || $step3 === false) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Failed to reorder group key.',
					'debug'   => array(
						'step1' => $step1,
						'step2' => $step2,
						'step3' => $step3,
						'dbError' => $wpdb->last_error,
					),
				),
				500
			);
		}

		return new WP_REST_Response(array('ok' => true, 'message' => 'Group key order updated.', 'debug' => array('from' => $current_sequence, 'to' => $target_sequence)), 200);
	}

	public function resequence_group_keys(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;
		$tables   = Extension_Cursor_DB::table_names();
		$group_id = (int) $request->get_param('id');
		$body     = $request->get_json_params();
		$data     = is_array($body) ? $body : array();

		$ordered_ids = isset($data['orderedGroupKeyIds']) && is_array($data['orderedGroupKeyIds'])
			? array_values(array_filter(array_map('intval', $data['orderedGroupKeyIds'])))
			: array();

		if (empty($ordered_ids)) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'orderedGroupKeyIds is required.'), 400);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$tables['group_keys']} WHERE group_id = %d ORDER BY sequence ASC",
				$group_id
			),
			ARRAY_A
		);
		$existing_ids = array_map(static fn($r) => (int) $r['id'], $rows ?: array());
		sort($existing_ids);
		$compare_ids = $ordered_ids;
		sort($compare_ids);

		if ($existing_ids !== $compare_ids) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'orderedGroupKeyIds does not match current group keys.'), 400);
		}

		$now = current_time('mysql');
		foreach ($ordered_ids as $index => $group_key_id) {
			$wpdb->update(
				$tables['group_keys'],
				array('sequence' => $index + 1, 'updated_at' => $now),
				array('id' => $group_key_id, 'group_id' => $group_id),
				array('%d', '%s'),
				array('%d', '%d')
			);
		}

		return new WP_REST_Response(array('ok' => true, 'message' => 'Group key sequence updated.'), 200);
	}
}
