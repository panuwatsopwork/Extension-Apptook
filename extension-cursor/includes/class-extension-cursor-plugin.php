<?php

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-admin.php';
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-api.php';

		Extension_Cursor_Admin::instance();
		Extension_Cursor_API::instance();
	}
}
