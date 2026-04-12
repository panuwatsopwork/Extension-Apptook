<?php

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
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
	}

	public function register_menu(): void {
		add_menu_page(
			__('Extension Cursor', 'extension-cursor'),
			__('Extension Cursor', 'extension-cursor'),
			'manage_options',
			'extension-cursor',
			array($this, 'render_page'),
			'dashicons-admin-generic',
			58
		);
	}

	public function enqueue_assets(string $hook): void {
		if ($hook !== 'toplevel_page_extension-cursor') {
			return;
		}

		$css_path = EXT_CURSOR_PATH . 'assets/admin.css';
		$js_path = EXT_CURSOR_PATH . 'assets/admin.js';

		wp_enqueue_style(
			'extension-cursor-admin',
			EXT_CURSOR_URL . 'assets/admin.css',
			array(),
			file_exists($css_path) ? (string) filemtime($css_path) : EXT_CURSOR_VERSION
		);

		wp_enqueue_script(
			'extension-cursor-admin',
			EXT_CURSOR_URL . 'assets/admin.js',
			array(),
			file_exists($js_path) ? (string) filemtime($js_path) : EXT_CURSOR_VERSION,
			true
		);

		wp_localize_script('extension-cursor-admin', 'extCursorAdmin', array(
			'restUrl' => esc_url_raw(rest_url('extension-cursor/v1')),
			'nonce' => wp_create_nonce('wp_rest'),
		));
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'extension-cursor'));
		}
		?>
		<div class="wrap ext-cursor-wrap">
			<h1>Extension Cursor</h1>
			<p>Admin panel running on your WordPress server.</p>

			<div class="ext-cursor-card">
				<h2>Server API Status</h2>
				<p id="ext-cursor-status">Ready.</p>
				<button id="ext-cursor-ping" class="button button-primary">Test API</button>
			</div>

			<div class="ext-cursor-card">
				<h2>Latest Response</h2>
				<pre id="ext-cursor-response">Ready.</pre>
			</div>
		</div>
		<?php
	}
}
