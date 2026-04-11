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

	/**
	 * Keep save notices across save + redirect filter in same request.
	 *
	 * @var array<int,string>
	 */
	private array $product_save_notices = array();

	private bool $product_save_has_errors = false;

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
		add_action('admin_action_apptook_ds_setup_external_db', array($this, 'handle_setup_external_db'));
		add_action('admin_post_apptook_ds_save_product_editor', array($this, 'handle_save_product_editor'));
		add_action('admin_menu', array($this, 'register_settings_page'));
		add_action('admin_menu', array($this, 'register_product_editor_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
		add_filter('admin_body_class', array($this, 'filter_admin_body_class'));
		add_action('admin_menu', array($this, 'hide_product_editor_submenu'), 99);
		add_filter('post_row_actions', array($this, 'replace_product_edit_row_action'), 20, 2);
		add_filter('page_row_actions', array($this, 'replace_product_edit_row_action'), 20, 2);
		add_action('admin_init', array($this, 'redirect_product_add_new_to_custom_editor'));
		add_action('admin_init', array($this, 'redirect_product_edit_to_custom_editor'));
		add_action('load-post.php', array($this, 'redirect_product_edit_to_custom_editor'));
		add_filter('redirect_post_location', array($this, 'append_product_save_messages'), 10, 2);
		add_action('admin_notices', array($this, 'render_product_save_notices'));
		add_filter('manage_apptook_product_posts_columns', array($this, 'product_columns'));
		add_action('manage_apptook_product_posts_custom_column', array($this, 'product_column_content'), 10, 2);
		add_action('quick_edit_custom_box', array($this, 'render_product_quick_edit'), 10, 2);
		add_filter('bulk_actions-edit-apptook_product', array($this, 'register_product_bulk_actions'));
		add_filter('handle_bulk_actions-edit-apptook_product', array($this, 'handle_product_bulk_actions'), 10, 3);
		add_action('restrict_manage_posts', array($this, 'render_product_status_filter'));
		add_action('pre_get_posts', array($this, 'filter_products_by_status'));
	}

	public function enqueue_admin_styles( $hook ): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_product_screen = $screen && $screen->post_type === 'apptook_product';
		$is_order_screen = $screen && $screen->post_type === 'apptook_order';
		$is_settings_screen = $hook === 'apptook-digital-store_page_apptook-ds-settings';
		$is_editor_screen = $hook === 'apptook_product_page_apptook-ds-product-editor';

		if (! $is_product_screen && ! $is_order_screen && ! $is_settings_screen && ! $is_editor_screen) {
			return;
		}

		$admin_css_path = APPTOOK_DS_PATH . 'assets/css/admin.css';
		$admin_css_ver = APPTOOK_DS_VERSION . '.' . (file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : '1');
		wp_enqueue_style(
			'apptook-ds-admin',
			APPTOOK_DS_URL . 'assets/css/admin.css',
			array(),
			$admin_css_ver
		);

		if ($is_editor_screen) {
			wp_enqueue_media();
		}

		$admin_js_path = APPTOOK_DS_PATH . 'assets/js/admin.js';
		$admin_js_ver = APPTOOK_DS_VERSION . '.' . (file_exists($admin_js_path) ? (string) filemtime($admin_js_path) : '1');
		wp_enqueue_script(
			'apptook-ds-admin',
			APPTOOK_DS_URL . 'assets/js/admin.js',
			array('jquery'),
			$admin_js_ver,
			true
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

	public function register_product_editor_page(): void {
		add_submenu_page(
			'edit.php?post_type=apptook_product',
			__('Custom Product Editor', 'apptook-digital-store'),
			__('Custom Product Editor', 'apptook-digital-store'),
			'edit_posts',
			'apptook-ds-product-editor',
			array($this, 'render_product_editor_page')
		);
	}

	public function filter_admin_body_class(string $classes): string {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && $screen->id === 'apptook_product_page_apptook-ds-product-editor') {
			return trim($classes . ' apptook-ds-editor-page');
		}
		return $classes;
	}

	public function hide_product_editor_submenu(): void {
		remove_submenu_page('edit.php?post_type=apptook_product', 'apptook-ds-product-editor');
	}

	public function redirect_product_add_new_to_custom_editor(): void {
		if (! is_admin()) {
			return;
		}

		global $pagenow;
		if ($pagenow !== 'post-new.php') {
			return;
		}

		$post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : 'post';
		if ($post_type !== 'apptook_product') {
			return;
		}

		if (! current_user_can('edit_posts')) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'apptook_product',
					'page' => 'apptook-ds-product-editor',
				),
				admin_url('edit.php')
			)
		);
		exit;
	}

	public function redirect_product_edit_to_custom_editor(): void {
		if (! is_admin()) {
			return;
		}

		global $pagenow;
		if ($pagenow !== 'post.php') {
			return;
		}

		$action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
		if ($action !== 'edit') {
			return;
		}

		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		if ($post_id <= 0) {
			return;
		}

		$post = get_post($post_id);
		if (! $post || $post->post_type !== 'apptook_product') {
			return;
		}

		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		$target = add_query_arg(
			array(
				'post_type' => 'apptook_product',
				'page' => 'apptook-ds-product-editor',
				'product_id' => $post_id,
			),
			admin_url('edit.php')
		);

		$current_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		if ($current_uri !== '' && strpos($current_uri, 'page=apptook-ds-product-editor') !== false) {
			return;
		}

		wp_safe_redirect($target);
		exit;
	}

	public function replace_product_edit_row_action(array $actions, WP_Post $post): array {
		if ($post->post_type !== 'apptook_product') {
			return $actions;
		}

		if (isset($actions['edit'])) {
			$actions['edit'] = '<a href="' . esc_url(
				add_query_arg(
					array(
						'post_type' => 'apptook_product',
						'page' => 'apptook-ds-product-editor',
						'product_id' => (int) $post->ID,
					),
					admin_url('edit.php')
				)
			) . '">' . esc_html__('แก้ไข', 'apptook-digital-store') . '</a>';
		}

		return $actions;
	}

	public function render_product_editor_page(): void {
		if (! current_user_can('edit_posts')) {
			wp_die(esc_html__('ไม่มีสิทธิ์', 'apptook-digital-store'));
		}

		$product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
		$post = $product_id ? get_post($product_id) : null;
		if ($post && $post->post_type !== 'apptook_product') {
			$post = null;
			$product_id = 0;
		}

		$title = $post ? $post->post_title : '';
		$slug = $post ? $post->post_name : '';
		$excerpt = $post ? $post->post_excerpt : '';
		$content = $post ? $post->post_content : '';
		$price = $post ? (string) get_post_meta($product_id, '_apptook_price', true) : '';
		$sale_price = $post ? (string) get_post_meta($product_id, '_apptook_sale_price', true) : '';
		$product_status = $post ? (string) get_post_meta($product_id, '_apptook_product_status', true) : 'active';
		$product_status = $product_status === 'inactive' ? 'inactive' : 'active';
		$period = $post ? (string) get_post_meta($product_id, '_apptook_period', true) : '/ เดือน';
		$badge = $post ? (string) get_post_meta($product_id, '_apptook_badge', true) : '';
		$badge_style = $post ? (string) get_post_meta($product_id, '_apptook_badge_style', true) : '';
		$bullets = $post ? (string) get_post_meta($product_id, '_apptook_bullets', true) : '';
		$keys = $post ? (string) get_post_meta($product_id, '_apptook_key_pool', true) : '';
		$type_enabled = $post ? ((string) get_post_meta($product_id, '_apptook_type_enabled', true) === '1') : true;
		$duration_enabled = $post ? ((string) get_post_meta($product_id, '_apptook_duration_enabled', true) !== '0') : true;
		$durations_text = $post ? (string) get_post_meta($product_id, '_apptook_duration_rows', true) : "1|0|1\n3|0|0\n6|0|0\n12|0|0";
		$types_text = $post ? (string) get_post_meta($product_id, '_apptook_type_rows', true) : "shared|1 profile Shared|0|1\nprivate|Private Account (Full ownership)|0|0";
		$term_ids = $post ? wp_get_post_terms($product_id, 'apptook_product_cat', array('fields' => 'ids')) : array();
		$tag_names = $post ? wp_get_post_terms($product_id, 'post_tag', array('fields' => 'names')) : array();
		$thumbnail_id = $post ? (int) get_post_thumbnail_id($product_id) : 0;
		$thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : '';
		$categories = get_terms(array('taxonomy' => 'apptook_product_cat', 'hide_empty' => false));
		?>
		<div class="wrap apptook-ds-custom-editor-wrap">
			<div class="apptook-ds-custom-editor-header">
				<div>
					<p class="apptook-ds-kicker">APPTOOK Admin</p>
					<h1><?php echo esc_html($post ? __('แก้ไขสินค้า Marketplace', 'apptook-digital-store') : __('เพิ่มสินค้า Marketplace', 'apptook-digital-store')); ?></h1>
				</div>
				<div class="apptook-ds-header-actions">
					<button type="submit" form="apptook-ds-product-editor-form" class="button button-primary button-large"><?php esc_html_e('บันทึกสินค้า', 'apptook-digital-store'); ?></button>
				</div>
			</div>

			<form id="apptook-ds-product-editor-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="apptook-ds-custom-editor-layout">
				<input type="hidden" name="action" value="apptook_ds_save_product_editor" />
				<input type="hidden" name="product_id" value="<?php echo esc_attr((string) $product_id); ?>" />
				<?php wp_nonce_field('apptook_ds_save_product_editor', 'apptook_ds_product_editor_nonce'); ?>

				<div class="apptook-ds-main-col">
					<div class="apptook-ds-admin-section">
						<h3><?php esc_html_e('ข้อมูลหลักสินค้า', 'apptook-digital-store'); ?></h3>
						<div class="apptook-ds-admin-grid">
							<div class="apptook-ds-admin-field"><label for="apptook_editor_title"><strong><?php esc_html_e('ชื่อสินค้า', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_title" name="post_title" type="text" class="regular-text" value="<?php echo esc_attr($title); ?>" required /></div>
							<div class="apptook-ds-admin-field"><label for="apptook_editor_slug"><strong><?php esc_html_e('Slug', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_slug" name="post_name" type="text" class="regular-text" value="<?php echo esc_attr($slug); ?>" /></div>
							<div class="apptook-ds-admin-field"><label for="apptook_editor_price"><strong><?php esc_html_e('ราคาปกติ', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_price" name="apptook_price" type="number" min="0" step="0.01" class="regular-text" value="<?php echo esc_attr($price); ?>" /></div>
							<div class="apptook-ds-admin-field"><label for="apptook_editor_sale_price"><strong><?php esc_html_e('ราคาโปร', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_sale_price" name="apptook_sale_price" type="number" min="0" step="0.01" class="regular-text" value="<?php echo esc_attr($sale_price); ?>" /></div>
							<div class="apptook-ds-admin-field"><label for="apptook_editor_status"><strong><?php esc_html_e('สถานะสินค้า', 'apptook-digital-store'); ?></strong></label><select id="apptook_editor_status" name="apptook_product_status"><option value="active" <?php selected($product_status, 'active'); ?>><?php esc_html_e('Active', 'apptook-digital-store'); ?></option><option value="inactive" <?php selected($product_status, 'inactive'); ?>><?php esc_html_e('Inactive', 'apptook-digital-store'); ?></option></select></div>
							<div class="apptook-ds-admin-field"><label for="apptook_editor_period"><strong><?php esc_html_e('หน่วยราคา', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_period" name="apptook_period" type="text" class="regular-text" value="<?php echo esc_attr($period); ?>" /></div>
						</div>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_excerpt"><strong><?php esc_html_e('คำอธิบายสั้น', 'apptook-digital-store'); ?></strong></label><textarea id="apptook_editor_excerpt" name="post_excerpt" rows="3" class="large-text"><?php echo esc_textarea($excerpt); ?></textarea></div>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_content"><strong><?php esc_html_e('รายละเอียด', 'apptook-digital-store'); ?></strong></label><textarea id="apptook_editor_content" name="post_content" rows="7" class="large-text"><?php echo esc_textarea($content); ?></textarea></div>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_bullets"><strong><?php esc_html_e('จุดเด่นสินค้า (1 บรรทัด = 1 ข้อ)', 'apptook-digital-store'); ?></strong></label><textarea id="apptook_editor_bullets" name="apptook_bullets" rows="4" class="large-text"><?php echo esc_textarea($bullets); ?></textarea></div>
					</div>

					<div class="apptook-ds-admin-section">
						<h3><?php esc_html_e('ตัวเลือกการซื้อใน Popup', 'apptook-digital-store'); ?></h3>
						<div class="apptook-ds-admin-field apptook-ds-admin-switches">
							<label><input type="checkbox" name="apptook_duration_enabled" value="1" <?php checked($duration_enabled); ?> /> <strong><?php esc_html_e('เปิดใช้งาน Duration', 'apptook-digital-store'); ?></strong></label>
							<label><input type="checkbox" name="apptook_type_enabled" value="1" <?php checked($type_enabled); ?> /> <strong><?php esc_html_e('เปิดใช้งาน Type', 'apptook-digital-store'); ?></strong></label>
						</div>
						<div class="apptook-ds-admin-field">
							<div class="apptook-ds-table-builder" data-builder="duration">
								<div class="apptook-ds-table apptook-ds-table-head"><div><?php esc_html_e('เดือน', 'apptook-digital-store'); ?></div><div><?php esc_html_e('ราคา (บาท)', 'apptook-digital-store'); ?></div><div><?php esc_html_e('ค่าเริ่มต้น', 'apptook-digital-store'); ?></div><div><?php esc_html_e('จัดการ', 'apptook-digital-store'); ?></div></div>
								<div class="apptook-ds-table-body" data-rows></div>
								<button type="button" class="button" data-add-row><?php esc_html_e('+ เพิ่มระยะเวลา', 'apptook-digital-store'); ?></button>
							</div>
							<textarea id="apptook_editor_duration_rows" name="apptook_duration_rows" rows="5" class="large-text code apptook-ds-hidden-source" data-source="duration"><?php echo esc_textarea($durations_text); ?></textarea>
						</div>
						<div class="apptook-ds-admin-field">
							<div class="apptook-ds-table-builder" data-builder="type">
								<div class="apptook-ds-table apptook-ds-table-head apptook-ds-table-type"><div><?php esc_html_e('Key', 'apptook-digital-store'); ?></div><div><?php esc_html_e('ชื่อที่แสดง', 'apptook-digital-store'); ?></div><div><?php esc_html_e('ส่วนเพิ่มราคา', 'apptook-digital-store'); ?></div><div><?php esc_html_e('ค่าเริ่มต้น', 'apptook-digital-store'); ?></div><div><?php esc_html_e('จัดการ', 'apptook-digital-store'); ?></div></div>
								<div class="apptook-ds-table-body" data-rows></div>
								<button type="button" class="button" data-add-row><?php esc_html_e('+ เพิ่มประเภท', 'apptook-digital-store'); ?></button>
							</div>
							<textarea id="apptook_editor_type_rows" name="apptook_type_rows" rows="5" class="large-text code apptook-ds-hidden-source" data-source="type"><?php echo esc_textarea($types_text); ?></textarea>
						</div>
					</div>

					<div class="apptook-ds-admin-section">
						<h3><?php esc_html_e('คีย์สินค้า', 'apptook-digital-store'); ?></h3>
						<div class="apptook-ds-admin-field"><textarea id="apptook_editor_key_pool" name="apptook_key_pool" rows="8" class="large-text code"><?php echo esc_textarea($keys); ?></textarea></div>
					</div>
				</div>

				<div class="apptook-ds-side-col">
					<div class="apptook-ds-admin-section">
						<h3><?php esc_html_e('จัดหมวดและแท็ก', 'apptook-digital-store'); ?></h3>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_tags"><strong><?php esc_html_e('Tags', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_tags" name="post_tags" type="text" class="regular-text" value="<?php echo esc_attr(implode(', ', is_array($tag_names) ? $tag_names : array())); ?>" /></div>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_category"><strong><?php esc_html_e('หมวดหมู่', 'apptook-digital-store'); ?></strong></label><select id="apptook_editor_category" name="apptook_product_cat[]" multiple size="6" class="large-text"><?php if (is_array($categories)) : foreach ($categories as $cat) : ?><option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, $term_ids, true)); ?>><?php echo esc_html($cat->name); ?></option><?php endforeach; endif; ?></select></div>
					</div>
					<div class="apptook-ds-admin-section">
						<h3><?php esc_html_e('ภาพสินค้า', 'apptook-digital-store'); ?></h3>
						<input id="apptook_editor_thumbnail_id" name="thumbnail_id" type="hidden" value="<?php echo esc_attr((string) $thumbnail_id); ?>" />
						<div id="apptook-editor-image-preview" class="apptook-ds-image-preview"><?php if ($thumbnail_url) : ?><img src="<?php echo esc_url($thumbnail_url); ?>" alt="" /><?php else : ?><span><?php esc_html_e('ยังไม่ได้เลือกรูป', 'apptook-digital-store'); ?></span><?php endif; ?></div>
						<p><button type="button" class="button" id="apptook-editor-select-image"><?php esc_html_e('เลือกรูปจาก Media Library', 'apptook-digital-store'); ?></button> <button type="button" class="button-link-delete" id="apptook-editor-remove-image"><?php esc_html_e('ลบรูป', 'apptook-digital-store'); ?></button></p>
					</div>
					<div class="apptook-ds-admin-section">
						<h3><?php esc_html_e('Badge', 'apptook-digital-store'); ?></h3>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_badge"><strong><?php esc_html_e('ข้อความป้าย', 'apptook-digital-store'); ?></strong></label><input id="apptook_editor_badge" name="apptook_badge" type="text" class="regular-text" value="<?php echo esc_attr($badge); ?>" /></div>
						<div class="apptook-ds-admin-field"><label for="apptook_editor_badge_style"><strong><?php esc_html_e('สไตล์ป้าย', 'apptook-digital-store'); ?></strong></label><select id="apptook_editor_badge_style" name="apptook_badge_style"><option value="" <?php selected($badge_style, ''); ?>><?php esc_html_e('มิ้นต์', 'apptook-digital-store'); ?></option><option value="green" <?php selected($badge_style, 'green'); ?>><?php esc_html_e('เขียว', 'apptook-digital-store'); ?></option></select></div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	public function handle_save_product_editor(): void {
		if (! current_user_can('edit_posts')) {
			wp_die(esc_html__('ไม่มีสิทธิ์', 'apptook-digital-store'));
		}
		if (! isset($_POST['apptook_ds_product_editor_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apptook_ds_product_editor_nonce'])), 'apptook_ds_save_product_editor')) {
			wp_die(esc_html__('ลิงก์ไม่ถูกต้อง', 'apptook-digital-store'));
		}

		$product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
		$postarr = array(
			'post_type' => 'apptook_product',
			'post_title' => sanitize_text_field(wp_unslash($_POST['post_title'] ?? '')),
			'post_name' => sanitize_title(wp_unslash($_POST['post_name'] ?? '')),
			'post_excerpt' => sanitize_textarea_field(wp_unslash($_POST['post_excerpt'] ?? '')),
			'post_content' => wp_kses_post(wp_unslash($_POST['post_content'] ?? '')),
			'post_status' => 'publish',
		);
		if ($product_id > 0) {
			$postarr['ID'] = $product_id;
			$result = wp_update_post($postarr, true);
		} else {
			$result = wp_insert_post($postarr, true);
		}

		if (is_wp_error($result)) {
			wp_safe_redirect(add_query_arg(array('post_type' => 'apptook_product', 'page' => 'apptook-ds-product-editor', 'apptook_saved' => '1', 'apptook_saved_type' => 'error', 'apptook_msg' => rawurlencode(base64_encode(wp_json_encode(array($result->get_error_message()))))), admin_url('edit.php')));
			exit;
		}

		$product_id = (int) $result;
		update_post_meta($product_id, '_apptook_price', sanitize_text_field(wp_unslash($_POST['apptook_price'] ?? '0')));
		update_post_meta($product_id, '_apptook_sale_price', sanitize_text_field(wp_unslash($_POST['apptook_sale_price'] ?? '')));
		update_post_meta($product_id, '_apptook_product_status', (isset($_POST['apptook_product_status']) && $_POST['apptook_product_status'] === 'inactive') ? 'inactive' : 'active');
		update_post_meta($product_id, '_apptook_period', sanitize_text_field(wp_unslash($_POST['apptook_period'] ?? '')));
		update_post_meta($product_id, '_apptook_badge', sanitize_text_field(wp_unslash($_POST['apptook_badge'] ?? '')));
		update_post_meta($product_id, '_apptook_badge_style', (isset($_POST['apptook_badge_style']) && $_POST['apptook_badge_style'] === 'green') ? 'green' : '');
		update_post_meta($product_id, '_apptook_bullets', sanitize_textarea_field(wp_unslash($_POST['apptook_bullets'] ?? '')));
		update_post_meta($product_id, '_apptook_duration_rows', sanitize_textarea_field(wp_unslash($_POST['apptook_duration_rows'] ?? '')));
		update_post_meta($product_id, '_apptook_type_rows', sanitize_textarea_field(wp_unslash($_POST['apptook_type_rows'] ?? '')));
		update_post_meta($product_id, '_apptook_duration_enabled', isset($_POST['apptook_duration_enabled']) ? '1' : '0');
		update_post_meta($product_id, '_apptook_type_enabled', isset($_POST['apptook_type_enabled']) ? '1' : '0');
		update_post_meta($product_id, '_apptook_key_pool', sanitize_textarea_field(wp_unslash($_POST['apptook_key_pool'] ?? '')));

		$term_ids = isset($_POST['apptook_product_cat']) && is_array($_POST['apptook_product_cat']) ? array_map('absint', wp_unslash($_POST['apptook_product_cat'])) : array();
		wp_set_object_terms($product_id, $term_ids, 'apptook_product_cat', false);
		$tags_raw = sanitize_text_field(wp_unslash($_POST['post_tags'] ?? ''));
		$tags = array_filter(array_map('trim', explode(',', $tags_raw)));
		wp_set_post_terms($product_id, $tags, 'post_tag', false);

		$thumbnail_id = isset($_POST['thumbnail_id']) ? absint($_POST['thumbnail_id']) : 0;
		if ($thumbnail_id > 0) {
			set_post_thumbnail($product_id, $thumbnail_id);
		} else {
			delete_post_thumbnail($product_id);
		}

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			Apptook_DS_External_DB::instance()->sync_product((int) $product_id);
			$durations = $this->parse_duration_rows((string) (wp_unslash($_POST['apptook_duration_rows'] ?? '')), (float) sanitize_text_field(wp_unslash($_POST['apptook_price'] ?? '0')));
			$types = isset($_POST['apptook_type_enabled']) ? $this->parse_type_rows((string) (wp_unslash($_POST['apptook_type_rows'] ?? ''))) : array();
			Apptook_DS_External_DB::instance()->sync_product_purchase_options((int) $product_id, $durations, $types);
		}

		$redirect = add_query_arg(
			array(
				'post_type' => 'apptook_product',
				'page' => 'apptook-ds-product-editor',
				'product_id' => $product_id,
				'apptook_saved' => '1',
			),
			admin_url('edit.php')
		);
		wp_safe_redirect($redirect);
		exit;
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

		add_settings_section(
			'apptook_ds_auth',
			__('User / Register', 'apptook-digital-store'),
			static function (): void {
				echo '<p>' . esc_html__('ตั้งค่าสมัครสมาชิกและ Google Login', 'apptook-digital-store') . '</p>';
			},
			'apptook-ds-settings'
		);

		add_settings_field(
			'google_client_id',
			__('Google Client ID', 'apptook-digital-store'),
			array($this, 'field_text'),
			'apptook-ds-settings',
			'apptook_ds_auth',
			array('key' => 'google_client_id')
		);

		add_settings_field(
			'google_client_secret',
			__('Google Client Secret', 'apptook-digital-store'),
			array($this, 'field_text'),
			'apptook-ds-settings',
			'apptook_ds_auth',
			array('key' => 'google_client_secret')
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
		$out['google_client_id'] = isset($input['google_client_id']) ? sanitize_text_field((string) $input['google_client_id']) : '';
		$out['google_client_secret'] = isset($input['google_client_secret']) ? sanitize_text_field((string) $input['google_client_secret']) : '';
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

		$setup_url = wp_nonce_url(
			admin_url('admin.php?action=apptook_ds_setup_external_db'),
			'apptook_ds_setup_external_db'
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('ตั้งค่า Apptook Digital Store', 'apptook-digital-store'); ?></h1>

			<?php if (isset($_GET['apptook_db_setup']) && $_GET['apptook_db_setup'] === 'success') : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('สร้าง/อัปเดตโครงสร้าง External DB สำเร็จแล้ว', 'apptook-digital-store'); ?></p></div>
			<?php elseif (isset($_GET['apptook_db_setup']) && $_GET['apptook_db_setup'] === 'error') : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e('ไม่สามารถสร้าง External DB ได้ กรุณาตรวจสอบค่าใน wp-config.php และสิทธิ์ฐานข้อมูล', 'apptook-digital-store'); ?></p></div>
			<?php endif; ?>

			<div style="margin: 12px 0 18px; padding: 12px 14px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; max-width: 900px;">
				<p style="margin:0 0 10px;"><strong><?php esc_html_e('เตรียมฐานข้อมูลธุรกิจ (External DB)', 'apptook-digital-store'); ?></strong></p>
				<p style="margin:0 0 10px;"><?php esc_html_e('กดปุ่มนี้ครั้งเดียวเพื่อให้ระบบสร้างตารางที่จำเป็นทั้งหมด (รวมตาราง Marketplace เช่น durations/types) ตามค่า APPTOOK_EXT_DB_* ใน wp-config.php', 'apptook-digital-store'); ?></p>
				<a class="button button-primary" href="<?php echo esc_url($setup_url); ?>"><?php esc_html_e('สร้าง/อัปเดต External DB ตอนนี้', 'apptook-digital-store'); ?></a>
			</div>

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
		$sale_price = get_post_meta($post->ID, '_apptook_sale_price', true);
		$product_status = (string) get_post_meta($post->ID, '_apptook_product_status', true);
		if ($product_status !== 'inactive') {
			$product_status = 'active';
		}
		$keys    = get_post_meta($post->ID, '_apptook_key_pool', true);
		$bullets = get_post_meta($post->ID, '_apptook_bullets', true);
		$badge   = get_post_meta($post->ID, '_apptook_badge', true);
		$badge_style = get_post_meta($post->ID, '_apptook_badge_style', true);
		$period  = get_post_meta($post->ID, '_apptook_period', true);
		$type_enabled = (string) get_post_meta($post->ID, '_apptook_type_enabled', true) === '1';
		$duration_enabled = (string) get_post_meta($post->ID, '_apptook_duration_enabled', true) !== '0';
		$durations_raw = get_post_meta($post->ID, '_apptook_duration_rows', true);
		$types_raw = get_post_meta($post->ID, '_apptook_type_rows', true);
		$durations_text = is_string($durations_raw) ? $durations_raw : "1|0|1\n3|0|0\n6|0|0\n12|0|0";
		$types_text = is_string($types_raw) ? $types_raw : "shared|1 profile Shared|0|1\nprivate|Private Account (Full ownership)|0|0";
		?>
		<div class="apptook-ds-admin-form">
			<div class="apptook-ds-admin-section">
				<h3><?php esc_html_e('ข้อมูลหลักสินค้า', 'apptook-digital-store'); ?></h3>
				<p class="description"><?php esc_html_e('กำหนดราคา การแสดงผลบนการ์ด และข้อความจุดเด่นของสินค้า', 'apptook-digital-store'); ?></p>

				<div class="apptook-ds-admin-grid">
					<div class="apptook-ds-admin-field">
						<label for="apptook_price"><strong><?php esc_html_e('ราคาปกติ (บาท)', 'apptook-digital-store'); ?></strong></label>
						<input type="number" step="0.01" min="0" name="apptook_price" id="apptook_price" value="<?php echo esc_attr((string) $price); ?>" class="regular-text" />
					</div>

					<div class="apptook-ds-admin-field">
						<label for="apptook_sale_price"><strong><?php esc_html_e('ราคาโปร (บาท)', 'apptook-digital-store'); ?></strong></label>
						<input type="number" step="0.01" min="0" name="apptook_sale_price" id="apptook_sale_price" value="<?php echo esc_attr((string) $sale_price); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e('เว้นว่างถ้าไม่มีโปรโมชัน', 'apptook-digital-store'); ?></p>
					</div>

					<div class="apptook-ds-admin-field">
						<label for="apptook_product_status"><strong><?php esc_html_e('สถานะสินค้า', 'apptook-digital-store'); ?></strong></label>
						<select name="apptook_product_status" id="apptook_product_status">
							<option value="active" <?php selected($product_status, 'active'); ?>><?php esc_html_e('Active', 'apptook-digital-store'); ?></option>
							<option value="inactive" <?php selected($product_status, 'inactive'); ?>><?php esc_html_e('Inactive', 'apptook-digital-store'); ?></option>
						</select>
					</div>
				</div>

				<div class="apptook-ds-admin-field">
					<label for="apptook_period"><strong><?php esc_html_e('หน่วยราคา (แสดงบนการ์ด)', 'apptook-digital-store'); ?></strong></label>
					<input type="text" name="apptook_period" id="apptook_period" value="<?php echo esc_attr(is_string($period) ? $period : ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('/ เดือน', 'apptook-digital-store'); ?>" />
					<p class="description"><?php esc_html_e('ตัวอย่าง: / เดือน, / 3 เดือน, / ปี', 'apptook-digital-store'); ?></p>
				</div>

				<div class="apptook-ds-admin-field">
					<label for="apptook_badge"><strong><?php esc_html_e('แถบป้ายมุมขวาบน (ถ้ามี)', 'apptook-digital-store'); ?></strong></label>
					<input type="text" name="apptook_badge" id="apptook_badge" value="<?php echo esc_attr(is_string($badge) ? $badge : ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('เช่น 4K Ultra HD', 'apptook-digital-store'); ?>" />
				</div>

				<div class="apptook-ds-admin-field">
					<label for="apptook_badge_style"><strong><?php esc_html_e('สไตล์ป้าย (ตามดีไซน์ stitch)', 'apptook-digital-store'); ?></strong></label>
					<select name="apptook_badge_style" id="apptook_badge_style">
						<option value="" <?php selected($badge_style, ''); ?>><?php esc_html_e('มิ้นต์ (ค่าเริ่มต้น)', 'apptook-digital-store'); ?></option>
						<option value="green" <?php selected($badge_style, 'green'); ?>><?php esc_html_e('เขียว (แบบ ChatGPT ใน stitch)', 'apptook-digital-store'); ?></option>
					</select>
				</div>

				<div class="apptook-ds-admin-field">
					<label for="apptook_bullets"><strong><?php esc_html_e('จุดเด่น (หนึ่งบรรทัดต่อหนึ่งข้อ — แสดงบนการ์ด)', 'apptook-digital-store'); ?></strong></label>
					<textarea name="apptook_bullets" id="apptook_bullets" rows="5" class="large-text"><?php echo esc_textarea(is_string($bullets) ? $bullets : ''); ?></textarea>
					<p class="description"><?php esc_html_e('ถ้าว่าง จะลองใช้บรรทัดจากคำโปรย (Excerpt) แทน', 'apptook-digital-store'); ?></p>
				</div>
			</div>

			<div class="apptook-ds-admin-section">
				<h3><?php esc_html_e('ตัวเลือกการซื้อใน Popup', 'apptook-digital-store'); ?></h3>
				<p class="description"><?php esc_html_e('เปิด/ปิดตัวเลือก และกำหนดข้อมูลเป็นรูปแบบแถวเพื่อสร้างตัวเลือกอัตโนมัติ', 'apptook-digital-store'); ?></p>

				<div class="apptook-ds-admin-field apptook-ds-admin-switches">
					<label><input type="checkbox" name="apptook_type_enabled" id="apptook_type_enabled" value="1" <?php checked($type_enabled); ?> /> <strong><?php esc_html_e('เปิดใช้งาน Type ใน popup ซื้อ', 'apptook-digital-store'); ?></strong></label>
					<p class="description"><?php esc_html_e('แสดง Select Type (ถ้าไม่ติ๊กจะซ่อน)', 'apptook-digital-store'); ?></p>

					<label><input type="checkbox" name="apptook_duration_enabled" id="apptook_duration_enabled" value="1" <?php checked($duration_enabled); ?> /> <strong><?php esc_html_e('เปิดใช้งาน Purchase months ใน popup ซื้อ', 'apptook-digital-store'); ?></strong></label>
					<p class="description"><?php esc_html_e('แสดง Purchase months (ถ้าไม่ติ๊กจะซ่อน)', 'apptook-digital-store'); ?></p>
				</div>

				<div class="apptook-ds-admin-field">
					<label><strong><?php esc_html_e('แพ็กระยะเวลา (Duration)', 'apptook-digital-store'); ?></strong></label>
					<p class="description"><?php esc_html_e('เพิ่มข้อมูลแบบเป็นแถว แทนการพิมพ์เครื่องหมาย | เอง', 'apptook-digital-store'); ?></p>

					<div class="apptook-ds-table-builder" data-builder="duration">
						<div class="apptook-ds-table apptook-ds-table-head">
							<div><?php esc_html_e('เดือน', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('ราคา (บาท)', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('ค่าเริ่มต้น', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('จัดการ', 'apptook-digital-store'); ?></div>
						</div>
						<div class="apptook-ds-table-body" data-rows></div>
						<button type="button" class="button" data-add-row><?php esc_html_e('+ เพิ่มระยะเวลา', 'apptook-digital-store'); ?></button>
					</div>

					<textarea name="apptook_duration_rows" id="apptook_duration_rows" rows="6" class="large-text code apptook-ds-hidden-source" spellcheck="false" data-source="duration"><?php echo esc_textarea($durations_text); ?></textarea>
				</div>

				<div class="apptook-ds-admin-field">
					<label><strong><?php esc_html_e('ประเภทบัญชี (Type)', 'apptook-digital-store'); ?></strong></label>
					<p class="description"><?php esc_html_e('กรอกเป็นช่องแยก อ่านง่ายและแก้ไขสะดวก', 'apptook-digital-store'); ?></p>

					<div class="apptook-ds-table-builder" data-builder="type">
						<div class="apptook-ds-table apptook-ds-table-head apptook-ds-table-type">
							<div><?php esc_html_e('Key', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('ชื่อที่แสดง', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('ส่วนเพิ่มราคา', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('ค่าเริ่มต้น', 'apptook-digital-store'); ?></div>
							<div><?php esc_html_e('จัดการ', 'apptook-digital-store'); ?></div>
						</div>
						<div class="apptook-ds-table-body" data-rows></div>
						<button type="button" class="button" data-add-row><?php esc_html_e('+ เพิ่มประเภท', 'apptook-digital-store'); ?></button>
					</div>

					<textarea name="apptook_type_rows" id="apptook_type_rows" rows="5" class="large-text code apptook-ds-hidden-source" spellcheck="false" data-source="type"><?php echo esc_textarea($types_text); ?></textarea>
				</div>
			</div>

			<div class="apptook-ds-admin-section">
				<h3><?php esc_html_e('คีย์สินค้า', 'apptook-digital-store'); ?></h3>
				<div class="apptook-ds-admin-field">
					<label for="apptook_key_pool"><strong><?php esc_html_e('คีย์คงเหลือ (หนึ่งบรรทัดต่อหนึ่งคีย์)', 'apptook-digital-store'); ?></strong></label>
					<textarea name="apptook_key_pool" id="apptook_key_pool" rows="10" class="large-text code" spellcheck="false"><?php echo esc_textarea(is_string($keys) ? $keys : ''); ?></textarea>
				</div>
			</div>
		</div>
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
		$has_metabox_nonce = isset($_POST['apptook_ds_product_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['apptook_ds_product_nonce'])), 'apptook_ds_save_product');
		$has_quick_edit_nonce = isset($_POST['_inline_edit']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_inline_edit'])), 'inlineeditnonce');
		if (! $has_metabox_nonce && ! $has_quick_edit_nonce) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (! current_user_can('edit_post', $post_id)) {
			return;
		}

		if ($has_quick_edit_nonce && ! $has_metabox_nonce) {
			$product_status = isset($_POST['apptook_product_status']) && $_POST['apptook_product_status'] === 'inactive' ? 'inactive' : 'active';
			$raw_sale_price = isset($_POST['apptook_sale_price']) ? sanitize_text_field(wp_unslash($_POST['apptook_sale_price'])) : '';
			if ($raw_sale_price !== '' && (! is_numeric($raw_sale_price) || (float) $raw_sale_price < 0)) {
				$raw_sale_price = '';
			}
			update_post_meta($post_id, '_apptook_product_status', $product_status);
			update_post_meta($post_id, '_apptook_sale_price', $raw_sale_price === '' ? '' : (string) (float) $raw_sale_price);
			return;
		}

		$raw_price = isset($_POST['apptook_price']) ? sanitize_text_field(wp_unslash($_POST['apptook_price'])) : '0';
		$raw_sale_price = isset($_POST['apptook_sale_price']) ? sanitize_text_field(wp_unslash($_POST['apptook_sale_price'])) : '';
		$keys    = isset($_POST['apptook_key_pool']) ? sanitize_textarea_field(wp_unslash($_POST['apptook_key_pool'])) : '';
		$bullets = isset($_POST['apptook_bullets']) ? sanitize_textarea_field(wp_unslash($_POST['apptook_bullets'])) : '';
		$badge   = isset($_POST['apptook_badge']) ? sanitize_text_field(wp_unslash($_POST['apptook_badge'])) : '';
		$badge_style = isset($_POST['apptook_badge_style']) && $_POST['apptook_badge_style'] === 'green' ? 'green' : '';
		$period  = isset($_POST['apptook_period']) ? sanitize_text_field(wp_unslash($_POST['apptook_period'])) : '';
		$product_status = isset($_POST['apptook_product_status']) && $_POST['apptook_product_status'] === 'inactive' ? 'inactive' : 'active';
		$type_enabled = isset($_POST['apptook_type_enabled']) ? '1' : '0';
		$duration_enabled = isset($_POST['apptook_duration_enabled']) ? '1' : '0';
		$duration_rows = isset($_POST['apptook_duration_rows']) ? sanitize_textarea_field(wp_unslash($_POST['apptook_duration_rows'])) : '';
		$type_rows = isset($_POST['apptook_type_rows']) ? sanitize_textarea_field(wp_unslash($_POST['apptook_type_rows'])) : '';

		$errors = array();
		$price = is_numeric($raw_price) ? (float) $raw_price : -1;
		if ($price < 0) {
			$errors[] = __('ราคาปกติต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป', 'apptook-digital-store');
			$price = 0.0;
		}

		$sale_price = '';
		if ($raw_sale_price !== '') {
			if (! is_numeric($raw_sale_price) || (float) $raw_sale_price < 0) {
				$errors[] = __('ราคาโปรต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป หรือเว้นว่าง', 'apptook-digital-store');
			} else {
				$sale_price = (string) (float) $raw_sale_price;
			}
		}

		if ($sale_price !== '' && (float) $sale_price > $price) {
			$errors[] = __('ราคาโปรไม่ควรมากกว่าราคาปกติ', 'apptook-digital-store');
		}

		update_post_meta($post_id, '_apptook_price', (string) $price);
		update_post_meta($post_id, '_apptook_sale_price', $sale_price);
		update_post_meta($post_id, '_apptook_product_status', $product_status);
		update_post_meta($post_id, '_apptook_key_pool', $keys);
		update_post_meta($post_id, '_apptook_bullets', $bullets);
		update_post_meta($post_id, '_apptook_badge', $badge);
		update_post_meta($post_id, '_apptook_badge_style', $badge_style);
		update_post_meta($post_id, '_apptook_period', $period);
		update_post_meta($post_id, '_apptook_type_enabled', $type_enabled);
		update_post_meta($post_id, '_apptook_duration_enabled', $duration_enabled);
		update_post_meta($post_id, '_apptook_duration_rows', $duration_rows);
		update_post_meta($post_id, '_apptook_type_rows', $type_rows);

		if ($errors === array()) {
			$this->product_save_notices[] = __('บันทึกข้อมูลสินค้าเรียบร้อย', 'apptook-digital-store');
			$this->product_save_has_errors = false;
		} else {
			$this->product_save_has_errors = true;
			foreach ($errors as $error_message) {
				$this->product_save_notices[] = $error_message;
			}
		}

		if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
			$durations = $this->parse_duration_rows($duration_rows, (float) $price);
			$types = $type_enabled === '1' ? $this->parse_type_rows($type_rows) : array();
			Apptook_DS_External_DB::instance()->sync_product_purchase_options((int) $post_id, $durations, $types);
		}
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

	public function product_columns(array $columns): array {
		$new = array();
		foreach ($columns as $key => $label) {
			$new[ $key ] = $label;
			if ($key === 'title') {
				$new['apptook_product_status'] = __('สถานะสินค้า', 'apptook-digital-store');
				$new['apptook_product_price'] = __('ราคา', 'apptook-digital-store');
			}
		}
		return $new;
	}

	public function product_column_content($column, $post_id): void {
		$post_id = (int) $post_id;
		if ($column === 'apptook_product_status') {
			$status = (string) get_post_meta($post_id, '_apptook_product_status', true);
			$normalized_status = $status === 'inactive' ? 'inactive' : 'active';
			echo esc_html($normalized_status === 'inactive' ? __('Inactive', 'apptook-digital-store') : __('Active', 'apptook-digital-store'));
			echo '<div class="hidden" id="apptook_inline_' . esc_attr((string) $post_id) . '">';
			echo '<span class="apptook_inline_status">' . esc_html($normalized_status) . '</span>';
			echo '<span class="apptook_inline_sale_price">' . esc_html((string) get_post_meta($post_id, '_apptook_sale_price', true)) . '</span>';
			echo '</div>';
			return;
		}
		if ($column === 'apptook_product_price') {
			$price = (string) get_post_meta($post_id, '_apptook_price', true);
			$sale_price = (string) get_post_meta($post_id, '_apptook_sale_price', true);
			if ($sale_price !== '') {
				echo '<strong>' . esc_html($sale_price) . '</strong> <span style="color:#666; text-decoration:line-through; margin-left:6px;">' . esc_html($price) . '</span>';
				return;
			}
			echo esc_html($price);
		}
	}

	public function render_product_quick_edit(string $column_name, string $post_type): void {
		if ($post_type !== 'apptook_product' || $column_name !== 'apptook_product_status') {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right apptook-ds-quick-edit-wrap">
			<div class="inline-edit-col">
				<label class="alignleft">
					<span class="title"><?php esc_html_e('สถานะสินค้า', 'apptook-digital-store'); ?></span>
					<select name="apptook_product_status">
						<option value="active"><?php esc_html_e('Active', 'apptook-digital-store'); ?></option>
						<option value="inactive"><?php esc_html_e('Inactive', 'apptook-digital-store'); ?></option>
					</select>
				</label>
				<label class="alignleft">
					<span class="title"><?php esc_html_e('ราคาโปร', 'apptook-digital-store'); ?></span>
					<input type="number" min="0" step="0.01" name="apptook_sale_price" value="" />
				</label>
			</div>
		</fieldset>
		<?php
	}

	public function register_product_bulk_actions(array $bulk_actions): array {
		$bulk_actions['apptook_set_active'] = __('ตั้งสถานะเป็น Active', 'apptook-digital-store');
		$bulk_actions['apptook_set_inactive'] = __('ตั้งสถานะเป็น Inactive', 'apptook-digital-store');
		return $bulk_actions;
	}

	public function handle_product_bulk_actions(string $redirect_to, string $doaction, array $post_ids): string {
		if ($doaction !== 'apptook_set_active' && $doaction !== 'apptook_set_inactive') {
			return $redirect_to;
		}

		$status = $doaction === 'apptook_set_active' ? 'active' : 'inactive';
		$updated = 0;
		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;
			if (! current_user_can('edit_post', $post_id)) {
				continue;
			}
			update_post_meta($post_id, '_apptook_product_status', $status);
			$updated++;
		}

		return add_query_arg(
			array(
				'apptook_bulk_updated' => $updated,
				'apptook_bulk_status' => $status,
			),
			$redirect_to
		);
	}

	public function render_product_status_filter(): void {
		global $typenow;
		if ($typenow !== 'apptook_product') {
			return;
		}
		$current = isset($_GET['apptook_product_status']) ? sanitize_text_field(wp_unslash($_GET['apptook_product_status'])) : '';
		echo '<select name="apptook_product_status">';
		echo '<option value="">' . esc_html__('ทุกสถานะสินค้า', 'apptook-digital-store') . '</option>';
		echo '<option value="active"' . selected($current, 'active', false) . '>' . esc_html__('Active', 'apptook-digital-store') . '</option>';
		echo '<option value="inactive"' . selected($current, 'inactive', false) . '>' . esc_html__('Inactive', 'apptook-digital-store') . '</option>';
		echo '</select>';
	}

	public function filter_products_by_status(WP_Query $query): void {
		if (! is_admin() || ! $query->is_main_query()) {
			return;
		}
		$post_type = $query->get('post_type');
		if ($post_type !== 'apptook_product') {
			return;
		}
		$status = isset($_GET['apptook_product_status']) ? sanitize_text_field(wp_unslash($_GET['apptook_product_status'])) : '';
		if ($status !== 'active' && $status !== 'inactive') {
			return;
		}
		if ($status === 'active') {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key' => '_apptook_product_status',
						'value' => 'active',
						'compare' => '=',
					),
					array(
						'key' => '_apptook_product_status',
						'compare' => 'NOT EXISTS',
					),
				)
			);
			return;
		}

		$query->set(
			'meta_query',
			array(
				array(
					'key' => '_apptook_product_status',
					'value' => 'inactive',
					'compare' => '=',
				),
			)
		);
	}

	public function append_product_save_messages(string $location, int $post_id): string {
		$post = get_post($post_id);
		if (! $post || $post->post_type !== 'apptook_product') {
			return $location;
		}
		$args = array('apptook_saved' => '1');
		if ($this->product_save_has_errors) {
			$args['apptook_saved_type'] = 'error';
		}
		if ($this->product_save_notices !== array()) {
			$args['apptook_msg'] = rawurlencode(base64_encode(wp_json_encode($this->product_save_notices)));
		}
		return add_query_arg($args, $location);
	}

	public function render_product_save_notices(): void {
		if (! is_admin()) {
			return;
		}
		$screen = get_current_screen();
		if (! $screen || $screen->post_type !== 'apptook_product') {
			return;
		}
		if (isset($_GET['apptook_bulk_updated'])) {
			$count = absint($_GET['apptook_bulk_updated']);
			$status = isset($_GET['apptook_bulk_status']) && sanitize_text_field(wp_unslash($_GET['apptook_bulk_status'])) === 'inactive' ? 'inactive' : 'active';
			$status_label = $status === 'inactive' ? __('Inactive', 'apptook-digital-store') : __('Active', 'apptook-digital-store');
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('อัปเดตสถานะสินค้า %1$d รายการเป็น %2$s แล้ว', 'apptook-digital-store'), $count, $status_label)) . '</p></div>';
		}

		if (! isset($_GET['apptook_saved'])) {
			return;
		}

		$messages = array(__('บันทึกข้อมูลสินค้าเรียบร้อย', 'apptook-digital-store'));
		if (isset($_GET['apptook_msg'])) {
			$decoded = base64_decode(rawurldecode(sanitize_text_field(wp_unslash($_GET['apptook_msg']))), true);
			if (is_string($decoded)) {
				$list = json_decode($decoded, true);
				if (is_array($list) && $list !== array()) {
					$messages = array_map('sanitize_text_field', $list);
				}
			}
		}

		$type = (isset($_GET['apptook_saved_type']) && sanitize_text_field(wp_unslash($_GET['apptook_saved_type'])) === 'error') ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html(implode(' | ', $messages)) . '</p></div>';
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

	public function handle_setup_external_db(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('ไม่มีสิทธิ์', 'apptook-digital-store'));
		}
		if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'apptook_ds_setup_external_db')) {
			wp_die(esc_html__('ลิงก์ไม่ถูกต้อง', 'apptook-digital-store'));
		}

		$ok = false;
		if (class_exists('Apptook_DS_External_DB')) {
			$ok = Apptook_DS_External_DB::instance()->ensure_manual_setup();
		}

		$redirect = add_query_arg(
			array(
				'post_type'        => 'apptook_product',
				'page'             => 'apptook-ds-settings',
				'apptook_db_setup' => $ok ? 'success' : 'error',
			),
			admin_url('edit.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}

	private function parse_duration_rows(string $raw, float $fallback_price): array {
		$lines = preg_split('/\r\n|\n|\r/', $raw);
		if (! is_array($lines)) {
			$lines = array();
		}

		$out = array();
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}
			$parts = array_map('trim', explode('|', $line));
			$months = isset($parts[0]) ? max(1, (int) $parts[0]) : 1;
			$price = isset($parts[1]) && $parts[1] !== '' ? (float) $parts[1] : $fallback_price;
			$is_default = isset($parts[2]) && (int) $parts[2] === 1 ? 1 : 0;
			$out[] = array(
				'months' => $months,
				'price' => $price,
				'is_default' => $is_default,
				'is_active' => 1,
			);
		}

		if ($out === array()) {
			$out[] = array(
				'months' => 1,
				'price' => $fallback_price,
				'is_default' => 1,
				'is_active' => 1,
			);
		}

		$has_default = false;
		foreach ($out as $row) {
			if ((int) $row['is_default'] === 1) {
				$has_default = true;
				break;
			}
		}
		if (! $has_default) {
			$out[0]['is_default'] = 1;
		}

		return $out;
	}

	private function parse_type_rows(string $raw): array {
		$lines = preg_split('/\r\n|\n|\r/', $raw);
		if (! is_array($lines)) {
			$lines = array();
		}

		$out = array();
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}
			$parts = array_map('trim', explode('|', $line));

			if (count($parts) === 1) {
				$type_label = sanitize_text_field($parts[0]);
				$type_key = sanitize_title($parts[0]);
				$modifier = 0.0;
				$is_default = 0;
			} else {
				$type_key = isset($parts[0]) ? sanitize_title($parts[0]) : '';
				$type_label = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';
				$modifier = isset($parts[2]) && $parts[2] !== '' ? (float) $parts[2] : 0.0;
				$is_default = isset($parts[3]) && (int) $parts[3] === 1 ? 1 : 0;
			}

			if ($type_key === '' || $type_label === '') {
				continue;
			}
			$out[] = array(
				'type_key' => $type_key,
				'type_label' => $type_label,
				'price_modifier' => $modifier,
				'is_default' => $is_default,
				'is_active' => 1,
			);
		}

		if ($out !== array()) {
			$has_default = false;
			foreach ($out as $row) {
				if ((int) $row['is_default'] === 1) {
					$has_default = true;
					break;
				}
			}
			if (! $has_default) {
				$out[0]['is_default'] = 1;
			}
		}

		return $out;
	}

}
