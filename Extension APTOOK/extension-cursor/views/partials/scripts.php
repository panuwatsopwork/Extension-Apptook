<?php if (! defined('ABSPATH')) { exit; } ?>
<script>
window.ExtensionCursorAdminViewData = <?php echo wp_json_encode(array(
	'stats' => $stats,
	'keys' => $keys,
	'allKeys' => $all_keys,
	'licences' => $licences,
	'availableKeys' => $available_keys,
	'monitorRows' => $monitor_rows,
	'monitorDetail' => $monitor_detail,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.ExtensionCursorModules = {
	createEcApi: function ({ ajaxUrl, nonce }) {
		return { post: function (action, data) { return jQuery.post(ajaxUrl, Object.assign({ action: action, nonce: nonce }, data || {})); } };
	},
	createEcUI: function ($) {
		var noticeTimer = null;
		return {
			pressEffect: function ($el) { $el.addClass('is-pressed'); window.setTimeout(function () { $el.removeClass('is-pressed'); }, 160); },
			setLoading: function ($button, isLoading, text) {
				if (!$button || !$button.length) return;
				if (isLoading) { $button.data('original-text', $button.text()); $button.prop('disabled', true).addClass('is-loading').text(text || 'Saving...'); return; }
				$button.prop('disabled', false).removeClass('is-loading'); var original = $button.data('original-text'); if (original) $button.text(original);
			},
			setNotice: function (message, type) {
				var $notice = $('#ecNotice');
				$notice.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(message).addClass('is-visible');
				window.clearTimeout(noticeTimer);
				noticeTimer = window.setTimeout(function () { $notice.removeClass('is-visible'); }, 12000);
			},
			escapeHtml: function (value) {
				return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
			}
		};
	},
	createEcRenderers: function ($, helpers) {
		return {
			renderLicenceList: function (licences) {
				return licences.length ? licences.map(function (licence) { return '<div class="ec-licence-item"><label class="ec-checkbox-item"><input type="checkbox" value="' + licence.id + '"><span><strong>' + helpers.escapeHtml(licence.token) + '</strong><small>' + helpers.escapeHtml(String(licence.duration_days)) + ' days • ' + helpers.escapeHtml(String(licence.token_limit)) + ' tokens • ' + helpers.escapeHtml(licence.status) + '</small></span></label><button class="ec-btn ec-btn-soft ec-delete-licence" type="button" data-id="' + licence.id + '">Delete</button></div>'; }).join('') : '<div class="ec-mini-note">ยังไม่มี licence ในฐานข้อมูล กรุณา import ข้อมูลก่อน</div>';
			},
			renderMonitorRows: function (rows) {
				return rows.length ? rows.map(function (row) { return '<tr data-monitor-key="' + row.id + '"><td><button class="ec-btn ec-btn-soft ec-monitor-key" type="button">' + helpers.escapeHtml(row.key_code) + '</button></td><td>' + helpers.escapeHtml(String(row.licence_count)) + '</td><td>' + helpers.escapeHtml(row.expiry_date || '-') + '</td><td>-</td><td>' + helpers.escapeHtml(row.status) + '</td><td><button class="ec-btn ec-btn-soft ec-delete-key" type="button" data-id="' + row.id + '">Delete</button></td></tr>'; }).join('') : '<tr><td colspan="6">ยังไม่มี key ในฐานข้อมูล</td></tr>';
			},
			renderMonitorDetail: function (monitor) {
				if (monitor && monitor.key) {
					$('#ecMonitorKeyTitle').text(monitor.key.key_code || '-'); $('#ecMonitorKeyDescription').text('สรุปรายละเอียดของ APPTOOK key ที่เลือก รวมถึงวันหมดอายุและ usage ที่เหลือจาก token capacity รวมของ licences ที่ผูกอยู่ครับ'); $('#ecMonitorExpiry').text(monitor.key.expiry_date || '-'); $('#ecMonitorUsage').text((monitor.usage ?? 0) + '%'); $('#ecMonitorNote').text('ในระบบจริง ค่านี้จะอัปเดตจาก usage ที่ส่งมาจาก Extension ของ user และใช้คำนวณ % คงเหลือของ key อีกทีครับ'); var rows = Array.isArray(monitor.licences) && monitor.licences.length ? monitor.licences.map(function (licence) { return '<tr><td>' + helpers.escapeHtml(licence.token) + '</td><td>' + helpers.escapeHtml(String(licence.duration_days)) + ' days</td><td>' + helpers.escapeHtml(String(licence.token_limit)) + '</td><td>' + helpers.escapeHtml(String(licence.raw_use)) + '</td><td>' + helpers.escapeHtml(licence.runtime_expiry || '-') + '</td></tr>'; }).join('') : '<tr><td colspan="5">ยังไม่มี licence ที่เชื่อมกับ key นี้</td></tr>'; $('#ecMonitorLicenceTable').html(rows); return; }
				$('#ecMonitorKeyTitle').text('-'); $('#ecMonitorExpiry').text('-'); $('#ecMonitorUsage').text('0%'); $('#ecMonitorLicenceTable').html('<tr><td colspan="5">ยังไม่มี licence ที่เชื่อมกับ key นี้</td></tr>');
			}
		};
	},
	createEcState: function () { return { loadingKey: false }; },
	createEcActions: function ({ api, ui, renderers, state, $, elements }) { return window.ExtensionCursorActionsFactory({ api: api, ui: ui, renderers: renderers, state: state, $, elements: elements }); }
};
</script>
