(function () {
	'use strict';

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function closeModal(overlay) {
		if (overlay && overlay.parentNode) {
			overlay.parentNode.removeChild(overlay);
		}
	}

	function openModal(html) {
		var overlay = document.createElement('div');
		overlay.className = 'apptook-ds-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML = html;
		document.body.appendChild(overlay);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				closeModal(overlay);
			}
		});
		var closeBtn = qs('[data-apptook-close]', overlay);
		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				closeModal(overlay);
			});
		}
		return overlay;
	}

	function postFormData(action, data) {
		data.append('action', action);
		return fetch(apptookDS.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		}).then(function (r) {
			return r.json();
		});
	}

	function postAjax(action, extra) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', apptookDS.nonce);
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				fd.append(k, extra[k]);
			});
		}
		return fetch(apptookDS.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		}).then(function (r) {
			return r.json();
		});
	}

	function showPayStep(data) {
		var qr = '';
		if (data.qr_image_url) {
			qr =
				'<div class="apptook-ds-modal-qr"><img src="' +
				esc(data.qr_image_url) +
				'" alt="QR" /></div>';
		}
		var note = data.payment_note
			? '<p class="apptook-ds-modal-note">' + esc(data.payment_note) + '</p>'
			: '';
		var pp = data.promptpay_id
			? '<p class="apptook-ds-modal-note"><strong>พร้อมเพย์:</strong> ' +
			  esc(data.promptpay_id) +
			  '</p>'
			: '';

		var html =
			'<div class="apptook-ds-modal">' +
			'<h2>' +
			esc(apptookDS.i18n.payTitle) +
			'</h2>' +
			'<p class="apptook-ds-modal-amount">' +
			esc(String(data.amount)) +
			' บาท</p>' +
			pp +
			qr +
			note +
			'<div class="apptook-ds-modal-actions">' +
			'<button type="button" class="apptook-ds-btn apptook-ds-btn-primary" data-apptook-next-slip>' +
			esc(apptookDS.i18n.next) +
			'</button>' +
			'<button type="button" class="apptook-ds-btn apptook-ds-btn-secondary" data-apptook-close>' +
			esc(apptookDS.i18n.close) +
			'</button>' +
			'</div>' +
			'<p class="apptook-ds-modal-error" data-apptook-err style="display:none"></p>' +
			'</div>';

		var overlay = openModal(html);
		var errEl = qs('[data-apptook-err]', overlay);

		qs('[data-apptook-next-slip]', overlay).addEventListener('click', function () {
			closeModal(overlay);
			showSlipStep(data);
		});

		return { overlay: overlay, errEl: errEl };
	}

	function showSlipStep(data) {
		var html =
			'<div class="apptook-ds-modal">' +
			'<h2>' +
			esc(apptookDS.i18n.slipTitle) +
			'</h2>' +
			'<p class="apptook-ds-modal-note">' +
			esc(String(data.amount)) +
			' บาท · Order #' +
			esc(String(data.order_id)) +
			'</p>' +
			'<input type="file" class="apptook-ds-slip-input" accept="image/jpeg,image/png,image/webp" data-apptook-file />' +
			'<div class="apptook-ds-modal-actions">' +
			'<button type="button" class="apptook-ds-btn apptook-ds-btn-primary" data-apptook-send-slip>' +
			esc(apptookDS.i18n.confirmSlip) +
			'</button>' +
			'<button type="button" class="apptook-ds-btn apptook-ds-btn-secondary" data-apptook-close>' +
			esc(apptookDS.i18n.close) +
			'</button>' +
			'</div>' +
			'<p class="apptook-ds-modal-error" data-apptook-err style="display:none"></p>' +
			'</div>';

		var overlay = openModal(html);
		var fileInput = qs('[data-apptook-file]', overlay);
		var errEl = qs('[data-apptook-err]', overlay);

		qs('[data-apptook-send-slip]', overlay).addEventListener('click', function () {
			var f = fileInput.files && fileInput.files[0];
			if (!f) {
				errEl.style.display = 'block';
				errEl.textContent = 'เลือกรูปสลิปก่อน';
				return;
			}
			errEl.style.display = 'none';
			var fd = new FormData();
			fd.append('order_id', String(data.order_id));
			fd.append('nonce', data.upload_nonce);
			fd.append('slip', f);
			var btn = qs('[data-apptook-send-slip]', overlay);
			btn.disabled = true;
			btn.textContent = apptookDS.i18n.uploading;

			postFormData('apptook_ds_upload_slip', fd)
				.then(function (res) {
					if (!res.success) {
						throw new Error((res.data && res.data.message) || apptookDS.i18n.error);
					}
					closeModal(overlay);
					alert(res.data && res.data.message ? res.data.message : 'OK');
				})
				.catch(function (e) {
					errEl.style.display = 'block';
					errEl.textContent = e.message || apptookDS.i18n.error;
					btn.disabled = false;
					btn.textContent = apptookDS.i18n.confirmSlip;
				});
		});
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.apptook-ds-buy');
		if (!btn || typeof apptookDS === 'undefined') {
			return;
		}
		var productId = btn.getAttribute('data-product-id');
		if (!productId) {
			return;
		}
		e.preventDefault();
		btn.disabled = true;

		postAjax('apptook_ds_create_order', { product_id: productId })
			.then(function (res) {
				btn.disabled = false;
				if (!res.success) {
					alert((res.data && res.data.message) || apptookDS.i18n.error);
					return;
				}
				showPayStep(res.data);
			})
			.catch(function () {
				btn.disabled = false;
				alert(apptookDS.i18n.error);
			});
	});
})();
