<?php
/**
 * Custom post types and statuses.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_Post_Types {

	public const ORDER_PENDING_PAYMENT = 'pending_payment';
	public const ORDER_SLIP_UPLOADED_WAITING_CUSTOMER_CONFIRM = 'slip_uploaded_waiting_customer_confirm';
	public const ORDER_PENDING_REVIEW  = 'pending_review';
	public const ORDER_PAID            = 'paid';
	public const ORDER_REJECTED        = 'rejected';

	public static function register_post_types(): void {
		register_post_type(
			'apptook_product',
			array(
				'labels'              => array(
					'name'          => __('สินค้าดิจิทัล', 'apptook-digital-store'),
					'singular_name' => __('สินค้าดิจิทัล', 'apptook-digital-store'),
					'add_new'       => __('เพิ่มสินค้า', 'apptook-digital-store'),
					'add_new_item'  => __('เพิ่มสินค้าใหม่', 'apptook-digital-store'),
					'edit_item'     => __('แก้ไขสินค้า', 'apptook-digital-store'),
				),
				'public'              => true,
				'has_archive'         => true,
				'rewrite'             => array('slug' => 'digital-products'),
				'menu_icon'           => 'dashicons-smartphone',
				'supports'            => array('title', 'editor', 'thumbnail', 'excerpt'),
				'taxonomies'          => array('apptook_product_cat', 'post_tag'),
				'show_in_rest'        => true,
				'exclude_from_search' => false,
			)
		);

		register_post_type(
			'apptook_order',
			array(
				'labels'              => array(
					'name'          => __('ออเดอร์', 'apptook-digital-store'),
					'singular_name' => __('ออเดอร์', 'apptook-digital-store'),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=apptook_product',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array('title'),
				/* สร้างออเดอร์ผ่าน AJAX โดยสลับผู้ใช้ชั่วคราวเป็นผู้ดูแลระบบ */
				'capabilities'        => array(
					'create_posts' => 'manage_options',
				),
			)
		);

		remove_post_type_support('apptook_order', 'editor');

		self::register_product_taxonomy();
	}

	public static function register_product_taxonomy(): void {
		register_taxonomy(
			'apptook_product_cat',
			array('apptook_product'),
			array(
				'labels'            => array(
					'name'          => __('หมวดสินค้า', 'apptook-digital-store'),
					'singular_name' => __('หมวดสินค้า', 'apptook-digital-store'),
					'search_items'  => __('ค้นหาหมวด', 'apptook-digital-store'),
					'all_items'     => __('ทุกหมวด', 'apptook-digital-store'),
					'edit_item'     => __('แก้ไขหมวด', 'apptook-digital-store'),
					'add_new_item'  => __('เพิ่มหมวดใหม่', 'apptook-digital-store'),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'meta_box_cb'       => 'post_categories_meta_box',
				'rewrite'           => array('slug' => 'product-category'),
			)
		);

		register_term_meta(
			'apptook_product_cat',
			'apptook_icon',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
				'description'  => __('ชื่อไอคอน Material Symbols (เช่น movie, music_note)', 'apptook-digital-store'),
			)
		);
	}

	/**
	 * ไอคอนเริ่มต้นตาม slug หมวด (ให้ตรงกับดีไซน์ stitch)
	 */
	public static function default_category_icon(string $slug): string {
		$map = array(
			'all'      => 'grid_view',
			'film'     => 'movie',
			'music'    => 'music_note',
			'ai'       => 'psychology',
			'software' => 'terminal',
			'star'     => 'grade',
		);
		return $map[ $slug ] ?? 'sell';
	}

	public static function order_statuses(): array {
		return array(
			self::ORDER_PENDING_PAYMENT => __('รอชำระเงิน', 'apptook-digital-store'),
			self::ORDER_SLIP_UPLOADED_WAITING_CUSTOMER_CONFIRM => __('อัปโหลดสลิปแล้ว รอลูกค้ายืนยัน', 'apptook-digital-store'),
			self::ORDER_PENDING_REVIEW  => __('รอตรวจสลิป', 'apptook-digital-store'),
			self::ORDER_PAID            => __('ชำระสำเร็จ', 'apptook-digital-store'),
			self::ORDER_REJECTED        => __('ปฏิเสธ', 'apptook-digital-store'),
		);
	}

	public static function get_order_status_label(string $status): string {
		$all = self::order_statuses();
		return $all[ $status ] ?? $status;
	}
}
