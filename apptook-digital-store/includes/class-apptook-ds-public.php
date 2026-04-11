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
		add_shortcode('apptook_order_history', array($this, 'shortcode_order_history'));
		add_shortcode('apptook_register', array($this, 'shortcode_register'));
		add_shortcode('apptook_blog', array($this, 'shortcode_blog'));
		add_shortcode('apptook_support', array($this, 'shortcode_support'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('template_redirect', array($this, 'maybe_render_mobile_verify_page'));
		add_action('template_redirect', array($this, 'maybe_render_support_route'));
		add_filter('single_template', array($this, 'single_product_template'));
		add_filter('template_include', array($this, 'blog_index_template'), 20);
		add_action('init', array($this, 'register_blog_rewrite_rule'));
		add_filter('query_vars', array($this, 'register_blog_query_vars'));
		add_filter('registration_redirect', array($this, 'filter_registration_redirect'), 100, 2);
		add_action('login_init', array($this, 'maybe_handle_google_routes'), 0);
		add_action('login_init', array($this, 'redirect_registered_checkemail_to_themed_page'), 1);
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
		add_action('wp_ajax_nopriv_apptook_ds_google_login_start', array($this, 'handle_google_login_start'));
		add_action('wp_ajax_apptook_ds_google_login_start', array($this, 'handle_google_login_start'));
		add_action('wp_ajax_nopriv_apptook_ds_google_login_callback', array($this, 'handle_google_login_callback'));
		add_action('wp_ajax_apptook_ds_google_login_callback', array($this, 'handle_google_login_callback'));
		add_action('init', array($this, 'maybe_handle_google_routes'), 1);
		add_action('rest_api_init', array($this, 'register_google_rest_routes'));
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

		if ( $user_email === '' || ! is_email( $user_email ) ) {
			$this->redirect_register_failure( 'invalid_email', $user_login );
		}
		if ( $user_login === '' ) {
			$parts      = explode( '@', $user_email );
			$local_part = isset( $parts[0] ) ? (string) $parts[0] : '';
			$user_login = sanitize_user( $local_part, true );
			if ( $user_login === '' ) {
				$user_login = 'apptookuser';
			}
		}
		if ( ! validate_username( $user_login ) ) {
			$this->redirect_register_failure( 'invalid_username', $user_login );
		}
		$base_login = $user_login;
		$counter    = 1;
		while ( username_exists( $user_login ) ) {
			$user_login = $base_login . $counter;
			$counter++;
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

	public function maybe_handle_google_routes(): void {
		$action = isset($_GET['action']) ? sanitize_text_field(wp_unslash((string) $_GET['action'])) : '';
		$google_route = isset($_GET['apptook_google']) ? sanitize_text_field(wp_unslash((string) $_GET['apptook_google'])) : '';
		$request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		$path = $request_uri !== '' ? trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/') : '';

		if ($action === 'apptook_ds_google_login_start' || $google_route === 'start' || $path === 'google-login-start') {
			$this->handle_google_login_start();
			exit;
		}
		if ($action === 'apptook_ds_google_login_callback' || $google_route === 'callback' || $path === 'google-login-callback') {
			$this->handle_google_login_callback();
			exit;
		}
	}

	public function register_google_rest_routes(): void {
		register_rest_route(
			'apptook-ds/v1',
			'/google/start',
			array(
				'methods' => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback' => array($this, 'rest_google_start'),
			)
		);

		register_rest_route(
			'apptook-ds/v1',
			'/google/callback',
			array(
				'methods' => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback' => array($this, 'rest_google_callback'),
			)
		);
	}

	public function rest_google_start( WP_REST_Request $request ): WP_REST_Response {
		$this->handle_google_login_start();
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public function rest_google_callback( WP_REST_Request $request ): WP_REST_Response {
		$this->handle_google_login_callback();
		return new WP_REST_Response( array( 'ok' => true ), 200 );
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
			array( 'apptook_google' => 'callback' ),
			home_url( '/' )
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

		$callback_url = home_url( '/google-login-callback' );
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
		if (isset($_REQUEST['action'])) {
			$action = sanitize_text_field(wp_unslash((string) $_REQUEST['action']));
			if ($action === 'apptook_ds_google_login_start' || $action === 'apptook_ds_google_login_callback') {
				return;
			}
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

	public function maybe_render_mobile_verify_page(): void {
		$flag_get = isset($_GET['apptook_mobile_verify']) ? sanitize_text_field(wp_unslash((string) $_GET['apptook_mobile_verify'])) : '';
		$flag_qv = (string) get_query_var('apptook_mobile_verify', '');
		$is_mobile_verify = ($flag_get === '1' || $flag_qv === '1');
		if (! $is_mobile_verify) {
			return;
		}
		$token_raw = (string) get_query_var('verify_token', '');
		if ($token_raw === '' && isset($_GET['verify_token'])) {
			$token_raw = (string) wp_unslash((string) $_GET['verify_token']);
		}
		$token = sanitize_text_field($token_raw);
		if ($token === '') {
			wp_die(esc_html__('ลิงก์ไม่ถูกต้อง', 'apptook-digital-store'));
		}
		if (function_exists('nocache_headers')) {
			nocache_headers();
		}
		$verify = get_transient('apptook_ds_verify_token_' . $token);
		$session_token = is_array($verify) && isset($verify['session_token']) ? (string) $verify['session_token'] : '';
		$session = $session_token !== '' ? get_transient('apptook_ds_mobile_session_' . $session_token) : array();
		$valid = is_array($session) && ! empty($session);
		if (! $valid) {
			status_header(403);
			echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1" /><title>Invalid link</title></head><body style="font-family:Kanit,sans-serif;padding:24px;background:#f3f7fb;color:#16324a"><h2>ลิงก์อัปโหลดหมดอายุหรือไม่ถูกต้อง</h2><p>กรุณากลับไปที่หน้าคอมพิวเตอร์เพื่อสร้าง QR ใหม่</p></body></html>';
			exit;
		}
		$product_id = isset($session['product_id']) ? (int) $session['product_id'] : 0;
		$product_name = $product_id > 0 ? get_the_title($product_id) : __('รายการสั่งซื้อ', 'apptook-digital-store');
		$ajax_url = admin_url('admin-ajax.php');
		header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
		echo '<!doctype html><html><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '" /><meta name="viewport" content="width=device-width, initial-scale=1" /><title>' . esc_html__('Mobile Slip Upload', 'apptook-digital-store') . '</title><style>body{font-family:Kanit,sans-serif;background:#eef4fa;margin:0;padding:16px;color:#153047}.box{max-width:480px;margin:0 auto;background:#fff;border:1px solid #d8e6f1;border-radius:14px;padding:18px}.btn{width:100%;height:48px;border:0;border-radius:12px;background:#1f6ea3;color:#fff;font-size:18px;font-family:Kanit,sans-serif;font-weight:700}.input{width:100%;padding:12px;border:1px solid #c7d8e6;border-radius:10px}.msg{margin-top:12px;font-size:14px}.ok{color:#0d7f3f}.err{color:#c62828}</style></head><body><div class="box"><h2 style="margin:0 0 8px">อัปโหลดสลิปผ่านมือถือ</h2><p style="margin:0 0 16px;color:#53708a">รายการ: ' . esc_html((string) $product_name) . '</p><input id="apptook-mobile-slip" class="input" type="file" accept="image/jpeg,image/png,image/webp" /><button id="apptook-mobile-upload" class="btn" type="button" style="margin-top:12px">อัปโหลดสลิป</button><p id="apptook-mobile-msg" class="msg"></p></div><script>(function(){var btn=document.getElementById("apptook-mobile-upload");var input=document.getElementById("apptook-mobile-slip");var msg=document.getElementById("apptook-mobile-msg");btn.addEventListener("click",function(){var f=input.files&&input.files[0];if(!f){msg.className="msg err";msg.textContent="กรุณาเลือกไฟล์สลิป";return;}var allow=["image/jpeg","image/png","image/webp"];if(allow.indexOf(f.type)===-1){msg.className="msg err";msg.textContent="รองรับเฉพาะ JPG, PNG, WEBP";return;}if((f.size||0)>5*1024*1024){msg.className="msg err";msg.textContent="ขนาดไฟล์ต้องไม่เกิน 5MB";return;}var fd=new FormData();fd.append("action","apptook_ds_mobile_upload_slip");fd.append("verify_token",' . wp_json_encode($token) . ');fd.append("slip",f);btn.disabled=true;btn.textContent="กำลังอัปโหลด...";fetch(' . wp_json_encode($ajax_url) . ',{method:"POST",credentials:"same-origin",body:fd}).then(function(r){return r.json();}).then(function(res){if(!res.success){throw new Error((res.data&&res.data.message)||"อัปโหลดไม่สำเร็จ");}msg.className="msg ok";msg.textContent=(res.data&&res.data.message)||"อัปโหลดสำเร็จ กรุณากลับไปที่คอมเพื่อกดยืนยัน";btn.textContent="อัปโหลดแล้ว";}).catch(function(err){msg.className="msg err";msg.textContent=err&&err.message?err.message:"อัปโหลดไม่สำเร็จ";btn.disabled=false;btn.textContent="อัปโหลดสลิป";});});})();</script></body></html>';
		exit;
	}

	public function single_product_template( $template ) {
		if ( is_singular( 'apptook_product' ) ) {
			$plugin_template = APPTOOK_DS_PATH . 'templates/single-apptook_product.php';
			if ( is_readable( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		if ( is_singular( 'post' ) ) {
			$plugin_template = APPTOOK_DS_PATH . 'templates/blog-single.php';
			if ( is_readable( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return is_string( $template ) ? $template : '';
	}

	public function register_blog_rewrite_rule(): void {
		add_rewrite_rule( '^blog/?$', 'index.php?apptook_ds_blog_index=1', 'top' );
		add_rewrite_rule( '^support/?$', 'index.php?apptook_ds_support=1', 'top' );
		add_rewrite_rule( '^apptook-mobile-verify/([^/]+)/?$', 'index.php?apptook_mobile_verify=1&verify_token=$matches[1]', 'top' );
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function register_blog_query_vars( array $vars ): array {
		$vars[] = 'apptook_ds_blog_index';
		$vars[] = 'apptook_ds_support';
		$vars[] = 'apptook_mobile_verify';
		$vars[] = 'verify_token';
		return $vars;
	}

	public function blog_index_template( $template ) {
		$is_apptook_blog = get_query_var( 'apptook_ds_blog_index', '' ) === '1';
		if ( $is_apptook_blog || is_home() || is_post_type_archive( 'post' ) || is_category() || is_tag() || is_author() || is_date() || is_page( 'blog' ) ) {
			$plugin_template = APPTOOK_DS_PATH . 'templates/blog-index.php';
			if ( is_readable( $plugin_template ) ) {
				status_header( 200 );
				nocache_headers();
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
		if (is_singular('apptook_product') || is_singular('post')) {
			return true;
		}

		$is_support_shell = (
			get_query_var('apptook_ds_support', '') === '1'
			|| is_page('support')
			|| $this->is_support_request_path()
		);
		if ($is_support_shell) {
			return true;
		}

		$is_blog_shell = (
			get_query_var('apptook_ds_blog_index', '') === '1'
			|| is_home()
			|| is_post_type_archive('post')
			|| is_category()
			|| is_tag()
			|| is_author()
			|| is_date()
			|| is_page('blog')
		);
		if ($is_blog_shell) {
			return true;
		}

		global $post;
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_library')) {
			return true;
		}
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_order_history')) {
			return true;
		}
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_register')) {
			return true;
		}
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_blog')) {
			return true;
		}
		if ($post instanceof WP_Post && has_shortcode($post->post_content, 'apptook_support')) {
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
		$load_library = $post instanceof WP_Post && has_shortcode( $post->post_content, 'apptook_library' );
		$load_order_history = $post instanceof WP_Post && has_shortcode( $post->post_content, 'apptook_order_history' );
		$load_blog = $post instanceof WP_Post && has_shortcode( $post->post_content, 'apptook_blog' );
		$load_support = $post instanceof WP_Post && has_shortcode( $post->post_content, 'apptook_support' );
		$load_blog_route = (
			get_query_var('apptook_ds_blog_index', '') === '1'
			|| is_home()
			|| is_post_type_archive('post')
			|| is_category()
			|| is_tag()
			|| is_author()
			|| is_date()
			|| is_page('blog')
		);
		$load_support_route = (
			get_query_var('apptook_ds_support', '') === '1'
			|| is_page('support')
			|| $this->is_support_request_path()
		);
		$load_simple_shop = $post instanceof WP_Post && has_shortcode( $post->post_content, 'apptook_shop' ) && $this->current_shop_layout() === 'simple';
		$load_single_product = is_singular('apptook_product');
		$load_single_post = is_singular('post');
		$load_global_shell = $load_mkt || $load_reg_skin || $load_library || $load_order_history || $load_blog || $load_support || $load_blog_route || $load_support_route || $load_simple_shop || $load_single_product || $load_single_post;

		$frontend_css_ver = (string) filemtime( APPTOOK_DS_PATH . 'assets/css/frontend.css' );
		$marketplace_css_ver = (string) filemtime( APPTOOK_DS_PATH . 'assets/css/marketplace.css' );
		$frontend_ver = (string) filemtime( APPTOOK_DS_PATH . 'assets/js/frontend.js' );
		$marketplace_ver = (string) filemtime( APPTOOK_DS_PATH . 'assets/js/marketplace.js' );

		wp_enqueue_style(
			'apptook-ds-kanit',
			'https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700;800&display=swap',
			array(),
			null
		);

		if ( $load_global_shell ) {
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
				$marketplace_css_ver
			);
		}

		wp_enqueue_style(
			'apptook-ds-frontend',
			APPTOOK_DS_URL . 'assets/css/frontend.css',
			array(),
			$frontend_css_ver
		);

		wp_enqueue_script(
			'apptook-ds-frontend',
			APPTOOK_DS_URL . 'assets/js/frontend.js',
			array(),
			$frontend_ver,
			true
		);

		if ( $load_global_shell ) {
			wp_enqueue_script(
				'apptook-ds-marketplace',
				APPTOOK_DS_URL . 'assets/js/marketplace.js',
				array('apptook-ds-frontend'),
				$marketplace_ver,
				true
			);
		}

		$opts = get_option( 'apptook_ds_options', array() );
		$discount_codes = array();
		if ( isset( $opts['discount_code_rows'] ) && is_array( $opts['discount_code_rows'] ) ) {
			foreach ( (array) $opts['discount_code_rows'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$code = isset( $row['code'] ) ? strtoupper( sanitize_text_field( (string) $row['code'] ) ) : '';
				$amount = isset( $row['amount'] ) ? (float) $row['amount'] : 0;
				if ( $code === '' || $amount <= 0 ) {
					continue;
				}
				$discount_codes[ $code ] = $amount;
			}
		}
		$discount_codes = array_slice( $discount_codes, 0, 10, true );

		wp_localize_script(
			'apptook-ds-frontend',
			'apptookDS',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('apptook_ds_public'),
				'discountCodes' => $discount_codes,
				'i18n'    => array(
					'loginRequired' => __('กรุณาเข้าสู่ระบบก่อนซื้อ', 'apptook-digital-store'),
					'uploading'     => __('กำลังอัปโหลด...', 'apptook-digital-store'),
					'error'         => __('เกิดข้อผิดพลาด ลองใหม่อีกครั้ง', 'apptook-digital-store'),
					'next'          => __('อัปโหลดสลิป', 'apptook-digital-store'),
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

	private function render_global_menuheader( string $active = 'marketplace' ): string {
		$shop_url     = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$library_url  = $this->get_page_url_by_option( 'apptook_ds_page_library_id' );
		$order_history_url = $this->get_order_history_page_url();
		$support_url  = $this->get_support_page_url();
		$register_url = $this->get_register_page_url();
		$current_user = wp_get_current_user();
		$login_url    = add_query_arg( 'apptook_open_login', '1', $shop_url );
		$ticker_items = $this->get_buyer_ticker_items();

		ob_start();
		?>
		<nav class="st-nav" aria-label="<?php esc_attr_e( 'เมนูหลัก', 'apptook-digital-store' ); ?>">
			<div class="st-nav__inner">
				<a class="st-nav__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<img src="https://apptook.waigona.com/wp-content/uploads/2026/04/logo-apptook-scaled.png" alt="APPTOOK" class="st-nav__logo-img" />
					<span>APPTOOK</span>
				</a>
				<div class="st-nav__links">
					<a class="<?php echo $active === 'marketplace' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Marketplace', 'apptook-digital-store' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>"><?php esc_html_e( 'Blog', 'apptook-digital-store' ); ?></a>
					<a class="<?php echo $active === 'support' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Support', 'apptook-digital-store' ); ?></a>
				</div>
				<div class="st-nav__right">
					<div class="st-nav__icons">
						<a href="<?php echo esc_url( $library_url ); ?>" class="st-nav__icon-btn" aria-label="<?php esc_attr_e( 'คลังสินค้า', 'apptook-digital-store' ); ?>">
							<span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
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
								<a href="<?php echo esc_url( $order_history_url ); ?>">
									<span class="material-symbols-outlined" aria-hidden="true">history</span>
									<?php esc_html_e( 'Order History', 'apptook-digital-store' ); ?>
								</a>
								<?php if ( ! is_user_logged_in() ) : ?>
									<a href="<?php echo esc_url( $login_url ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true">login</span>
										<?php esc_html_e( 'Log In', 'apptook-digital-store' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( is_user_logged_in() ) : ?>
									<div class="st-nav__dropdown-divider"></div>
									<a class="st-nav__logout" href="<?php echo esc_url( wp_logout_url( $shop_url ) ); ?>">
										<span class="material-symbols-outlined" aria-hidden="true">logout</span>
										<?php esc_html_e( 'Logout', 'apptook-digital-store' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>
						<button type="button" class="st-nav__icon-btn st-nav__mobile-trigger" aria-expanded="false" aria-controls="apptook-st-mobile-menu" aria-label="<?php esc_attr_e( 'เปิดเมนู', 'apptook-digital-store' ); ?>">
							<span class="material-symbols-outlined" aria-hidden="true">menu</span>
						</button>
					</div>
					<?php if ( ! is_user_logged_in() ) : ?>
						<div class="st-nav__auth" id="apptook-st-auth-buttons">
							<a class="st-nav__btn-ghost" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'apptook-digital-store' ); ?></a>
							<?php if ( get_option( 'users_can_register' ) ) : ?>
								<a class="st-nav__btn-primary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Sign Up', 'apptook-digital-store' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<div class="st-nav__mobile-panel" id="apptook-st-mobile-menu" aria-hidden="true">
				<a class="<?php echo $active === 'marketplace' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Marketplace', 'apptook-digital-store' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>"><?php esc_html_e( 'Blog', 'apptook-digital-store' ); ?></a>
				<a class="<?php echo $active === 'support' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $support_url ); ?>"><?php esc_html_e( 'Support', 'apptook-digital-store' ); ?></a>
			</div>
		</nav>
		<?php if ( $ticker_items !== array() ) : ?>
			<div class="buyer-ticker-track py-2" aria-label="<?php esc_attr_e( 'ออเดอร์ล่าสุด', 'apptook-digital-store' ); ?>">
				<div class="buyer-ticker-track__inner" role="presentation">
					<?php foreach ( array( 1, 2 ) as $loop_idx ) : ?>
						<?php foreach ( $ticker_items as $item ) : ?>
							<span class="buyer-ticker-track__item">
								<span class="buyer-ticker-track__buyer"><?php echo esc_html( (string) $item['buyer'] ); ?></span>
								<span class="buyer-ticker-track__action"><?php esc_html_e( 'ซื้อ', 'apptook-digital-store' ); ?></span>
								<span class="buyer-ticker-track__product"><?php echo esc_html( (string) $item['product'] ); ?></span>
								<span class="buyer-ticker-track__time"><?php echo esc_html( (string) $item['time_ago'] ); ?></span>
							</span>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}

	public function render_site_menuheader( string $active = 'marketplace' ): string {
		return $this->render_global_menuheader( $active );
	}

	public function render_site_footer(): string {
		return $this->render_global_footer();
	}

	private function render_global_footer(): string {
		ob_start();
		?>
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
		<?php

		return (string) ob_get_clean();
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
			<?php
			if ( $show_nav ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_global_menuheader( 'marketplace' );
			}
			?>

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

			<?php
			if ( $show_footer ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_global_footer();
			}
			?>

			<?php
			if ( $show_nav && ! is_user_logged_in() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_login_modal( $shop_url, $register_url );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_register_modal();
			}
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * โมดัลล็อกอินแบบ stitch
	 */
	private function render_login_modal( string $redirect_url, string $register_url ): string {
		$login_action = admin_url( 'admin-post.php' );
		$google_login_url = add_query_arg(
			array(
				'loginSocial' => 'google',
				'redirect'    => rawurlencode( $redirect_url ),
			),
			wp_login_url()
		);
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
						<div class="apptook-st-login-alert" role="alert" aria-live="assertive"><?php echo esc_html( $err_msg ); ?></div>
					<?php endif; ?>

					<div class="apptook-st-social-auth">
						<a class="apptook-st-google-btn" href="<?php echo esc_url( $google_login_url ); ?>">
							<span class="apptook-st-google-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="20" height="20" focusable="false" aria-hidden="true">
									<path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.56c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"></path>
									<path d="M12 23c2.97 0 5.46-.98 7.28-2.65l-3.56-2.77c-.98.66-2.24 1.06-3.72 1.06-2.86 0-5.28-1.93-6.15-4.53H2.18v2.84A11 11 0 0 0 12 23z" fill="#34A853"></path>
									<path d="M5.85 14.11A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.44.35-2.11V7.05H2.18A11 11 0 0 0 1 12c0 1.77.42 3.44 1.18 4.95l3.67-2.84z" fill="#FBBC05"></path>
									<path d="M12 5.36c1.62 0 3.06.56 4.2 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.69 1 3.97 3.47 2.18 7.05l3.67 2.84C6.72 7.29 9.14 5.36 12 5.36z" fill="#EA4335"></path>
								</svg>
							</span>
							<span class="apptook-st-google-text"><?php esc_html_e( 'Login with Google', 'apptook-digital-store' ); ?></span>
						</a>
					</div>

					<div class="apptook-st-login-divider"><span><?php esc_html_e( 'หรือ', 'apptook-digital-store' ); ?></span></div>

					<form class="apptook-st-modal-form" method="post" action="<?php echo esc_url( $login_action ); ?>">
						<input type="hidden" name="action" value="apptook_ds_login" />
						<?php wp_nonce_field( 'apptook_ds_login', 'apptook_ds_login_nonce' ); ?>
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_url ); ?>" />

						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_user_login"><?php esc_html_e( 'Username', 'apptook-digital-store' ); ?></label>
							<input class="apptook-st-input" type="text" name="log" id="apptook_st_user_login" autocomplete="username" required placeholder="<?php esc_attr_e( 'กรอก username', 'apptook-digital-store' ); ?>" />
						</div>

						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_user_pass"><?php esc_html_e( 'Password', 'apptook-digital-store' ); ?></label>
							<div class="apptook-st-password-wrap is-empty">
								<input class="apptook-st-input apptook-st-input-password" type="password" name="pwd" id="apptook_st_user_pass" autocomplete="current-password" required placeholder="<?php esc_attr_e( 'กรอกรหัสผ่าน', 'apptook-digital-store' ); ?>" />
								<button type="button" id="apptook_st_user_pass_toggle" class="apptook-st-password-toggle" data-apptook-toggle-password="apptook_st_user_pass" aria-controls="apptook_st_user_pass" aria-label="<?php esc_attr_e( 'แสดงรหัสผ่าน', 'apptook-digital-store' ); ?>" aria-hidden="true">
									<span class="material-symbols-outlined" aria-hidden="true">visibility</span>
								</button>
							</div>
						</div>

						<input type="submit" name="wp-submit" class="apptook-st-submit" value="<?php echo esc_attr( __( 'Login', 'apptook-digital-store' ) ); ?>" />
					</form>

					<p class="apptook-st-modal-meta">
						<a href="<?php echo esc_url( wp_lostpassword_url( $redirect_url ) ); ?>"><?php esc_html_e( 'Lost your password?', 'apptook-digital-store' ); ?></a>
						<?php if ( get_option( 'users_can_register' ) ) : ?>
							<span class="apptook-st-modal-meta-sep">·</span>
							<button type="button" class="apptook-st-link-btn st-nav__open-register"><?php esc_html_e( 'Create account', 'apptook-digital-store' ); ?></button>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function render_register_modal(): string {
		$form_action = admin_url( 'admin-post.php' );
		$min_pw      = $this->minimum_password_length();
		ob_start();
		?>
		<div class="apptook-st-modal-overlay" id="apptook-st-register-modal" role="dialog" aria-modal="true" aria-labelledby="apptook-st-register-title" aria-hidden="true">
			<div class="apptook-st-modal">
				<button type="button" class="apptook-st-modal-close apptook-st-close-register-modal" aria-label="<?php esc_attr_e( 'ปิด', 'apptook-digital-store' ); ?>">
					<span class="material-symbols-outlined" aria-hidden="true">close</span>
				</button>
				<div class="apptook-st-modal-body">
					<h2 id="apptook-st-register-title" class="apptook-st-modal-title"><?php esc_html_e( 'Create Account', 'apptook-digital-store' ); ?></h2>
					<p class="apptook-st-modal-lead"><?php esc_html_e( 'Join APPTOOK today for premium access.', 'apptook-digital-store' ); ?></p>
					<form class="apptook-st-modal-form" method="post" action="<?php echo esc_url( $form_action ); ?>" autocomplete="off">
						<input type="hidden" name="action" value="apptook_ds_register" />
						<?php wp_nonce_field( 'apptook_ds_register', 'apptook_ds_register_nonce' ); ?>
						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_reg_email"><?php esc_html_e( 'Email', 'apptook-digital-store' ); ?></label>
							<input class="apptook-st-input" type="email" name="user_email" id="apptook_st_reg_email" required autocomplete="email" placeholder="<?php esc_attr_e( 'Enter email', 'apptook-digital-store' ); ?>" />
						</div>
						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_reg_pass"><?php esc_html_e( 'Password', 'apptook-digital-store' ); ?></label>
							<input class="apptook-st-input" type="password" name="user_pass" id="apptook_st_reg_pass" required autocomplete="new-password" minlength="<?php echo esc_attr( (string) $min_pw ); ?>" placeholder="<?php esc_attr_e( 'Create password', 'apptook-digital-store' ); ?>" />
						</div>
						<div class="apptook-st-field">
							<label class="apptook-st-label" for="apptook_st_reg_pass2"><?php esc_html_e( 'Confirm Password', 'apptook-digital-store' ); ?></label>
							<input class="apptook-st-input" type="password" name="user_pass_confirm" id="apptook_st_reg_pass2" required autocomplete="new-password" minlength="<?php echo esc_attr( (string) $min_pw ); ?>" placeholder="<?php esc_attr_e( 'Confirm password', 'apptook-digital-store' ); ?>" />
						</div>
						<input type="hidden" name="user_login" value="" />
						<input type="submit" class="apptook-st-submit" value="<?php echo esc_attr( __( 'Register', 'apptook-digital-store' ) ); ?>" />
					</form>
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
			return '<div class="apptook-stitch apptook-ds">'
				. $this->render_global_menuheader( 'register' )
				. '<div class="apptook-st-register"><p class="apptook-st-register-warning">' . esc_html__( 'ขณะนี้ปิดรับสมัครสมาชิก — กรุณาเปิดที่ การตั้งค่า → ทั่วไป → สมาชิก', 'apptook-digital-store' ) . '</p></div>'
				. $this->render_global_footer()
				. '</div>';
		}

		$shop        = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$form_action = admin_url( 'admin-post.php' );
		$err_code    = isset( $_GET['apptook_err'] ) ? sanitize_key( wp_unslash( (string) $_GET['apptook_err'] ) ) : '';
		$err_msg     = $err_code !== '' ? $this->register_error_message_for_code( $err_code ) : '';
		$min_pw      = $this->minimum_password_length();

		ob_start();
		?>
		<div class="apptook-stitch apptook-ds">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'register' );
			?>
			<div class="apptook-st-register">
				<div class="apptook-st-register-card">
				<?php if ( $err_msg !== '' ) : ?>
					<p class="apptook-st-register-error" role="alert"><?php echo esc_html( $err_msg ); ?></p>
				<?php endif; ?>

				<h1 class="apptook-st-register-title"><?php esc_html_e( 'Create Account', 'apptook-digital-store' ); ?></h1>
				<p class="apptook-st-register-lead"><?php esc_html_e( 'Join APPTOOK today for premium access.', 'apptook-digital-store' ); ?></p>
				<form class="apptook-st-modal-form" method="post" action="<?php echo esc_url( $form_action ); ?>" autocomplete="off">
					<input type="hidden" name="action" value="apptook_ds_register" />
					<input type="hidden" name="user_login" value="" />
					<?php wp_nonce_field( 'apptook_ds_register', 'apptook_ds_register_nonce' ); ?>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_email"><?php esc_html_e( 'Email', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="email" name="user_email" id="apptook_reg_user_email" required autocomplete="email" placeholder="<?php esc_attr_e( 'Enter email', 'apptook-digital-store' ); ?>" /></div>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_pass"><?php esc_html_e( 'Password', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="password" name="user_pass" id="apptook_reg_user_pass" required autocomplete="new-password" minlength="<?php echo esc_attr( (string) $min_pw ); ?>" placeholder="<?php esc_attr_e( 'Create password', 'apptook-digital-store' ); ?>" /></div>
					<div class="apptook-st-field"><label class="apptook-st-label" for="apptook_reg_user_pass2"><?php esc_html_e( 'Confirm Password', 'apptook-digital-store' ); ?></label><input class="apptook-st-input" type="password" name="user_pass_confirm" id="apptook_reg_user_pass2" required autocomplete="new-password" minlength="<?php echo esc_attr( (string) $min_pw ); ?>" placeholder="<?php esc_attr_e( 'Confirm password', 'apptook-digital-store' ); ?>" /></div>
					<input type="submit" name="apptook_ds_register_submit" class="apptook-st-submit" value="<?php echo esc_attr( __( 'Register', 'apptook-digital-store' ) ); ?>" />
				</form>

					<p class="apptook-st-modal-meta apptook-st-register-footer">
						<a href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( '← Back to Marketplace', 'apptook-digital-store' ); ?></a>
					</p>
				</div>
			</div>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
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
		<div class="apptook-stitch apptook-ds">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'register' );
			?>
			<div class="apptook-st-register">
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
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

	private function is_support_request_path(): bool {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = $request_uri !== '' ? (string) wp_parse_url( $request_uri, PHP_URL_PATH ) : '';
		if ( $path === '' ) {
			return false;
		}
		$path = trim( $path, '/' );
		if ( $path === 'support' ) {
			return true;
		}
		return (bool) preg_match( '#/support$#', $path );
	}

	public function maybe_render_support_route(): void {
		if ( is_admin() ) {
			return;
		}

		$is_support_route = get_query_var( 'apptook_ds_support', '' ) === '1' || is_page( 'support' ) || $this->is_support_request_path();

		if ( ! $is_support_route ) {
			return;
		}

		status_header( 200 );
		nocache_headers();

		echo '<!doctype html><html ' . get_language_attributes() . '><head>';
		echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html__( 'Support', 'apptook-digital-store' ) . '</title>';
		wp_head();
		$body_classes = array( 'apptook-support-route' );
		if ( is_admin_bar_showing() ) {
			$body_classes[] = 'admin-bar';
		}
		echo '<body class="' . esc_attr( implode( ' ', $body_classes ) ) . '">';
		wp_body_open();
		echo $this->shortcode_support();
		wp_footer();
		echo '</body></html>';
		exit;
	}

	private function get_order_history_page_url(): string {
		$page_id = (int) get_option( 'apptook_ds_page_order_history_id', 0 );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		$page = get_page_by_path( 'my-order-history', OBJECT, 'page' );
		if ( $page instanceof WP_Post && $page->post_status === 'publish' ) {
			$url = get_permalink( $page );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		return add_query_arg( 'apptook_tab', 'history', $this->get_page_url_by_option( 'apptook_ds_page_library_id' ) );
	}

	private function get_support_page_url(): string {
		$page_id = (int) get_option( 'apptook_ds_page_support_id', 0 );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		$page = get_page_by_path( 'support', OBJECT, 'page' );
		if ( $page instanceof WP_Post && $page->post_status === 'publish' ) {
			$url = get_permalink( $page );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}

		return home_url( '/support/' );
	}

	/**
	 * @return list<array{buyer:string,product:string,time_ago:string}>
	 */
	private function get_buyer_ticker_items(): array {
		$query = new WP_Query(
			array(
				'post_type'        => 'apptook_order',
				'post_status'      => 'publish',
				'posts_per_page'   => 10,
				'suppress_filters' => true,
				'meta_query'       => array(
					array(
						'key'   => '_apptook_status',
						'value' => Apptook_DS_Post_Types::ORDER_PAID,
					),
				),
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		$items = array();

		if ( $query instanceof WP_Query && $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$order_id = get_the_ID();
				$product_id = (int) get_post_meta( $order_id, '_apptook_product_id', true );
				$product_name = $product_id > 0 ? get_the_title( $product_id ) : __( 'Unknown Product', 'apptook-digital-store' );
				$customer_id = (int) get_post_meta( $order_id, '_apptook_customer_id', true );
				$buyer = __( 'guest***', 'apptook-digital-store' );
				if ( $customer_id > 0 ) {
					$user = get_userdata( $customer_id );
					if ( $user instanceof WP_User ) {
						$username = trim( (string) $user->user_login );
						if ( $username === '' && is_string( $user->display_name ) ) {
							$username = trim( (string) $user->display_name );
						}
						if ( $username === '' && is_string( $user->user_email ) ) {
							$email = trim( strtolower( $user->user_email ) );
							$parts = explode( '@', $email );
							$username = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
						}
						if ( $username !== '' ) {
							$local_mask = strlen( $username ) > 5 ? substr( $username, 0, 5 ) : $username;
							$buyer = $local_mask . '***';
						}
					}
				}

				$diff = max( 60, (int) ( current_time( 'timestamp', true ) - get_post_time( 'U', true, $order_id ) ) );
				$minutes = (int) floor( $diff / 60 );
				$hours = (int) floor( $diff / 3600 );
				$days = (int) floor( $diff / 86400 );
				if ( $minutes < 60 ) {
					$time_ago = sprintf( __( 'ซื้อเมื่อ %d นาทีที่แล้ว', 'apptook-digital-store' ), $minutes );
				} elseif ( $hours < 24 ) {
					$time_ago = sprintf( __( 'ซื้อเมื่อ %d ชั่วโมงที่แล้ว', 'apptook-digital-store' ), $hours );
				} else {
					$time_ago = sprintf( __( 'ซื้อเมื่อ %d วันที่แล้ว', 'apptook-digital-store' ), $days );
				}

				$items[] = array(
					'buyer' => $buyer,
					'product' => is_string( $product_name ) ? $product_name : __( 'Unknown Product', 'apptook-digital-store' ),
					'time_ago' => $time_ago,
				);
			}
			wp_reset_postdata();
		}

		if ( $items === array() ) {
			return array(
				array(
					'buyer' => 'mikuscz1***',
					'product' => 'Cursor AI',
					'time_ago' => __( 'ซื้อเมื่อ 1 นาทีที่แล้ว', 'apptook-digital-store' ),
				),
				array(
					'buyer' => 'kokostu***',
					'product' => 'Windsurf',
					'time_ago' => __( 'ซื้อเมื่อ 1 ชั่วโมงที่แล้ว', 'apptook-digital-store' ),
				),
				array(
					'buyer' => 'ais***',
					'product' => 'ChatGPT Plus',
					'time_ago' => __( 'ซื้อเมื่อ 1 วันที่แล้ว', 'apptook-digital-store' ),
				),
			);
		}

		return $items;
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

		$buyer_inline_items = $this->get_product_buyer_ticker_items( $post_id );

		$bullets = $this->get_product_bullets( $post_id );
		$purchase_options = $this->get_purchase_options_for_product( $post_id, $price_f );
		$durations_json = wp_json_encode( $purchase_options['durations'] );
		$types_json = wp_json_encode( $purchase_options['types'] );
		$type_enabled = ! empty( $purchase_options['type_enabled'] ) ? '1' : '0';
		$duration_enabled = ! empty( $purchase_options['duration_enabled'] ) ? '1' : '0';

		$display_price_number = $price_f;
		if ( ! empty( $purchase_options['durations'] ) && is_array( $purchase_options['durations'] ) ) {
			$default_duration = null;
			foreach ( $purchase_options['durations'] as $duration_row ) {
				if ( ! empty( $duration_row['is_default'] ) ) {
					$default_duration = $duration_row;
					break;
				}
			}
			if ( ! is_array( $default_duration ) ) {
				$default_duration = $purchase_options['durations'][0] ?? null;
			}
			if ( is_array( $default_duration ) && isset( $default_duration['price'] ) ) {
				$display_price_number = (float) $default_duration['price'];
			}
		}

		$price_display = '฿' . number_format( $display_price_number, 2 );

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
				<?php if ( $buyer_inline_items !== array() ) : ?>
					<div class="st-card-price-buyer-wrap" aria-label="recent buyers">
						<div class="st-card-price-buyer-track" role="presentation" data-card-buyer-track>
							<?php foreach ( $buyer_inline_items as $buyer_idx => $buyer_row ) : ?>
								<span class="st-card-price-buyer-item<?php echo $buyer_idx === 0 ? ' is-active' : ''; ?>">
									<span class="material-symbols-outlined" aria-hidden="true">account_circle</span>
									<strong><?php echo esc_html( (string) ( $buyer_row['buyer'] ?? 'guest***' ) ); ?></strong>
									<em><?php echo esc_html( (string) ( $buyer_row['time_ago'] ?? '' ) ); ?></em>
								</span>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
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
					<button
						type="button"
						class="buy-btn apptook-ds-buy"
						data-product-id="<?php echo esc_attr( (string) $post_id ); ?>"
						data-durations="<?php echo esc_attr( is_string( $durations_json ) ? $durations_json : '[]' ); ?>"
						data-types="<?php echo esc_attr( is_string( $types_json ) ? $types_json : '[]' ); ?>"
						data-type-enabled="<?php echo esc_attr( $type_enabled ); ?>"
						data-duration-enabled="<?php echo esc_attr( $duration_enabled ); ?>"
					>
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
	/**
	 * @return list<array{buyer:string,time_ago:string}>
	 */
	private function get_product_buyer_ticker_items( int $product_id ): array {
		$query = new WP_Query(
			array(
				'post_type'        => 'apptook_order',
				'post_status'      => 'publish',
				'posts_per_page'   => 20,
				'suppress_filters' => true,
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => '_apptook_status',
						'value' => Apptook_DS_Post_Types::ORDER_PAID,
					),
					array(
						'key'   => '_apptook_product_id',
						'value' => $product_id,
					),
				),
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		$items = array();
		$seen_buyers = array();

		if ( $query instanceof WP_Query && $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$order_id = get_the_ID();
				$customer_id = (int) get_post_meta( $order_id, '_apptook_customer_id', true );
				if ( $customer_id <= 0 ) {
					continue;
				}

				$user = get_userdata( $customer_id );
				if ( ! $user instanceof WP_User ) {
					continue;
				}

				$username = trim( (string) $user->user_login );
				if ( $username === '' && is_string( $user->display_name ) ) {
					$username = trim( (string) $user->display_name );
				}
				if ( $username === '' && is_string( $user->user_email ) ) {
					$email = trim( strtolower( $user->user_email ) );
					$parts = explode( '@', $email );
					$username = isset( $parts[0] ) ? trim( (string) $parts[0] ) : '';
				}
				if ( $username === '' ) {
					continue;
				}

				$masked = strlen( $username ) > 5 ? substr( $username, 0, 5 ) . '***' : $username . '***';
				if ( isset( $seen_buyers[ $masked ] ) ) {
					continue;
				}
				$seen_buyers[ $masked ] = true;

				$diff = max( 60, (int) ( current_time( 'timestamp', true ) - get_post_time( 'U', true, $order_id ) ) );
				$minutes = (int) floor( $diff / 60 );
				$hours = (int) floor( $diff / 3600 );
				$days = (int) floor( $diff / 86400 );
				if ( $minutes < 60 ) {
					$time_ago = sprintf( __( 'ซื้อเมื่อ %d นาทีที่แล้ว', 'apptook-digital-store' ), $minutes );
				} elseif ( $hours < 24 ) {
					$time_ago = sprintf( __( 'ซื้อเมื่อ %d ชั่วโมงที่แล้ว', 'apptook-digital-store' ), $hours );
				} else {
					$time_ago = sprintf( __( 'ซื้อเมื่อ %d วันที่แล้ว', 'apptook-digital-store' ), $days );
				}

				$items[] = array(
					'buyer' => $masked,
					'time_ago' => $time_ago,
				);
			}
			wp_reset_postdata();
		}

		return $items;
	}

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

	private function get_purchase_options_for_product( int $post_id, float $fallback_price ): array {
		$durations = array();
		$types = array();
		$type_enabled = (string) get_post_meta( $post_id, '_apptook_type_enabled', true ) === '1';
		$duration_enabled = (string) get_post_meta( $post_id, '_apptook_duration_enabled', true ) !== '0';

		if ( $duration_enabled ) {
			$raw_durations = get_post_meta( $post_id, '_apptook_duration_rows', true );
			if ( is_string( $raw_durations ) && trim( $raw_durations ) !== '' ) {
				$lines = preg_split( "/\r\n|\n|\r/", $raw_durations );
				if ( is_array( $lines ) ) {
					foreach ( $lines as $line ) {
						$line = trim( (string) $line );
						if ( $line === '' ) {
							continue;
						}
						$parts = array_map( 'trim', explode( '|', $line ) );
						$months = isset( $parts[0] ) ? max( 1, (int) $parts[0] ) : 1;
						$price = isset( $parts[1] ) && $parts[1] !== '' ? (float) $parts[1] : $fallback_price;
						$is_default = isset( $parts[2] ) && (int) $parts[2] === 1 ? 1 : 0;
						$durations[] = array(
							'months' => $months,
							'price' => $price,
							'is_default' => $is_default,
						);
					}
				}
			}
		}

		if ( $type_enabled ) {
			$raw_types = get_post_meta( $post_id, '_apptook_type_rows', true );
			if ( is_string( $raw_types ) && trim( $raw_types ) !== '' ) {
				$lines = preg_split( "/\r\n|\n|\r/", $raw_types );
				if ( is_array( $lines ) ) {
					foreach ( $lines as $line ) {
						$line = trim( (string) $line );
						if ( $line === '' ) {
							continue;
						}
						$parts = array_map( 'trim', explode( '|', $line ) );
						if ( count( $parts ) === 1 ) {
							$key = sanitize_title( $parts[0] );
							$label = sanitize_text_field( $parts[0] );
							$modifier = 0.0;
							$is_default = 0;
						} else {
							$key = isset( $parts[0] ) ? sanitize_title( $parts[0] ) : '';
							$label = isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
							$modifier = isset( $parts[2] ) && $parts[2] !== '' ? (float) $parts[2] : 0.0;
							$is_default = isset( $parts[3] ) && (int) $parts[3] === 1 ? 1 : 0;
						}
						if ( $key === '' || $label === '' ) {
							continue;
						}
						$types[] = array(
							'type_key'       => $key,
							'type_label'     => $label,
							'price_modifier' => $modifier,
							'is_default'     => $is_default,
						);
					}
				}
			}
		}

		if ( ( $duration_enabled && $durations === array() ) || ( $type_enabled && $types === array() ) ) {
			if ( class_exists( 'Apptook_DS_External_DB' ) && Apptook_DS_External_DB::instance()->is_configured() ) {
				$data = Apptook_DS_External_DB::instance()->get_product_purchase_options( $post_id );
				if ( $duration_enabled && $durations === array() && ! empty( $data['durations'] ) && is_array( $data['durations'] ) ) {
					$durations = array_values( $data['durations'] );
				}
				if ( $type_enabled && $types === array() && ! empty( $data['types'] ) && is_array( $data['types'] ) ) {
					$types = array_values( $data['types'] );
				}
			}
		}

		if ( $duration_enabled && $durations !== array() ) {
			$has_default = false;
			foreach ( $durations as $row ) {
				if ( ! empty( $row['is_default'] ) ) {
					$has_default = true;
					break;
				}
			}
			if ( ! $has_default && isset( $durations[0] ) ) {
				$durations[0]['is_default'] = 1;
			}
		}

		if ( $type_enabled && $types !== array() ) {
			$has_default = false;
			foreach ( $types as $row ) {
				if ( ! empty( $row['is_default'] ) ) {
					$has_default = true;
					break;
				}
			}
			if ( ! $has_default && isset( $types[0] ) ) {
				$types[0]['is_default'] = 1;
			}
		}

		return array(
			'durations' => $duration_enabled ? $durations : array(),
			'types' => $type_enabled ? $types : array(),
			'type_enabled' => $type_enabled,
			'duration_enabled' => $duration_enabled,
		);
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
		<div class="apptook-stitch apptook-ds">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'marketplace' );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function shortcode_blog(): string {
		$posts = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 12,
				'ignore_sticky_posts' => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
			)
		);

		ob_start();
		?>
		<div class="apptook-stitch apptook-ds apptook-blog-page">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'blog' );
			?>
			<main class="apptook-blog-main">
				<section class="apptook-blog-wrap" aria-labelledby="apptook-blog-heading">
					<header class="apptook-blog-head">
						<h1 id="apptook-blog-heading"><?php esc_html_e( 'Blog', 'apptook-digital-store' ); ?></h1>
						<p><?php esc_html_e( 'อัปเดตข่าวสาร บทความ และเทคนิคการใช้งานล่าสุดจากทีมงาน', 'apptook-digital-store' ); ?></p>
					</header>

					<?php if ( ! $posts->have_posts() ) : ?>
						<div class="apptook-blog-empty"><?php esc_html_e( 'ยังไม่มีบทความในขณะนี้', 'apptook-digital-store' ); ?></div>
					<?php else : ?>
						<div class="apptook-blog-grid">
							<?php while ( $posts->have_posts() ) : $posts->the_post(); ?>
								<?php
								$post_id = get_the_ID();
								$title = get_the_title( $post_id );
								$permalink = get_permalink( $post_id );
								$date_text = get_the_date( 'j M Y', $post_id );
								$excerpt = get_the_excerpt( $post_id );
								if ( ! is_string( $excerpt ) || trim( $excerpt ) === '' ) {
									$excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 24, '…' );
								}
								?>
								<article class="apptook-blog-card">
									<a class="apptook-blog-thumb-link" href="<?php echo esc_url( $permalink ); ?>">
										<?php if ( has_post_thumbnail( $post_id ) ) : ?>
											<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'class' => 'apptook-blog-thumb', 'loading' => 'lazy' ) ); ?>
										<?php else : ?>
											<div class="apptook-blog-thumb is-fallback"><span class="material-symbols-outlined" aria-hidden="true">article</span></div>
										<?php endif; ?>
									</a>
									<div class="apptook-blog-content">
										<p class="apptook-blog-date"><?php echo esc_html( $date_text ); ?></p>
										<h2><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h2>
										<p class="apptook-blog-excerpt"><?php echo esc_html( $excerpt ); ?></p>
										<a class="apptook-blog-readmore" href="<?php echo esc_url( $permalink ); ?>"><?php esc_html_e( 'คลิกเพื่ออ่านเพิ่มเติม', 'apptook-digital-store' ); ?></a>
									</div>
								</article>
							<?php endwhile; ?>
						</div>
						<?php wp_reset_postdata(); ?>
					<?php endif; ?>
				</section>
			</main>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function shortcode_support(): string {
		$opts = get_option( 'apptook_ds_options', array() );
		$line_support_url = isset( $opts['line_support_url'] ) ? esc_url( (string) $opts['line_support_url'] ) : '';
		if ( $line_support_url === '' ) {
			$line_support_url = 'https://line.me/';
		}

		ob_start();
		?>
		<div class="apptook-stitch apptook-ds apptook-support-page">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'support' );
			?>
			<main class="apptook-support-main" aria-labelledby="apptook-support-heading">
				<section class="apptook-support-wrap">
					<div class="apptook-support-grid">
						<article class="apptook-support-card apptook-support-card--primary">
							<p class="apptook-support-kicker">SUPPORT CENTER</p>
							<h1 id="apptook-support-heading"><?php esc_html_e( 'ติดต่อทีมซัพพอร์ตผ่าน LINE', 'apptook-digital-store' ); ?></h1>
							<p class="apptook-support-lead"><?php esc_html_e( 'หากพบปัญหาการใช้งาน สามารถติดต่อทีมงานผ่าน LINE ได้ทันที เพื่อรับการช่วยเหลือแบบรวดเร็ว', 'apptook-digital-store' ); ?></p>
							<div class="apptook-support-meta-grid">
								<div class="apptook-support-meta-box">
									<span><?php esc_html_e( 'เวลาทำการ', 'apptook-digital-store' ); ?></span>
									<strong><?php esc_html_e( 'ทุกวัน 09:00 - 22:00 น.', 'apptook-digital-store' ); ?></strong>
								</div>
								<div class="apptook-support-meta-box">
									<span><?php esc_html_e( 'เวลาตอบกลับเฉลี่ย', 'apptook-digital-store' ); ?></span>
									<strong><?php esc_html_e( 'ภายใน 5-15 นาที', 'apptook-digital-store' ); ?></strong>
								</div>
							</div>
							<a class="apptook-support-line-btn" href="<?php echo esc_url( $line_support_url ); ?>" target="_blank" rel="noopener noreferrer">
								<span class="material-symbols-outlined" aria-hidden="true">chat</span>
								<?php esc_html_e( 'แชทผ่าน LINE Official', 'apptook-digital-store' ); ?>
							</a>
						</article>
						<div class="apptook-support-side">
							<article class="apptook-support-card">
								<h2><?php esc_html_e( 'ก่อนติดต่อแนะนำให้เตรียม', 'apptook-digital-store' ); ?></h2>
								<ul class="apptook-support-checklist">
									<li><?php esc_html_e( 'ชื่อสินค้าที่สั่งซื้อ', 'apptook-digital-store' ); ?></li>
									<li><?php esc_html_e( 'อีเมลหรือชื่อผู้ใช้ที่สั่งซื้อ', 'apptook-digital-store' ); ?></li>
									<li><?php esc_html_e( 'ภาพหน้าจอปัญหาที่พบ (ถ้ามี)', 'apptook-digital-store' ); ?></li>
								</ul>
							</article>
							<article class="apptook-support-card">
								<h2><?php esc_html_e( 'คำถามที่พบบ่อย', 'apptook-digital-store' ); ?></h2>
								<div class="apptook-support-faq-tags">
									<span><?php esc_html_e( 'เข้าใช้งานไม่ได้', 'apptook-digital-store' ); ?></span>
									<span><?php esc_html_e( 'การต่ออายุ', 'apptook-digital-store' ); ?></span>
									<span><?php esc_html_e( 'เปลี่ยนแพ็กเกจ', 'apptook-digital-store' ); ?></span>
									<span><?php esc_html_e( 'ตรวจสอบออเดอร์', 'apptook-digital-store' ); ?></span>
								</div>
							</article>
						</div>
					</div>
				</section>
			</main>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function shortcode_order_history(): string {
		$shop_url     = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$register_url = $this->get_register_page_url();
		$login_url    = add_query_arg( 'apptook_open_login', '1', $shop_url );

		if ( ! is_user_logged_in() ) {
			ob_start();
			?>
			<div class="apptook-stitch apptook-ds">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_global_menuheader( 'marketplace' );
				?>
				<main class="apptook-order-history apptook-order-history--guest" aria-labelledby="apptook-order-history-heading">
					<section class="apptook-order-history__container">
						<header class="apptook-order-history__head">
							<h1 id="apptook-order-history-heading" class="apptook-order-history__title"><?php esc_html_e( 'Order History', 'apptook-digital-store' ); ?></h1>
							<p class="apptook-order-history__subtitle"><?php esc_html_e( 'ติดตามออเดอร์ล่าสุดและสถานะการชำระเงินของคุณ', 'apptook-digital-store' ); ?></p>
						</header>
						<div class="apptook-order-history__panel" role="status" aria-live="polite">
							<h2><?php esc_html_e( 'กรุณาเข้าสู่ระบบก่อน', 'apptook-digital-store' ); ?></h2>
							<p><?php esc_html_e( 'เข้าสู่ระบบเพื่อดูประวัติการสั่งซื้อของบัญชีนี้', 'apptook-digital-store' ); ?></p>
							<div class="apptook-order-history__actions">
								<a class="apptook-st-submit apptook-order-history__btn" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'apptook-digital-store' ); ?></a>
								<?php if ( get_option( 'users_can_register' ) ) : ?>
									<a class="apptook-st-btn-secondary apptook-order-history__btn-secondary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Sign Up', 'apptook-digital-store' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					</section>
				</main>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_global_footer();
				?>
			</div>
			<?php
			return (string) ob_get_clean();
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
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		$rows = array();
		$load_error = false;

		if ( $orders instanceof WP_Query ) {
			if ( $orders->have_posts() ) {
				while ( $orders->have_posts() ) {
					$orders->the_post();
					$order_id    = get_the_ID();
					$product_id  = (int) get_post_meta( $order_id, '_apptook_product_id', true );
					$status_key  = (string) get_post_meta( $order_id, '_apptook_status', true );
					$amount      = (float) get_post_meta( $order_id, '_apptook_amount', true );
					$created_at  = get_the_date( 'd/m/Y H:i', $order_id );
					$created_date_iso = get_post_time( 'Y-m-d', false, $order_id );
					$expires_at_raw = (string) get_post_meta( $order_id, '_apptook_expire_at', true );
					$expires_ts = $expires_at_raw !== '' ? strtotime( $expires_at_raw ) : false;
					if ( ! is_int( $expires_ts ) || $expires_ts <= 0 ) {
						$expires_ts = strtotime( '+30 days', (int) get_post_time( 'U', true, $order_id ) );
					}
					$expires_at = is_int( $expires_ts ) && $expires_ts > 0 ? gmdate( 'd/m/Y', $expires_ts ) : '-';
					$product_name = $product_id > 0 ? get_the_title( $product_id ) : '';
					if ( ! is_string( $product_name ) || trim( $product_name ) === '' ) {
						$fallback_name = get_post_meta( $order_id, '_apptook_product_name', true );
						if ( is_string( $fallback_name ) && trim( $fallback_name ) !== '' ) {
							$product_name = $fallback_name;
						} else {
							$order_title = get_the_title( $order_id );
							$product_name = is_string( $order_title ) && trim( $order_title ) !== '' ? $order_title : __( 'Unknown Product', 'apptook-digital-store' );
						}
					}

					$ui_state = 'pending';
					if ( $status_key === Apptook_DS_Post_Types::ORDER_PAID ) {
						$ui_state = 'paid';
					} elseif ( $status_key === Apptook_DS_Post_Types::ORDER_REJECTED ) {
						$ui_state = 'cancelled';
					}

					$rows[] = array(
						'order_id' => $order_id,
						'product_name' => is_string( $product_name ) ? $product_name : __( 'Unknown Product', 'apptook-digital-store' ),
						'status_label' => Apptook_DS_Post_Types::get_order_status_label( $status_key ),
						'ui_state' => $ui_state,
						'amount' => $amount,
						'amount_text' => sprintf( '฿%s', number_format_i18n( $amount, 2 ) ),
						'created_at' => is_string( $created_at ) ? $created_at : '',
						'created_date_iso' => is_string( $created_date_iso ) ? $created_date_iso : '',
						'expires_at' => is_string( $expires_at ) ? $expires_at : '-',
					);

				}
				wp_reset_postdata();
			}
		} else {
			$load_error = true;
		}

		ob_start();
		?>
		<div class="apptook-stitch apptook-ds">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'marketplace' );
			?>
			<main class="apptook-order-history" aria-labelledby="apptook-order-history-heading">
				<section class="apptook-order-history__container">
					<header class="apptook-order-history__head apptook-order-history__head--with-action">
						<div>
							<h1 id="apptook-order-history-heading" class="apptook-order-history__title"><?php esc_html_e( 'Order History', 'apptook-digital-store' ); ?></h1>
							<p class="apptook-order-history__subtitle"><?php esc_html_e( 'รายการออเดอร์ทั้งหมดของบัญชีนี้ พร้อมสถานะล่าสุดแบบเรียลไทม์', 'apptook-digital-store' ); ?></p>
						</div>
						<a class="apptook-ds-pd-back" href="<?php echo esc_url( $shop_url ); ?>">
							<?php esc_html_e( 'กลับไปหน้า Marketplace', 'apptook-digital-store' ); ?>
							<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
						</a>
					</header>

					<?php if ( $load_error ) : ?>
						<div class="apptook-order-history__panel apptook-order-history__panel--error" role="alert">
							<h2><?php esc_html_e( 'เกิดข้อผิดพลาดในการโหลดข้อมูล', 'apptook-digital-store' ); ?></h2>
							<p><?php esc_html_e( 'กรุณารีเฟรชหน้าใหม่ หรือลองอีกครั้งในภายหลัง', 'apptook-digital-store' ); ?></p>
						</div>
					<?php elseif ( $rows === array() ) : ?>
						<div class="apptook-order-history__panel apptook-order-history__panel--empty" role="status">
							<h2><?php esc_html_e( 'ยังไม่มีประวัติการสั่งซื้อ', 'apptook-digital-store' ); ?></h2>
							<p><?php esc_html_e( 'เมื่อคุณทำรายการสั่งซื้อ รายการจะปรากฏที่หน้านี้ทันที', 'apptook-digital-store' ); ?></p>
							<a class="apptook-st-submit apptook-order-history__btn" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'ไปที่ Marketplace', 'apptook-digital-store' ); ?></a>
						</div>
					<?php else : ?>
						<div class="apptook-order-history__panel apptook-order-history__panel--filter">
							<h3><?php esc_html_e( 'เลือกช่วงวันที่ที่ต้องการดู', 'apptook-digital-store' ); ?></h3>
							<div class="apptook-order-history__filters">
								<div>
									<label for="apptook-order-filter-start"><?php esc_html_e( 'วันที่เริ่มต้น', 'apptook-digital-store' ); ?></label>
									<input id="apptook-order-filter-start" class="apptook-order-history__date-input" type="date" />
								</div>
								<div>
									<label for="apptook-order-filter-end"><?php esc_html_e( 'วันที่สิ้นสุด', 'apptook-digital-store' ); ?></label>
									<input id="apptook-order-filter-end" class="apptook-order-history__date-input" type="date" />
								</div>
							</div>
						</div>

						<div class="apptook-order-history__panel">
							<div class="apptook-order-history__table-wrap" role="region" aria-label="<?php esc_attr_e( 'ตารางประวัติการสั่งซื้อ', 'apptook-digital-store' ); ?>">
								<table class="apptook-order-history__table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'รหัสออเดอร์', 'apptook-digital-store' ); ?></th>
											<th><?php esc_html_e( 'สินค้า', 'apptook-digital-store' ); ?></th>
											<th><?php esc_html_e( 'ราคา', 'apptook-digital-store' ); ?></th>
											<th><?php esc_html_e( 'สถานะ', 'apptook-digital-store' ); ?></th>
											<th><?php esc_html_e( 'วันที่ซื้อ', 'apptook-digital-store' ); ?></th>
											<th><?php esc_html_e( 'วันหมดอายุ', 'apptook-digital-store' ); ?></th>
										</tr>
									</thead>
									<tbody id="apptook-order-history-tbody">
										<?php foreach ( $rows as $row ) : ?>
											<tr data-order-date="<?php echo esc_attr( (string) $row['created_date_iso'] ); ?>" data-order-amount="<?php echo esc_attr( (string) $row['amount'] ); ?>">
												<td>#<?php echo esc_html( (string) $row['order_id'] ); ?></td>
												<td><?php echo esc_html( (string) $row['product_name'] ); ?></td>
												<td><?php echo esc_html( (string) $row['amount_text'] ); ?></td>
												<td><span class="apptook-order-history__badge is-<?php echo esc_attr( (string) $row['ui_state'] ); ?>"><?php echo esc_html( (string) $row['status_label'] ); ?></span></td>
												<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
												<td><?php echo esc_html( (string) $row['expires_at'] ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<p id="apptook-order-history-empty" class="apptook-order-history__empty" hidden><?php esc_html_e( 'ไม่พบรายการตามช่วงวันที่ที่เลือก', 'apptook-digital-store' ); ?></p>
							<div class="apptook-order-history__summary-grid">
								<div class="apptook-order-history__summary-box">
									<p><?php esc_html_e( 'จำนวนรายการที่พบ', 'apptook-digital-store' ); ?></p>
									<strong id="apptook-order-history-total-items">0 <?php esc_html_e( 'รายการ', 'apptook-digital-store' ); ?></strong>
								</div>
								<div class="apptook-order-history__summary-box apptook-order-history__summary-box--highlight">
									<p><?php esc_html_e( 'ยอดรวมตามช่วงวันที่', 'apptook-digital-store' ); ?></p>
									<strong id="apptook-order-history-total-amount">฿0.00</strong>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</section>
			</main>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function shortcode_library(): string {
		$shop_url     = $this->get_page_url_by_option( 'apptook_ds_page_shop_id' );
		$register_url = $this->get_register_page_url();
		$login_url    = add_query_arg( 'apptook_open_login', '1', $shop_url );

		if ( ! is_user_logged_in() ) {
			ob_start();
			?>
			<div class="apptook-stitch apptook-ds">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_global_menuheader( 'marketplace' );
				?>
				<main class="apptook-my-subscription apptook-my-subscription--guest" aria-labelledby="apptook-my-sub-heading">
					<section class="apptook-my-subscription__container">
						<header class="apptook-my-subscription__head apptook-my-subscription__head--with-action">
							<div class="apptook-my-subscription__head-main">
								<h1 id="apptook-my-sub-heading" class="apptook-my-subscription__title"><?php esc_html_e( 'My Subscription', 'apptook-digital-store' ); ?></h1>
								<p class="apptook-my-subscription__subtitle"><?php esc_html_e( 'ตรวจสอบสถานะ subscription ของคุณหลังเข้าสู่ระบบ', 'apptook-digital-store' ); ?></p>
							</div>
							<a class="apptook-ds-pd-back" href="<?php echo esc_url( $shop_url ); ?>" aria-label="<?php esc_attr_e( 'กลับไปหน้า Marketplace', 'apptook-digital-store' ); ?>">
								<?php esc_html_e( 'กลับไปหน้า Marketplace', 'apptook-digital-store' ); ?>
								<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
							</a>
						</header>
						<div class="apptook-my-subscription__panel" role="status" aria-live="polite">
							<h2><?php esc_html_e( 'กรุณาเข้าสู่ระบบก่อน', 'apptook-digital-store' ); ?></h2>
							<p><?php esc_html_e( 'เข้าสู่ระบบเพื่อดูรายการ subscription และสถานะล่าสุดของคุณ', 'apptook-digital-store' ); ?></p>
							<div class="apptook-my-subscription__guest-actions">
								<a class="apptook-st-submit apptook-my-subscription__btn" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'apptook-digital-store' ); ?></a>
								<?php if ( get_option( 'users_can_register' ) ) : ?>
									<a class="apptook-st-btn-secondary apptook-my-subscription__btn-secondary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Sign Up', 'apptook-digital-store' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
					</section>
				</main>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->render_global_footer();
				?>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$user_id = get_current_user_id();
		$orders  = new WP_Query(
			array(
				'post_type'        => 'apptook_order',
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'suppress_filters' => true,
				'meta_query'       => array(
					array(
						'key'   => '_apptook_customer_id',
						'value' => $user_id,
					),
				),
				'orderby'          => 'date',
				'order'            => 'DESC',
			)
		);

		$total_orders = 0;
		$active_count = 0;
		$inactive_count = 0;
		$cancelled_count = 0;
		$total_amount = 0.0;
		$items = array();
		$load_error = false;

		if ( $orders instanceof WP_Query ) {
			if ( $orders->have_posts() ) {
				while ( $orders->have_posts() ) {
					$orders->the_post();
					$oid = get_the_ID();
					$product_id = (int) get_post_meta( $oid, '_apptook_product_id', true );
					$status = (string) get_post_meta( $oid, '_apptook_status', true );
					$amount = (float) get_post_meta( $oid, '_apptook_amount', true );
					$key = (string) get_post_meta( $oid, '_apptook_license_key', true );
					$expires_at_raw = (string) get_post_meta( $oid, '_apptook_expire_at', true );
					$expires_ts = $expires_at_raw !== '' ? strtotime( $expires_at_raw ) : false;
					if ( ! is_int( $expires_ts ) || $expires_ts <= 0 ) {
						$expires_ts = strtotime( '+30 days', (int) get_post_time( 'U', true, $oid ) );
					}
					$days_left = max( 0, (int) floor( ( (int) $expires_ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) );

					$ui_state = 'inactive';
					$ui_state_label = __( 'Inactive', 'apptook-digital-store' );
					$ui_state_icon = 'schedule';

					if ( $status === Apptook_DS_Post_Types::ORDER_PAID ) {
						$ui_state = 'active';
						$ui_state_label = __( 'Active', 'apptook-digital-store' );
						$ui_state_icon = 'verified';
						$active_count++;
					} elseif ( $status === Apptook_DS_Post_Types::ORDER_REJECTED ) {
						$ui_state = 'cancelled';
						$ui_state_label = __( 'Cancelled', 'apptook-digital-store' );
						$ui_state_icon = 'cancel';
						$cancelled_count++;
					} else {
						$inactive_count++;
					}

					$total_orders++;
					$total_amount += $amount;

					$thumb_url = '';
					if ( $product_id > 0 && has_post_thumbnail( $product_id ) ) {
						$thumb = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
						$thumb_url = is_string( $thumb ) ? $thumb : '';
					}

					$items[] = array(
						'order_id' => $oid,
						'product_title' => $product_id > 0 ? get_the_title( $product_id ) : __( 'Unknown Product', 'apptook-digital-store' ),
						'product_thumb_url' => $thumb_url,
						'ui_state' => $ui_state,
						'ui_state_label' => $ui_state_label,
						'ui_state_icon' => $ui_state_icon,
						'amount_text' => sprintf( '฿%s', number_format_i18n( $amount, 2 ) ),
						'license_key' => $key,
						'instruction_url' => $product_id > 0 ? get_permalink( $product_id ) : '',
						'expires_ts' => (int) $expires_ts,
						'days_left' => $days_left,
					);
				}
				wp_reset_postdata();
			}
		} else {
			$load_error = true;
		}

		ob_start();
		?>
		<div class="apptook-stitch apptook-ds">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_menuheader( 'marketplace' );
			?>
			<main class="apptook-my-subscription" aria-labelledby="apptook-my-sub-heading">
				<section class="apptook-my-subscription__container">
					<header class="apptook-my-subscription__head apptook-my-subscription__head--with-action">
						<div class="apptook-my-subscription__head-main">
							<h1 id="apptook-my-sub-heading" class="apptook-my-subscription__title"><?php esc_html_e( 'My Subscription', 'apptook-digital-store' ); ?></h1>
							<p class="apptook-my-subscription__subtitle"><?php esc_html_e( 'ตรวจสอบแพ็กเกจที่คุณสั่งซื้อและสถานะการใช้งานล่าสุด', 'apptook-digital-store' ); ?></p>
						</div>
						<a class="apptook-ds-pd-back" href="<?php echo esc_url( $shop_url ); ?>" aria-label="<?php esc_attr_e( 'กลับไปหน้า Marketplace', 'apptook-digital-store' ); ?>">
							<?php esc_html_e( 'กลับไปหน้า Marketplace', 'apptook-digital-store' ); ?>
							<span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
						</a>
					</header>

					<?php if ( $load_error ) : ?>
						<div class="apptook-my-subscription__panel apptook-my-subscription__panel--error" role="alert">
							<h2><?php esc_html_e( 'เกิดข้อผิดพลาดในการโหลดข้อมูล', 'apptook-digital-store' ); ?></h2>
							<p><?php esc_html_e( 'กรุณารีเฟรชหน้าใหม่ หรือลองอีกครั้งในภายหลัง', 'apptook-digital-store' ); ?></p>
						</div>
					<?php elseif ( $total_orders === 0 ) : ?>
						<div class="apptook-my-subscription__panel apptook-my-subscription__panel--empty" role="status">
							<h2><?php esc_html_e( 'ยังไม่มี Subscription', 'apptook-digital-store' ); ?></h2>
							<p><?php esc_html_e( 'เมื่อคุณสั่งซื้อสำเร็จ รายการจะแสดงที่หน้านี้อัตโนมัติ', 'apptook-digital-store' ); ?></p>
							<a class="apptook-st-submit apptook-my-subscription__btn" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'ไปที่ Marketplace', 'apptook-digital-store' ); ?></a>
						</div>
					<?php else : ?>
						<div class="apptook-my-subscription__layout">
							<div class="apptook-my-subscription__panel">
								<div class="apptook-my-subscription__panel-head">
									<h2><?php esc_html_e( 'รายการ Subscription ของคุณ', 'apptook-digital-store' ); ?></h2>
									<span class="apptook-my-subscription__count-pill">
										<span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
										<?php echo esc_html( sprintf( _n( '%d Product', '%d Products', (int) $total_orders, 'apptook-digital-store' ), (int) $total_orders ) ); ?>
									</span>
								</div>
								<div class="apptook-my-subscription__list">
									<?php foreach ( $items as $item ) : ?>
										<?php
										$alert_state = 'safe';
										if ( (int) $item['days_left'] <= 1 ) {
											$alert_state = 'danger';
										} elseif ( (int) $item['days_left'] < 7 ) {
											$alert_state = 'warning';
										}
										?>
										<article class="apptook-my-subscription__item" data-order-id="<?php echo esc_attr( (string) $item['order_id'] ); ?>">
											<div class="apptook-my-subscription__item-head">
												<div class="apptook-my-subscription__item-head-main">
													<?php if ( (string) $item['product_thumb_url'] !== '' ) : ?>
														<img class="apptook-my-subscription__product-thumb" src="<?php echo esc_url( (string) $item['product_thumb_url'] ); ?>" alt="<?php echo esc_attr( (string) $item['product_title'] ); ?>" loading="lazy" decoding="async" />
													<?php endif; ?>
													<div>
														<h3><?php echo esc_html( (string) $item['product_title'] ); ?></h3>
														<p><?php echo esc_html( sprintf( __( 'Order #%d', 'apptook-digital-store' ), (int) $item['order_id'] ) ); ?></p>
													</div>
												</div>
												<span class="apptook-my-subscription__badge is-<?php echo esc_attr( (string) $item['ui_state'] ); ?>" data-apptook-sub-badge>
													<span class="material-symbols-outlined" aria-hidden="true" data-apptook-sub-badge-icon><?php echo esc_html( (string) $item['ui_state_icon'] ); ?></span>
													<span data-apptook-sub-badge-text><?php echo esc_html( (string) $item['ui_state_label'] ); ?></span>
												</span>
											</div>
											<div class="apptook-my-subscription__item-grid">
												<div class="apptook-my-subscription__meta-card"><p class="apptook-my-subscription__meta-label"><?php esc_html_e( 'ราคา', 'apptook-digital-store' ); ?></p><p class="apptook-my-subscription__meta-value"><?php echo esc_html( (string) $item['amount_text'] ); ?></p></div>
												<div class="apptook-my-subscription__meta-card"><p class="apptook-my-subscription__meta-label"><?php esc_html_e( 'วันหมดอายุโดยประมาณ', 'apptook-digital-store' ); ?></p><p class="apptook-my-subscription__meta-value"><?php echo esc_html( gmdate( 'd/m/Y', (int) $item['expires_ts'] ) ); ?></p></div>
												<div class="apptook-my-subscription__meta-card"><p class="apptook-my-subscription__meta-label"><?php esc_html_e( 'แจ้งเตือน', 'apptook-digital-store' ); ?></p><p class="apptook-my-subscription__meta-value apptook-my-subscription__meta-value--alert is-<?php echo esc_attr( $alert_state ); ?>" data-apptook-sub-alert><?php echo esc_html( sprintf( __( 'เหลือ %d วัน', 'apptook-digital-store' ), (int) $item['days_left'] ) ); ?></p></div>
											</div>
											<?php if ( (string) $item['license_key'] !== '' ) : ?>
												<div class="apptook-my-subscription__license-row">
													<p class="apptook-my-subscription__license"><span><?php esc_html_e( 'License Key:', 'apptook-digital-store' ); ?></span> <code><?php echo esc_html( (string) $item['license_key'] ); ?></code></p>
													<?php if ( ! empty( $item['instruction_url'] ) ) : ?>
														<a class="apptook-my-subscription__howto-btn" href="<?php echo esc_url( (string) $item['instruction_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'วิธีการใช้งาน', 'apptook-digital-store' ); ?></a>
													<?php endif; ?>
												</div>
											<?php endif; ?>
										</article>
									<?php endforeach; ?>
								</div>
							</div>
							<aside class="apptook-my-subscription__panel apptook-my-subscription__panel--summary">
								<h2><?php esc_html_e( 'ภาพรวมบัญชี', 'apptook-digital-store' ); ?></h2>
								<ul class="apptook-my-subscription__summary">
									<li><span><?php esc_html_e( 'แพ็กเกจทั้งหมด', 'apptook-digital-store' ); ?></span><strong><?php echo esc_html( sprintf( __( '%d รายการ', 'apptook-digital-store' ), $total_orders ) ); ?></strong></li>
									<li><span><?php esc_html_e( 'Active', 'apptook-digital-store' ); ?></span><strong class="is-active"><?php echo esc_html( sprintf( __( '%d รายการ', 'apptook-digital-store' ), $active_count ) ); ?></strong></li>
									<li><span><?php esc_html_e( 'Inactive', 'apptook-digital-store' ); ?></span><strong class="is-inactive"><?php echo esc_html( sprintf( __( '%d รายการ', 'apptook-digital-store' ), $inactive_count ) ); ?></strong></li>
									<li><span><?php esc_html_e( 'Cancelled', 'apptook-digital-store' ); ?></span><strong class="is-cancelled"><?php echo esc_html( sprintf( __( '%d รายการ', 'apptook-digital-store' ), $cancelled_count ) ); ?></strong></li>
									<li><span><?php esc_html_e( 'ยอดรวมทั้งหมด', 'apptook-digital-store' ); ?></span><strong><?php echo esc_html( sprintf( '฿%s', number_format_i18n( $total_amount, 2 ) ) ); ?></strong></li>
								</ul>
							</aside>
						</div>
					<?php endif; ?>
				</section>
			</main>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->render_global_footer();
			?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
