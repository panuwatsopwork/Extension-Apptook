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
				'ajaxUrl'  => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('extension_cursor_admin_nonce'),
				'restNonce' => wp_create_nonce('wp_rest'),
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
							<button id="tabSimulationButton" class="tab-btn" type="button" role="tab" aria-selected="false">Simulation Engine</button>
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
								<div class="grid two">
									<div>
										<label for="localStockSearch">Search stock keys</label>
										<input id="localStockSearch" type="text" placeholder="Search by ID, source key, provider...">
									</div>
									<div>
										<label>&nbsp;</label>
										<div class="actions" style="margin-top:0;">
											<button id="localStockClearButton" class="btn-ghost" type="button">Clear</button>
										</div>
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
								<div class="grid two">
									<div>
										<label for="localGroupSearch">Search groups</label>
										<input id="localGroupSearch" type="text" placeholder="Search by ID, code, name, mode...">
									</div>
									<div>
										<label>&nbsp;</label>
										<div class="actions" style="margin-top:0;">
											<button id="localGroupClearButton" class="btn-ghost" type="button">Clear</button>
										</div>
									</div>
								</div>
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
			</div>

			<div id="tabSimulationContent" class="tab-content">
				<section class="panel">
					<div class="panel-head">
						<h2>Simulation Engine</h2>
						<p>Create simulated licences that behave like real provider licences for testing purposes.</p>
					</div>
					<div class="panel-body">
						<div class="grid four">
							<div>
								<label for="simulationLicenseCode">License Code</label>
								<input id="simulationLicenseCode" type="text" placeholder="e.g. sim_001">
							</div>
							<div>
								<label for="simulationLicenseName">Name</label>
								<input id="simulationLicenseName" type="text" placeholder="Sim Licence A">
							</div>
							<div>
								<label for="simulationTokenCapacity">Token Capacity</label>
								<input id="simulationTokenCapacity" type="number" min="1" step="1" value="100">
							</div>
							<div>
								<label for="simulationCurrentRawUsage">Current Raw Usage</label>
								<input id="simulationCurrentRawUsage" type="number" min="0" step="0.000001" value="0">
							</div>
						</div>
						<div class="grid three" style="margin-top:12px;">
							<div>
								<label for="simulationMode">Mode</label>
								<select id="simulationMode"><option value="simulation">simulation</option><option value="real">real</option></select>
							</div>
							<div>
								<label for="simulationStatus">Status</label>
								<select id="simulationStatus"><option value="active">active</option><option value="inactive">inactive</option></select>
							</div>
							<div>
								<label for="simulationNote">Note</label>
								<input id="simulationNote" type="text" placeholder="Optional note">
							</div>
						</div>
						<div class="actions" style="margin-top:14px;">
							<button id="simulationCreateButton" class="btn-primary" type="button">Create Simulation Licence</button>
							<button id="simulationRefreshButton" class="btn-secondary" type="button">Refresh Simulation Licences</button>
						</div>
						<div id="simulationLicensesTable" class="table-wrap" style="margin-top:12px;"><div class="empty">No simulation licences loaded.</div></div>
					</div>
				</section>
			</div>
		</div>
		<?php
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
