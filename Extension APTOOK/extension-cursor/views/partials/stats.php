<?php if (! defined('ABSPATH')) { exit; } ?>
<section class="ec-grid-stats">
	<div class="ec-stat"><div class="ec-label">Keys Ready</div><div class="ec-value"><?php echo esc_html((string) $stats['keys_ready']); ?></div><div class="ec-hint">APPTOOK key ที่พร้อมใช้งาน</div></div>
	<div class="ec-stat"><div class="ec-label">Licences Available</div><div class="ec-value"><?php echo esc_html((string) $stats['licences_available']); ?></div><div class="ec-hint">licence ที่ยังไม่ถูกผูกกับ key</div></div>
	<div class="ec-stat"><div class="ec-label">Mapped Licences</div><div class="ec-value"><?php echo esc_html((string) $stats['mapped_licences']); ?></div><div class="ec-hint">licence ที่ถูก assign แล้ว</div></div>
	<div class="ec-stat"><div class="ec-label">Needs Review</div><div class="ec-value"><?php echo esc_html((string) $stats['needs_review']); ?></div><div class="ec-hint">รายการซ้ำ หมดอายุ หรือข้อมูลไม่ครบ</div></div>
</section>
