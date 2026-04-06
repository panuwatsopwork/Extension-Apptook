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

while (have_posts()) :
	the_post();
	$pid   = get_the_ID();
	$price = get_post_meta($pid, '_apptook_price', true);
	?>
	<main id="primary" class="site-main apptook-ds apptook-ds-single-product">
		<article id="post-<?php the_ID(); ?>" <?php post_class('apptook-ds-product-article'); ?>>
			<header class="apptook-ds-product-header">
				<h1 class="apptook-ds-product-title"><?php the_title(); ?></h1>
				<p class="apptook-ds-product-price">
					<?php echo esc_html((string) $price); ?>
					<span class="apptook-ds-currency"><?php esc_html_e('บาท', 'apptook-digital-store'); ?></span>
				</p>
			</header>

			<?php if (has_post_thumbnail()) : ?>
				<div class="apptook-ds-product-media">
					<?php the_post_thumbnail('large'); ?>
				</div>
			<?php endif; ?>

			<div class="apptook-ds-product-content entry-content">
				<?php the_content(); ?>
			</div>

			<div class="apptook-ds-product-actions">
				<?php if (is_user_logged_in()) : ?>
					<button type="button" class="apptook-ds-btn apptook-ds-btn-primary apptook-ds-buy" data-product-id="<?php echo esc_attr((string) $pid); ?>">
						<?php esc_html_e('ซื้อสินค้านี้', 'apptook-digital-store'); ?>
					</button>
				<?php else : ?>
					<p class="apptook-ds-login-hint">
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: login URL */
								__('กรุณา <a href="%s">เข้าสู่ระบบ</a> ก่อนซื้อ', 'apptook-digital-store'),
								esc_url(wp_login_url(get_permalink()))
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</article>
	</main>
	<?php
endwhile;

get_footer();
