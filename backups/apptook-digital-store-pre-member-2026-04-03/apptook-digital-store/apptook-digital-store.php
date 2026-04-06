<?php
/**
 * Plugin Name:       Apptook Digital Store
 * Description:       ร้านขายดิจิทัลแบบทดสอบ — พร้อมเพย์ QR, อัปโหลดสลิป, แอดมินยืนยัน, คลัง key
 * Version:           0.3.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Apptook
 * Text Domain:       apptook-digital-store
 * Domain Path:       /languages
 *
 * @package Apptook_Digital_Store
 *
 * Backup snapshot: ร้านดิจิทัลก่อนรวมโมดูลสมาชิก (member) — สำหรับกู้คืนด้วยตนเอง
 */

if (! defined('ABSPATH')) {
	exit;
}

define('APPTOOK_DS_VERSION', '0.3.3');
define('APPTOOK_DS_FILE', __FILE__);
define('APPTOOK_DS_PATH', plugin_dir_path(__FILE__));
define('APPTOOK_DS_URL', plugin_dir_url(__FILE__));

require_once APPTOOK_DS_PATH . 'includes/class-apptook-ds-activator.php';
require_once APPTOOK_DS_PATH . 'includes/class-apptook-ds-post-types.php';
require_once APPTOOK_DS_PATH . 'includes/class-apptook-ds-admin.php';
require_once APPTOOK_DS_PATH . 'includes/class-apptook-ds-ajax.php';
require_once APPTOOK_DS_PATH . 'includes/class-apptook-ds-public.php';
require_once APPTOOK_DS_PATH . 'includes/class-apptook-ds-plugin.php';

register_activation_hook(__FILE__, array('Apptook_DS_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('Apptook_DS_Activator', 'deactivate'));

/**
 * Bootstrap.
 */
function apptook_ds(): Apptook_DS_Plugin {
	return Apptook_DS_Plugin::instance();
}

apptook_ds();
