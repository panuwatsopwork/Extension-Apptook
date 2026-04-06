<?php
/**
 * Admin UI, product meta, order actions, settings.
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Apptook_DS_Admin {

	private static ?self $instance = null;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
		add_action('save_post_apptook_product', array($this, 'save_product_meta'), 10, 2);
		add_action('apptook_product_cat_add_form_fields', array($this, 'product_cat_add_icon_field'));
		add_action('apptook_product_cat_edit_form_fields', array($this, 'product_cat_edit_icon_field'), 10, 2);
		add_action('created_apptook_product_cat', array($this, 'save_product_cat_icon_meta'));
		add_action('edited_apptook_product_cat', array($this, 'save_product_cat_icon_meta'));
		add_filter('manage_apptook_order_posts_columns', array($this, 'order_columns'));
		add_action('manage_apptook_order_posts_custom_column', array($this, 'order_column_content'), 10, 2);
		add_filter('post_row_actions', array($this, 'order_row_actions'), 10, 2);
		add_action('admin_action_apptook_ds_approve_order', array($this, 'handle_approve_order'));
		add_action('admin_action_apptook_ds_reject_order', array($this, 'handle_reject_order'));
		add_action('admin_menu', array($this, 'register_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
	}

	public function enqueue_admin_styles( $hook ): void {
		if (strpos($hook, 'apptook_product') === false && strpos($hook, 'apptook_order') === false && $hook !== 'apptook-digital-store_page_apptook-ds-settings') {
			return;
		}
		wp_enqueue_style(
			'apptook-ds-admin',
			APPTOOK_DS_URL . 'assets/css/admin.css',
			array(),
			APPTOOK_DS_VERSION
		);
	}

	public function register_settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=apptook_product',
			__('ตั้งค่าร้าน', 'apptook-digital-store'),
			__('ตั้งค่าร้าน', 'apptook-digital-store'),
			'manage_options',
			'apptook-ds-settings',
			array($this, 'render_settings_page')
		);
	}

	public function register_settings(): void {
		register_setting(
			'apptook_ds_settings',
			'apptook_ds_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this, 'sanitize_options'),
				'default'           => array(),
			)
		);

		add_settings_section(
			'apptook_ds_main',
			__('พร้อมเพย์ / QR (ทดสอบ)', 'apptook-digital-store'),
			static function (): void {
				echo '<p>' . esc_html__('รอบแรกใช้แสดงยอดและรูป QR จาก URL — ค่อยต่อกับสร้าง QR จริงทีหลัง', 'apptook-digital-store') . '</p>';
			},
			'apptook-ds-settings'
		);

		add_settings_field(
			'promptpay_id',
			__('เลขพร้อมเพย์ / โทรศัพท์ (แสดงในหน้าจ่ายเงิน)', 'apptook-digital-store'),
			array($this, 'field_text'),
			'apptook-ds-settings',
			'apptook_ds_main',
			array('key' => 'promptpay_id')
		);

		add_settings_field(
			'qr_image_url',
			__('URL รูป QR (ถ้ามี)', 'apptook-digital-store'),
			array($this, 'field_text'),
			'apptook-ds-settings',
			'apptook_ds_main',
			array('key' => 'qr_image_url')
		);

		add_settings_field(
			'payment_note',
			__('ข้อความใต้ QR', 'apptook-digital-store'),
			array($this, 'field_textarea'),
			'apptook-ds-settings',
			'apptook_ds_main',
			array('key' => 'payment_note')
		);

		add_settings_section(
			'apptook_ds_marketplace',
			__('หน้า Marketplace (หน้าแรก)', 'apptook-digital-store'),
			static function (): void {
				echo '<p>' . esc_html__('ข้อความหัวข้อและช่องค้นหา — ใช้กับช็อตโค้ด [apptook_shop] หรือ [apptook_marketplace]', 'apptook-digital-store') . '</p>';
			},
			'apptook-ds-settings'
		);

		add_settings_field(
			'mkt_title',
			__('หัวข้อใหญ่ (Hero)', 'apptook-digital-store'),
			array($this, 'field_text'),
			'apptook-ds-settings',
			'apptook_ds_marketplace',
			array('key' => 'mkt_title')
		);

		add_settings_field(
			'mkt_subtitle',
			__('หัวข้อรอง (ถ้ามี)', 'apptook-digital-store'),
			array($this, 'field_textarea'),
			'apptook-ds-settings',
			'apptook_ds_marketplace',
			array('key' => 'mkt_subtitle')
		);

		add_settings_field(
			'mkt_search_placeholder',
			__('Placeholder ช่องค้นหา', 'apptook-digital-store'),
			array($this, 'field_text'),
			'apptook-ds-settings',
			'apptook_ds_marketplace',
			array('key' => 'mkt_search_placeholder')
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public function sanitize_options(array $input): array {
		$out = array();
		$out['promptpay_id'] = isset($input['promptpay_id']) ? sanitize_text_field((string) $input['promptpay_id']) : '';
		$out['qr_image_url'] = isset($input['qr_image_url']) ? esc_url_raw((string) $input['qr_image_url']) : '';
		$out['payment_note'] = isset($input['payment_note']) ? sanitize_textarea_field((string) $input['payment_note']) : '';
		$out['mkt_title']             = isset($input['mkt_title']) ? sanitize_text_field((string) $input['mkt_title']) : '';
		$out['mkt_subtitle']          = isset($input['mkt_subtitle']) ? sanitize_textarea_field((string) $input['mkt_subtitle']) : '';
		$out['mkt_search_placeholder'] = isset($input['mkt_search_placeholder']) ? sanitize_text_field((string) $input['mkt_search_placeholder']) : '';
		return $out;
	}

	public function field_text(array $args): void {
		$opts = get_option('apptook_ds_options', array());
		$key  = (string) $args['key'];
		$val  = isset($opts[ $key ]) ? (string) $opts[ $key ] : '';
		printf(
			'<input type="text" class="regular-text" name="apptook_ds_options[%1$s]" id="apptook_ds_%1$s" value="%2$s" />',
			esc_attr($key),
			esc_attr($val)
		);
	}

	public function field_textarea(array $args): void {
		$opts = get_option('apptook_ds_options', array());
		$key  = (string) $args['key'];
		$val  = isset($opts[ $key ]) ? (string) $opts[ $key ] : '';
		printf(
			'<textarea class="large-text" rows="3" name="apptook_ds_options[%1$s]" id="apptook_ds_%1$s">%2$s</textarea>',
			esc_attr($key),
			esc_textarea($val)
		);
	}

	public function render_settings_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('ตั้งค่า Apptook Digital Store', 'apptook-digital-store'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('apptook_ds_settings');
				do_settings_sections('apptook-ds-settings');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function add_meta_boxes(): void {
		add_meta_box(
			'apptook_product_details',
			__('ราคา & คีย์ (คลัง)', 'apptook-digital-store'),
			array($this, 'render_product_meta_box'),
			'apptook_product',
			'normal',
			'high'
		);

		add_meta_box(
			'apptook_order_details',
			__('รายละเอียดออเดอร์', 'apptook-digital-store'),
			array($this, 'render_order_meta_box'),
			'apptook_order',
			'normal',
			'high'
		);
	}

	public function render_product_meta_box(WP_Post $post): void {
		wp_nonce_field('apptook_ds_save_product', 'apptook_ds_product_nonce');
		$price   = get_post_meta($post->ID, '_apptook_price', true);
		$keys    = get_post_meta($post->ID, '_apptook_key_pool', true);
		$bullets = get_post_meta($post->ID, '_apptook_bullets', true);
		$badge   = get_post_meta($post->ID, '_apptook_badge', true);
		$badge_style = get_post_meta($post->ID, '_apptook_badge_style', true);
		$period  = get_post_meta($post->ID, '_apptook_period', true);
		?>
		<p>
			<label for="apptook_price"><strong><?php esc_html_e('ราคา (บาท)', 'apptook-digital-store'); ?></strong></label><br />
			<input type="number" step="0.01" min="0" name="apptook_price" id="apptook_price" value="<?php echo esc_attr((string) $price); ?>" class="regular-text" />
		</p>
		<p>
			<label for="apptook_period"><strong><?php esc_html_e('หน่วยราคา (แสดงบนการ์ด)', 'apptook-digital-store'); ?></strong></label><br />
			<input type="text" name="apptook_period" id="apptook_period" value="<?php echo esc_attr(is_string($period) ? $period : ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('/ เดือน', 'apptook-digital-store'); ?>" />
		</p>
		<p>
			<label for="apptook_badge"><strong><?php esc_html_e('แถบป้ายมุมขวาบน (ถ้ามี)', 'apptook-digital-store'); ?></strong></label><br />
			<input type="text" name="apptook_badge" id="apptook_badge" value="<?php echo esc_attr(is_string($badge) ? $badge : ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('เช่น 4K Ultra HD', 'apptook-digital-store'); ?>" />
		</p>
		<p>
			<label for="apptook_badge_style"><strong><?php esc_html_e('สไตล์ป้าย (ตามดีไซน์ stitch)', 'apptook-digital-store'); ?></strong></label><br />
			<select name="apptook_badge_style" id="apptook_badge_style">
				<option value="" <?php selected($badge_style, ''); ?>><?php esc_html_e('มิ้นต์ (ค่าเริ่มต้น)', 'apptook-digital-store'); ?></option>
				<option value="green" <?php selected($badge_style, 'green'); ?>><?php esc_html_e('เขียว (แบบ ChatGPT ใน stitch)', 'apptook-digital-store'); ?></option>
			</select>
		</p>
		<p>
			<label for="apptook_bullets"><strong><?php esc_html_e('จุดเด่น (หนึ่งบรรทัดต่อหนึ่งข้อ — แสดงบนการ์ด)', 'apptook-digital-store'); ?></strong></label><br />
			<textarea name="apptook_bullets" id="apptook_bullets" rows="5" class="large-text"><?php echo esc_textarea(is_string($bullets) ? $bullets : ''); ?></textarea>
		</p>
		<p class="description"><?php esc_html_e('ถ้าว่าง จะลองใช้บรรทัดจากคำโปรย (Excerpt) แทน', 'apptook-digital-store'); ?></p>
		<p>
			<label for="apptook_key_pool"><strong><?php esc_html_e('คีย์คงเหลือ (หนึ่งบรรทัดต่อหนึ่งคีย์)', 'apptook-digital-store'); ?></strong></label><br />
			<textarea name="apptook_key_pool" id="apptook_key_pool" rows="8" class="large-text code"><?php echo esc_textarea(is_string($keys) ? $keys : ''); ?></textarea>
		</p>
		<?php
	}

	public function product_cat_add_icon_field(): void {
		?>
		<div class="form-field term-group">
			<label for="apptook_icon"><?php esc_html_e('ไอคอน Material Symbols', 'apptook-digital-store'); ?></label>
			<input type="text" id="apptook_icon" name="apptook_icon" value="" placeholder="movie" />
			<p class="description"><?php esc_html_e('เว้นว่างให้ใช้ค่าเริ่มต้นตาม slug (film→movie, music→music_note ฯลฯ)', 'apptook-digital-store'); ?></p>
		</div>
		<?php
	}

	public function product_cat_edit_icon_field(WP_Term $term): void {
		$icon = get_term_meta($term->term_id, 'apptook_icon', true);
		?>
		<tr class="form-field term-group-wrap">
			<th scope="row"><label for="apptook_icon"><?php esc_html_e('ไอคอน Material Symbols', 'apptook-digital-store'); ?></label></th>
			<td>
				<input type="text" id="apptook_icon" name="apptook_icon" value="<?php echo esc_attr(is_string($icon) ? $icon : ''); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e('เช่น movie, music_note, psychology, terminal, grade', 'apptook-digital-store'); ?></p>
			</td>
		</tr>
		<?php
	}

	public function save_product_cat_icon_meta( $term_id ): void {
		$term_id = (int) $term_id;
		if (! isset($_POST['apptook_icon'])) {
			return;
		}
		$icon = sanitize_text_field(wp_unslash((string) $_POST['apptook_icon']));
		update_term_meta($term_id, 'apptook_icon', $icon);
	}

	public function render_order_meta_box(WP_Post $post): void {
		$product_id = (int) get_post_meta($post->ID, '_apptook_product_id', true);
		$user_id    = (int) get_post_meta($post->ID, '_apptook_customer_id', true);
		$amount     = get_post_meta($post->ID, '_apptook_amount', true);
		$status     = (string) get_post_meta($post->ID, '_apptook_status', true);
		$slip_id    = (int) get_post_meta($post->ID, '_apptook_slip_id', true);
		$key        = get_post_meta($post->ID, '_apptook_license_key', true);
		$user       = $user_id ? get_userdata($user_id) : null;
		$product    = $product_id ? get_post($product_id) : null;
		?>
		<table class="form-table apptook-ds-order-meta">
			<tr>
				<th><?php esc_html_e('สถานะ', 'apptook-digital-store'); ?></th>
				<td><strong><?php echo esc_html(Apptook_DS_Post_Types::get_order_status_label($status)); ?></strong></td>
			</tr>
			<tr>
				<th><?php esc_html_e('ลูกค้า', 'apptook-digital-store'); ?></th>
				<td>
					<?php
					if ($user) {
						echo esc_html($user->user_login . ' (' . $user->user_email . ')');
					} else {
						echo '—';
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e('สินค้า', 'apptook-digital-store'); ?></th>
				<td><?php echo $product ? esc_html(get_the_title($product)) : '—'; ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e('ยอด', 'apptook-digital-store'); ?></th>
				<td><?php echo esc_html((string) $amount); ?> <?php esc_html_e('บาท', 'apptook-digital-store'); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e('สลิป', 'apptook-digital-store'); ?></th>
				<td>
					<?php
					if ($slip_id) {
						echo wp_get_attachment_link($slip_id, 'full', false, true);
					} else {
						esc_html_e('ยังไม่อัปโหลด', 'apptook-digital-store');
					}
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e('คีย์ที่มอบให้', 'apptook-digital-store'); ?></th>
				<td><code><?php echo $key ? esc_html((string) $key) : '—'; ?></code></td>
			</tr>
		</table>
		<?php
		if ($status === Apptook_DS_Post_Types::ORDER_PENDING_REVIEW && current_user_can('edit_post', $post->ID)) {
			$approve = wp_nonce_url(
				admin_url('admin.php?action=apptook_ds_approve_order&order_id=' . $post->ID),
				'apptook_ds_approve_' . $post->ID
			);
			$reject = wp_nonce_url(
				admin_url('admin.php?action=apptook_ds_reject_order&order_id=' . $post->ID),
				'apptook_ds_reject_' . $post->ID
			);
			echo '<p class="apptook-ds-order-actions">';
			echo '<a href="' . esc_url($approve) . '" class="button button-primary">' . esc_html__('ยืนยันการโอน', 'apptook-digital-store') . '</a> ';
			echo '<a href="' . esc_url($reject) . '" class="button">' . esc_html__('ปฏิเสธ', 'apptook-digital-store') . '</a>';
			echo '</p>';
		}
	}

	public function save_product_meta( $post_id, WP_Post $post ): void {
		$post_id = (int) $post_id;
		if (! isset($_POST['apptook_ds_product_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apptook_ds_product_nonce'])), 'apptook_ds_save_product')) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$price   = isset($_POST['apptook_price']) ? sanitize_text_field(wp_unslash($_POST['apptook_price'])) : '0';
		$keys    = isset($_POST['apptook_key_pool']) ? sanitize_textarea_field(wp_unslash($_POST['apptook_key_pool'])) : '';
		$bullets = isset($_POST['apptook_bullets']) ? sanitize_textarea_field(wp_unslash($_POST['apptook_bullets'])) : '';
		$badge   = isset($_POST['apptook_badge']) ? sanitize_text_field(wp_unslash($_POST['apptook_badge'])) : '';
		$badge_style = isset($_POST['apptook_badge_style']) && $_POST['apptook_badge_style'] === 'green' ? 'green' : '';
		$period  = isset($_POST['apptook_period']) ? sanitize_text_field(wp_unslash($_POST['apptook_period'])) : '';

		update_post_meta($post_id, '_apptook_price', $price);
		update_post_meta($post_id, '_apptook_key_pool', $keys);
		update_post_meta($post_id, '_apptook_bullets', $bullets);
		update_post_meta($post_id, '_apptook_badge', $badge);
		update_post_meta($post_id, '_apptook_badge_style', $badge_style);
		update_post_meta($post_id, '_apptook_period', $period);
	}

	public function order_columns(array $columns): array {
		$new = array();
		foreach ($columns as $key => $label) {
			$new[ $key ] = $label;
			if ($key === 'title') {
				$new['apptook_status'] = __('สถานะ', 'apptook-digital-store');
				$new['apptook_customer'] = __('ลูกค้า', 'apptook-digital-store');
				$new['apptook_amount'] = __('ยอด', 'apptook-digital-store');
			}
		}
		return $new;
	}

	public function order_column_content( $column, $post_id ): void {
		$post_id = (int) $post_id;
		if ($column === 'apptook_status') {
			$s = (string) get_post_meta($post_id, '_apptook_status', true);
			echo esc_html(Apptook_DS_Post_Types::get_order_status_label($s));
		} elseif ($column === 'apptook_customer') {
			$uid = (int) get_post_meta($post_id, '_apptook_customer_id', true);
			$u   = $uid ? get_userdata($uid) : null;
			echo $u ? esc_html($u->user_login) : '—';
		} elseif ($column === 'apptook_amount') {
			echo esc_html((string) get_post_meta($post_id, '_apptook_amount', true));
		}
	}

	public function order_row_actions(array $actions, WP_Post $post): array {
		if ($post->post_type !== 'apptook_order') {
			return $actions;
		}
		$status = (string) get_post_meta($post->ID, '_apptook_status', true);
		if ($status === Apptook_DS_Post_Types::ORDER_PENDING_REVIEW && current_user_can('edit_post', $post->ID)) {
			$actions['apptook_approve'] = '<a href="' . esc_url(
				wp_nonce_url(
					admin_url('admin.php?action=apptook_ds_approve_order&order_id=' . $post->ID),
					'apptook_ds_approve_' . $post->ID
				)
			) . '">' . esc_html__('ยืนยันการโอน', 'apptook-digital-store') . '</a>';
			$actions['apptook_reject'] = '<a href="' . esc_url(
				wp_nonce_url(
					admin_url('admin.php?action=apptook_ds_reject_order&order_id=' . $post->ID),
					'apptook_ds_reject_' . $post->ID
				)
			) . '">' . esc_html__('ปฏิเสธ', 'apptook-digital-store') . '</a>';
		}
		return $actions;
	}

	public function handle_approve_order(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('ไม่มีสิทธิ์', 'apptook-digital-store'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		if (! $order_id || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'apptook_ds_approve_' . $order_id)) {
			wp_die(esc_html__('ลิงก์ไม่ถูกต้อง', 'apptook-digital-store'));
		}

		$post = get_post($order_id);
		if (! $post || $post->post_type !== 'apptook_order') {
			wp_die(esc_html__('ไม่พบออเดอร์', 'apptook-digital-store'));
		}

		$status = (string) get_post_meta($order_id, '_apptook_status', true);
		if ($status !== Apptook_DS_Post_Types::ORDER_PENDING_REVIEW) {
			wp_safe_redirect(get_edit_post_link($order_id, 'raw'));
			exit;
		}

		$product_id = (int) get_post_meta($order_id, '_apptook_product_id', true);
		$pool       = get_post_meta($product_id, '_apptook_key_pool', true);
		$lines      = is_string($pool) ? preg_split("/\r\n|\n|\r/", $pool) : array();
		$lines      = array_values(array_filter(array_map('trim', $lines)));

		$key = array_shift($lines);
		if ($key === null || $key === '') {
			$key = __('(ไม่มีคีย์ในคลัง — กรุณาเติมคีย์ที่สินค้า)', 'apptook-digital-store');
		} else {
			update_post_meta($product_id, '_apptook_key_pool', implode("\n", $lines));
		}

		update_post_meta($order_id, '_apptook_license_key', $key);
		update_post_meta($order_id, '_apptook_status', Apptook_DS_Post_Types::ORDER_PAID);

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->upsert_order_from_wp((int) $order_id);
			Apptook_DS_External_DB::instance()->add_order_log((int) $order_id, 'order_approved', Apptook_DS_Post_Types::ORDER_PENDING_REVIEW, Apptook_DS_Post_Types::ORDER_PAID);
		}

		wp_safe_redirect(get_edit_post_link($order_id, 'raw'));
		exit;
	}

	public function handle_reject_order(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('ไม่มีสิทธิ์', 'apptook-digital-store'));
		}
		$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
		if (! $order_id || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'apptook_ds_reject_' . $order_id)) {
			wp_die(esc_html__('ลิงก์ไม่ถูกต้อง', 'apptook-digital-store'));
		}

		$post = get_post($order_id);
		if (! $post || $post->post_type !== 'apptook_order') {
			wp_die(esc_html__('ไม่พบออเดอร์', 'apptook-digital-store'));
		}

		$old_status = (string) get_post_meta($order_id, '_apptook_status', true);
		update_post_meta($order_id, '_apptook_status', Apptook_DS_Post_Types::ORDER_REJECTED);

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->upsert_order_from_wp((int) $order_id);
			Apptook_DS_External_DB::instance()->add_order_log((int) $order_id, 'order_rejected', $old_status, Apptook_DS_Post_Types::ORDER_REJECTED);
		}

		wp_safe_redirect(get_edit_post_link($order_id, 'raw'));
		exit;
	}

}
