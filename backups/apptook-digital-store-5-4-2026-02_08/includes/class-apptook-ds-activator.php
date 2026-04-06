<?php
/**
 * Activation / deactivation.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_Activator {

	public static function activate(): void {
		Apptook_DS_Post_Types::register_post_types();
		add_role(
			'apptook_customer',
			__('ลูกค้า Apptook', 'apptook-digital-store'),
			array(
				'read' => true,
			)
		);
		flush_rewrite_rules();

		if (get_option('apptook_ds_setup_version')) {
			return;
		}

		$shop_id = wp_insert_post(
			array(
				'post_title'   => __('ร้านดิจิทัล', 'apptook-digital-store'),
				'post_name'    => 'digital-shop',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[apptook_shop]<!-- /wp:shortcode -->',
			),
			true
		);

		$lib_id = wp_insert_post(
			array(
				'post_title'   => __('คลังของฉัน', 'apptook-digital-store'),
				'post_name'    => 'my-digital-library',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[apptook_library]<!-- /wp:shortcode -->',
			),
			true
		);

		if (! is_wp_error($shop_id)) {
			update_option('apptook_ds_page_shop_id', (int) $shop_id);
		}
		if (! is_wp_error($lib_id)) {
			update_option('apptook_ds_page_library_id', (int) $lib_id);
		}

		$reg_id = wp_insert_post(
			array(
				'post_title'   => __('สมัครสมาชิก', 'apptook-digital-store'),
				'post_name'    => 'apptook-register',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[apptook_register]<!-- /wp:shortcode -->',
			),
			true
		);
		if (! is_wp_error($reg_id)) {
			update_option('apptook_ds_page_register_id', (int) $reg_id);
		}

		update_option('apptook_ds_setup_version', APPTOOK_DS_VERSION);
	}

	/**
	 * สร้างหน้าสมัครสมาชิกครั้งเดียว (สำหรับเว็บที่ติดตั้งก่อนมีหน้านี้)
	 */
	public static function maybe_create_register_page(): void {
		if ( (int) get_option( 'apptook_ds_page_register_id', 0 ) > 0 ) {
			return;
		}
		$reg_id = wp_insert_post(
			array(
				'post_title'   => __( 'สมัครสมาชิก', 'apptook-digital-store' ),
				'post_name'    => 'apptook-register',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:shortcode -->[apptook_register]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( ! is_wp_error( $reg_id ) ) {
			update_option( 'apptook_ds_page_register_id', (int) $reg_id );
		}
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
