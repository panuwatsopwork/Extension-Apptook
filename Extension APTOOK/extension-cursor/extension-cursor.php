<?php
/**
 * Plugin Name:       Extension Cursor
 * Description:       Dark admin panel for managing APPTOOK stock licenses.
 * Version:           1.0.0
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

define('EXT_CURSOR_VERSION', '1.0.0');
define('EXT_CURSOR_FILE', __FILE__);
define('EXT_CURSOR_PATH', plugin_dir_path(__FILE__));
define('EXT_CURSOR_URL', plugin_dir_url(__FILE__));
define('EXT_CURSOR_DB_VERSION', '1.0.0');

require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-plugin.php';
require_once EXT_CURSOR_PATH . 'includes/class-extension-cursor-admin.php';

register_activation_hook(__FILE__, array('Extension_Cursor_Plugin', 'activate'));

function extension_cursor(): Extension_Cursor_Plugin {
	return Extension_Cursor_Plugin::instance();
}

extension_cursor();
