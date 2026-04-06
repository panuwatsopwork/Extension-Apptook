<?php
/**
 * Single product (front-end).
 *
 * @package Apptook_Digital_Store
 */

if (! defined('ABSPATH')) {
	exit;
}

get_header();

$apptook_public_view = class_exists('Apptook_DS_Public') ? Apptook_DS_Public::instance() : null;

while (have_posts()) :
	the_post();
	$pid        = get_the_ID();
	$title      = get_the_title($pid);
	$price_raw  = (string) get_post_meta($pid, '_apptook_price', true);
	$price_f    = (float) $price_raw;
	$period     = (string) get_post_meta($pid, '_apptook_period', true);
	$period     = $period !== '' ? $period : __('/ เดือน', 'apptook-digital-store');
	$badge      = trim((string) get_post_meta($pid, '_apptook_badge', true));
	$badge_style = (string) get_post_meta($pid, '_apptook_badge_style', true);

	$price_display = $price_raw !== '' ? $price_raw : '0';
	if ($price_display !== '' && strpos($price_display, '฿') !== 0 && strpos($price_display, 'THB') === false) {
		$price_display = '฿' . $price_display;
	}

	$description = trim((string) get_the_excerpt($pid));
	if ($description === '') {
		$description = trim(wp_strip_all_tags((string) get_the_content(null, false, $pid)));
	}
	if ($description === '') {
		$description = __('บริการพรีเมียมพร้อมใช้งานทันที รองรับการช่วยเหลือหลังการขายจากทีมงาน', 'apptook-digital-store');
	}

	$bullets_raw = (string) get_post_meta($pid, '_apptook_bullets', true);
	$bullets = array();
	if ($bullets_raw !== '') {
		$lines = preg_split("/\r\n|\n|\r/", $bullets_raw);
		if (is_array($lines)) {
			$bullets = array_values(array_filter(array_map('trim', $lines)));
		}
	}
	if ($bullets === array()) {
		$excerpt_lines = preg_split("/\r\n|\n|\r/", (string) get_the_excerpt($pid));
		if (is_array($excerpt_lines)) {
			$bullets = array_values(array_filter(array_map('trim', $excerpt_lines)));
		}
	}
	if ($bullets === array()) {
		$bullets = array(
			__('บัญชีใช้งานได้ทันทีหลังชำระเงิน', 'apptook-digital-store'),
			__('มีแอดมินช่วยเหลือเมื่อพบปัญหาการใช้งาน', 'apptook-digital-store'),
			__('รองรับการต่ออายุและดูแลหลังการขาย', 'apptook-digital-store'),
		);
	}

	$type_enabled = (string) get_post_meta($pid, '_apptook_type_enabled', true) === '1';
	$duration_enabled = (string) get_post_meta($pid, '_apptook_duration_enabled', true) !== '0';
	$durations = array(
		array('months' => 1, 'price' => $price_f, 'is_default' => 1),
	);
	$types = array();

	if (class_exists('Apptook_DS_External_DB') && Apptook_DS_External_DB::instance()->is_configured()) {
		$data = Apptook_DS_External_DB::instance()->get_product_purchase_options((int) $pid);
		if ($duration_enabled && ! empty($data['durations']) && is_array($data['durations'])) {
			$durations = array_values($data['durations']);
		}
		if (! empty($data['types']) && is_array($data['types'])) {
			$types = array_values($data['types']);
		}
	}

	if ($type_enabled && $types === array()) {
		$raw_types = (string) get_post_meta($pid, '_apptook_type_rows', true);
		if ($raw_types !== '') {
			$lines = preg_split("/\r\n|\n|\r/", $raw_types);
			if (is_array($lines)) {
				foreach ($lines as $line) {
					$line = trim((string) $line);
					if ($line === '') {
						continue;
					}
					$parts = array_map('trim', explode('|', $line));
					if (count($parts) === 1) {
						$key = sanitize_title($parts[0]);
						$label = sanitize_text_field($parts[0]);
						$modifier = 0.0;
						$is_default = 0;
					} else {
						$key = isset($parts[0]) ? sanitize_title($parts[0]) : '';
						$label = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';
						$modifier = isset($parts[2]) && $parts[2] !== '' ? (float) $parts[2] : 0.0;
						$is_default = isset($parts[3]) && (int) $parts[3] === 1 ? 1 : 0;
					}
					if ($key === '' || $label === '') {
						continue;
					}
					$types[] = array(
						'type_key' => $key,
						'type_label' => $label,
						'price_modifier' => $modifier,
						'is_default' => $is_default,
					);
				}
			}
		}
	}

	if ($type_enabled && $types === array()) {
		$types = array(
			array('type_key' => 'shared', 'type_label' => '1 profile Shared', 'price_modifier' => 0, 'is_default' => 1),
			array('type_key' => 'private', 'type_label' => 'Private Account (Full ownership)', 'price_modifier' => 0, 'is_default' => 0),
		);
	}

	$durations_json = wp_json_encode($duration_enabled ? $durations : array());
	$types_json = wp_json_encode($type_enabled ? $types : array());
	$shop_page_id = (int) get_option('apptook_ds_page_shop_id', 0);
	$shop_url = $shop_page_id > 0 ? get_permalink($shop_page_id) : '';
	if (! is_string($shop_url) || $shop_url === '') {
		$shop_url = home_url('/');
	}
	$icon_html = '';
	if (has_post_thumbnail($pid)) {
		$icon_html = get_the_post_thumbnail($pid, 'medium', array('class' => '')); 
	} else {
		$icon_html = '<span class="material-symbols-outlined st-card-icon-lg text-on-surface" aria-hidden="true">inventory_2</span>';
	}
	?>
	<main id="primary" class="site-main apptook-ds apptook-stitch apptook-ds-product-detail-page">
		<?php
		if ($apptook_public_view instanceof Apptook_DS_Public) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $apptook_public_view->render_site_menuheader('marketplace');
		}
		?>
		<div class="apptook-ds-pd-wrap">
			<div class="apptook-ds-pd-back-wrap">
				<a class="apptook-ds-pd-back" href="<?php echo esc_url($shop_url); ?>">
					<span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
					<span><?php esc_html_e('กลับไปหน้า Marketplace', 'apptook-digital-store'); ?></span>
				</a>
			</div>

			<article id="post-<?php the_ID(); ?>" <?php post_class('apptook-ds-pd-grid'); ?>>
				<section class="apptook-ds-pd-main">
					<div class="apptook-ds-pd-card apptook-ds-pd-hero-card">
						<div class="apptook-ds-pd-hero-head">
							<div class="apptook-ds-pd-icon-wrap">
								<?php if (has_post_thumbnail()) : ?>
									<?php the_post_thumbnail('medium', array('class' => 'apptook-ds-pd-thumb')); ?>
								<?php else : ?>
									<span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
								<?php endif; ?>
							</div>
							<div>
								<p class="apptook-ds-pd-kicker"><?php esc_html_e('Premium App', 'apptook-digital-store'); ?></p>
								<h1 class="apptook-ds-pd-title"><?php echo esc_html($title); ?></h1>
							</div>
						</div>

						<div class="apptook-ds-pd-meta-grid">
							<div class="apptook-ds-pd-meta-item">
								<p><?php esc_html_e('ประเภทบัญชี', 'apptook-digital-store'); ?></p>
								<strong><?php echo esc_html($type_enabled ? __('Shared / Private', 'apptook-digital-store') : __('Standard', 'apptook-digital-store')); ?></strong>
							</div>
							<div class="apptook-ds-pd-meta-item">
								<p><?php esc_html_e('ระยะเวลา', 'apptook-digital-store'); ?></p>
								<strong><?php echo esc_html($duration_enabled ? __('1, 3, 6, 12 เดือน', 'apptook-digital-store') : __('1 เดือน', 'apptook-digital-store')); ?></strong>
							</div>
							<div class="apptook-ds-pd-meta-item">
								<p><?php esc_html_e('การจัดส่ง', 'apptook-digital-store'); ?></p>
								<strong><?php esc_html_e('ภายใน 5-30 นาที', 'apptook-digital-store'); ?></strong>
							</div>
						</div>

						<p class="apptook-ds-pd-desc"><?php echo esc_html($description); ?></p>
					</div>

					<div class="apptook-ds-pd-card">
						<h2 class="apptook-ds-pd-section-title"><?php esc_html_e('สิ่งที่จะได้รับ', 'apptook-digital-store'); ?></h2>
						<ul class="apptook-ds-pd-feature-list">
							<?php foreach (array_slice($bullets, 0, 8) as $line) : ?>
								<li>
									<span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
									<span><?php echo esc_html($line); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</section>

				<aside class="apptook-ds-pd-side">
					<div class="apptook-ds-pd-card apptook-ds-pd-purchase-card">
						<p class="apptook-ds-pd-price-label"><?php esc_html_e('ราคาเริ่มต้น', 'apptook-digital-store'); ?></p>
						<div class="apptook-ds-pd-price"><?php echo esc_html($price_display); ?></div>
						<p class="apptook-ds-pd-period"><?php echo esc_html($period); ?></p>

						<?php if ($duration_enabled) : ?>
							<div class="apptook-ds-pd-static-block">
								<p class="apptook-ds-pd-static-title"><?php esc_html_e('เลือกระยะเวลา', 'apptook-digital-store'); ?></p>
								<div class="apptook-ds-pd-duration-grid">
									<?php foreach (array_slice($durations, 0, 4) as $d) : ?>
										<?php $months = (int) ($d['months'] ?? 1); ?>
										<button type="button" class="apptook-ds-pd-duration-pill<?php echo ! empty($d['is_default']) ? ' is-active' : ''; ?>" data-pd-month="<?php echo esc_attr((string) $months); ?>"><?php echo esc_html((string) $months); ?> <?php esc_html_e('เดือน', 'apptook-digital-store'); ?></button>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ($type_enabled && ! empty($types)) : ?>
							<div class="apptook-ds-pd-static-block">
								<p class="apptook-ds-pd-static-title"><?php esc_html_e('ประเภทบัญชี', 'apptook-digital-store'); ?></p>
								<div class="apptook-ds-pd-type-select-wrap">
									<select class="apptook-ds-pd-type-select" data-pd-type-select>
										<?php foreach ($types as $t) : ?>
											<?php $is_default = ! empty($t['is_default']); ?>
											<option value="<?php echo esc_attr((string) ($t['type_key'] ?? '')); ?>"<?php echo $is_default ? ' selected' : ''; ?>><?php echo esc_html((string) ($t['type_label'] ?? '')); ?></option>
										<?php endforeach; ?>
									</select>
									<span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
								</div>
							</div>
						<?php endif; ?>

						<?php if (is_user_logged_in()) : ?>
							<button
								type="button"
								class="apptook-ds-pd-buy-btn apptook-ds-buy"
								data-product-id="<?php echo esc_attr((string) $pid); ?>"
								data-durations="<?php echo esc_attr(is_string($durations_json) ? $durations_json : '[]'); ?>"
								data-types="<?php echo esc_attr(is_string($types_json) ? $types_json : '[]'); ?>"
								data-type-enabled="<?php echo esc_attr($type_enabled ? '1' : '0'); ?>"
								data-duration-enabled="<?php echo esc_attr($duration_enabled ? '1' : '0'); ?>"
								data-product-name="<?php echo esc_attr($title); ?>"
								data-price-text="<?php echo esc_attr($price_display); ?>"
								data-icon-html="<?php echo esc_attr($icon_html); ?>"
								data-pd-selected-month=""
								data-pd-selected-type=""
							>
								<?php esc_html_e('สั่งซื้อทันที', 'apptook-digital-store'); ?>
							</button>
						<?php else : ?>
							<a class="apptook-ds-pd-buy-btn is-login" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php esc_html_e('เข้าสู่ระบบเพื่อสั่งซื้อ', 'apptook-digital-store'); ?></a>
						<?php endif; ?>

						<a class="apptook-ds-pd-chat-btn" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('แชทสอบถามก่อนซื้อ', 'apptook-digital-store'); ?></a>

						<div class="apptook-ds-pd-trust">
							<p><span class="material-symbols-outlined" aria-hidden="true">verified_user</span><?php esc_html_e('ธุรกรรมปลอดภัย', 'apptook-digital-store'); ?></p>
							<p><span class="material-symbols-outlined" aria-hidden="true">support_agent</span><?php esc_html_e('มีแอดมินดูแลหลังการขาย', 'apptook-digital-store'); ?></p>
						</div>
					</div>
				</aside>
			</article>
		</div>
		<?php
		if ($apptook_public_view instanceof Apptook_DS_Public) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $apptook_public_view->render_site_footer();
		}
		?>
	</main>
	<?php
endwhile;

get_footer();
