<?php
/**
 * Plugin Name:       Extension Cursor
 * Description:       Admin panel for managing APPTOOK key system on your own WordPress server.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Apptook
 * Text Domain:       extension-cursor
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

define('EXT_CURSOR_VERSION', '0.1.1');
define('EXT_CURSOR_FILE', __FILE__);
define('EXT_CURSOR_PATH', plugin_dir_path(__FILE__));
define('EXT_CURSOR_URL', plugin_dir_url(__FILE__));

require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-db.php';
require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-api.php';
require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-admin.php';
require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-plugin.php';

function extension_cursor(): Extension_Cursor_Plugin {
	return Extension_Cursor_Plugin::instance();
}

register_activation_hook(
	EXT_CURSOR_FILE,
	static function (): void {
		Extension_Cursor_DB::install();
	}
);

extension_cursor();
