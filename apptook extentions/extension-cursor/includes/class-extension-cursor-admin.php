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
			<section class="hero">
				<div class="hero-copy">
					<h1>APPTOOK Admin (Extension Cursor)</h1>
					<p>WordPress-hosted admin UI. Requests are proxied to Google Apps Script.</p>
				</div>
			</section>

			<div class="panel">
				<div class="panel-body">
					<div class="grid two">
						<div>
							<label for="adminToken">Admin Token</label>
							<input id="adminToken" type="password" placeholder="Enter admin token">
						</div>
						<div>
							<label for="gasWebAppUrl">GAS Web App URL</label>
							<input id="gasWebAppUrl" type="url" value="<?php echo esc_attr($this->get_gas_web_app_url()); ?>" readonly>
						</div>
					</div>
					<div class="actions">
						<button id="setupButton" class="btn-primary">Setup tables</button>
						<button id="refreshButton" class="btn-secondary">Refresh data</button>
					</div>
					<div id="statusBar" class="status-bar"><span id="statusText">Ready.</span></div>
				</div>
			</div>

			<div class="panel">
				<div class="panel-body">
					<h2>Import Source Licenses</h2>
					<textarea id="sourceKeysText" placeholder="one source key per line"></textarea>
					<div class="grid four">
						<input id="sourceExpireAt" type="date">
						<input id="sourceMaxDevices" type="number" min="1" step="1" value="1">
						<input id="sourceTokenCapacity" type="number" min="100" step="1" value="100">
						<input id="sourceNote" type="text" placeholder="Optional note">
					</div>
					<div class="actions"><button id="importSourceKeysButton" class="btn-primary">Import source licenses</button></div>
				</div>
			</div>

			<div class="panel">
				<div class="panel-body">
					<h2>Create APPTOOK Key</h2>
					<div class="grid three">
						<input id="apptookKey" type="text" placeholder="Leave blank to auto-generate">
						<select id="keyType"><option value="single">single</option><option value="loop">loop</option></select>
						<input id="appKeyExpireAt" type="date">
					</div>
					<div class="grid two">
						<input id="appKeyNote" type="text" placeholder="Optional note">
						<button id="randomApptookKeyButton" class="btn-ghost" type="button">Random APPTOOK key</button>
					</div>
					<div class="actions"><button id="createApptookKeyButton" class="btn-primary">Create APPTOOK key</button></div>
				</div>
			</div>

			<div class="panel">
				<div class="panel-body">
					<h2>Assign Source Licenses</h2>
					<div class="grid two">
						<select id="assignApptookKeySelect"><option value="">Select APPTOOK key</option></select>
						<input id="assignKeyType" type="text" value="-" readonly>
					</div>
					<div class="actions"><button id="addSourceRowButton" class="btn-secondary" type="button">Add source license</button></div>
					<div id="sourceRows" class="assign-rows"></div>
					<div class="actions"><button id="saveSourcesButton" class="btn-primary">Save source mapping</button></div>
					<datalist id="sourceKeyList"></datalist>
				</div>
			</div>

			<div class="panel">
				<div class="panel-body">
					<h2>Data Explorer</h2>
					<div id="responseBox" class="response-box">Ready.</div>
					<div id="sourceLicensesTable" class="table-wrap"></div>
					<div id="apptookKeysTable" class="table-wrap"></div>
					<div id="apptookKeySourcesTable" class="table-wrap"></div>
					<div id="dashboardTokensTable" class="table-wrap"></div>
				</div>
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

		return 'https://script.google.com/macros/s/AKfycbwWO_JLPueD8wbIdng9i_GZ24809CP_nLPBMvu4OO9r1wKU579kTLkKO7Iz99BuKpGDAg/exec';
	}

	private function assert_permissions(): void {
		check_ajax_referer('extension_cursor_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Forbidden.'), 403);
		}
	}
}
