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
	$durations = array();
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

	if ($duration_enabled && count($durations) <= 1) {
		$raw_durations = (string) get_post_meta($pid, '_apptook_duration_rows', true);
		if ($raw_durations !== '') {
			$duration_lines = preg_split("/\r\n|\n|\r/", $raw_durations);
			$parsed_durations = array();
			if (is_array($duration_lines)) {
				foreach ($duration_lines as $duration_line) {
					$duration_line = trim((string) $duration_line);
					if ($duration_line === '') {
						continue;
					}
					$parts = array_map('trim', explode('|', $duration_line));
					$months = isset($parts[0]) ? max(1, (int) $parts[0]) : 1;
					$row_price = isset($parts[1]) && $parts[1] !== '' ? (float) $parts[1] : $price_f;
					$is_default = isset($parts[2]) && (int) $parts[2] === 1 ? 1 : 0;
					$parsed_durations[] = array(
						'months' => $months,
						'price' => $row_price,
						'is_default' => $is_default,
					);
				}
			}
			if ($parsed_durations !== array()) {
				$has_default_duration = false;
				foreach ($parsed_durations as $row) {
					if ((int) ($row['is_default'] ?? 0) === 1) {
						$has_default_duration = true;
						break;
					}
				}
				if (! $has_default_duration) {
					$parsed_durations[0]['is_default'] = 1;
				}
				$durations = $parsed_durations;
			}
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

	if ($duration_enabled && $durations !== array()) {
		$has_duration_default = false;
		foreach ($durations as $duration_row) {
			if (! empty($duration_row['is_default'])) {
				$has_duration_default = true;
				break;
			}
		}
		if (! $has_duration_default && isset($durations[0])) {
			$durations[0]['is_default'] = 1;
		}
	}

	if ($type_enabled && $types !== array()) {
		$has_type_default = false;
		foreach ($types as $type_row) {
			if (! empty($type_row['is_default'])) {
				$has_type_default = true;
				break;
			}
		}
		if (! $has_type_default && isset($types[0])) {
			$types[0]['is_default'] = 1;
		}
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
					<div class="apptook-ds-pd-card apptook-ds-pd-left-shell">
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

						<div class="apptook-ds-pd-card apptook-ds-pd-config-card">
							<div class="apptook-ds-pd-config-head">
								<p><?php esc_html_e('ปรับแพ็กเกจที่ต้องการ', 'apptook-digital-store'); ?></p>
								<span><?php esc_html_e('เลือกให้เหมาะกับการใช้งาน', 'apptook-digital-store'); ?></span>
							</div>

							<?php if ($duration_enabled) : ?>
								<div class="apptook-ds-pd-static-block">
									<p class="apptook-ds-pd-static-title"><?php esc_html_e('เลือกระยะเวลา', 'apptook-digital-store'); ?></p>
									<div class="apptook-ds-pd-duration-grid">
										<?php foreach (array_slice($durations, 0, 10) as $d) : ?>
											<?php $months = (int) ($d['months'] ?? 1); ?>
											<button type="button" class="apptook-ds-pd-duration-pill<?php echo ! empty($d['is_default']) ? ' is-active' : ''; ?>" data-pd-month="<?php echo esc_attr((string) $months); ?>"><span class="month-title"><?php echo esc_html((string) $months); ?> month</span></button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ($type_enabled && ! empty($types)) : ?>
								<div class="apptook-ds-pd-static-block">
									<p class="apptook-ds-pd-static-title"><?php esc_html_e('ประเภทบัญชี', 'apptook-digital-store'); ?></p>
									<div class="apptook-ds-pd-choice-grid" data-pd-account-choice-grid>
										<?php foreach ($types as $t) : ?>
											<?php $is_active = ! empty($t['is_default']); ?>
											<button type="button" class="apptook-ds-pd-choice-btn<?php echo $is_active ? ' is-active' : ''; ?>" data-pd-account-choice data-type-key="<?php echo esc_attr((string) ($t['type_key'] ?? '')); ?>"><?php echo esc_html((string) ($t['type_label'] ?? '')); ?></button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<div class="apptook-ds-pd-static-block">
								<p class="apptook-ds-pd-static-title"><?php esc_html_e('ตัวเลือกสินค้า', 'apptook-digital-store'); ?></p>
								<div class="apptook-ds-pd-choice-grid" data-pd-product-choice-grid>
									<button type="button" class="apptook-ds-pd-choice-btn is-active" data-pd-product-choice data-variant-key="standard">Standard</button>
									<button type="button" class="apptook-ds-pd-choice-btn" data-pd-product-choice data-variant-key="plus">Plus</button>
								</div>
							</div>
						</div>

						<div class="apptook-ds-pd-card apptook-ds-pd-overview-card">
						<div class="apptook-ds-pd-overview-head">
							<p><?php esc_html_e('Product Insight', 'apptook-digital-store'); ?></p>
							<h2><?php esc_html_e('Product Overview', 'apptook-digital-store'); ?></h2>
							<span><?php esc_html_e('สรุปจุดเด่นสำคัญ ช่วยตัดสินใจได้เร็วขึ้น', 'apptook-digital-store'); ?></span>
						</div>
						<p class="apptook-ds-pd-overview-summary"><?php echo esc_html($description); ?></p>
						<ul class="apptook-ds-pd-feature-list">
							<?php foreach (array_slice($bullets, 0, 3) as $line) : ?>
								<li>
									<span class="material-symbols-outlined" aria-hidden="true">workspace_premium</span>
									<span><?php echo esc_html($line); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>

					</div>
				</section>

				<aside class="apptook-ds-pd-side">
					<div class="apptook-ds-pd-card apptook-ds-pd-purchase-card">
						<div class="apptook-ds-pd-summary-lines">
							<div class="apptook-ds-pd-summary-row">
								<span class="detail-muted-label"><?php esc_html_e('ราคาสุทธิ:', 'apptook-digital-store'); ?></span>
								<span class="apptook-ds-pd-summary-value" data-pd-live-net><?php echo esc_html($price_display . '/ month'); ?></span>
							</div>
							<div class="apptook-ds-pd-summary-row">
								<span class="detail-muted-label"><?php esc_html_e('รหัสโปรโมชั่นและคูปอง:', 'apptook-digital-store'); ?></span>
								<span class="apptook-ds-pd-summary-value" data-pd-live-coupon>฿0.00</span>
							</div>
							<div class="apptook-ds-pd-summary-row is-grand">
								<span class="detail-muted-label"><?php esc_html_e('ทั้งหมด:', 'apptook-digital-store'); ?></span>
								<div class="apptook-ds-pd-summary-grand-right">
									<div class="apptook-ds-pd-summary-total" data-pd-live-total><?php echo esc_html($price_display); ?></div>
									<p class="apptook-ds-pd-total-monthly" data-pd-live-monthly><?php echo esc_html($period); ?></p>
								</div>
							</div>
						</div>

						<div class="apptook-ds-pd-promo-wrap" data-pd-promo-wrap>
							<button type="button" class="apptook-ds-pd-promo-toggle" data-pd-promo-toggle>
								<span><?php esc_html_e('มีรหัสโปรโมชั่นหรือคูปองส่วนลดไหม?', 'apptook-digital-store'); ?></span>
								<span class="material-symbols-outlined" data-pd-promo-icon aria-hidden="true">expand_more</span>
							</button>
							<div class="apptook-ds-pd-promo-panel" data-pd-promo-panel hidden>
								<div class="apptook-ds-pd-promo-row">
									<input class="apptook-ds-pd-promo-input" data-pd-promo-input type="text" placeholder="<?php echo esc_attr__('ใส่รหัสโปรโมชั่น', 'apptook-digital-store'); ?>" />
									<button type="button" class="apptook-ds-pd-promo-apply" data-pd-promo-apply><?php esc_html_e('นำมาใช้', 'apptook-digital-store'); ?></button>
								</div>
							</div>
						</div>

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

						<div class="apptook-ds-pd-recent-buyer">
							<div class="apptook-ds-pd-recent-buyer-head">
								<span><?php esc_html_e('มีผู้ใช้งานซื้อสินค้านี้ล่าสุด', 'apptook-digital-store'); ?></span>
								<a href="#"><?php esc_html_e('คลิกค้างแล้วลากเพื่อดูเพิ่มเติม', 'apptook-digital-store'); ?></a>
							</div>
							<div class="apptook-ds-pd-recent-buyer-ticker-wrap">
								<div class="apptook-ds-pd-recent-buyer-ticker">
									<div class="apptook-ds-pd-recent-buyer-inline-item"><span class="material-symbols-outlined" aria-hidden="true">person</span><strong>ma***06</strong><em>เข้าร่วมเมื่อ 16 ชั่วโมงที่แล้ว</em></div>
									<div class="apptook-ds-pd-recent-buyer-inline-item"><span class="material-symbols-outlined" aria-hidden="true">person</span><strong>ka***ss</strong><em>เข้าร่วมเมื่อ 17 ชั่วโมงที่แล้ว</em></div>
									<div class="apptook-ds-pd-recent-buyer-inline-item"><span class="material-symbols-outlined" aria-hidden="true">person</span><strong>miku***z1</strong><em>เข้าร่วมเมื่อ 1 วันที่แล้ว</em></div>
								</div>
							</div>
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
