<?php
/**
 * Front-end: shortcodes, assets, template.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_Public {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode('apptook_shop', array($this, 'shortcode_shop'));
		add_shortcode('apptook_marketplace', array($this, 'shortcode_marketplace'));
		add_shortcode('apptook_library', array($this, 'shortcode_library'));
		add_shortcode('apptook_register', array($this, 'shortcode_register'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
		add_filter('single_template', array($this, 'single_product_template'));
		add_filter('registration_redirect', array($this, 'filter_registration_redirect'), 100, 2);
		add_action('login_init', array($this, 'redirect_registered_checkemail_to_themed_page'), 0);
		add_action('admin_post_nopriv_apptook_ds_register', array($this, 'handle_frontend_register'));
		add_action('admin_post_apptook_ds_register', array($this, 'handle_frontend_register'));
		add_action('admin_post_nopriv_apptook_ds_verify_email', array($this, 'handle_verify_email'));
		add_action('admin_post_apptook_ds_verify_email', array($this, 'handle_verify_email'));
		add_action('admin_post_nopriv_apptook_ds_login', array($this, 'handle_frontend_login'));
		add_action('admin_post_apptook_ds_login', array($this, 'handle_frontend_login'));
		add_action('admin_post_nopriv_apptook_ds_google_login_start', array($this, 'handle_google_login_start'));
		add_action('admin_post_apptook_ds_google_login_start', array($this, 'handle_google_login_start'));
		add_action('admin_post_nopriv_apptook_ds_google_login_callback', array($this, 'handle_google_login_callback'));
		add_action('admin_post_apptook_ds_google_login_callback', array($this, 'handle_google_login_callback'));
		add_action('admin_post_nopriv_apptook_ds_forgot_password', array($this, 'handle_forgot_password'));
		add_action('admin_post_apptook_ds_forgot_password', array($this, 'handle_forgot_password'));
		add_action('admin_post_nopriv_apptook_ds_reset_password', array($this, 'handle_reset_password'));
		add_action('admin_post_apptook_ds_reset_password', array($this, 'handle_reset_password'));
		add_filter('authenticate', array($this, 'block_unverified_customer_login'), 30, 3);
		add_action('after_setup_theme', array($this, 'maybe_hide_admin_bar_for_customers'));
		add_action('admin_init', array($this, 'block_customer_admin_access'));
		add_filter('login_redirect', array($this, 'filter_login_redirect_by_role'), 10, 3);
	}

	/**
	 * หลังสมัครสำเร็จ ส่งผู้ใช้กลับมาที่หน้า shortcode แทน wp-login.php?checkemail=registered
	 *
	 * @param mixed                $registration_redirect Default redirect URL.
	 * @param int|WP_Error         $errors                User ID on success, WP_Error otherwise.
	 */
	public function filter_registration_redirect( $registration_redirect, $errors ) {
		if ( is_wp_error( $errors ) || (int) $errors <= 0 ) {
			return $registration_redirect;
		}

		$target = add_query_arg(
			array(
				'apptook_reg' => 'success',
			),
			$this->get_register_page_url()
		);
		$fallback = is_string( $registration_redirect ) && $registration_redirect !== ''
			? $registration_redirect
			: wp_login_url();

		return wp_validate_redirect( $target, $fallback );
	}

	/**
	 * ผู้ที่ได้ลิงก์เก่า / บุ๊กมาร์กไปหน้า login ข้อความ "registered" ให้เห็นธีมเดียวกับร้าน
	 */
	public function redirect_registered_checkemail_to_themed_page(): void {
		if ( ! isset( $_GET['checkemail'] ) || (string) wp_unslash( $_GET['checkemail'] ) !== 'registered' ) {
			return;
		}

		$target = add_query_arg(
			array(
				'apptook_reg' => 'success',
			),
			$this->get_register_page_url()
		);

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * สมัครจากหน้าร้าน: ตั้งรหัสผ่านทันที (ไม่ผ่าน wp-login register ที่ไม่มีช่องรหัส)
	 */
	public function handle_frontend_register(): void {
		$reg_url = $this->get_register_page_url();

		if ( is_user_logged_in() ) {
			wp_safe_redirect( $this->get_page_url_by_option( 'apptook_ds_page_shop_id' ) );
			exit;
		}

		if ( ! isset( $_POST['apptook_ds_register_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['apptook_ds_register_nonce'] ) ), 'apptook_ds_register' ) ) {
			$this->redirect_register_failure( 'invalid_nonce' );
		}

		if ( ! get_option( 'users_can_register' ) ) {
			$this->redirect_register_failure( 'registration_disabled' );
		}

		$user_login = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( (string) $_POST['user_login'] ), true ) : '';
		$user_email = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['user_email'] ) ) : '';
		$pass1      = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : '';
		$pass2      = isset( $_POST['user_pass_confirm'] ) ? (string) wp_unslash( $_POST['user_pass_confirm'] ) : '';

		if ( $user_login === '' ) {
			$this->redirect_register_failure( 'empty_username' );
		}
		if ( ! validate_username( $user_login ) ) {
			$this->redirect_register_failure( 'invalid_username', $user_login );
		}
		if ( $user_email === '' || ! is_email( $user_email ) ) {
			$this->redirect_register_failure( 'invalid_email', $user_login );
		}
		if ( $pass1 === '' || $pass2 === '' ) {
			$this->redirect_register_failure( 'empty_password', $user_login );
		}
		if ( $pass1 !== $pass2 ) {
			$this->redirect_register_failure( 'password_mismatch', $user_login );
		}

		$min_len = $this->minimum_password_length();
		if ( strlen( $pass1 ) < $min_len ) {
			$this->redirect_register_failure( 'password_too_short', $user_login );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_email'   => $user_email,
				'user_pass'    => $pass1,
				'display_name' => $user_login,
				'nickname'     => $user_login,
				'role'         => 'apptook_customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->redirect_register_failure( (string) $user_id->get_error_code(), $user_login );
		}

		$verify_token = wp_generate_password(32, false, false);
		update_user_meta((int) $user_id, 'apptook_email_verified', '0');
		update_user_meta((int) $user_id, 'apptook_email_verify_token', $verify_token);
		$verify_url = add_query_arg(
			array(
				'action' => 'apptook_ds_verify_email',
				'uid' => (int) $user_id,
				'token' => $verify_token,
			),
			admin_url('admin-post.php')
		);
		wp_mail(
			$user_email,
			__('ยืนยันอีเมลบัญชี Apptook', 'apptook-digital-store'),
			sprintf(
				/* translators: %s verify url */
				__('กรุณายืนยันอีเมลของคุณโดยคลิกลิงก์นี้: %s', 'apptook-digital-store'),
				$verify_url
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'apptook_reg' => 'success',
				),
				$reg_url
			)
		);
		exit;
	}

	private function redirect_register_failure( string $code, string $user_login = '' ): void {
		$args = array( 'apptook_err' => sanitize_key( $code ) );
		if ( $user_login !== '' ) {
			$args['user_login'] = $user_login;
		}
		wp_safe_redirect( add_query_arg( $args, $this->get_register_page_url() ) );
		exit;
	}

	private function minimum_password_length(): int {
		if ( function_exists( 'wp_get_minimum_password_length' ) ) {
			return max( 1, (int) wp_get_minimum_password_length() );
		}

		return max( 8, (int) apply_filters( 'wp_min_password_length', 8 ) );
	}

	public function handle_verify_email(): void {
		$user_id = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
		$token = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';
		if ($user_id <= 0 || $token === '') {
			wp_safe_redirect(add_query_arg('apptook_err', 'invalid_verify_link', $this->get_register_page_url()));
			exit;
		}
		$saved_token = (string) get_user_meta($user_id, 'apptook_email_verify_token', true);
		if ($saved_token === '' || ! hash_equals($saved_token, $token)) {
			wp_safe_redirect(add_query_arg('apptook_err', 'invalid_verify_link', $this->get_register_page_url()));
			exit;
		}
		update_user_meta($user_id, 'apptook_email_verified', '1');
		delete_user_meta($user_id, 'apptook_email_verify_token');
		wp_safe_redirect(add_query_arg('apptook_reg', 'verified', $this->get_register_page_url()));
		exit;
	}

	public function block_unverified_customer_login($user, $username, $password) {
		if ($user instanceof WP_User) {
			if (in_array('apptook_customer', (array) $user->roles, true)) {
				$verified = (string) get_user_meta((int) $user->ID, 'apptook_email_verified', true);
				if ($verified !== '1') {
					return new WP_Error('apptook_email_not_verified', __('กรุณายืนยันอีเมลก่อนเข้าสู่ระบบ', 'apptook-digital-store'));
				}
			}
		}
		return $user;
	}

	public function handle_frontend_login(): void {
		$shop_url = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['redirect_to'] ) ) : '';
		$target   = $redirect !== '' ? $redirect : $shop_url;

		if (! isset($_POST['apptook_ds_login_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['apptook_ds_login_nonce'])), 'apptook_ds_login')) {
			wp_safe_redirect(add_query_arg(array('apptook_open_login' => '1', 'apptook_err' => 'invalid_nonce'), $target));
			exit;
		}

		$creds = array(
			'user_login' => isset($_POST['log']) ? sanitize_text_field(wp_unslash((string) $_POST['log'])) : '',
			'user_password' => isset($_POST['pwd']) ? (string) wp_unslash($_POST['pwd']) : '',
			'remember' => ! empty($_POST['rememberme']),
		);
		$user = wp_signon($creds, is_ssl());
		if (is_wp_error($user)) {
			$code = (string) $user->get_error_code();
			if ($code !== 'apptook_email_not_verified') {
				$code = 'login_failed';
			}
			wp_safe_redirect(add_query_arg(array('apptook_open_login' => '1', 'apptook_err' => sanitize_key($code)), $target));
			exit;
		}
		wp_safe_redirect($shop_url);
		exit;
	}

	public function handle_google_login_start(): void {
		$opts          = get_option( 'apptook_ds_options', array() );
		$client_id     = isset( $opts['google_client_id'] ) ? trim( (string) $opts['google_client_id'] ) : '';
		$client_secret = isset( $opts['google_client_secret'] ) ? trim( (string) $opts['google_client_secret'] ) : '';
		$shop_url      = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$target        = $shop_url;

		if ( $client_id === '' || $client_secret === '' ) {
			wp_safe_redirect( add_query_arg( array( 'apptook_open_login' => '1', 'apptook_err' => 'google_not_configured' ), $target ) );
			exit;
		}

		$state = wp_generate_password( 20, false, false );
		set_transient( 'apptook_ds_google_state_' . $state, '1', 10 * MINUTE_IN_SECONDS );

		$callback_url = add_query_arg(
			array( 'action' => 'apptook_ds_google_login_callback' ),
			admin_url( 'admin-post.php' )
		);

		$auth_url = add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $callback_url,
				'response_type' => 'code',
				'scope'         => 'openid email profile',
				'state'         => $state,
				'prompt'        => 'select_account',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);

		wp_safe_redirect( $auth_url );
		exit;
	}

	public function handle_google_login_callback(): void {
		$shop_url      = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code          = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$state_key     = 'apptook_ds_google_state_' . $state;
		$state_valid   = $state !== '' && get_transient( $state_key ) === '1';
		$redirect_fail = add_query_arg( array( 'apptook_open_login' => '1', 'apptook_err' => 'google_login_failed' ), $shop_url );

		if ( ! $state_valid || $code === '' ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		delete_transient( $state_key );

		$opts          = get_option( 'apptook_ds_options', array() );
		$client_id     = isset( $opts['google_client_id'] ) ? trim( (string) $opts['google_client_id'] ) : '';
		$client_secret = isset( $opts['google_client_secret'] ) ? trim( (string) $opts['google_client_secret'] ) : '';
		if ( $client_id === '' || $client_secret === '' ) {
			wp_safe_redirect( add_query_arg( array( 'apptook_open_login' => '1', 'apptook_err' => 'google_not_configured' ), $shop_url ) );
			exit;
		}

		$callback_url = add_query_arg(
			array( 'action' => 'apptook_ds_google_login_callback' ),
			admin_url( 'admin-post.php' )
		);

		$token_response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $callback_url,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $token_response ) ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		$token_body = json_decode( (string) wp_remote_retrieve_body( $token_response ), true );
		if ( ! is_array( $token_body ) || empty( $token_body['access_token'] ) ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		$user_info_response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v2/userinfo',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . sanitize_text_field( (string) $token_body['access_token'] ),
				),
			)
		);

		if ( is_wp_error( $user_info_response ) ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		$user_info = json_decode( (string) wp_remote_retrieve_body( $user_info_response ), true );
		$email     = is_array( $user_info ) && isset( $user_info['email'] ) ? sanitize_email( (string) $user_info['email'] ) : '';
		$name      = is_array( $user_info ) && isset( $user_info['name'] ) ? sanitize_text_field( (string) $user_info['name'] ) : '';

		if ( $email === '' || ! is_email( $email ) ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof WP_User ) {
			$base_login = sanitize_user( current( explode( '@', $email ) ), true );
			if ( $base_login === '' ) {
				$base_login = 'apptook_user';
			}
			$user_login = $base_login;
			$index      = 1;
			while ( username_exists( $user_login ) ) {
				$user_login = $base_login . '_' . $index;
				$index++;
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $user_login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'display_name' => $name !== '' ? $name : $user_login,
					'role'         => 'apptook_customer',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				wp_safe_redirect( $redirect_fail );
				exit;
			}
			$user = get_user_by( 'id', (int) $user_id );
		}

		if ( ! $user instanceof WP_User ) {
			wp_safe_redirect( $redirect_fail );
			exit;
		}

		update_user_meta( (int) $user->ID, 'apptook_email_verified', '1' );
		wp_set_auth_cookie( (int) $user->ID, true );
		wp_set_current_user( (int) $user->ID );

		wp_safe_redirect( $shop_url );
		exit;
	}

	public function handle_forgot_password(): void {
		$reg_url = $this->get_register_page_url();
		if (! isset($_POST['apptook_ds_forgot_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['apptook_ds_forgot_nonce'])), 'apptook_ds_forgot_password')) {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'forgot', 'apptook_err' => 'invalid_nonce'), $reg_url));
			exit;
		}
		$login = isset($_POST['user_login']) ? sanitize_text_field(wp_unslash((string) $_POST['user_login'])) : '';
		if ($login === '') {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'forgot', 'apptook_err' => 'empty_username'), $reg_url));
			exit;
		}
		$user_data = get_user_by('email', $login);
		if (! $user_data) {
			$user_data = get_user_by('login', $login);
		}
		if (! $user_data instanceof WP_User) {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'forgot', 'apptook_err' => 'invalidcombo'), $reg_url));
			exit;
		}
		$key = get_password_reset_key($user_data);
		if (is_wp_error($key)) {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'forgot', 'apptook_err' => 'reset_failed'), $reg_url));
			exit;
		}
		$reset_url = add_query_arg(
			array(
				'apptook_view' => 'reset',
				'key' => $key,
				'login' => rawurlencode($user_data->user_login),
			),
			$reg_url
		);
		wp_mail(
			$user_data->user_email,
			__('ตั้งรหัสผ่านใหม่ - Apptook', 'apptook-digital-store'),
			sprintf(__('คลิกลิงก์นี้เพื่อตั้งรหัสผ่านใหม่: %s', 'apptook-digital-store'), $reset_url)
		);
		wp_safe_redirect(add_query_arg(array('apptook_view' => 'login', 'apptook_reg' => 'reset_sent'), $reg_url));
		exit;
	}

	public function handle_reset_password(): void {
		$reg_url = $this->get_register_page_url();
		if (! isset($_POST['apptook_ds_reset_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['apptook_ds_reset_nonce'])), 'apptook_ds_reset_password')) {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'reset', 'apptook_err' => 'invalid_nonce'), $reg_url));
			exit;
		}
		$login = isset($_POST['login']) ? sanitize_user(wp_unslash((string) $_POST['login'])) : '';
		$key = isset($_POST['key']) ? sanitize_text_field(wp_unslash((string) $_POST['key'])) : '';
		$pass1 = isset($_POST['user_pass']) ? (string) wp_unslash($_POST['user_pass']) : '';
		$pass2 = isset($_POST['user_pass_confirm']) ? (string) wp_unslash($_POST['user_pass_confirm']) : '';
		if ($pass1 === '' || $pass1 !== $pass2) {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'reset', 'login' => rawurlencode($login), 'key' => rawurlencode($key), 'apptook_err' => 'password_mismatch'), $reg_url));
			exit;
		}
		$user = check_password_reset_key($key, $login);
		if (! $user instanceof WP_User) {
			wp_safe_redirect(add_query_arg(array('apptook_view' => 'forgot', 'apptook_err' => 'invalidkey'), $reg_url));
			exit;
		}
		reset_password($user, $pass1);
		wp_safe_redirect(add_query_arg(array('apptook_view' => 'login', 'apptook_reg' => 'reset_done'), $reg_url));
		exit;
	}

	public function maybe_hide_admin_bar_for_customers(): void {
		if (is_user_logged_in() && ! current_user_can('manage_options')) {
			show_admin_bar(false);
		}
	}

	public function block_customer_admin_access(): void {
		if (! is_admin() || wp_doing_ajax()) {
			return;
		}
		if (! is_user_logged_in()) {
			return;
		}
		if (current_user_can('manage_options')) {
			return;
		}
		wp_safe_redirect($this->get_page_url_by_option('apptook_ds_page_shop_id'));
		exit;
	}

	/**
	 * @param mixed $user
	 * @param mixed $requested_redirect_to
	 */
	public function filter_login_redirect_by_role(string $redirect_to, $requested_redirect_to, $user): string {
		if ($user instanceof WP_User && ! user_can($user, 'manage_options')) {
			return $this->get_page_url_by_option('apptook_ds_page_shop_id');
		}
		return $redirect_to;
	}

	/**
	 * ข้อความแสดงเมื่อสมัครไม่สำเร็จ (รหัสจาก wp_insert_user หรือของเรา)
	 */
	private function register_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
				return __( 'เซสชันหมดอายุหรือข้อมูลไม่ถูกต้อง กรุณาลองใหม่', 'apptook-digital-store' );
			case 'registration_disabled':
				return __( 'ขณะนี้ปิดรับสมัครสมาชิก', 'apptook-digital-store' );
			case 'empty_username':
				return __( 'กรุณากรอกชื่อผู้ใช้', 'apptook-digital-store' );
			case 'invalid_username':
				return __( 'ชื่อผู้ใช้ใช้ไม่ได้หรือมีอักขระที่ไม่อนุญาต', 'apptook-digital-store' );
			case 'invalid_email':
				return __( 'กรุณากรอกอีเมลให้ถูกต้อง', 'apptook-digital-store' );
			case 'empty_password':
				return __( 'กรุณากรอกรหัสผ่านและยืนยันรหัสผ่าน', 'apptook-digital-store' );
			case 'password_mismatch':
				return __( 'รหัสผ่านและยืนยันไม่ตรงกัน', 'apptook-digital-store' );
			case 'password_too_short':
				return sprintf(
					/* translators: %d: minimum length */
					__( 'รหัสผ่านสั้นเกินไป (อย่างน้อย %d ตัวอักษร)', 'apptook-digital-store' ),
					$this->minimum_password_length()
				);
			case 'existing_user_login':
				return __( 'ชื่อผู้ใช้นี้มีในระบบแล้ว', 'apptook-digital-store' );
			case 'existing_user_email':
				return __( 'อีเมลนี้ถูกใช้สมัครแล้ว', 'apptook-digital-store' );
			case 'user_login_too_long':
				return __( 'ชื่อผู้ใช้ยาวเกินไป', 'apptook-digital-store' );
			case 'weak_password':
				return __( 'รหัสผ่านอ่อนเกินไป ลองใช้ตัวอักษรผสมยาวขึ้น', 'apptook-digital-store' );
			case 'login_failed':
				return __( 'เข้าสู่ระบบไม่สำเร็จ กรุณาตรวจสอบชื่อผู้ใช้/อีเมล และรหัสผ่าน', 'apptook-digital-store' );
			case 'google_not_configured':
				return __( 'Google Login ยังไม่ถูกตั้งค่า (Client ID / Secret)', 'apptook-digital-store' );
			case 'google_login_failed':
				return __( 'ไม่สามารถเข้าสู่ระบบด้วย Google ได้ กรุณาลองใหม่', 'apptook-digital-store' );
			case 'apptook_email_not_verified':
				return __( 'กรุณายืนยันอีเมลก่อนเข้าสู่ระบบ', 'apptook-digital-store' );
			case 'invalidcombo':
				return __( 'ไม่พบบัญชีผู้ใช้นี้ในระบบ', 'apptook-digital-store' );
			case 'reset_failed':
				return __( 'ไม่สามารถส่งลิงก์รีเซ็ตรหัสผ่านได้ กรุณาลองใหม่', 'apptook-digital-store' );
			case 'invalidkey':
			case 'invalid_verify_link':
				return __( 'ลิงก์ไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่', 'apptook-digital-store' );
			default:
				return __( 'ไม่สามารถดำเนินการได้ กรุณาตรวจสอบข้อมูลหรือลองใหม่', 'apptook-digital-store' );
		}
	}

	public function single_product_template( $template ) {
		if (is_singular('apptook_product')) {
			$plugin_template = APPTOOK_DS_PATH . 'templates/single-apptook_product.php';
			if (is_readable($plugin_template)) {
				return $plugin_template;
			}
		}
		return is_string( $template ) ? $template : '';
	}

	private function should_load_marketplace_assets(): bool {
		global $post;
		if (! $post instanceof WP_Post) {
			return false;
		}
		if (has_shortcode($post->post_content, 'apptook_marketplace')) {
			return true;
		}
		if (has_shortcode($post->post_content, 'apptook_shop') && $this->current_shop_layout() === 'marketplace') {
			return true;
		}
		return false;
	}

	/**
	 * อ่าน layout จาก shortcode ในหน้า
	 */
	private function current_shop_layout(): string {
		global $post;
		if (! $post instanceof WP_Post) {
			return 'marketplace';
		}
		if (has_shortcode($post->post_content, 'apptook_marketplace')) {
			return 'marketplace';
		}
		if (! has_shortcode($post->post_content, 'apptook_shop')) {
			return 'marketplace';
		}
		if (preg_match_all('/\[apptook_shop\b([^\]]*)\]/', $post->post_content, $matches)) {
			foreach ($matches[1] as $raw) {
				$parsed = shortcode_parse_atts(trim((string) $raw));
				if (is_array($parsed) && isset($parsed['layout']) && strtolower((string) $parsed['layout']) === 'simple') {
					return 'simple';
				}
			}
		}
		return 'marketplace';
	}

	private function should_load_assets(): bool {
		if (is_singular('apptook_product')) {
			return true;
		}
		global $post;
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_library')) {
			return true;
		}
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_register')) {
			return true;
		}
		if ($this->should_load_marketplace_assets()) {
			return true;
		}
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_shop') && $this->current_shop_layout() === 'simple') {
			return true;
		}
		return false;
	}

	public function enqueue_assets(): void {
		if (! $this->should_load_assets()) {
			return;
		}

		$load_mkt = $this->should_load_marketplace_assets();
		global $post;
		$load_reg_skin = $post instanceof WP_Post && has_shortcode( $post->post_content, 'apptook_register' );

		if ( $load_mkt || $load_reg_skin ) {
			wp_enqueue_style(
				'apptook-ds-material',
				'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap',
				array(),
				null
			);
			wp_enqueue_style(
				'apptook-ds-marketplace',
				APPTOOK_DS_URL . 'assets/css/marketplace.css',
				array(),
				APPTOOK_DS_VERSION
			);
		}

		wp_enqueue_style(
			'apptook-ds-frontend',
			APPTOOK_DS_URL . 'assets/css/frontend.css',
			array(),
			APPTOOK_DS_VERSION
		);

		wp_enqueue_script(
			'apptook-ds-frontend',
			APPTOOK_DS_URL . 'assets/js/frontend.js',
			array(),
			APPTOOK_DS_VERSION,
			true
		);

		if ($load_mkt) {
			wp_enqueue_script(
				'apptook-ds-marketplace',
				APPTOOK_DS_URL . 'assets/js/marketplace.js',
				array(),
				APPTOOK_DS_VERSION,
				true
			);
		}

		wp_localize_script(
			'apptook-ds-frontend',
			'apptookDS',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('apptook_ds_public'),
				'i18n'    => array(
					'loginRequired' => __('กรุณาเข้าสู่ระบบก่อนซื้อ', 'apptook-digital-store'),
					'uploading'     => __('กำลังอัปโหลด...', 'apptook-digital-store'),
					'error'         => __('เกิดข้อผิดพลาด ลองใหม่อีกครั้ง', 'apptook-digital-store'),
					'next'          => __('ดำเนินการต่อ — อัปโหลดสลิป', 'apptook-digital-store'),
					'payTitle'      => __('ชำระด้วยพร้อมเพย์', 'apptook-digital-store'),
					'slipTitle'     => __('อัปโหลดสลิปการโอน', 'apptook-digital-store'),
					'confirmSlip'   => __('ส่งสลิป', 'apptook-digital-store'),
					'close'         => __('ปิด', 'apptook-digital-store'),
				),
			)
		);
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function shortcode_shop( $atts = array(), ?string $content = null ): string {
		$atts = shortcode_atts(
			array(
				'layout'   => 'marketplace',
				'title'    => '',
				'subtitle' => '',
				'nav'      => '1',
				'footer'   => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'apptook_shop'
		);

		if ( $atts['layout'] === 'simple' ) {
			return $this->render_shop_simple();
		}

		return $this->render_marketplace( $atts );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function shortcode_marketplace( $atts = array(), ?string $content = null ): string {
		$atts = shortcode_atts(
			array(
				'title'    => '',
				'subtitle' => '',
				'nav'      => '1',
				'footer'   => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'apptook_marketplace'
		);

		$atts['layout'] = 'marketplace';

		return $this->render_marketplace( $atts );
	}

	/**
	 * @param array<string, string> $atts
	 */
	private function render_marketplace( array $atts ): string {
		$opts = get_option( 'apptook_ds_options', array() );

		$show_nav    = ! in_array( strtolower( (string) ( $atts['nav'] ?? '1' ) ), array( '0', 'false', 'no' ), true );
		$show_footer = ! in_array( strtolower( (string) ( $atts['footer'] ?? '1' ) ), array( '0', 'false', 'no' ), true );

		$title = $atts['title'] !== ''
			? $atts['title']
			: ( isset( $opts['mkt_title'] ) && $opts['mkt_title'] !== ''
				? (string) $opts['mkt_title']
				: __( 'APPTOOK ทำให้แอปพรีเมียมเข้าถึงได้ง่ายขึ้น', 'apptook-digital-store' ) );

		$subtitle = $atts['subtitle'] !== ''
			? $atts['subtitle']
			: ( isset( $opts['mkt_subtitle'] ) ? (string) $opts['mkt_subtitle'] : '' );

		$placeholder = isset( $opts['mkt_search_placeholder'] ) && $opts['mkt_search_placeholder'] !== ''
			? (string) $opts['mkt_search_placeholder']
			: __( 'ค้นหาบริการพรีเมียม เช่น ChatGPT, Netflix, Canva...', 'apptook-digital-store' );

		$shop_url     = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$library_url  = $this->get_page_url_by_option( 'apptook_ds_page_library_id' );
		$register_url = $this->get_register_page_url();
		$archive_url  = get_post_type_archive_link( 'apptook_product' );
		if ( ! is_string( $archive_url ) || $archive_url === '' ) {
			$archive_url = $shop_url;
		}

		$current_user = wp_get_current_user();
		$q            = new WP_Query(
			array(
				'post_type'      => 'apptook_product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$terms = get_terms(
			array(
				'taxonomy'   => 'apptook_product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		ob_start();
		?>
		<div class="apptook-stitch apptook-ds">
			<?php if ( $show_nav ) : ?>
			<nav class="st-nav" aria-label="<?php esc_attr_e( 'เมนูหลัก', 'apptook-digital-store' ); ?>">
				<div class="st-nav__inner">
					<a class="st-nav__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">APPTOOK</a>
					<div class="st-nav__links">
						<a class="is-active" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Marketplace', 'apptook-digital-store' ); ?></a>
						<a href="<?php echo esc_url( $shop_url ); ?>#apptook-st-cats"><?php esc_html_e( 'Categories', 'apptook-digital-store' ); ?></a>
						<a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'Deals', 'apptook-digital-store' ); ?></a>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Support', 'apptook-digital-store' ); ?></a>
					</div>
					<div class="st-nav__right">
						<div class="st-nav__icons">
							<a href="<?php echo esc_url( $library_url ); ?>" class="st-nav__icon-btn" aria-label="<?php esc_attr_e( 'คลัง / ตะกร้า', 'apptook-digital-store' ); ?>">
								<span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
							</a>
							<div class="st-nav__profile-wrap">
								<button type="button" class="st-nav__icon-btn st-nav__profile-trigger" aria-expanded="false" aria-haspopup="true" aria-label="<?php esc_attr_e( 'บัญชี', 'apptook-digital-store' ); ?>">
									<span class="material-symbols-outlined" aria-hidden="true">account_circle</span>
								</button>
								<div class="st-nav__dropdown" id="apptook-st-profile-dropdown">
									<div class="st-nav__dropdown-head">
										<p><?php esc_html_e( 'Signed in as', 'apptook-digital-store' ); ?></p>
										<strong><?php echo esc_html( is_user_logged_in() ? $current_user->display_name : __( 'Guest', 'apptook-digital-store' ) ); ?></strong>
									</div>
									<a href="<?php echo esc_url( $library_url ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true">subscriptions</span>
										<?php esc_html_e( 'My Subscription', 'apptook-digital-store' ); ?>
									</a>
									<a href="<?php echo esc_url( $library_url ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true">history</span>
										<?php esc_html_e( 'Order History', 'apptook-digital-store' ); ?>
									</a>
									<?php if ( ! is_user_logged_in() ) : ?>
									<button type="button" class="st-nav__dropdown-item-btn st-nav__open-login">
										<span class="material-symbols-outlined" aria-hidden="true">login</span>
										<?php esc_html_e( 'Log In', 'apptook-digital-store' ); ?>
									</button>
									<?php endif; ?>
									<?php if ( is_user_logged_in() ) : ?>
									<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true">settings</span>
										<?php esc_html_e( 'Account Settings', 'apptook-digital-store' ); ?>
									</a>
									<div class="st-nav__dropdown-divider"></div>
									<a class="st-nav__logout" href="<?php echo esc_url( wp_logout_url( $shop_url ) ); ?>" title="<?php esc_attr_e( 'ออกจากบัญชี WordPress ทั้งหมด รวมถึงหน้าผู้ดูแลระบบ (wp-admin)', 'apptook-digital-store' ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true">logout</span>
										<?php esc_html_e( 'Logout', 'apptook-digital-store' ); ?>
									</a>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php if ( ! is_user_logged_in() ) : ?>
						<div class="st-nav__auth" id="apptook-st-auth-buttons">
							<button type="button" class="st-nav__btn-ghost st-nav__open-login"><?php esc_html_e( 'Log In', 'apptook-digital-store' ); ?></button>
							<?php if ( get_option( 'users_can_register' ) ) : ?>
							<a class="st-nav__btn-primary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Sign Up', 'apptook-digital-store' ); ?></a>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</div>
				</div>
			</nav>
			<?php endif; ?>

			<main class="st-main">
				<section class="st-hero">
					<h1 class="st-hero__title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( $subtitle !== '' ) : ?>
						<p class="st-hero__sub"><?php echo esc_html( $subtitle ); ?></p>
					<?php endif; ?>
					<div class="st-search-wrap">
						<div class="st-search-pill">
							<span class="material-symbols-outlined" aria-hidden="true">search</span>
							<input type="search" class="st-search-input" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" data-apptook-search />
							<button type="button" class="st-search-btn" data-apptook-search-btn><?php esc_html_e( 'Search', 'apptook-digital-store' ); ?></button>
						</div>
					</div>
					<div class="st-sort-row">
						<label class="st-sort-label" for="apptook-st-sort"><?php esc_html_e( 'เรียงตาม', 'apptook-digital-store' ); ?></label>
						<select id="apptook-st-sort" class="st-sort-select" data-apptook-sort>
							<option value="date_desc"><?php esc_html_e( 'ใหม่ล่าสุด', 'apptook-digital-store' ); ?></option>
							<option value="date_asc"><?php esc_html_e( 'เก่าสุด', 'apptook-digital-store' ); ?></option>
							<option value="price_asc"><?php esc_html_e( 'ราคา: ต่ำ → สูง', 'apptook-digital-store' ); ?></option>
							<option value="price_desc"><?php esc_html_e( 'ราคา: สูง → ต่ำ', 'apptook-digital-store' ); ?></option>
							<option value="title_asc"><?php esc_html_e( 'ชื่อ A–Z', 'apptook-digital-store' ); ?></option>
							<option value="title_desc"><?php esc_html_e( 'ชื่อ Z–A', 'apptook-digital-store' ); ?></option>
						</select>
					</div>
				</section>

				<section class="st-cats" id="apptook-st-cats" aria-label="<?php esc_attr_e( 'หมวดสินค้า', 'apptook-digital-store' ); ?>">
					<div class="st-cats__inner">
						<div class="st-cats__tabs" id="category-tabs" data-apptook-tabs>
							<button type="button" class="category-btn category-btn--row is-active" data-category="all">
								<span class="material-symbols-outlined ms-fill" aria-hidden="true">grid_view</span>
								<span><?php esc_html_e( 'All Items', 'apptook-digital-store' ); ?></span>
							</button>
							<?php
							foreach ( $terms as $term ) {
								if ( ! $term instanceof WP_Term ) {
									continue;
								}
								$icon = get_term_meta( $term->term_id, 'apptook_icon', true );
								$icon = is_string( $icon ) && $icon !== ''
									? $icon
									: Apptook_DS_Post_Types::default_category_icon( $term->slug );
								?>
								<button type="button" class="category-btn category-btn--stack is-inactive" data-category="<?php echo esc_attr( $term->slug ); ?>">
									<span class="material-symbols-outlined" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
									<span class="st-cat-label"><?php echo esc_html( $term->name ); ?></span>
								</button>
								<?php
							}
							?>
						</div>
					</div>
				</section>

				<section class="st-grid-section">
					<div class="st-product-grid" id="product-grid" data-apptook-grid>
						<?php
						if ( ! $q->have_posts() ) {
							echo '<p class="apptook-ds-empty" style="grid-column:1/-1;text-align:center;padding:2rem;">' . esc_html__( 'ยังไม่มีสินค้า', 'apptook-digital-store' ) . '</p>';
						} else {
							while ( $q->have_posts() ) {
								$q->the_post();
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->render_product_card( (int) get_the_ID() );
							}
							wp_reset_postdata();
						}
						?>
					</div>
					<p class="st-empty-grid" data-apptook-empty><?php esc_html_e( 'ไม่พบสินค้าที่ตรงกับการค้นหาหรือหมวดนี้', 'apptook-digital-store' ); ?></p>
					<div class="st-show-more-wrap" id="show-more-container" data-apptook-show-more>
						<a class="st-show-more-btn" href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'ดูทั้งหมด', 'apptook-digital-store' ); ?></a>
					</div>
				</section>
			</main>

			<?php if ( $show_footer ) : ?>
			<footer class="st-footer">
				<div class="st-footer__inner">
					<div class="st-footer__brand">
						<a class="st-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">APPTOOK</a>
						<p class="st-footer__copy">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: year */
									__( '© %d APPTOOK Marketplace. Premium apps made easy.', 'apptook-digital-store' ),
									(int) gmdate( 'Y' )
								)
							);
							?>
						</p>
					</div>
					<div class="st-footer__links">
						<a href="#"><?php esc_html_e( 'Terms of Service', 'apptook-digital-store' ); ?></a>
						<a href="#"><?php esc_html_e( 'Privacy Policy', 'apptook-digital-store' ); ?></a>
						<a href="#"><?php esc_html_e( 'Contact Us', 'apptook-digital-store' ); ?></a>
						<a href="#"><?php esc_html_e( 'Help Center', 'apptook-digital-store' ); ?></a>
					</div>
					<div class="st-footer__social">
						<a href="#" aria-label="Share"><span class="material-symbols-outlined">share</span></a>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'เว็บไซต์', 'apptook-digital-store' ); ?>"><span class="material-symbols-outlined">language</span></a>
					</div>
				</div>
			</footer>
			<?php endif; ?>

			<?php
			if ( $show_nav && ! is_user_logged_in() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_login_modal( $shop_url, $register_url );
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * โมดัลล็อกอินแบบ stitch — ส่งฟอร์มไป wp-login.php
	 */
	private function render_login_modal( string $redirect_url, string $register_url ): string {
		$login_action = admin_url( 'admin-post.php' );
		$err_code     = isset( $_GET['apptook_err'] ) ? sanitize_key( wp_unslash( (string) $_GET['apptook_err'] ) ) : '';
		$err_msg      = $err_code !== '' ? $this->register_error_message_for_code( $err_code ) : '';
		$should_open  = isset( $_GET['apptook_open_login'] ) && (string) wp_unslash( $_GET['apptook_open_login'] ) === '1';
		ob_start();
		?>
		<div class="apptook-st-modal-overlay<?php echo $should_open ? ' is-open' : ''; ?>" id="apptook-st-login-modal" role="dialog" aria-modal="true" aria-labelledby="apptook-st-login-title" aria-hidden="<?php echo $should_open ? 'false' : 'true'; ?>">
			<div class="apptook-st-modal">
				<button type="button" class="apptook-st-modal-close apptook-st-close-modal" aria-label="<?php esc_attr_e( 'ปิด', 'apptook-digital-store' ); ?>">
					<span class="material-symbols-outlined" aria-hidden="true">close</span>
				</button>
				<div class="apptook-st-modal-body">
					<h2 id="apptook-st-login-title" class="apptook-st-modal-title"><?php esc_html_e( 'Login', 'apptook-digital-store' ); ?></h2>
					<p class="apptook-st-modal-lead"><?php esc_html_e( 'เข้าสู่ระบบเพื่อใช้งานบัญชีของคุณ', 'apptook-digital-store' ); ?></p>
					<?php if ( $err_msg !== '' ) : ?>
						<div class="apptook-st-login-alert" role="alert" aria-live="assertive" style="color:#dc2626 !important;border-color:#ef4444 !important;"><?php echo esc_html( $err_msg ); ?></div>
					<?php endif; ?>
					<form class="apptook-st-modal-form" method="post" action="<?php echo esc_url( $login_action ); ?>">
						<input type="hidden" name="action" value="apptook_ds_login" />
						<?php wp_nonce_field( 'apptook_ds_login', 'apptook_ds_login_nonce' ); ?>
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_url ); ?>" />
						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_user_login"><?php esc_html_e( 'Username or Email Address', 'apptook-digital-store' ); ?></label>
							<input class="apptook-st-input" type="text" name="log" id="apptook_st_user_login" autocomplete="username" required placeholder="<?php esc_attr_e( 'Enter username or email', 'apptook-digital-store' ); ?>" />
						</div>
						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_user_pass"><?php esc_html_e( 'Password', 'apptook-digital-store' ); ?></label>
							<div class="apptook-st-password-wrap is-empty" style="position:relative;display:block;">
								<input class="apptook-st-input apptook-st-input-password" type="password" name="pwd" id="apptook_st_user_pass" autocomplete="current-password" required placeholder="<?php esc_attr_e( 'Enter password', 'apptook-digital-store' ); ?>" style="padding-right:3rem;" oninput="(function(input){var btn=document.getElementById('apptook_st_user_pass_toggle'); if(!btn){return;} var has=(input.value||'').length>0; btn.style.display=has?'inline-flex':'none'; btn.style.pointerEvents=has?'auto':'none'; btn.style.opacity=has?'1':'0'; btn.setAttribute('aria-hidden',has?'false':'true'); if(!has){input.type='password'; var ic=btn.querySelector('.material-symbols-outlined'); if(ic){ic.textContent='visibility';} btn.setAttribute('aria-label','แสดงรหัสผ่าน');}})(this);" />
								<button type="button" id="apptook_st_user_pass_toggle" class="apptook-st-password-toggle" data-apptook-toggle-password="apptook_st_user_pass" aria-controls="apptook_st_user_pass" aria-label="<?php esc_attr_e( 'แสดงรหัสผ่าน', 'apptook-digital-store' ); ?>" aria-hidden="true" onclick="(function(btn){var input=document.getElementById('apptook_st_user_pass'); if(!input||!(input.value||'').length){return false;} var ic=btn.querySelector('.material-symbols-outlined'); var isPw=input.type==='password'; input.type=isPw?'text':'password'; if(ic){ic.textContent=isPw?'visibility_off':'visibility';} btn.setAttribute('aria-label',isPw?'ซ่อนรหัสผ่าน':'แสดงรหัสผ่าน'); input.focus(); return false;})(this); return false;" style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);z-index:6;width:2rem;height:2rem;display:none;align-items:center;justify-content:center;margin:0;padding:0;border:0;border-radius:.5rem;background:transparent;color:#64748b;box-shadow:none;min-height:0;line-height:1;pointer-events:none;opacity:0;">
									<span class="material-symbols-outlined" aria-hidden="true">visibility</span>
								</button>
							</div>
						</div>
						<label class="apptook-st-remember">
							<input type="checkbox" name="rememberme" value="forever" />
							<span><?php esc_html_e( 'Remember Me', 'apptook-digital-store' ); ?></span>
						</label>
						<input type="submit" name="wp-submit" class="apptook-st-submit" value="<?php echo esc_attr( __( 'Log In', 'apptook-digital-store' ) ); ?>" />
					</form>
					<div class="apptook-st-login-divider"><span><?php esc_html_e( 'หรือเข้าสู่ระบบด้วย', 'apptook-digital-store' ); ?></span></div>
					<div class="apptook-st-social-auth">
						<a class="apptook-st-google-btn" href="<?php echo esc_url( add_query_arg( array( 'action' => 'apptook_ds_google_login_start' ), admin_url( 'admin-post.php' ) ) ); ?>">
							<span class="apptook-st-google-icon" aria-hidden="true">
								<img src="<?php echo esc_url( APPTOOK_DS_URL . 'assets/img/logo_google.svg' ); ?>" alt="" loading="lazy" decoding="async" />
							</span>
							<span class="apptook-st-google-text"><?php esc_html_e( 'Sign in with Google', 'apptook-digital-store' ); ?></span>
						</a>
					</div>
					<p class="apptook-st-modal-meta">
						<a href="<?php echo esc_url( wp_lostpassword_url( $redirect_url ) ); ?>"><?php esc_html_e( 'Lost your password?', 'apptook-digital-store' ); ?></a>
						<?php if ( get_option( 'users_can_register' ) ) : ?>
							<span class="apptook-st-modal-meta-sep">·</span>
							<a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create account', 'apptook-digital-store' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * หน้าสมัครสมาชิก (มาตรฐาน WP: ชื่อผู้ใช้ + อีเมล ระบบส่งรหัส/ลิงก์ตามการตั้งค่า)
	 */
	public function shortcode_register(): string {
		if ( is_user_logged_in() ) {
			$shop = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );

			return '<p class="apptook-st-register-note apptook-ds">' . esc_html__( 'คุณเข้าสู่ระบบอยู่แล้ว', 'apptook-digital-store' ) . ' — <a href="' . esc_url( $shop ) . '">' . esc_html__( 'ไปที่ร้าน', 'apptook-digital-store' ) . '</a></p>';
		}

		$view = isset( $_GET['apptook_view'] ) ? sanitize_key( wp_unslash( (string) $_GET['apptook_view'] ) ) : '';
		if ( in_array( $view, array( 'login', 'forgot', 'reset' ), true ) ) {
			wp_safe_redirect( $this->get_register_page_url() );
			exit;
		}

		$reg_flag = isset( $_GET['apptook_reg'] ) ? sanitize_key( wp_unslash( (string) $_GET['apptook_reg'] ) ) : '';
		if ( $reg_flag === 'success' ) {
			return $this->render_register_success();
		}

		if ( ! get_option( 'users_can_register' ) ) {
			return '<div class="apptook-st-register apptook-stitch apptook-ds"><p class="apptook-st-register-warning">' . esc_html__( 'ขณะนี้ปิดรับสมัครสมาชิก — กรุณาเปิดที่ การตั้งค่า → ทั่วไป → สมาชิก', 'apptook-digital-store' ) . '</p></div>';
		}

		$shop        = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$form_action = admin_url( 'admin-post.php' );
		$err_code    = isset( $_GET['apptook_err'] ) ? sanitize_key( wp_unslash( (string) $_GET['apptook_err'] ) ) : '';
		$err_msg     = $err_code !== '' ? $this->register_error_message_for_code( $err_code ) : '';
		$min_pw      = $this->minimum_password_length();

		ob_start();
		?>
		<div class="apptook-st-register apptook-stitch apptook-ds">
			<div class="apptook-st-register-card">
				<?php if ( $err_msg !== '' ) : ?>
					<p class="apptook-st-register-error" role="alert" style="color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:.85rem 1rem;border-radius:1rem;font-weight:700;"><?php echo esc_html( $err_msg ); ?></p>
				<?php endif; ?>

				<h1 class="apptook-st-register-title"><?php esc_html_e( 'Create Account', 'apptook-digital-store' ); ?></h1>
				<p class="apptook-st-register-lead"><?php esc_html_e( 'Join APPTOOK today for premium access.', 'apptook-digital-store' ); ?></p>
				<p class="apptook-st-register-hint"><?php echo esc_html(sprintf(__( 'ตั้งรหัสผ่านของคุณที่นี่ (อย่างน้อย %d ตัวอักษร)', 'apptook-digital-store' ), $min_pw)); ?></p>
				<form class="apptook-st-modal-form" method="post" action="<?php echo esc_url( $form_action ); ?>" autocomplete="off">
					<input type="hidden" name="action" value="apptook_ds_register" />
					<?php wp_nonce_field( 'apptook_ds_register', 'apptook_ds_register_nonce' ); ?>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_login"><?php esc_html_e( 'Username', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="text" name="user_login" id="apptook_reg_user_login" value="<?php echo isset( $_GET['user_login'] ) ? esc_attr( sanitize_user( wp_unslash( (string) $_GET['user_login'] ), true ) ) : ''; ?>" required autocomplete="username" /></div>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_email"><?php esc_html_e( 'Email', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="email" name="user_email" id="apptook_reg_user_email" required autocomplete="email" /></div>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_pass"><?php esc_html_e( 'Password', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="password" name="user_pass" id="apptook_reg_user_pass" required autocomplete="new-password" minlength="<?php echo esc_attr( (string) $min_pw ); ?>" /></div>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_pass2"><?php esc_html_e( 'Confirm password', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="password" name="user_pass_confirm" id="apptook_reg_user_pass2" required autocomplete="new-password" minlength="<?php echo esc_attr( (string) $min_pw ); ?>" /></div>
					<input type="submit" name="apptook_ds_register_submit" class="apptook-st-submit" value="<?php echo esc_attr( __( 'Register', 'apptook-digital-store' ) ); ?>" />
				</form>

				<p class="apptook-st-modal-meta apptook-st-register-footer">
					<a href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( '← Back to Marketplace', 'apptook-digital-store' ); ?></a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * หน้าสถานะหลังสมัครสมาชิก (อีเมลยืนยันตามการตั้งค่า WordPress)
	 */
	private function render_register_success(): string {
		$shop      = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$login_url = add_query_arg( 'apptook_open_login', '1', home_url( '/' ) );
		$plain_reg = $this->get_register_page_url();

		ob_start();
		?>
		<div class="apptook-st-register apptook-stitch apptook-ds">
			<div class="apptook-st-register-card apptook-st-register-card--success" role="status">
				<div class="apptook-st-register-success-icon" aria-hidden="true">
					<span class="material-symbols-outlined">check_circle</span>
				</div>
				<h1 class="apptook-st-register-title"><?php esc_html_e( 'สมัครสมาชิกสำเร็จ', 'apptook-digital-store' ); ?></h1>
				<p class="apptook-st-register-lead apptook-st-register-success-lead">
					<?php esc_html_e( 'บัญชีพร้อมใช้งานแล้ว — เข้าสู่ระบบด้วยชื่อผู้ใช้และรหัสผ่านที่คุณตั้งไว้', 'apptook-digital-store' ); ?>
				</p>
				<ol class="apptook-st-register-steps">
					<li><?php esc_html_e( 'กดปุ่มเข้าสู่ระบบ แล้วล็อกอินด้วยชื่อผู้ใช้หรืออีเมล', 'apptook-digital-store' ); ?></li>
					<li><?php esc_html_e( 'หากได้รับอีเมลจากเว็บไซต์ ให้เก็บไว้เป็นหลักฐาน (ไม่บังคับ)', 'apptook-digital-store' ); ?></li>
					<li><?php esc_html_e( 'เริ่มเลือกสินค้าใน Marketplace ได้ทันที', 'apptook-digital-store' ); ?></li>
				</ol>
				<div class="apptook-st-register-success-actions">
					<a class="apptook-st-submit apptook-st-register-success-primary" href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( 'ไปที่ Marketplace', 'apptook-digital-store' ); ?></a>
					<a class="apptook-st-btn-secondary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'เข้าสู่ระบบ', 'apptook-digital-store' ); ?></a>
				</div>
				<p class="apptook-st-modal-meta apptook-st-register-footer apptook-st-register-success-meta">
					<a href="<?php echo esc_url( $plain_reg ); ?>"><?php esc_html_e( 'สมัครบัญชีอื่น', 'apptook-digital-store' ); ?></a>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function get_register_page_url(): string {
		$page_id = (int) get_option( 'apptook_ds_page_register_id', 0 );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}
		$page = get_page_by_path( 'apptook-register', OBJECT, 'page' );
		if ( $page instanceof WP_Post && $page->post_status === 'publish' ) {
			$url = get_permalink( $page );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}
		return wp_registration_url();
	}

	private function get_page_url_by_option( string $option_key ): string {
		$page_id = (int) get_option( $option_key, 0 );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	private function render_product_card( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'apptook_product' ) {
			return '';
		}

		$price_raw = (string) get_post_meta( $post_id, '_apptook_price', true );
		$price_f   = (float) $price_raw;
		$period    = get_post_meta( $post_id, '_apptook_period', true );
		$period    = is_string( $period ) && $period !== '' ? $period : __( '/ เดือน', 'apptook-digital-store' );
		$badge     = get_post_meta( $post_id, '_apptook_badge', true );
		$badge     = is_string( $badge ) ? trim( $badge ) : '';
		$badge_st  = (string) get_post_meta( $post_id, '_apptook_badge_style', true );

		$slugs = array();
		$terms = get_the_terms( $post_id, 'apptook_product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$slugs[] = $t->slug;
			}
		}
		$cat_attr = esc_attr( implode( ' ', $slugs ) );

		$title     = get_the_title( $post_id );
		$name_attr = esc_attr( wp_strip_all_tags( $title ) );
		$date_ts   = (int) get_post_time( 'U', true, $post_id );
		$permalink = get_permalink( $post_id );

		$price_display = $price_raw !== '' ? $price_raw : '0';
		if ( $price_display !== '' && strpos( $price_display, '฿' ) !== 0 && strpos( $price_display, 'THB' ) === false ) {
			$price_display = '฿' . $price_display;
		}

		$bullets = $this->get_product_bullets( $post_id );

		ob_start();
		?>
		<article
			class="product-card group bg-white"
			data-category="<?php echo $cat_attr; ?>"
			data-name="<?php echo $name_attr; ?>"
			data-price="<?php echo esc_attr( (string) $price_f ); ?>"
			data-date="<?php echo esc_attr( (string) $date_ts ); ?>"
		>
			<div class="st-card-top">
				<?php if ( $badge !== '' ) : ?>
					<?php if ( $badge_st === 'green' ) : ?>
						<span class="st-card-badge st-card-badge--green">
							<span class="material-symbols-outlined" aria-hidden="true">bolt</span>
							<?php echo esc_html( $badge ); ?>
						</span>
					<?php else : ?>
						<span class="st-card-badge st-card-badge--mint"><?php echo esc_html( $badge ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
				<?php
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, 'medium', array( 'class' => '', 'alt' => '' ) );
				} else {
					echo '<span class="material-symbols-outlined st-card-icon-lg text-on-surface" aria-hidden="true">inventory_2</span>';
				}
				?>
				<h3 class="st-card-title font-headline"><?php echo esc_html( $title ); ?></h3>
			</div>
			<div class="card-wave-bg text-center">
				<div class="st-card-price">
					<?php echo esc_html( $price_display ); ?>
					<span> <?php echo esc_html( $period ); ?></span>
				</div>
			</div>
			<div class="st-card-body">
				<?php if ( $bullets !== array() ) : ?>
					<ul>
						<?php
						foreach ( array_slice( $bullets, 0, 4 ) as $line ) {
							?>
							<li>
								<span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
								<span><?php echo esc_html( $line ); ?></span>
							</li>
							<?php
						}
						?>
					</ul>
				<?php endif; ?>
				<div class="st-card-bounce-wrap">
					<span class="material-symbols-outlined animate-bounce-slow" aria-hidden="true">expand_more</span>
				</div>
				<?php if ( is_user_logged_in() ) : ?>
					<button type="button" class="buy-btn apptook-ds-buy" data-product-id="<?php echo esc_attr( (string) $post_id ); ?>">
						<?php esc_html_e( 'ซื้อเลย', 'apptook-digital-store' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="buy-btn" disabled title="<?php esc_attr_e( 'เข้าสู่ระบบก่อน', 'apptook-digital-store' ); ?>">
						<?php esc_html_e( 'ซื้อเลย', 'apptook-digital-store' ); ?>
					</button>
				<?php endif; ?>
				<a class="st-card-more" href="<?php echo esc_url( $permalink ); ?>"><?php esc_html_e( 'ดูรายละเอียดเพิ่มเติม', 'apptook-digital-store' ); ?></a>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @return list<string>
	 */
	private function get_product_bullets( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_apptook_bullets', true );
		if ( is_string( $raw ) && trim( $raw ) !== '' ) {
			$lines = preg_split( "/\r\n|\n|\r/", $raw );

			return array_values(
				array_filter(
					array_map( 'trim', is_array( $lines ) ? $lines : array() )
				)
			);
		}

		$p = get_post( $post_id );
		if ( $p && is_string( $p->post_excerpt ) && trim( $p->post_excerpt ) !== '' ) {
			$lines = preg_split( "/\r\n|\n|\r/", $p->post_excerpt );

			return array_slice(
				array_values(
					array_filter(
						array_map( 'trim', is_array( $lines ) ? $lines : array() )
					)
				),
				0,
				6
			);
		}

		return array();
	}

	private function render_shop_simple(): string {
		$q = new WP_Query(
			array(
				'post_type'      => 'apptook_product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		ob_start();
		?>
		<div class="apptook-ds apptook-ds-shop">
			<?php
			if ( ! $q->have_posts() ) {
				echo '<p class="apptook-ds-empty">' . esc_html__( 'ยังไม่มีสินค้า', 'apptook-digital-store' ) . '</p>';
			} else {
				echo '<ul class="apptook-ds-shop-list">';
				while ( $q->have_posts() ) {
					$q->the_post();
					$pid   = get_the_ID();
					$price = get_post_meta( $pid, '_apptook_price', true );
					?>
					<li class="apptook-ds-shop-item">
						<a class="apptook-ds-shop-link" href="<?php echo esc_url( get_permalink() ); ?>">
							<?php if ( has_post_thumbnail() ) : ?>
								<span class="apptook-ds-shop-thumb"><?php the_post_thumbnail( 'medium' ); ?></span>
							<?php endif; ?>
							<span class="apptook-ds-shop-title"><?php the_title(); ?></span>
							<span class="apptook-ds-shop-price"><?php echo esc_html( (string) $price ); ?> <?php esc_html_e( 'บาท', 'apptook-digital-store' ); ?></span>
						</a>
					</li>
					<?php
				}
				echo '</ul>';
				wp_reset_postdata();
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function shortcode_library(): string {
		if ( ! is_user_logged_in() ) {
			return '<p class="apptook-ds apptook-ds-notice">' . esc_html__( 'กรุณาเข้าสู่ระบบเพื่อดูคลังของคุณ', 'apptook-digital-store' ) . '</p>';
		}

		$user_id = get_current_user_id();
		$orders  = new WP_Query(
			array(
				'post_type'        => 'apptook_order',
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'suppress_filters' => true,
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => '_apptook_customer_id',
						'value' => $user_id,
					),
					array(
						'key'   => '_apptook_status',
						'value' => Apptook_DS_Post_Types::ORDER_PAID,
					),
				),
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		ob_start();
		?>
		<div class="apptook-ds apptook-ds-library">
			<?php
			if ( ! $orders->have_posts() ) {
				echo '<p class="apptook-ds-empty">' . esc_html__( 'ยังไม่มีสินค้าที่ชำระแล้ว', 'apptook-digital-store' ) . '</p>';
			} else {
				echo '<ul class="apptook-ds-library-list">';
				while ( $orders->have_posts() ) {
					$orders->the_post();
					$oid        = get_the_ID();
					$product_id = (int) get_post_meta( $oid, '_apptook_product_id', true );
					$key        = get_post_meta( $oid, '_apptook_license_key', true );
					?>
					<li class="apptook-ds-library-item">
						<h3 class="apptook-ds-library-title"><?php echo esc_html( get_the_title( $product_id ) ); ?></h3>
						<p class="apptook-ds-library-key">
							<span class="apptook-ds-library-key-label"><?php esc_html_e( 'คีย์ / ไลเซนส์', 'apptook-digital-store' ); ?></span>
							<code class="apptook-ds-key-value"><?php echo esc_html( (string) $key ); ?></code>
						</p>
					</li>
					<?php
				}
				echo '</ul>';
				wp_reset_postdata();
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
