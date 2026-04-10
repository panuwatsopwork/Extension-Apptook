<?php
/**
 * AJAX handlers.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_Ajax {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('wp_ajax_apptook_ds_create_order', array($this, 'create_order'));
		add_action('wp_ajax_apptook_ds_upload_slip', array($this, 'upload_slip'));
		add_action('wp_ajax_nopriv_apptook_ds_login_popup', array($this, 'login_popup'));
		add_action('wp_ajax_apptook_ds_login_popup', array($this, 'login_popup'));
		add_action('wp_ajax_apptook_ds_subscription_statuses', array($this, 'subscription_statuses'));
	}

	private static function first_admin_user_id(): int {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => array('ID'),
			)
		);
		if (! empty($users[0]->ID)) {
			return (int) $users[0]->ID;
		}
		return 1;
	}

	private function create_order_record(int $product_id, int $customer_id, string $price, string $initial_status = Apptook_DS_Post_Types::ORDER_PENDING_PAYMENT): int {
		$admin_uid = self::first_admin_user_id();
		$old_uid   = get_current_user_id();
		wp_set_current_user($admin_uid);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'apptook_order',
				'post_status' => 'publish',
				'post_title'  => __('ออเดอร์ใหม่', 'apptook-digital-store'),
				'post_author' => $admin_uid,
			),
			true
		);

		if (! is_wp_error($post_id)) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => sprintf(
						/* translators: 1: order id, 2: product title */
						__('ออเดอร์ #%1$d — %2$s', 'apptook-digital-store'),
						$post_id,
						get_the_title($product_id)
					),
				)
			);
		}

		wp_set_current_user($old_uid);

		if (is_wp_error($post_id)) {
			return 0;
		}

		update_post_meta($post_id, '_apptook_customer_id', $customer_id);
		update_post_meta($post_id, '_apptook_product_id', $product_id);
		update_post_meta($post_id, '_apptook_amount', $price);
		update_post_meta($post_id, '_apptook_status', $initial_status);
		update_post_meta($post_id, '_apptook_slip_id', 0);
		update_post_meta($post_id, '_apptook_license_key', '');

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->upsert_order_from_wp((int) $post_id);
			Apptook_DS_External_DB::instance()->add_order_log((int) $post_id, 'order_created', '', $initial_status);
		}

		return (int) $post_id;
	}

	public function create_order(): void {
		check_ajax_referer('apptook_ds_public', 'nonce');

		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('กรุณาเข้าสู่ระบบก่อน', 'apptook-digital-store')), 401);
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$product    = $product_id ? get_post($product_id) : null;

		if (! $product || $product->post_type !== 'apptook_product' || $product->post_status !== 'publish') {
			wp_send_json_error(array('message' => __('ไม่พบสินค้า', 'apptook-digital-store')), 400);
		}

		$price = (string) get_post_meta($product_id, '_apptook_price', true);
		if ($price === '' || (float) $price <= 0) {
			wp_send_json_error(array('message' => __('สินค้านี้ยังไม่ตั้งราคา', 'apptook-digital-store')), 400);
		}

		$opts = get_option('apptook_ds_options', array());
		$user_id = get_current_user_id();

		wp_send_json_success(
			array(
				'order_id'       => 0,
				'product_id'     => $product_id,
				'amount'         => $price,
				'promptpay_id'   => isset($opts['promptpay_id']) ? (string) $opts['promptpay_id'] : '',
				'qr_image_url'   => isset($opts['qr_image_url']) ? (string) $opts['qr_image_url'] : '',
				'payment_note'   => isset($opts['payment_note']) ? (string) $opts['payment_note'] : '',
				'order_ref'      => 'PENDING-' . $user_id . '-' . $product_id,
				'upload_nonce'   => wp_create_nonce('apptook_ds_upload_product_' . $product_id . '_' . $user_id),
			)
		);
	}

	public function login_popup(): void {
		check_ajax_referer('apptook_ds_public', 'nonce');
		$login = isset($_POST['log']) ? sanitize_text_field(wp_unslash((string) $_POST['log'])) : '';
		$pass = isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '';
		$remember = ! empty($_POST['rememberme']);

		if ($login === '' || $pass === '') {
			wp_send_json_error(array('message' => __('กรุณากรอกชื่อผู้ใช้/อีเมล และรหัสผ่าน', 'apptook-digital-store')), 400);
		}

		$creds = array(
			'user_login' => $login,
			'user_password' => $pass,
			'remember' => $remember,
		);
		$user = wp_signon($creds, is_ssl());
		if (is_wp_error($user)) {
			wp_send_json_error(array('message' => __('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'apptook-digital-store')), 401);
		}

		wp_send_json_success(array('message' => __('เข้าสู่ระบบสำเร็จ', 'apptook-digital-store')));
	}

	public function subscription_statuses(): void {
		check_ajax_referer('apptook_ds_public', 'nonce');

		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('กรุณาเข้าสู่ระบบ', 'apptook-digital-store')), 401);
		}

		$user_id = get_current_user_id();
		$orders  = new WP_Query(
			array(
				'post_type'        => 'apptook_order',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'suppress_filters' => true,
				'meta_query'       => array(
					array(
						'key'   => '_apptook_customer_id',
						'value' => $user_id,
					),
				),
			),
		);

		$items = array();
		if ($orders instanceof WP_Query && $orders->have_posts()) {
			while ($orders->have_posts()) {
				$orders->the_post();
				$order_id = get_the_ID();
				$status = (string) get_post_meta($order_id, '_apptook_status', true);
				$expires_at_raw = (string) get_post_meta($order_id, '_apptook_expire_at', true);
				$expires_ts = $expires_at_raw !== '' ? strtotime($expires_at_raw) : false;
				if (! is_int($expires_ts) || $expires_ts <= 0) {
					$expires_ts = strtotime('+30 days', (int) get_post_time('U', true, $order_id));
				}
				$days_left = max(0, (int) floor((((int) $expires_ts) - current_time('timestamp', true)) / DAY_IN_SECONDS));

				$ui_state = 'inactive';
				$ui_state_label = __('Inactive', 'apptook-digital-store');
				$ui_state_icon = 'schedule';
				if ($status === Apptook_DS_Post_Types::ORDER_PAID) {
					$ui_state = 'active';
					$ui_state_label = __('Active', 'apptook-digital-store');
					$ui_state_icon = 'verified';
				} elseif ($status === Apptook_DS_Post_Types::ORDER_REJECTED) {
					$ui_state = 'cancelled';
					$ui_state_label = __('Cancelled', 'apptook-digital-store');
					$ui_state_icon = 'cancel';
				}

				$alert_state = 'safe';
				if ($days_left <= 1) {
					$alert_state = 'danger';
				} elseif ($days_left < 7) {
					$alert_state = 'warning';
				}

				$items[] = array(
					'order_id' => (int) $order_id,
					'ui_state' => $ui_state,
					'ui_state_label' => $ui_state_label,
					'ui_state_icon' => $ui_state_icon,
					'days_left' => $days_left,
					'alert_state' => $alert_state,
					'alert_text' => sprintf(__('เหลือ %d วัน', 'apptook-digital-store'), $days_left),
				);
			}
			wp_reset_postdata();
		}

		wp_send_json_success(array('items' => $items));
	}

	public function upload_slip(): void {
		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('กรุณาเข้าสู่ระบบ', 'apptook-digital-store')), 401);
		}

		$current_user_id = get_current_user_id();
		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

		if ($order_id > 0) {
			check_ajax_referer('apptook_ds_upload_' . $order_id, 'nonce');
		} else {
			if (! $product_id) {
				wp_send_json_error(array('message' => __('ไม่พบข้อมูลสินค้า', 'apptook-digital-store')), 400);
			}
			check_ajax_referer('apptook_ds_upload_product_' . $product_id . '_' . $current_user_id, 'nonce');
		}

		if (empty($_FILES['slip']['name'])) {
			wp_send_json_error(array('message' => __('กรุณาเลือกไฟล์สลิป', 'apptook-digital-store')), 400);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file = $_FILES['slip'];
		$allowed = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);
		$overrides = array(
			'test_form' => false,
			'mimes'     => $allowed,
		);
		$move = wp_handle_upload($file, $overrides);
		if (isset($move['error'])) {
			wp_send_json_error(array('message' => $move['error']), 400);
		}

		if ($order_id > 0) {
			$order = get_post($order_id);
			if (! $order || $order->post_type !== 'apptook_order') {
				wp_send_json_error(array('message' => __('ไม่พบออเดอร์', 'apptook-digital-store')), 400);
			}
			$customer_id = (int) get_post_meta($order_id, '_apptook_customer_id', true);
			if ($customer_id !== $current_user_id) {
				wp_send_json_error(array('message' => __('ไม่มีสิทธิ์', 'apptook-digital-store')), 403);
			}
			$status = (string) get_post_meta($order_id, '_apptook_status', true);
			if ($status !== Apptook_DS_Post_Types::ORDER_PENDING_PAYMENT) {
				wp_send_json_error(array('message' => __('สถานะออเดอร์ไม่รองรับการอัปโหลด', 'apptook-digital-store')), 400);
			}
		} else {
			$product = get_post($product_id);
			if (! $product || $product->post_type !== 'apptook_product' || $product->post_status !== 'publish') {
				wp_send_json_error(array('message' => __('ไม่พบสินค้า', 'apptook-digital-store')), 400);
			}
			$price = (string) get_post_meta($product_id, '_apptook_price', true);
			if ($price === '' || (float) $price <= 0) {
				wp_send_json_error(array('message' => __('สินค้านี้ยังไม่ตั้งราคา', 'apptook-digital-store')), 400);
			}

			$order_id = $this->create_order_record(
				$product_id,
				$current_user_id,
				$price,
				Apptook_DS_Post_Types::ORDER_PENDING_PAYMENT
			);
			if ($order_id <= 0) {
				wp_send_json_error(array('message' => __('ไม่สามารถสร้างออเดอร์ได้', 'apptook-digital-store')), 500);
			}
		}

		$attachment = array(
			'post_mime_type' => $move['type'],
			'post_title'     => sanitize_file_name(pathinfo($move['file'], PATHINFO_FILENAME)),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $current_user_id,
		);

		$attach_id = wp_insert_attachment($attachment, $move['file'], $order_id);
		if (is_wp_error($attach_id)) {
			wp_send_json_error(array('message' => $attach_id->get_error_message()), 500);
		}

		$meta = wp_generate_attachment_metadata($attach_id, $move['file']);
		wp_update_attachment_metadata($attach_id, $meta);

		$old_status = (string) get_post_meta($order_id, '_apptook_status', true);
		update_post_meta($order_id, '_apptook_slip_id', $attach_id);
		update_post_meta($order_id, '_apptook_status', Apptook_DS_Post_Types::ORDER_PENDING_REVIEW);

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->upsert_order_from_wp((int) $order_id);
			Apptook_DS_External_DB::instance()->add_order_log((int) $order_id, 'slip_uploaded', $old_status, Apptook_DS_Post_Types::ORDER_PENDING_REVIEW);
		}

		wp_send_json_success(
			array(
				'order_id' => (int) $order_id,
				'message'  => __('อัปโหลดสลิปแล้ว รอแอดมินตรวจสอบ', 'apptook-digital-store'),
			)
		);
	}
}
