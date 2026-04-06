<?php
/**
 * Main plugin controller.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('init', array(Apptook_DS_Post_Types::class, 'register_post_types'), 0);
		add_action('init', array('Apptook_DS_Activator', 'maybe_create_register_page'), 2);
		add_action('init', array($this, 'maybe_flush_rewrites'), 99);
		add_action('plugins_loaded', array($this, 'load_textdomain'));
		add_action('init', array($this, 'maybe_bootstrap_external_db'), 20);
		add_action('save_post_apptook_product', array($this, 'sync_product_to_external_db'), 30, 3);
		add_action('save_post_apptook_order', array($this, 'sync_order_to_external_db'), 30, 3);
		Apptook_DS_Admin::instance();
		Apptook_DS_Ajax::instance();
		Apptook_DS_Public::instance();
	}

	public function maybe_flush_rewrites(): void {
		$ver = get_option('apptook_ds_rewrite_version', '');
		if ($ver === APPTOOK_DS_VERSION) {
			return;
		}
		flush_rewrite_rules(false);
		update_option('apptook_ds_rewrite_version', APPTOOK_DS_VERSION);
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'apptook-digital-store',
			false,
			dirname(plugin_basename(APPTOOK_DS_FILE)) . '/languages'
		);
	}

	public function maybe_bootstrap_external_db(): void {
		if (! Apptook_DS_External_DB::instance()->is_configured()) {
			return;
		}
		// Always attempt table bootstrap to self-heal when credentials were fixed later.
		Apptook_DS_External_DB::instance()->maybe_create_tables();
	}

	public function sync_product_to_external_db( int $post_id, WP_Post $post, bool $update ): void {
		if ($post->post_type !== 'apptook_product') {
			return;
		}
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}
		if ((string) $post->post_status === 'auto-draft') {
			return;
		}
		if (! Apptook_DS_External_DB::instance()->is_configured()) {
			return;
		}
		Apptook_DS_External_DB::instance()->sync_product($post_id);
	}

	public function sync_order_to_external_db( int $post_id, WP_Post $post, bool $update ): void {
		if ($post->post_type !== 'apptook_order') {
			return;
		}
		if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! Apptook_DS_External_DB::instance()->is_configured()) {
			return;
		}
		Apptook_DS_External_DB::instance()->upsert_order_from_wp($post_id);
	}
}
