<?php
/**
 * Admin page and AJAX proxy handlers.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Admin {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('wp_ajax_extension_cursor_admin_dispatch', array($this, 'ajax_dispatch'));
		add_action('wp_ajax_ec_data', array($this, 'ajax_dispatch_v2'));
		add_action('wp_ajax_extension_cursor_admin_debug_ping', array($this, 'ajax_debug_ping'));
		add_action('rest_api_init', array($this, 'register_rest_routes'));
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__('Extension Cursor', 'extension-cursor'),
			__('Extension Cursor', 'extension-cursor'),
			'manage_options',
			'extension-cursor-admin',
			array($this, 'render_admin_page'),
			'dashicons-admin-generic',
			58
		);
	}

	public function enqueue_assets(string $hook): void {
		if ($hook !== 'toplevel_page_extension-cursor-admin') {
			return;
		}

		$css_path    = EXT_CURSOR_PATH . 'assets/admin.css';
		$js_path     = EXT_CURSOR_PATH . 'assets/admin.js';
		$css_version = file_exists($css_path) ? (string) filemtime($css_path) : EXT_CURSOR_VERSION;
		$js_version  = file_exists($js_path) ? (string) filemtime($js_path) : EXT_CURSOR_VERSION;

		wp_enqueue_style('extension-cursor-admin', EXT_CURSOR_URL . 'assets/admin.css', array(), $css_version);
		wp_enqueue_script('extension-cursor-admin', EXT_CURSOR_URL . 'assets/admin.js', array(), $js_version, true);

		wp_localize_script(
			'extension-cursor-admin',
			'ExtensionCursorAdmin',
			array(
				'ajaxUrl'   => admin_url('admin-ajax.php'),
				'restUrl'      => rest_url('extension-cursor/v1/dispatch'),
				'restApiBase'  => rest_url('extension-cursor/v1'),
				'nonce'        => wp_create_nonce('extension_cursor_admin_nonce'),
				'restNonce'    => wp_create_nonce('wp_rest'),
				'gasWebApp'    => $this->get_gas_web_app_url(),
			)
		);
	}

	public function render_admin_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'extension-cursor'));
		}
		?>
		<div class="extension-cursor">
			<div class="page">
				<section class="hero">
					<div class="hero-logo" aria-hidden="true">
						<img src="<?php echo esc_url(EXT_CURSOR_URL . 'assets/logo.png'); ?>" alt="APPTOOK logo" width="72" height="72" />
					</div>
					<div class="hero-copy">
						<h1>APPTOOK Admin</h1>
						<p>Manage your private source licenses, generate APPTOOK customer keys, assign single or loop mappings, and monitor live usage snapshots in one place.</p>
						<div class="tab-nav" role="tablist" aria-label="Extension Cursor Tabs">
							<button id="tabMainButton" class="tab-btn active" type="button" role="tab" aria-selected="true">Main</button>
							<button id="tabRuntimeMonitorButton" class="tab-btn" type="button" role="tab" aria-selected="false">Runtime Monitor</button>
						</div>
					</div>
				</section>

				<div id="tabMainContent" class="tab-content active">

				<div class="layout">
					<div class="stack">
						<section class="panel">
							<div class="panel-head">
								<h2>Phase 2: Local Stock Key Vault (WP DB)</h2>
								<p>Import stock license keys directly into WordPress database (no Google Sheets required).</p>
							</div>
							<div class="panel-body">
								<div class="grid">
									<div>
										<label for="localStockKeysText">Stock Keys (one per line)</label>
										<textarea id="localStockKeysText" placeholder="Paste stock keys here"></textarea>
									</div>
								</div>
								<div class="grid four">
									<div>
										<label for="localProvider">Provider</label>
										<input id="localProvider" type="text" placeholder="Optional provider">
									</div>
									<div>
										<label for="localExpireAt">Expire Date</label>
										<input id="localExpireAt" type="date">
									</div>
									<div>
										<label for="localMaxDevices">Max Devices</label>
										<input id="localMaxDevices" type="number" min="1" step="1" value="1">
									</div>
									<div>
										<label for="localTokenCapacity">Token Capacity</label>
										<input id="localTokenCapacity" type="number" min="1" step="1" value="100">
									</div>
								</div>
								<div class="grid">
									<div>
										<label for="localNote">Note</label>
										<input id="localNote" type="text" placeholder="Optional note">
									</div>
								</div>
								<div class="actions">
									<button id="localImportButton" class="btn-primary" type="button">Import to WP DB</button>
									<button id="localRefreshButton" class="btn-secondary" type="button">Refresh Local Data</button>
								</div>
								<div id="localStockTable" class="table-wrap"><div class="empty">No local stock keys loaded.</div></div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Phase 2: Groups (WP DB)</h2>
								<p>Create key groups for loop/single assignment.</p>
							</div>
							<div class="panel-body">
								<div class="grid four">
									<div>
										<label for="localGroupCode">Group Code</label>
										<input id="localGroupCode" type="text" placeholder="e.g. grp_main">
									</div>
									<div>
										<label for="localGroupName">Group Name</label>
										<input id="localGroupName" type="text" placeholder="Main Loop Group">
									</div>
									<div>
										<label for="localGroupMode">Mode</label>
										<select id="localGroupMode"><option value="loop">loop</option><option value="single">single</option></select>
									</div>
									<div>
										<label for="localGroupNote">Note</label>
										<textarea id="localGroupNote" placeholder="Optional note" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" name="ec_group_note" readonly onfocus="this.removeAttribute('readonly');"></textarea>
									</div>
								</div>
								<div class="actions">
									<button id="localCreateGroupButton" class="btn-primary" type="button">Create Group</button>
								</div>
								<div id="localGroupsTable" class="table-wrap"><div class="empty">No groups loaded.</div></div>

								<div class="grid three" style="margin-top:14px;">
									<div>
										<label for="mapGroupSelect">Select Group</label>
										<select id="mapGroupSelect"><option value="">Select group</option></select>
									</div>
									<div>
										<label for="mapStockKeyIds">Stock Key IDs / source_key</label>
										<textarea id="mapStockKeyIds" class="map-ids-input" placeholder="e.g. 12,15,20 or key_abc,key_xyz" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" aria-autocomplete="none" readonly onfocus="this.removeAttribute('readonly');" onmousedown="this.removeAttribute('readonly');"></textarea>
									</div>
									<div>
										<label>&nbsp;</label>
										<div class="actions" style="margin-top:0;">
											<button id="mapAttachKeysButton" class="btn-secondary" type="button">Attach Keys</button>
											<button id="mapLoadGroupKeysButton" class="btn-ghost" type="button">Load Group Keys</button>
										</div>
									</div>
								</div>
								<div id="mapGroupKeysTable" class="table-wrap" style="margin-top:10px;"><div class="empty">No group keys loaded.</div></div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Phase 3: APTOOK Keys (WP DB)</h2>
								<p>Create customer APTOOK keys and bind to a local group.</p>
							</div>
							<div class="panel-body">
								<div class="grid four">
									<div>
										<label for="localApptookKey">APTOOK Key</label>
										<input id="localApptookKey" type="text" placeholder="e.g. apptook_xxxxx" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" aria-autocomplete="none" name="ec_apptook_key_manual" readonly onfocus="this.removeAttribute('readonly');" onmousedown="this.removeAttribute('readonly');">
									</div>
									<div>
										<label for="localApptookGroupSelect">Group</label>
										<select id="localApptookGroupSelect"><option value="">Select group</option></select>
									</div>
									<div>
										<label for="localApptookKeyType">Key Type</label>
										<select id="localApptookKeyType"><option value="loop">loop</option><option value="single">single</option></select>
									</div>
									<div>
										<label for="localApptookExpireAt">Expire Date</label>
										<input id="localApptookExpireAt" type="date">
									</div>
								</div>
								<div class="actions">
									<button id="localCreateApptookButton" class="btn-primary" type="button">Create APTOOK Key</button>
									<button id="localRefreshApptookButton" class="btn-secondary" type="button">Refresh APTOOK Keys</button>
								</div>
								<div id="localApptookKeysTable" class="table-wrap"><div class="empty">No APTOOK keys loaded.</div></div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Runtime Test Panel</h2>
								<p>Test runtime endpoints directly against WP backend before building VSIX.</p>
							</div>
							<div class="panel-body">
								<div class="grid three">
									<div>
										<label for="rtApptookKey">APTOOK Key</label>
										<input id="rtApptookKey" type="text" placeholder="e.g. apptook_xxxxx" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" aria-autocomplete="none" name="ec_runtime_apptook_key" readonly onfocus="this.removeAttribute('readonly');" onmousedown="this.removeAttribute('readonly');">
									</div>
									<div>
										<label for="rtDeviceId">Device ID</label>
										<input id="rtDeviceId" type="text" placeholder="dev-001">
									</div>
									<div>
										<label for="rtReason">Loop Reason</label>
										<input id="rtReason" type="text" value="manual_switch">
									</div>
								</div>
								<div class="grid two">
									<div>
										<label for="rtRawUsage">Raw Usage</label>
										<input id="rtRawUsage" type="number" step="0.000001" placeholder="Optional">
									</div>
									<div>
										<label for="rtDisplayUsage">Display Usage</label>
										<input id="rtDisplayUsage" type="number" step="0.000001" placeholder="Optional">
									</div>
								</div>
								<div class="actions">
									<button id="rtLoginButton" class="btn-primary" type="button">Test runtime/login</button>
									<button id="rtLoopNextButton" class="btn-secondary" type="button">Test runtime/loop-next</button>
									<button id="rtDashboardSyncButton" class="btn-ghost" type="button">Test runtime/dashboard-sync</button>
								</div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Admin Access</h2>
								<p>Use your admin token for setup, importing source licenses, creating APPTOOK keys, and refreshing all managed data.</p>
							</div>
							<div class="panel-body">
								<label for="adminToken">Admin Token</label>
								<input id="adminToken" type="password" placeholder="Enter admin token">
								<div class="actions">
									<button id="setupButton" class="btn-primary">Setup tables</button>
									<button id="refreshButton" class="btn-secondary">Refresh data</button>
									<button id="debugPingButton" class="btn-ghost" type="button">Debug Ping</button>
								</div>
								<div id="statusBar" class="status-bar">
									<span class="status-dot"></span>
									<span id="statusText">Ready.</span>
								</div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Import Source Licenses</h2>
								<p>Paste one or many source licenses from your supplier. Each line becomes one row in <code>source_licenses</code>.</p>
							</div>
							<div class="panel-body">
								<div class="grid">
									<div>
										<label for="sourceKeysText">Source Licenses</label>
										<textarea id="sourceKeysText" placeholder="Paste source licenses here, one per line"></textarea>
									</div>
								</div>
								<div class="grid four">
									<div>
										<label for="sourceExpireAt">Source Expire Date</label>
										<input id="sourceExpireAt" type="date">
									</div>
									<div>
										<label for="sourceMaxDevices">Max Devices</label>
										<input id="sourceMaxDevices" type="number" min="1" step="1" value="1">
									</div>
									<div>
										<label for="sourceTokenCapacity">Source Token Capacity</label>
										<input id="sourceTokenCapacity" type="number" min="100" step="1" value="100">
									</div>
									<div>
										<label for="sourceNote">Note</label>
										<input id="sourceNote" type="text" placeholder="Optional note">
									</div>
								</div>
								<div class="actions">
									<button id="importSourceKeysButton" class="btn-primary">Import source licenses</button>
								</div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Create APPTOOK Key</h2>
								<p>Create your customer-facing key. Use <code>single</code> for one source license or <code>loop</code> for rotation across many source licenses.</p>
							</div>
							<div class="panel-body">
								<div class="grid two">
									<div>
										<label for="apptookKey">APPTOOK Key</label>
										<input id="apptookKey" type="text" placeholder="Leave blank to auto-generate">
									</div>
									<div>
										<label>&nbsp;</label>
										<button id="randomApptookKeyButton" class="btn-ghost" type="button">Random APPTOOK key</button>
									</div>
								</div>
								<div class="grid three">
									<div>
										<label for="keyType">Key Type</label>
										<select id="keyType">
											<option value="single">single</option>
											<option value="loop">loop</option>
										</select>
									</div>
									<div>
										<label for="appKeyExpireAt">APPTOOK Key Expire Date</label>
										<input id="appKeyExpireAt" type="date">
									</div>
									<div>
										<label for="appKeyUsageScale">Customer Usage Scale</label>
										<input id="appKeyUsageScale" type="text" value="0 / 100 fixed scale" disabled>
									</div>
								</div>
								<div class="grid">
									<div>
										<label for="appKeyNote">Note</label>
										<input id="appKeyNote" type="text" placeholder="Optional note">
									</div>
								</div>
								<div class="actions">
									<button id="createApptookKeyButton" class="btn-primary">Create APPTOOK key</button>
								</div>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Assign Source Licenses</h2>
								<p>Select an APPTOOK key, then assign one or many source licenses in order. Loop keys can rotate across the saved order.</p>
							</div>
							<div class="panel-body">
								<div class="grid two">
									<div>
										<label for="assignApptookKeySelect">APPTOOK Key</label>
										<select id="assignApptookKeySelect"><option value="">Select APPTOOK key</option></select>
									</div>
									<div>
										<label for="assignKeyType">Key Mode</label>
										<input id="assignKeyType" type="text" value="-" readonly>
									</div>
								</div>
								<div class="actions">
									<button id="addSourceRowButton" class="btn-secondary" type="button">Add source license</button>
								</div>
								<div id="sourceRows" class="assign-rows"></div>
								<div class="actions">
									<button id="saveSourcesButton" class="btn-primary">Save source mapping</button>
								</div>
								<datalist id="sourceKeyList"></datalist>
							</div>
						</section>

						<section class="panel">
							<div class="panel-head">
								<h2>Data Explorer (Legacy GAS)</h2>
								<p>Legacy GAS data (kept for transition period only). Refresh data after changes to confirm the latest state.</p>
							</div>
							<div class="panel-body stack">
								<div>
									<label>source_licenses</label>
									<div id="sourceLicensesTable" class="table-wrap"><div class="empty">No data loaded yet.</div></div>
								</div>
								<div>
									<label>apptook_keys</label>
									<div id="apptookKeysTable" class="table-wrap"><div class="empty">No data loaded yet.</div></div>
								</div>
								<div>
									<label>apptook_key_sources</label>
									<div id="apptookKeySourcesTable" class="table-wrap"><div class="empty">No data loaded yet.</div></div>
								</div>
								<div>
									<label>dashboard token</label>
									<div id="dashboardTokensTable" class="table-wrap"><div class="empty">No data loaded yet.</div></div>
								</div>
							</div>
						</section>
					</div>

					<div class="stack">
						<section class="panel">
							<div class="panel-head">
								<h2>Live Response</h2>
								<p>The latest response from Apps Script is shown here in a compact view.</p>
							</div>
							<div class="panel-body">
								<div id="responseBox" class="response-box">Ready.</div>
							</div>
						</section>
					</div>
				</div>
				</div>

				<div id="tabRuntimeMonitorContent" class="tab-content">
					<section class="panel">
						<div class="panel-head">
							<h2>Runtime Monitor</h2>
							<p>Track APTOOK usage %, active license, and per-license token usage in real time.</p>
						</div>
						<div class="panel-body">
							<div class="grid three">
								<div>
									<label for="rmApptookKey">APTOOK Key</label>
									<input id="rmApptookKey" type="text" placeholder="e.g. apptook_k1" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" aria-autocomplete="none" readonly onfocus="this.removeAttribute('readonly');" onmousedown="this.removeAttribute('readonly');">
								</div>
								<div>
									<label>&nbsp;</label>
									<div class="actions" style="margin-top:0;">
										<button id="rmLoadButton" class="btn-primary" type="button">Load Monitor</button>
										<button id="rmRefreshButton" class="btn-secondary" type="button">Refresh</button>
									</div>
								</div>
							</div>

							<div class="grid four" style="margin-top:10px;">
								<div>
									<label for="rmSimThreshold">Switch Threshold %</label>
									<input id="rmSimThreshold" type="number" min="1" max="100" step="0.1" value="95">
								</div>
								<div>
									<label for="rmSimIntervalMs">Step Interval (ms)</label>
									<input id="rmSimIntervalMs" type="number" value="100" readonly>
								</div>
								<div>
									<label for="rmSimTargetSeconds">Target Seconds to Threshold</label>
									<input id="rmSimTargetSeconds" type="number" min="1" step="1" value="5">
								</div>
								<div>
									<label>&nbsp;</label>
									<div class="actions" style="margin-top:0;">
										<button id="rmSimStartButton" class="btn-primary" type="button">Start Simulation</button>
										<button id="rmSimStopButton" class="btn-ghost" type="button">Stop Simulation</button>
										<button id="rmSimResetButton" class="btn-secondary" type="button">Reset Data</button>
									</div>
								</div>
							</div>

							<div class="grid three" style="margin-top:10px;">
								<div class="panel" style="border-radius:16px;">
									<div class="panel-body" style="padding:14px 16px;">
										<label>APTOOK Usage</label>
										<div id="rmUsagePercent" style="font-size:24px;font-weight:700;">-</div>
									</div>
								</div>
								<div class="panel" style="border-radius:16px;">
									<div class="panel-body" style="padding:14px 16px;">
										<label>Current Licence</label>
										<div id="rmCurrentSourceKey" style="font-size:18px;font-weight:700;">-</div>
									</div>
								</div>
								<div class="panel" style="border-radius:16px;">
									<div class="panel-body" style="padding:14px 16px;">
										<label>Total Capacity</label>
										<div id="rmTotalCapacity" style="font-size:18px;font-weight:700;">-</div>
									</div>
								</div>
							</div>

							<div id="rmLicensesTable" class="table-wrap" style="margin-top:14px;"><div class="empty">No runtime monitor data loaded.</div></div>
						</div>
					</section>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_debug_ping(): void {
		$this->assert_permissions();

		wp_send_json_success(
			array(
				'message'    => 'WP AJAX debug ping OK.',
				'userId'     => get_current_user_id(),
				'siteUrl'    => site_url(),
				'ajaxUrl'    => admin_url('admin-ajax.php'),
				'restUrl'    => rest_url('extension-cursor/v1/dispatch'),
				'timestamp'  => gmdate('c'),
			)
		);
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'extension-cursor/v1',
			'/dispatch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'rest_dispatch'),
				'permission_callback' => array($this, 'rest_permission_check'),
			)
		);
	}

	public function rest_permission_check(WP_REST_Request $request): bool {
		$nonce = $request->get_header('X-WP-Nonce');
		if (! wp_verify_nonce((string) $nonce, 'wp_rest')) {
			return false;
		}

		return current_user_can('manage_options');
	}

	public function rest_dispatch(WP_REST_Request $request): WP_REST_Response {
		$params  = $request->get_json_params();
		$payload = is_array($params) ? $params : array();
		$action  = ! empty($payload['action']) ? sanitize_text_field((string) $payload['action']) : '';
		$token   = ! empty($payload['token']) ? sanitize_text_field((string) $payload['token']) : '';
		$extra   = ! empty($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : array();

		$data = array_merge($extra, array(
			'action' => $action,
			'token'  => $token,
		));

		if (empty($data['action'])) {
			return new WP_REST_Response(array('ok' => false, 'message' => 'Invalid payload.'), 400);
		}

		$result = $this->dispatch_to_gas($data);
		if (! $result['ok']) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => $result['message'],
					'raw'     => $result['raw'],
					'debug'   => isset($result['debug']) ? $result['debug'] : array(),
				),
				(int) $result['status']
			);
		}

		return new WP_REST_Response($result['data'], 200);
	}

	public function ajax_dispatch(): void {
		$this->assert_permissions();

		$payload = '';
		if (isset($_POST['payload_b64'])) {
			$decoded = base64_decode((string) wp_unslash($_POST['payload_b64']), true);
			$payload = is_string($decoded) ? $decoded : '';
		} elseif (isset($_POST['payload'])) {
			$payload = (string) wp_unslash($_POST['payload']);
		}

		$data = json_decode((string) $payload, true);
		if (! is_array($data) || empty($data['action'])) {
			wp_send_json_error(array('message' => 'Invalid payload.'), 400);
		}

		$result = $this->dispatch_to_gas($data);
		if (! $result['ok']) {
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'raw'     => $result['raw'],
					'debug'   => isset($result['debug']) ? $result['debug'] : array(),
				),
				200
			);
		}

		wp_send_json_success($result['data']);
	}

	public function ajax_dispatch_v2(): void {
		$this->assert_permissions();

		$action = isset($_POST['ec_action']) ? sanitize_text_field(wp_unslash($_POST['ec_action'])) : '';
		$token  = isset($_POST['ec_token']) ? sanitize_text_field(wp_unslash($_POST['ec_token'])) : '';
		$extra  = isset($_POST['ec_payload']) ? wp_unslash($_POST['ec_payload']) : '{}';
		$extra_data = json_decode((string) $extra, true);
		if (! is_array($extra_data)) {
			$extra_data = array();
		}

		$data = array_merge($extra_data, array(
			'action' => $action,
			'token'  => $token,
		));

		if (empty($data['action'])) {
			wp_send_json_error(array('message' => 'Invalid payload.'), 400);
		}

		$result = $this->dispatch_to_gas($data);
		if (! $result['ok']) {
			wp_send_json_error(
				array(
					'message' => $result['message'],
					'raw'     => $result['raw'],
					'debug'   => isset($result['debug']) ? $result['debug'] : array(),
				),
				200
			);
		}

		wp_send_json_success($result['data']);
	}

	private function dispatch_to_gas(array $data): array {
		$gas_url = $this->get_gas_web_app_url();
		$request_body = wp_json_encode($data);
		$debug = array(
			'gasUrl'      => $gas_url,
			'requestBody' => $request_body,
			'timeout'     => 25,
		);

		if (empty($gas_url)) {
			return array('ok' => false, 'status' => 500, 'message' => 'GAS URL is not configured.', 'raw' => '', 'debug' => $debug);
		}

		$response = wp_remote_post(
			$gas_url,
			array(
				'timeout'     => 25,
				'redirection' => 10,
				'httpversion' => '1.1',
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json, text/plain, */*',
					'User-Agent'   => 'ExtensionCursorWP/1.0 (+WordPress)',
				),
				'body'        => $request_body,
			)
		);

		if (is_wp_error($response)) {
			$debug['wpErrorCode'] = $response->get_error_code();
			$debug['wpErrorData'] = $response->get_error_data();
			return array('ok' => false, 'status' => 502, 'message' => $response->get_error_message(), 'raw' => '', 'debug' => $debug);
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$headers_obj = wp_remote_retrieve_headers($response);
		$headers     = method_exists($headers_obj, 'getAll') ? $headers_obj->getAll() : (array) $headers_obj;
		$body        = wp_remote_retrieve_body($response);

		$debug['httpCode'] = $status_code;
		$debug['headers']  = $headers;
		$debug['rawBodyPreview'] = mb_substr((string) $body, 0, 1000);

		$decoded = json_decode((string) $body, true);
		if (! is_array($decoded)) {
			return array('ok' => false, 'status' => 502, 'message' => 'Invalid GAS response.', 'raw' => (string) $body, 'debug' => $debug);
		}

		if (empty($decoded['ok'])) {
			return array(
				'ok'      => false,
				'status'  => 400,
				'message' => ! empty($decoded['message']) ? (string) $decoded['message'] : 'GAS request failed.',
				'raw'     => wp_json_encode($decoded),
				'debug'   => $debug,
			);
		}

		return array('ok' => true, 'status' => 200, 'message' => '', 'raw' => '', 'data' => $decoded, 'debug' => $debug);
	}

	private function get_gas_web_app_url(): string {
		if (defined('EXT_CURSOR_GAS_WEB_APP_URL') && EXT_CURSOR_GAS_WEB_APP_URL) {
			return (string) EXT_CURSOR_GAS_WEB_APP_URL;
		}

		return 'https://script.google.com/macros/s/AKfycbw3tKH82o6KHtBIXjXsT7tl4Cg87kuwFALMu55Pk8c2WejcDm7wZdZUT_CHW0iRWfFk2A/exec';
	}

	private function assert_permissions(): void {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (! wp_verify_nonce($nonce, 'extension_cursor_admin_nonce')) {
			wp_send_json_error(array('message' => 'Invalid or expired nonce. Please refresh the page and try again.'), 403);
		}

		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Forbidden.'), 403);
		}
	}
}
