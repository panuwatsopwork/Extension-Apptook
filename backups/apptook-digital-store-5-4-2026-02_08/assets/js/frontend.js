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

	function bindPopupLoginAjax() {
		if (!window.apptookDS) return;
		if (document.documentElement.getAttribute('data-apptook-login-delegated') === '1') return;
		document.documentElement.setAttribute('data-apptook-login-delegated', '1');

		document.addEventListener('submit', function (e) {
			var form = e.target;
			if (!form || !form.querySelector) return;
			var actionInput = form.querySelector('input[name="action"]');
			if (!actionInput || actionInput.value !== 'apptook_ds_login') return;

			e.preventDefault();

			var errBox = form.querySelector('.apptook-st-register-error');
			if (!errBox) {
				errBox = document.createElement('p');
				errBox.className = 'apptook-st-register-error';
				errBox.style.display = 'none';
				form.insertBefore(errBox, form.firstChild);
			}

			var fd = new FormData(form);
			fd.set('action', 'apptook_ds_login_popup');
			fd.set('nonce', apptookDS.nonce);

			fetch(apptookDS.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: fd,
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.success) {
						errBox.style.display = 'block';
						errBox.textContent = (res.data && res.data.message) || 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
						return;
					}
					window.location.reload();
				})
				.catch(function () {
					errBox.style.display = 'block';
					errBox.textContent = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
				});
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

	bindPopupLoginAjax();
	var popup = document.getElementById('apptook-st-login-modal');
	if (popup) {
		popup.addEventListener('click', function () {
			bindPopupLoginAjax();
		});
	}

	function startOrderFlow(productId, triggerBtn) {
		if (!productId || typeof apptookDS === 'undefined') {
			return;
		}
		if (triggerBtn) {
			triggerBtn.disabled = true;
		}

		postAjax('apptook_ds_create_order', { product_id: productId })
			.then(function (res) {
				if (triggerBtn) {
					triggerBtn.disabled = false;
				}
				if (!res.success) {
					alert((res.data && res.data.message) || apptookDS.i18n.error);
					return;
				}
				showPayStep(res.data);
			})
			.catch(function () {
				if (triggerBtn) {
					triggerBtn.disabled = false;
				}
				alert(apptookDS.i18n.error);
			});
	}

	function openStitchPurchaseModal(payload) {
		if (!payload || !payload.productId) {
			return;
		}

		var title = payload.productName || 'Product';
		var price = payload.priceText || '';
		var iconHtml = payload.iconHtml || '<span class="material-symbols-outlined">inventory_2</span>';
		var html =
			'<div class="apptook-st-purchase-overlay" role="dialog" aria-modal="true">' +
			'<div class="apptook-st-purchase-modal">' +
			'<button type="button" class="apptook-st-purchase-close" data-apptook-close><span class="material-symbols-outlined">close</span></button>' +
			'<div class="apptook-st-purchase-head">' +
			'<div class="apptook-st-purchase-head-left">' +
			'<div class="apptook-st-purchase-icon">' + iconHtml + '</div>' +
			'<div><h3 class="apptook-st-purchase-title">' + esc(title) + '</h3><p class="apptook-st-purchase-sub">Premium Subscription</p></div>' +
			'</div>' +
			'<div class="apptook-st-purchase-head-right"><div class="apptook-st-purchase-price">' + esc(price || '-') + '</div><div class="apptook-st-purchase-price-sub">' + esc(price || '-') + ' / month</div></div>' +
			'</div>' +
			'<div class="apptook-st-purchase-body">' +
			'<label class="apptook-st-purchase-label">Purchase months</label>' +
			'<div class="apptook-st-purchase-chip-grid">' +
			'<button type="button" class="apptook-st-duration-chip is-active">1 month</button>' +
			'<button type="button" class="apptook-st-duration-chip">3 months</button>' +
			'<button type="button" class="apptook-st-duration-chip">6 months</button>' +
			'<button type="button" class="apptook-st-duration-chip">12 months</button>' +
			'</div>' +
			'<label class="apptook-st-purchase-label">Select Type</label>' +
			'<select class="apptook-st-purchase-select"><option>1 profile Shared</option><option>Private Account (Full ownership)</option></select>' +
			'<div class="apptook-st-purchase-actions">' +
			'<button type="button" class="apptook-st-purchase-cancel" data-apptook-close>Cancel</button>' +
			'<button type="button" class="apptook-st-purchase-confirm">Go to payment <span class="material-symbols-outlined">arrow_forward</span></button>' +
			'</div>' +
			'</div>' +
			'</div>' +
			'</div>';

		var wrap = document.createElement('div');
		wrap.innerHTML = html;
		var overlay = wrap.firstChild;
		document.body.appendChild(overlay);

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay || e.target.closest('[data-apptook-close]')) {
				overlay.remove();
			}
		});

		overlay.querySelectorAll('.apptook-st-duration-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				overlay.querySelectorAll('.apptook-st-duration-chip').forEach(function (c) {
					c.classList.remove('is-active');
				});
				chip.classList.add('is-active');
			});
		});

		var confirmBtn = overlay.querySelector('.apptook-st-purchase-confirm');
		confirmBtn.addEventListener('click', function () {
			overlay.remove();
			startOrderFlow(payload.productId, payload.triggerBtn || null);
		});
	}

	window.ApptookDS = window.ApptookDS || {};
	window.ApptookDS.startOrderFlow = startOrderFlow;
	window.ApptookDS.openPurchaseModal = openStitchPurchaseModal;

	document.addEventListener('apptook:start-order', function (e) {
		var detail = e && e.detail ? e.detail : {};
		startOrderFlow(detail.productId, detail.triggerBtn || null);
	});

	document.addEventListener('apptook:open-purchase', function (e) {
		var detail = e && e.detail ? e.detail : {};
		openStitchPurchaseModal(detail);
	});

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
		openStitchPurchaseModal({
			productId: productId,
			productName: btn.getAttribute('data-product-name') || '',
			priceText: btn.getAttribute('data-price-text') || '',
			iconHtml: btn.getAttribute('data-icon-html') || '',
			triggerBtn: btn
		});
	});
})();
