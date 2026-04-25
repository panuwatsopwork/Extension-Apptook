<?php
/**
 * Main plugin bootstrap.
 *
 * @package Extension_Cursor
 */

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

	public static function activate(): void {
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-loader.php';
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-database.php';
		Extension_Cursor_Database::activate();
	}

	private function __construct() {
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-loader.php';
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-database.php';
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-repository.php';
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-service.php';
		require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-admin.php';
		Extension_Cursor_Admin::instance();
	}
}
