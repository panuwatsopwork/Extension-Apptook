<?php
/**
 * Plugin Name:       Extension Cursor
 * Description:       Admin panel for managing APPTOOK key orchestration on your own WordPress server.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Apptook
 * Text Domain:       extension-cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

define('EXT_CURSOR_VERSION', '0.1.0');
define('EXT_CURSOR_FILE', __FILE__);
define('EXT_CURSOR_PATH', plugin_dir_path(__FILE__));
define('EXT_CURSOR_URL', plugin_dir_url(__FILE__));

require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-plugin.php';

function extension_cursor(): Extension_Cursor_Plugin {
	return Extension_Cursor_Plugin::instance();
}

extension_cursor();
