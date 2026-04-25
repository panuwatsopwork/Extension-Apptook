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
				'nonce'     => wp_create_nonce('extension_cursor_admin_nonce'),
				'gasWebApp' => $this->get_gas_web_app_url(),
			)
		);
	}

	public function render_admin_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'extension-cursor'));
		}
		?>
		<div class="extension-cursor page">
			<div class="admin-shell">
				<header class="admin-hero">
					<div class="brand-lockup">
						<div class="brand-mark" aria-hidden="true">A</div>
						<div>
							<p class="eyebrow">APPTOOK Admin</p>
							<h1>Extension Cursor</h1>
							<p class="hero-subtitle">ธีมหลังบ้านโทนมืดสำหรับ WordPress plugin พร้อมแท็บ Main และ Licence Monitor</p>
						</div>
					</div>

					<div class="hero-meta">
						<div class="meta-chip">
							<span class="meta-label">WordPress</span>
							<span class="meta-value">Admin UI</span>
						</div>
						<div class="meta-chip">
							<span class="meta-label">Mode</span>
							<span class="meta-value">UI prototype</span>
						</div>
					</div>

					<nav class="admin-tabs" aria-label="Admin sections">
						<button class="admin-tab is-active" type="button" data-tab-target="main">Main</button>
						<button class="admin-tab" type="button" data-tab-target="licence-monitor">Licence Monitor</button>
					</nav>
				</header>

				<section class="admin-tab-panel is-active" data-tab-panel="main">
					<div class="content-grid">
						<div class="card card-hero">
							<div class="card-heading">
								<div>
									<p class="card-kicker">Primary actions</p>
									<h2>Phase 2: Local Stock Key Vault</h2>
								</div>
								<span class="card-badge">WP DB</span>
							</div>
							<p class="card-description">เก็บ source license ลงฐานข้อมูล WordPress โดยตรง และเตรียมข้อมูลสำหรับเชื่อมต่อภายหลัง</p>

							<div class="field-grid two">
								<div class="field-block">
									<label for="adminToken">Admin Token</label>
									<input id="adminToken" type="password" placeholder="Enter admin token">
								</div>
								<div class="field-block">
									<label for="gasWebAppUrl">GAS Web App URL</label>
									<input id="gasWebAppUrl" type="url" value="<?php echo esc_attr($this->get_gas_web_app_url()); ?>" readonly>
								</div>
							</div>

							<div class="toolbar">
								<button id="setupButton" class="btn-primary" type="button">Setup tables</button>
								<button id="refreshButton" class="btn-secondary" type="button">Refresh data</button>
							</div>
							<div id="statusBar" class="status-bar"><span id="statusText">Ready.</span></div>
						</div>

						<div class="card">
							<div class="card-heading">
								<div>
									<p class="card-kicker">Stock import</p>
									<h2>Import Source Licenses</h2>
								</div>
								<span class="card-badge soft">Bulk load</span>
							</div>
							<textarea id="sourceKeysText" placeholder="one source key per line"></textarea>
							<div class="field-grid four">
								<input id="sourceExpireAt" type="date">
								<input id="sourceMaxDevices" type="number" min="1" step="1" value="1">
								<input id="sourceTokenCapacity" type="number" min="100" step="1" value="100">
								<input id="sourceNote" type="text" placeholder="Optional note">
							</div>
							<div class="toolbar">
								<button id="importSourceKeysButton" class="btn-primary" type="button">Import source licenses</button>
							</div>
						</div>

						<div class="card">
							<div class="card-heading">
								<div>
									<p class="card-kicker">Creation flow</p>
									<h2>Create APPTOOK Key</h2>
								</div>
								<span class="card-badge">Key builder</span>
							</div>
							<div class="field-grid three">
								<input id="apptookKey" type="text" placeholder="Leave blank to auto-generate">
								<select id="keyType"><option value="single">single</option><option value="loop">loop</option></select>
								<input id="appKeyExpireAt" type="date">
							</div>
							<div class="field-grid two compact-gap">
								<input id="appKeyNote" type="text" placeholder="Optional note">
								<button id="randomApptookKeyButton" class="btn-ghost" type="button">Random APPTOOK key</button>
							</div>
							<div class="toolbar">
								<button id="createApptookKeyButton" class="btn-primary" type="button">Create APPTOOK key</button>
							</div>
						</div>

						<div class="card">
							<div class="card-heading">
								<div>
									<p class="card-kicker">Mapping</p>
									<h2>Assign Source Licenses</h2>
								</div>
								<span class="card-badge soft">One-to-many</span>
							</div>
							<div class="field-grid two">
								<select id="assignApptookKeySelect"><option value="">Select APPTOOK key</option></select>
								<input id="assignKeyType" type="text" value="-" readonly>
							</div>
							<div class="toolbar">
								<button id="addSourceRowButton" class="btn-secondary" type="button">Add source license</button>
							</div>
							<div id="sourceRows" class="assign-rows"></div>
							<div class="toolbar">
								<button id="saveSourcesButton" class="btn-primary" type="button">Save source mapping</button>
							</div>
							<datalist id="sourceKeyList"></datalist>
						</div>
					</div>
				</section>

				<section class="admin-tab-panel" data-tab-panel="licence-monitor">
					<div class="content-grid monitor-grid">
						<div class="card monitor-summary">
							<div class="card-heading">
								<div>
									<p class="card-kicker">Realtime view</p>
									<h2>Licence Monitor</h2>
								</div>
								<span class="card-badge">Live feed</span>
							</div>
							<p class="card-description">พื้นที่สำหรับตรวจสอบ source licenses, APPTOOK keys และ token usage แบบอ่านง่าย</p>
							<div class="monitor-stats">
								<div class="stat-card"><span>Source Licenses</span><strong id="sourceLicenseCount">0</strong></div>
								<div class="stat-card"><span>APPTOOK Keys</span><strong id="apptookKeyCount">0</strong></div>
								<div class="stat-card"><span>Mappings</span><strong id="mappingCount">0</strong></div>
							</div>
							<div class="toolbar">
								<button id="refreshMonitorButton" class="btn-primary" type="button">Refresh monitor data</button>
							</div>
							<div id="responseBox" class="response-box">Ready.</div>
						</div>

						<div class="card tables-card">
							<div class="card-heading">
								<div>
									<p class="card-kicker">Data tables</p>
									<h2>Monitor detail panels</h2>
								</div>
								<span class="card-badge soft">Auto refreshed</span>
							</div>
							<div id="sourceLicensesTable" class="table-wrap"></div>
							<div id="apptookKeysTable" class="table-wrap"></div>
							<div id="apptookKeySourcesTable" class="table-wrap"></div>
							<div id="dashboardTokensTable" class="table-wrap"></div>
						</div>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	public function ajax_dispatch(): void {
		$this->assert_permissions();

		$payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
		$data    = json_decode((string) $payload, true);

		if (! is_array($data) || empty($data['action'])) {
			wp_send_json_error(array('message' => 'Invalid payload.'), 400);
		}

		$gas_url = $this->get_gas_web_app_url();
		if (empty($gas_url)) {
			wp_send_json_error(array('message' => 'GAS URL is not configured.'), 500);
		}

		$response = wp_remote_post(
			$gas_url,
			array(
				'timeout' => 20,
				'headers' => array('Content-Type' => 'application/json'),
				'body'    => wp_json_encode($data),
			)
		);

		if (is_wp_error($response)) {
			wp_send_json_error(array('message' => $response->get_error_message()), 502);
		}

		$body    = wp_remote_retrieve_body($response);
		$decoded = json_decode((string) $body, true);

		if (! is_array($decoded)) {
			wp_send_json_error(array('message' => 'Invalid GAS response.', 'raw' => $body), 502);
		}

		if (! empty($decoded['ok'])) {
			wp_send_json_success($decoded);
		}

		wp_send_json_error($decoded, 400);
	}

	private function get_gas_web_app_url(): string {
		if (defined('EXT_CURSOR_GAS_WEB_APP_URL') && EXT_CURSOR_GAS_WEB_APP_URL) {
			return (string) EXT_CURSOR_GAS_WEB_APP_URL;
		}

		return 'https://script.google.com/macros/s/AKfycbyJvMCawEeP2qCWTCxEUEZ3ygaV8f9aVJjXgJz8GcAVgoUKyKo9EiTmBRewOpecrYZE/exec';
	}

	private function assert_permissions(): void {
		check_ajax_referer('extension_cursor_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Forbidden.'), 403);
		}
	}
}
