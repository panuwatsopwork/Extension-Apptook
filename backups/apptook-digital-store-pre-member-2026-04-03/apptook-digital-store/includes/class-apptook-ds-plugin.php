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
}
