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
		add_action('wp_ajax_apptook_ds_mobile_verify_status', array($this, 'mobile_verify_status'));
		add_action('wp_ajax_apptook_ds_mobile_verify_confirm', array($this, 'mobile_verify_confirm'));
		add_action('wp_ajax_apptook_ds_mobile_upload_slip', array($this, 'mobile_upload_slip'));
		add_action('wp_ajax_nopriv_apptook_ds_mobile_upload_slip', array($this, 'mobile_upload_slip'));
		add_action('wp_ajax_nopriv_apptook_ds_login_popup', array($this, 'login_popup'));
		add_action('wp_ajax_apptook_ds_login_popup', array($this, 'login_popup'));
		add_action('wp_ajax_apptook_ds_subscription_statuses', array($this, 'subscription_statuses'));
		add_action('wp_ajax_apptook_ds_coupon_reserve', array($this, 'coupon_reserve'));
		add_action('wp_ajax_apptook_ds_coupon_release', array($this, 'coupon_release'));
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

	private function generate_verify_token(): string {
		return wp_generate_password(48, false, false);
	}

	private function verify_mobile_token(string $token): array {
		if ($token === '' || strlen($token) < 24) {
			return array();
		}
		$data = get_transient('apptook_ds_verify_token_' . $token);
		return is_array($data) ? $data : array();
	}

	private function issue_mobile_verify_token(string $session_token, int $user_id): array {
		$token = $this->generate_verify_token();
		$expire_ts = current_time('timestamp', true) + (45 * MINUTE_IN_SECONDS);
		$expire_at = gmdate('Y-m-d H:i:s', $expire_ts);
		set_transient(
			'apptook_ds_verify_token_' . $token,
			array(
				'session_token' => $session_token,
				'user_id' => $user_id,
			),
			45 * MINUTE_IN_SECONDS
		);
		$url = home_url('/apptook-mobile-verify/' . rawurlencode($token) . '/');
		return array(
			'token' => $token,
			'expire_at' => $expire_at,
			'url' => $url,
		);
	}

	private function create_mobile_session(int $product_id, int $user_id, string $price, string $coupon_code = '', bool $coupon_reserved = false): string {
		$session_token = wp_generate_password(40, false, false);
		set_transient(
			'apptook_ds_mobile_session_' . $session_token,
			array(
				'product_id' => $product_id,
				'user_id' => $user_id,
				'price' => $price,
				'coupon_code' => $coupon_code,
				'coupon_reserved' => $coupon_reserved ? 1 : 0,
				'mobile_slip_attachment_id' => 0,
				'mobile_uploaded_at' => '',
			),
			2 * HOUR_IN_SECONDS
		);
		return $session_token;
	}

	private function get_mobile_session(string $session_token): array {
		$data = get_transient('apptook_ds_mobile_session_' . $session_token);
		return is_array($data) ? $data : array();
	}

	private function set_mobile_session(string $session_token, array $data): void {
		set_transient('apptook_ds_mobile_session_' . $session_token, $data, 2 * HOUR_IN_SECONDS);
	}

	private function slip_preview_url(int $attachment_id): string {
		if ($attachment_id <= 0) {
			return '';
		}
		$url = wp_get_attachment_image_url($attachment_id, 'medium');
		if (! is_string($url) || $url === '') {
			$url = wp_get_attachment_url($attachment_id);
		}
		return is_string($url) ? $url : '';
	}

	private function get_discount_rows(): array {
		$opts = get_option('apptook_ds_options', array());
		$rows = isset($opts['discount_code_rows']) && is_array($opts['discount_code_rows']) ? (array) $opts['discount_code_rows'] : array();
		$out = array();
		foreach ($rows as $row) {
			if (! is_array($row)) {
				continue;
			}
			$code = isset($row['code']) ? strtoupper(sanitize_text_field((string) $row['code'])) : '';
			$amount = isset($row['amount']) ? (float) $row['amount'] : 0;
			$qty = isset($row['qty']) ? max(0, (int) $row['qty']) : 0;
			if ($code === '' || $amount <= 0) {
				continue;
			}
			$out[] = array('code' => $code, 'amount' => $amount, 'qty' => $qty);
		}
		return $out;
	}

	private function get_coupon_discount_amount(string $coupon_code): float {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($coupon_code === '') {
			return 0.0;
		}
		$rows = $this->get_discount_rows();
		foreach ($rows as $row) {
			if ((string) $row['code'] === $coupon_code) {
				return (float) $row['amount'];
			}
		}
		return 0.0;
	}

	private function decrement_coupon_stock(string $coupon_code): bool {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($coupon_code === '') {
			return false;
		}
		$opts = get_option('apptook_ds_options', array());
		$rows = isset($opts['discount_code_rows']) && is_array($opts['discount_code_rows']) ? array_values((array) $opts['discount_code_rows']) : array();
		$changed = false;
		foreach ($rows as $idx => $row) {
			if (! is_array($row)) {
				continue;
			}
			$code = isset($row['code']) ? strtoupper(sanitize_text_field((string) $row['code'])) : '';
			if ($code !== $coupon_code) {
				continue;
			}
			$qty = isset($row['qty']) ? max(0, (int) $row['qty']) : 0;
			if ($qty > 0) {
				$rows[$idx]['qty'] = $qty - 1;
				$changed = true;
			}
			break;
		}
		if ($changed) {
			$opts['discount_code_rows'] = $rows;
			update_option('apptook_ds_options', $opts);
		}
		return $changed;
	}

	private function increment_coupon_stock(string $coupon_code): bool {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($coupon_code === '') {
			return false;
		}
		$opts = get_option('apptook_ds_options', array());
		$rows = isset($opts['discount_code_rows']) && is_array($opts['discount_code_rows']) ? array_values((array) $opts['discount_code_rows']) : array();
		$changed = false;
		foreach ($rows as $idx => $row) {
			if (! is_array($row)) {
				continue;
			}
			$code = isset($row['code']) ? strtoupper(sanitize_text_field((string) $row['code'])) : '';
			if ($code !== $coupon_code) {
				continue;
			}
			$qty = isset($row['qty']) ? max(0, (int) $row['qty']) : 0;
			$rows[$idx]['qty'] = $qty + 1;
			$changed = true;
			break;
		}
		if ($changed) {
			$opts['discount_code_rows'] = $rows;
			update_option('apptook_ds_options', $opts);
		}
		return $changed;
	}

	private function get_coupon_reserve_key(int $user_id, int $product_id, string $coupon_code): string {
		return 'apptook_ds_coupon_reserve_' . md5($user_id . '|' . $product_id . '|' . strtoupper($coupon_code));
	}

	private function reserve_coupon(int $user_id, int $product_id, string $coupon_code): bool {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($user_id <= 0 || $product_id <= 0 || $coupon_code === '') {
			return false;
		}
		$key = $this->get_coupon_reserve_key($user_id, $product_id, $coupon_code);
		$existing = get_transient($key);
		if (is_array($existing)) {
			set_transient($key, $existing, 2 * HOUR_IN_SECONDS);
			return true;
		}
		if (! $this->decrement_coupon_stock($coupon_code)) {
			return false;
		}
		set_transient(
			$key,
			array(
				'user_id' => $user_id,
				'product_id' => $product_id,
				'coupon_code' => $coupon_code,
				'reserved_at' => current_time('mysql', true),
			),
			2 * HOUR_IN_SECONDS
		);
		return true;
	}

	private function has_coupon_reserve(int $user_id, int $product_id, string $coupon_code): bool {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($user_id <= 0 || $product_id <= 0 || $coupon_code === '') {
			return false;
		}
		$key = $this->get_coupon_reserve_key($user_id, $product_id, $coupon_code);
		$reserve = get_transient($key);
		return is_array($reserve);
	}

	private function release_coupon_reserve(int $user_id, int $product_id, string $coupon_code): bool {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($user_id <= 0 || $product_id <= 0 || $coupon_code === '') {
			return false;
		}
		$key = $this->get_coupon_reserve_key($user_id, $product_id, $coupon_code);
		$reserve = get_transient($key);
		if (! is_array($reserve)) {
			return false;
		}
		delete_transient($key);
		return $this->increment_coupon_stock($coupon_code);
	}

	private function consume_coupon_reserve(int $user_id, int $product_id, string $coupon_code): void {
		$coupon_code = strtoupper(trim($coupon_code));
		if ($user_id <= 0 || $product_id <= 0 || $coupon_code === '') {
			return;
		}
		$key = $this->get_coupon_reserve_key($user_id, $product_id, $coupon_code);
		delete_transient($key);
	}

	private function create_order_record(int $product_id, int $customer_id, string $price, string $initial_status = Apptook_DS_Post_Types::ORDER_PENDING_PAYMENT, string $coupon_code = '', bool $coupon_already_reserved = false): int {
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
		update_post_meta($post_id, '_apptook_mobile_slip_attachment_id', 0);
		update_post_meta($post_id, '_apptook_mobile_uploaded_at', '');
		update_post_meta($post_id, '_apptook_customer_confirmed_at', '');
		update_post_meta($post_id, '_apptook_license_key', '');
		if ($coupon_code !== '') {
			update_post_meta($post_id, '_apptook_coupon_code', $coupon_code);
			if ($coupon_already_reserved) {
				$this->consume_coupon_reserve($customer_id, $product_id, $coupon_code);
			} else {
				$this->decrement_coupon_stock($coupon_code);
			}
		}

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->upsert_order_from_wp((int) $post_id);
			Apptook_DS_External_DB::instance()->add_order_log((int) $post_id, 'order_created', '', $initial_status);
		}

		return (int) $post_id;
	}

	public function coupon_reserve(): void {
		check_ajax_referer('apptook_ds_public', 'nonce');
		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('กรุณาเข้าสู่ระบบก่อน', 'apptook-digital-store')), 401);
		}
		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$coupon_code = isset($_POST['coupon_code']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['coupon_code']))) : '';
		if ($product_id <= 0 || $coupon_code === '') {
			wp_send_json_error(array('message' => __('ข้อมูลไม่ถูกต้อง', 'apptook-digital-store')), 400);
		}
		if ($this->get_coupon_discount_amount($coupon_code) <= 0) {
			wp_send_json_error(array('message' => __('โค้ดส่วนลดไม่ถูกต้องหรือหมดจำนวนแล้ว', 'apptook-digital-store')), 400);
		}
		$user_id = get_current_user_id();
		if (! $this->reserve_coupon($user_id, $product_id, $coupon_code)) {
			wp_send_json_error(array('message' => __('โค้ดนี้หมดแล้ว', 'apptook-digital-store')), 400);
		}
		wp_send_json_success(array('reserved' => true));
	}

	public function coupon_release(): void {
		check_ajax_referer('apptook_ds_public', 'nonce');
		if (! is_user_logged_in()) {
			wp_send_json_error(array('message' => __('กรุณาเข้าสู่ระบบก่อน', 'apptook-digital-store')), 401);
		}
		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$coupon_code = isset($_POST['coupon_code']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['coupon_code']))) : '';
		if ($product_id <= 0 || $coupon_code === '') {
			wp_send_json_error(array('message' => __('ข้อมูลไม่ถูกต้อง', 'apptook-digital-store')), 400);
		}
		$this->release_coupon_reserve(get_current_user_id(), $product_id, $coupon_code);
		wp_send_json_success(array('released' => true));
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

		$coupon_code = isset($_POST['coupon_code']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['coupon_code']))) : '';
		$coupon_discount = $this->get_coupon_discount_amount($coupon_code);
		$coupon_reserved = false;
		if ($coupon_code !== '') {
			if ($coupon_discount <= 0) {
				wp_send_json_error(array('message' => __('โค้ดส่วนลดไม่ถูกต้องหรือหมดจำนวนแล้ว', 'apptook-digital-store')), 400);
			}
			$coupon_reserved = $this->has_coupon_reserve(get_current_user_id(), $product_id, $coupon_code);
			if (! $coupon_reserved) {
				$coupon_reserved = $this->reserve_coupon(get_current_user_id(), $product_id, $coupon_code);
			}
			if (! $coupon_reserved) {
				wp_send_json_error(array('message' => __('โค้ดส่วนลดไม่ถูกต้องหรือหมดจำนวนแล้ว', 'apptook-digital-store')), 400);
			}
		}
		$final_price = max(0, ((float) $price) - $coupon_discount);

		$opts = get_option('apptook_ds_options', array());
		$user_id = get_current_user_id();
		$session_token = $this->create_mobile_session($product_id, $user_id, (string) $final_price, $coupon_code, $coupon_reserved);
		$verify = $this->issue_mobile_verify_token($session_token, $user_id);
		$mobile_verify_qr_url = add_query_arg(
			array(
				'size' => '240x240',
				'data' => (string) $verify['url'],
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);

		wp_send_json_success(
			array(
				'order_id'       => 0,
				'mobile_session_token' => $session_token,
				'product_id'     => $product_id,
				'coupon_code'    => $coupon_code,
				'amount'         => (string) $final_price,
				'promptpay_id'   => isset($opts['promptpay_id']) ? (string) $opts['promptpay_id'] : '',
				'qr_image_url'   => isset($opts['qr_image_url']) ? (string) $opts['qr_image_url'] : '',
				'payment_note'   => isset($opts['payment_note']) ? (string) $opts['payment_note'] : '',
				'order_ref'      => 'PENDING-' . $user_id . '-' . $product_id,
				'upload_nonce'   => wp_create_nonce('apptook_ds_upload_product_' . $product_id . '_' . $user_id),
				'mobile_verify_token' => (string) $verify['token'],
				'mobile_verify_expires_at' => (string) $verify['expire_at'],
				'mobile_verify_url' => (string) $verify['url'],
				'mobile_verify_qr_url' => (string) $mobile_verify_qr_url,
				'mobile_status_nonce' => wp_create_nonce('apptook_ds_mobile_status_' . $session_token),
				'mobile_confirm_nonce' => wp_create_nonce('apptook_ds_mobile_confirm_' . $session_token),
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
		$coupon_code = isset($_POST['coupon_code']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['coupon_code']))) : '';

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

			$coupon_discount = $this->get_coupon_discount_amount($coupon_code);
			$coupon_reserved = false;
			if ($coupon_code !== '') {
				if ($coupon_discount <= 0) {
					wp_send_json_error(array('message' => __('โค้ดส่วนลดไม่ถูกต้องหรือหมดจำนวนแล้ว', 'apptook-digital-store')), 400);
				}
				$coupon_reserved = $this->has_coupon_reserve($current_user_id, $product_id, $coupon_code);
				if (! $coupon_reserved) {
					$coupon_reserved = $this->reserve_coupon($current_user_id, $product_id, $coupon_code);
				}
				if (! $coupon_reserved) {
					wp_send_json_error(array('message' => __('โค้ดส่วนลดไม่ถูกต้องหรือหมดจำนวนแล้ว', 'apptook-digital-store')), 400);
				}
			}
			$final_price = max(0, ((float) $price) - $coupon_discount);
			$order_id = $this->create_order_record(
				$product_id,
				$current_user_id,
				(string) $final_price,
				Apptook_DS_Post_Types::ORDER_PENDING_PAYMENT,
				$coupon_code,
				$coupon_reserved
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

	public function mobile_verify_status(): void {
		if (! is_user_logged_in()) {
			wp_send_json_error(array('code' => 'not_logged_in', 'message' => __('กรุณาเข้าสู่ระบบ', 'apptook-digital-store')), 401);
		}
		$session_token = isset($_POST['session_token']) ? sanitize_text_field(wp_unslash((string) $_POST['session_token'])) : '';
		if ($session_token === '') {
			wp_send_json_error(array('code' => 'invalid_session', 'message' => __('ไม่พบรายการ', 'apptook-digital-store')), 400);
		}
		check_ajax_referer('apptook_ds_mobile_status_' . $session_token, 'nonce');
		$session = $this->get_mobile_session($session_token);
		if (empty($session)) {
			wp_send_json_error(array('code' => 'session_expired', 'message' => __('ลิงก์หมดอายุ กรุณาสร้างใหม่', 'apptook-digital-store')), 410);
		}
		if ((int) ($session['user_id'] ?? 0) !== get_current_user_id()) {
			wp_send_json_error(array('code' => 'forbidden', 'message' => __('ไม่มีสิทธิ์เข้าถึงรายการนี้', 'apptook-digital-store')), 403);
		}
		$slip_id = (int) ($session['mobile_slip_attachment_id'] ?? 0);
		$preview_url = $this->slip_preview_url($slip_id);
		$uploaded_at = (string) ($session['mobile_uploaded_at'] ?? '');
		wp_send_json_success(array(
			'code' => 'ok',
			'message' => __('โหลดสถานะสำเร็จ', 'apptook-digital-store'),
			'data' => array(
				'session_token' => $session_token,
				'status' => $slip_id > 0 ? Apptook_DS_Post_Types::ORDER_SLIP_UPLOADED_WAITING_CUSTOMER_CONFIRM : Apptook_DS_Post_Types::ORDER_PENDING_PAYMENT,
				'mobile_uploaded' => $slip_id > 0,
				'preview_url' => $preview_url,
				'mobile_uploaded_at' => $uploaded_at,
			),
		));
	}

	public function mobile_verify_confirm(): void {
		if (! is_user_logged_in()) {
			wp_send_json_error(array('code' => 'not_logged_in', 'message' => __('กรุณาเข้าสู่ระบบ', 'apptook-digital-store')), 401);
		}
		$session_token = isset($_POST['session_token']) ? sanitize_text_field(wp_unslash((string) $_POST['session_token'])) : '';
		if ($session_token === '') {
			wp_send_json_error(array('code' => 'invalid_session', 'message' => __('ไม่พบรายการ', 'apptook-digital-store')), 400);
		}
		check_ajax_referer('apptook_ds_mobile_confirm_' . $session_token, 'nonce');
		$session = $this->get_mobile_session($session_token);
		if (empty($session)) {
			wp_send_json_error(array('code' => 'session_expired', 'message' => __('ลิงก์หมดอายุ กรุณาเริ่มใหม่', 'apptook-digital-store')), 410);
		}
		$user_id = (int) ($session['user_id'] ?? 0);
		$product_id = (int) ($session['product_id'] ?? 0);
		$price = (string) ($session['price'] ?? '');
		$coupon_code = isset($session['coupon_code']) ? strtoupper(sanitize_text_field((string) $session['coupon_code'])) : '';
		$coupon_reserved = ! empty($session['coupon_reserved']);
		$slip_id = (int) ($session['mobile_slip_attachment_id'] ?? 0);
		if ($user_id !== get_current_user_id()) {
			wp_send_json_error(array('code' => 'forbidden', 'message' => __('ไม่มีสิทธิ์เข้าถึงรายการนี้', 'apptook-digital-store')), 403);
		}
		if ($product_id <= 0 || $price === '') {
			wp_send_json_error(array('code' => 'invalid_session_data', 'message' => __('ข้อมูลรายการไม่ครบ', 'apptook-digital-store')), 400);
		}
		if ($slip_id <= 0) {
			wp_send_json_error(array('code' => 'no_slip', 'message' => __('ยังไม่พบสลิปจากมือถือ', 'apptook-digital-store')), 400);
		}

		if ($coupon_code !== '' && $this->get_coupon_discount_amount($coupon_code) <= 0) {
			if ($coupon_reserved) {
				$this->release_coupon_reserve($user_id, $product_id, $coupon_code);
			}
			wp_send_json_error(array('code' => 'coupon_unavailable', 'message' => __('โค้ดส่วนลดไม่ถูกต้องหรือหมดจำนวนแล้ว', 'apptook-digital-store')), 400);
		}
		$order_id = $this->create_order_record(
			$product_id,
			$user_id,
			$price,
			Apptook_DS_Post_Types::ORDER_SLIP_UPLOADED_WAITING_CUSTOMER_CONFIRM,
			$coupon_code,
			$coupon_reserved
		);
		if ($order_id <= 0) {
			wp_send_json_error(array('code' => 'create_order_failed', 'message' => __('ไม่สามารถสร้างคำขอซื้อได้', 'apptook-digital-store')), 500);
		}

		$confirmed_at = current_time('mysql', true);
		$uploaded_at = (string) ($session['mobile_uploaded_at'] ?? '');
		update_post_meta($order_id, '_apptook_mobile_slip_attachment_id', $slip_id);
		update_post_meta($order_id, '_apptook_mobile_uploaded_at', $uploaded_at);
		update_post_meta($order_id, '_apptook_customer_confirmed_at', $confirmed_at);
		update_post_meta($order_id, '_apptook_slip_id', $slip_id);
		update_post_meta($order_id, '_apptook_status', Apptook_DS_Post_Types::ORDER_PENDING_REVIEW);
		delete_transient('apptook_ds_mobile_session_' . $session_token);

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->upsert_order_from_wp((int) $order_id);
			Apptook_DS_External_DB::instance()->add_order_log((int) $order_id, 'customer_confirmed_slip', Apptook_DS_Post_Types::ORDER_SLIP_UPLOADED_WAITING_CUSTOMER_CONFIRM, Apptook_DS_Post_Types::ORDER_PENDING_REVIEW);
		}

		wp_send_json_success(array(
			'code' => 'confirmed',
			'message' => __('ส่งหลักฐานสำเร็จ รอแอดมินตรวจสอบ', 'apptook-digital-store'),
			'data' => array(
				'order_id' => $order_id,
				'status' => Apptook_DS_Post_Types::ORDER_PENDING_REVIEW,
				'customer_confirmed_at' => $confirmed_at,
			),
		));
	}

	public function mobile_upload_slip(): void {
		$token = isset($_POST['verify_token']) ? sanitize_text_field(wp_unslash((string) $_POST['verify_token'])) : '';
		$verify = $this->verify_mobile_token($token);
		$session_token = isset($verify['session_token']) ? (string) $verify['session_token'] : '';
		if ($session_token === '') {
			wp_send_json_error(array('code' => 'invalid_or_expired_token', 'message' => __('ลิงก์อัปโหลดไม่ถูกต้องหรือหมดอายุ', 'apptook-digital-store')), 403);
		}
		$session = $this->get_mobile_session($session_token);
		if (empty($session)) {
			wp_send_json_error(array('code' => 'session_expired', 'message' => __('เซสชันหมดอายุ กรุณาเริ่มใหม่จากคอมพิวเตอร์', 'apptook-digital-store')), 410);
		}
		if (empty($_FILES['slip']['name'])) {
			wp_send_json_error(array('code' => 'missing_file', 'message' => __('กรุณาเลือกรูปสลิป', 'apptook-digital-store')), 400);
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$file = $_FILES['slip'];
		if (! isset($file['size']) || (int) $file['size'] <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
			wp_send_json_error(array('code' => 'file_size_limit', 'message' => __('ขนาดไฟล์ต้องไม่เกิน 5MB', 'apptook-digital-store')), 400);
		}
		$allowed = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);
		$overrides = array('test_form' => false, 'mimes' => $allowed);
		$move = wp_handle_upload($file, $overrides);
		if (isset($move['error'])) {
			wp_send_json_error(array('code' => 'upload_failed', 'message' => sanitize_text_field((string) $move['error'])), 400);
		}
		$real_mime = wp_check_filetype_and_ext($move['file'], basename((string) $move['file']));
		$mime = isset($real_mime['type']) ? (string) $real_mime['type'] : '';
		if (! in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)) {
			@unlink($move['file']);
			wp_send_json_error(array('code' => 'invalid_mime', 'message' => __('ไฟล์ต้องเป็นรูปภาพเท่านั้น', 'apptook-digital-store')), 400);
		}
		$attachment = array(
			'post_mime_type' => $move['type'],
			'post_title'     => sanitize_file_name(pathinfo($move['file'], PATHINFO_FILENAME)),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment($attachment, $move['file'], 0);
		if (is_wp_error($attach_id)) {
			wp_send_json_error(array('code' => 'attachment_failed', 'message' => $attach_id->get_error_message()), 500);
		}
		$meta = wp_generate_attachment_metadata($attach_id, $move['file']);
		wp_update_attachment_metadata($attach_id, $meta);
		$uploaded_at = current_time('mysql', true);
		$session['mobile_slip_attachment_id'] = (int) $attach_id;
		$session['mobile_uploaded_at'] = $uploaded_at;
		$this->set_mobile_session($session_token, $session);
		wp_send_json_success(array(
			'code' => 'uploaded',
			'message' => __('อัปโหลดสำเร็จ กรุณากลับไปที่หน้าคอมพิวเตอร์เพื่อกดยืนยันส่งหลักฐาน', 'apptook-digital-store'),
			'data' => array(
				'session_token' => $session_token,
				'status' => Apptook_DS_Post_Types::ORDER_SLIP_UPLOADED_WAITING_CUSTOMER_CONFIRM,
				'preview_url' => $this->slip_preview_url((int) $attach_id),
				'mobile_uploaded_at' => $uploaded_at,
			),
		));
	}
}
