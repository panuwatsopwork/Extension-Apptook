(function () {
	'use strict';

	if (window.__apptookFrontendBooted) {
		return;
	}
	window.__apptookFrontendBooted = true;

	var APTOOK_DEBUG = true;
	function dlog() {
		if (!APTOOK_DEBUG || !window.console || typeof console.log !== 'function') return;
		var args = Array.prototype.slice.call(arguments);
		args.unshift('[APTOOK-DEBUG][frontend]');
		console.log.apply(console, args);
	}

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function closeModal(overlay) {
		dlog('closeModal called', { hasOverlay: !!overlay });
		if (overlay && overlay.parentNode) {
			overlay.parentNode.removeChild(overlay);
			dlog('closeModal removed overlay');
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
		overlay.querySelectorAll('[data-apptook-close]').forEach(function (closeBtn) {
			closeBtn.addEventListener('click', function () {
				closeModal(overlay);
			});
		});
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

	function fileToDataUrl(file) {
		return new Promise(function (resolve) {
			if (!file) {
				resolve('');
				return;
			}
			try {
				var reader = new FileReader();
				reader.onload = function (evt) {
					resolve(evt && evt.target && typeof evt.target.result === 'string' ? evt.target.result : '');
				};
				reader.onerror = function () {
					resolve('');
				};
				reader.readAsDataURL(file);
			} catch (e) {
				resolve('');
			}
		});
	}

	function showToastSuccess(message) {
		var html =
			'<div class="apptook-ds-modal apptook-ds-modal--success">' +
			'<h2>สำเร็จ</h2>' +
			'<p class="apptook-ds-modal-note">' + esc(message || 'ดำเนินการสำเร็จ') + '</p>' +
			'<div class="apptook-ds-modal-actions">' +
			'<button type="button" class="apptook-ds-btn apptook-ds-btn-primary" data-apptook-close>ตกลง</button>' +
			'</div>' +
			'</div>';
		openModal(html);
	}

	function showPayStep(data) {
		var amountText = esc(String(data.amount || '0'));
		var promptpayText = data.promptpay_id ? esc(String(data.promptpay_id)) : '-';
		var referenceText = data.order_ref
			? esc(String(data.order_ref))
			: (data.order_id ? 'PMT-' + esc(String(data.order_id)) : '-');
		var paymentNote = data.payment_note
			? '<p class="apptook-ds-pay-qr-note">' + esc(String(data.payment_note)) + '</p>'
			: '<p class="apptook-ds-pay-qr-note">สแกน QR ผ่าน Mobile Banking แล้วอัปโหลดสลิปยืนยันการชำระเงิน</p>';
		var qrMarkup = data.qr_image_url
			? '<img src="' + esc(data.qr_image_url) + '" alt="PromptPay QR" />'
			: '<p class="apptook-ds-pay-qr-note">ไม่พบรูป QR สำหรับการชำระเงิน</p>';

		var html =
			'<div class="apptook-ds-modal apptook-ds-modal--payment">' +
				'<button type="button" class="apptook-ds-pay-close" data-apptook-close aria-label="ปิด">×</button>' +
				'<div class="apptook-ds-pay-head">' +
					'<p class="apptook-ds-pay-head-kicker">SECURE CHECKOUT</p>' +
					'<h2>พร้อมเพย์ QR Payment</h2>' +
					'<div class="apptook-ds-pay-badges">' +
						'<span class="apptook-ds-pay-badge">⚡ ชำระเงินได้ทันที</span>' +
						'<span class="apptook-ds-pay-badge is-amount">ยอดสุทธิ ' + amountText + ' บาท</span>' +
					'</div>' +
				'</div>' +
				'<div class="apptook-ds-pay-body">' +
					'<div class="apptook-ds-pay-qr-card">' +
						'<div class="apptook-ds-pay-qr-title-row">' +
							'<p class="apptook-ds-pay-qr-title">QR พร้อมเพย์สำหรับชำระเงิน</p>' +
							'<span class="apptook-ds-pay-qr-ref">Ref: ' + referenceText + '</span>' +
						'</div>' +
						'<div class="apptook-ds-pay-qr-wrap">' + qrMarkup + '</div>' +
						 paymentNote +
					'</div>' +
					'<div class="apptook-ds-pay-info-col">' +
						'<div class="apptook-ds-pay-info-card">' +
							'<p class="apptook-ds-pay-info-label">ยอดที่ต้องชำระ</p>' +
							'<p class="apptook-ds-pay-info-value">' + amountText + ' บาท</p>' +
						'</div>' +
						'<div class="apptook-ds-pay-info-card">' +
							'<p class="apptook-ds-pay-info-label">พร้อมเพย์</p>' +
							'<p class="apptook-ds-pay-info-value is-id">' + promptpayText + '</p>' +
						'</div>' +
						'<div class="apptook-ds-pay-info-card">' +
							'<p class="apptook-ds-pay-info-label">สถานะการชำระ</p>' +
							'<p class="apptook-ds-pay-info-value is-pending">รอยืนยันสลิป</p>' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div class="apptook-ds-pay-actions">' +
					'<button type="button" class="apptook-ds-btn apptook-ds-btn-primary" data-apptook-next-slip>อัปโหลดสลิปการโอน</button>' +
					'<button type="button" class="apptook-ds-btn apptook-ds-btn-secondary" data-apptook-close>' + esc(apptookDS.i18n.close) + '</button>' +
				'</div>' +
				'<p class="apptook-ds-modal-error" data-apptook-err style="display:none"></p>' +
			'</div>';

		var overlay = openModal(html);
		var errEl = qs('[data-apptook-err]', overlay);
		var nextBtn = qs('[data-apptook-next-slip]', overlay);
		if (nextBtn) {
			nextBtn.addEventListener('click', function () {
				closeModal(overlay);
				showSlipStep(data);
			});
		}

		return { overlay: overlay, errEl: errEl };
	}

	function showSlipStep(data) {
		var mobileQrImg = data && data.mobile_verify_qr_url
			? '<img src="' + esc(String(data.mobile_verify_qr_url)) + '" alt="QR อัปโหลดสลิปผ่านมือถือ" />'
			: '<p class="apptook-ds-slip-qr-empty">ยังไม่มี QR สำหรับแสดงผล</p>';
		var html =
			'<div class="apptook-ds-modal apptook-ds-modal--slip">' +
				'<button type="button" class="apptook-ds-slip-close" data-apptook-close aria-label="ปิด">×</button>' +
				'<div class="apptook-ds-slip-top">' +
					'<p class="apptook-ds-slip-kicker">PAYMENT VERIFICATION</p>' +
					'<h2>อัปโหลดสลิปการโอน</h2>' +
					'<p class="apptook-ds-slip-subtitle">เลือกโหมดอัปโหลดจากด้านล่าง</p>' +
				'</div>' +
				'<div class="apptook-ds-slip-tabs" role="tablist" aria-label="โหมดอัปโหลดสลิป">' +
					'<button type="button" class="apptook-ds-slip-tab is-active" data-apptook-slip-tab="upload" role="tab" aria-selected="true">ลากไฟล์ปกติ</button>' +
					'<button type="button" class="apptook-ds-slip-tab" data-apptook-slip-tab="mobile" role="tab" aria-selected="false">Upload via Mobile</button>' +
				'</div>' +
				'<div class="apptook-ds-slip-panel is-active" data-apptook-slip-panel="upload">' +
					'<input type="file" class="apptook-ds-slip-input" accept="image/jpeg,image/png,image/webp" data-apptook-file />' +
					'<div class="apptook-ds-slip-drop-zone-wrap">' +
						'<div class="apptook-ds-slip-drop-zone" data-apptook-drop-zone>' +
							'<span class="material-symbols-outlined" aria-hidden="true">cloud_upload</span>' +
							'<p class="apptook-ds-slip-drop-title">ลากไฟล์สลิปมาวางที่นี่</p>' +
							'<p class="apptook-ds-slip-drop-sub">รองรับไฟล์ .jpg, .jpeg, .png สูงสุด 10MB</p>' +
							'<button type="button" class="apptook-ds-slip-drop-choose" data-apptook-pick-file>Choose file</button>' +
							'<p class="apptook-ds-slip-file-name" data-apptook-file-name>ยังไม่ได้เลือกไฟล์</p>' +
							'<div class="apptook-ds-slip-preview" data-apptook-slip-preview-wrap hidden>' +
								'<img data-apptook-slip-preview alt="Slip preview" />' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div class="apptook-ds-slip-panel" data-apptook-slip-panel="mobile" hidden>' +
					'<div class="apptook-ds-slip-mobile-qr-wrap">' +
						'<p class="apptook-ds-slip-mobile-title">1) สแกน QR ด้วยมือถือ 2) อัปโหลดสลิป 3) กลับมากดยืนยันที่คอม</p>' +
						'<div class="apptook-ds-slip-mobile-qr">' + mobileQrImg + '</div>' +
						'<p class="apptook-ds-slip-mobile-status" data-apptook-mobile-status>ยังไม่อัปโหลดจากมือถือ</p>' +
						'<div class="apptook-ds-slip-preview" data-apptook-mobile-preview-wrap hidden><img data-apptook-mobile-preview alt="Mobile slip preview" /></div>' +
					'</div>' +
				'</div>' +
				'<div class="apptook-ds-slip-actions">' +
					'<button type="button" class="apptook-ds-btn apptook-ds-btn-primary" data-apptook-send-slip>' + esc(apptookDS.i18n.confirmSlip) + '</button>' +
					'<button type="button" class="apptook-ds-btn apptook-ds-btn-secondary" data-apptook-close>' + esc(apptookDS.i18n.close) + '</button>' +
				'</div>' +
				'<p class="apptook-ds-modal-error" data-apptook-err style="display:none"></p>' +
			'</div>';

		var overlay = openModal(html);
		var fileInput = qs('[data-apptook-file]', overlay);
		var errEl = qs('[data-apptook-err]', overlay);
		var fileNameEl = qs('[data-apptook-file-name]', overlay);
		var previewWrap = qs('[data-apptook-slip-preview-wrap]', overlay);
		var previewImg = qs('[data-apptook-slip-preview]', overlay);
		var dropZone = qs('[data-apptook-drop-zone]', overlay);
		var sendBtn = qs('[data-apptook-send-slip]', overlay);
		var mobileStatusEl = qs('[data-apptook-mobile-status]', overlay);
		var mobilePreviewWrap = qs('[data-apptook-mobile-preview-wrap]', overlay);
		var mobilePreviewImg = qs('[data-apptook-mobile-preview]', overlay);
		var pickers = overlay.querySelectorAll('[data-apptook-pick-file]');
		var tabBtns = overlay.querySelectorAll('[data-apptook-slip-tab]');
		var panels = overlay.querySelectorAll('[data-apptook-slip-panel]');
		var activeTab = 'upload';
		var pollId = null;

		function setPrimaryButtonState() {
			if (!sendBtn) return;
			if (activeTab === 'mobile') {
				sendBtn.textContent = 'ยืนยันส่งหลักฐานการโอน';
				var hasMobileSlip = mobilePreviewWrap && !mobilePreviewWrap.hidden;
				sendBtn.disabled = !hasMobileSlip;
			} else {
				sendBtn.textContent = apptookDS.i18n.confirmSlip;
				sendBtn.disabled = false;
			}
		}

		function applyMobileStatus(payload) {
			if (!payload) return;
			if (mobileStatusEl) {
				mobileStatusEl.textContent = payload.mobile_uploaded ? 'อัปโหลดแล้วจากมือถือ' : 'ยังไม่อัปโหลดจากมือถือ';
			}
			if (mobilePreviewWrap && mobilePreviewImg) {
				if (payload.mobile_uploaded && payload.preview_url) {
					mobilePreviewImg.src = payload.preview_url;
					mobilePreviewWrap.hidden = false;
				} else {
					mobilePreviewWrap.hidden = true;
					mobilePreviewImg.removeAttribute('src');
				}
			}
			if (payload.mobile_uploaded) {
				overlay.classList.add('apptook-ds-mobile-has-preview');
			} else {
				overlay.classList.remove('apptook-ds-mobile-has-preview');
			}
			setPrimaryButtonState();
		}

		function pollMobileStatus() {
			if (!data.mobile_session_token || !data.mobile_status_nonce) return;
			var fd = new FormData();
			fd.append('session_token', String(data.mobile_session_token));
			fd.append('nonce', data.mobile_status_nonce);
			postFormData('apptook_ds_mobile_verify_status', fd).then(function (res) {
				if (res && res.success && res.data && res.data.data) {
					applyMobileStatus(res.data.data);
				}
			});
		}

		function ensurePolling() {
			if (pollId) return;
			pollMobileStatus();
			pollId = window.setInterval(function () {
				if (!document.body.contains(overlay)) {
					window.clearInterval(pollId);
					pollId = null;
					return;
				}
				pollMobileStatus();
			}, 4000);
		}

		function setActiveTab(tab) {
			activeTab = tab === 'mobile' ? 'mobile' : 'upload';
			tabBtns.forEach(function (btn) {
				var isActive = btn.getAttribute('data-apptook-slip-tab') === activeTab;
				btn.classList.toggle('is-active', isActive);
				btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
			});
			panels.forEach(function (panel) {
				var isActive = panel.getAttribute('data-apptook-slip-panel') === activeTab;
				panel.classList.toggle('is-active', isActive);
				panel.hidden = !isActive;
			});
			if (errEl) errEl.style.display = 'none';
			setPrimaryButtonState();
			if (activeTab === 'mobile') ensurePolling();
		}

		tabBtns.forEach(function (btn) {
			btn.addEventListener('click', function () {
				setActiveTab(btn.getAttribute('data-apptook-slip-tab') || 'upload');
			});
		});

		function setSelectedFile(file) {
			if (fileNameEl) fileNameEl.textContent = file ? file.name : 'ยังไม่ได้เลือกไฟล์';
			if (dropZone) {
				dropZone.classList.toggle('is-selected', !!file);
				dropZone.classList.toggle('has-preview', !!file);
			}
			if (!previewWrap || !previewImg) return;
			if (!file) {
				previewWrap.hidden = true;
				previewImg.removeAttribute('src');
				return;
			}
			fileToDataUrl(file).then(function (dataUrl) {
				if (!dataUrl) {
					previewWrap.hidden = true;
					previewImg.removeAttribute('src');
					return;
				}
				previewImg.src = dataUrl;
				previewWrap.hidden = false;
			});
		}

		function setInputFile(file) {
			if (!fileInput || !file) return;
			try {
				var dt = new DataTransfer();
				dt.items.add(file);
				fileInput.files = dt.files;
			} catch (err) {}
			setSelectedFile(file);
		}

		if (pickers && pickers.length && fileInput) {
			pickers.forEach(function (btn) {
				btn.addEventListener('click', function () { fileInput.click(); });
			});
		}
		if (fileInput) {
			fileInput.addEventListener('change', function () {
				var f = fileInput.files && fileInput.files[0];
				setSelectedFile(f || null);
			});
		}
		if (dropZone) {
			['dragenter', 'dragover'].forEach(function (name) {
				dropZone.addEventListener(name, function (e) { e.preventDefault(); e.stopPropagation(); dropZone.classList.add('is-drag-over'); });
			});
			['dragleave', 'drop'].forEach(function (name) {
				dropZone.addEventListener(name, function (e) { e.preventDefault(); e.stopPropagation(); dropZone.classList.remove('is-drag-over'); });
			});
			dropZone.addEventListener('drop', function (e) {
				var dropped = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
				if (dropped) setInputFile(dropped);
			});
		}

		sendBtn.addEventListener('click', function () {
			if (activeTab === 'mobile') {
				if (!data.mobile_session_token || !data.mobile_confirm_nonce) {
					errEl.style.display = 'block';
					errEl.textContent = 'ไม่พบข้อมูลยืนยันรายการ';
					return;
				}
				errEl.style.display = 'none';
				sendBtn.disabled = true;
				sendBtn.textContent = apptookDS.i18n.uploading;
				var cfd = new FormData();
				cfd.append('session_token', String(data.mobile_session_token));
				cfd.append('nonce', data.mobile_confirm_nonce);
				postFormData('apptook_ds_mobile_verify_confirm', cfd).then(function (res) {
					if (!res.success) throw new Error((res.data && res.data.message) || apptookDS.i18n.error);
					closeModal(overlay);
					showToastSuccess((res.data && res.data.message) || 'ส่งหลักฐานสำเร็จ รอแอดมินตรวจสอบ');
				}).catch(function (e) {
					errEl.style.display = 'block';
					errEl.textContent = e.message || apptookDS.i18n.error;
					sendBtn.disabled = false;
					setPrimaryButtonState();
				});
				return;
			}

			var f = fileInput.files && fileInput.files[0];
			if (!f) {
				errEl.style.display = 'block';
				errEl.textContent = 'เลือกรูปสลิปก่อน';
				return;
			}
			var allowed = ['image/jpeg', 'image/png', 'image/webp'];
			if (allowed.indexOf(f.type) === -1) {
				errEl.style.display = 'block';
				errEl.textContent = 'รองรับเฉพาะไฟล์ JPG, JPEG, PNG หรือ WEBP';
				return;
			}
			if ((f.size || 0) > 10 * 1024 * 1024) {
				errEl.style.display = 'block';
				errEl.textContent = 'ขนาดไฟล์ต้องไม่เกิน 10MB';
				return;
			}
			errEl.style.display = 'none';
			var fd = new FormData();
			if (data.order_id) fd.append('order_id', String(data.order_id));
			if (data.product_id) fd.append('product_id', String(data.product_id));
			fd.append('nonce', data.upload_nonce);
			fd.append('slip', f);
			sendBtn.disabled = true;
			sendBtn.textContent = apptookDS.i18n.uploading;
			postFormData('apptook_ds_upload_slip', fd)
				.then(function (res) {
					if (!res.success) throw new Error((res.data && res.data.message) || apptookDS.i18n.error);
					closeModal(overlay);
					showToastSuccess(res.data && res.data.message ? res.data.message : 'อัปโหลดสลิปแล้ว');
				})
				.catch(function (e) {
					errEl.style.display = 'block';
					errEl.textContent = e.message || apptookDS.i18n.error;
					sendBtn.disabled = false;
					setPrimaryButtonState();
				});
		});

		setPrimaryButtonState();
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
		dlog('openStitchPurchaseModal called', payload);
		if (!payload || !payload.productId) {
			return;
		}

		document.querySelectorAll('.apptook-st-purchase-overlay').forEach(function (existing) {
			existing.remove();
		});

		var title = payload.productName || 'Product';
		var iconHtml = payload.iconHtml || '<span class="material-symbols-outlined">inventory_2</span>';
		var durations = Array.isArray(payload.durations) ? payload.durations : [];
		var types = Array.isArray(payload.types) ? payload.types : [];
		var hasType = !!payload.typeEnabled && types.length > 0;

		if (!durations.length) {
			durations = [{ months: 1, price: 0, is_default: 1 }];
		}

		var hasDurationDefault = durations.some(function (d) {
			return String(d && d.is_default ? d.is_default : '0') === '1';
		});

		var durationHtml = durations.map(function (d, i) {
			var months = parseInt(d.months || '1', 10);
			var isDefault = hasDurationDefault ? String(d.is_default || '0') === '1' : i === 0;
			return '<button type="button" class="duration-chip' + (isDefault ? ' chip-active' : '') + '" data-months="' + esc(String(months)) + '" data-price="' + esc(String(d.price || '0')) + '"><span class="month-title">' + esc(String(months)) + ' month</span></button>';
		}).join('');

		var hasTypeDefault = types.some(function (t) {
			return String(t && t.is_default ? t.is_default : '0') === '1';
		});

		var typeChipsHtml = hasType
			? types.map(function (t, i) {
				var label = t.type_label || t.type_key || ('Type ' + (i + 1));
				var isDefaultType = hasTypeDefault ? String(t.is_default || '0') === '1' : i === 0;
				return '<button type="button" class="modal-choice-btn' + (isDefaultType ? ' is-active' : '') + '" data-choice="account" data-type-key="' + esc(String(t.type_key || '')) + '" data-modifier="' + esc(String(t.price_modifier || '0')) + '">' + esc(String(label)) + '</button>';
			}).join('')
			: '<button type="button" class="modal-choice-btn is-active" data-choice="account" data-type-key="default" data-modifier="0">1 Profile (Shared)</button>';

		var html = '' +
			'<div class="apptook-st-purchase-overlay" role="dialog" aria-modal="true">' +
				'<div class="apptook-st-purchase-modal">' +
					'<button type="button" class="apptook-st-purchase-close" data-apptook-close><span class="material-symbols-outlined">close</span></button>' +
					'<div class="apptook-st-purchase-top">' +
						'<div class="apptook-st-purchase-top-row">' +
							'<div class="apptook-st-purchase-icon">' + iconHtml + '</div>' +
							'<div class="apptook-st-purchase-top-main">' +
								'<div class="apptook-st-purchase-price-wrap">' +
									'<h3 class="apptook-st-purchase-price-value" data-apptook-live-price>฿0.00</h3>' +
									'<p class="apptook-st-purchase-price-month" data-apptook-live-price-sub>฿0.00 / month</p>' +
								'</div>' +
								'<p class="apptook-st-purchase-product-name" data-apptook-title>' + esc(title) + '</p>' +
								'<p class="apptook-st-purchase-account-hint">Shared Account</p>' +
							'</div>' +
						'</div>' +
					'</div>' +
					'<div class="apptook-st-purchase-body">' +
						'<div class="apptook-st-purchase-card">' +
							'<p class="apptook-st-purchase-card-title">เลือกแพ็กเกจที่ต้องการ</p>' +
							'<div class="apptook-st-section"><p class="apptook-st-purchase-label">เลือกระยะเวลา</p><div class="apptook-st-chip-row" data-apptook-month-grid>' + durationHtml + '</div></div>' +
							'<div class="apptook-st-section"><p class="apptook-st-purchase-label">ประเภทบัญชี</p><div class="apptook-st-chip-row" data-apptook-type-grid>' + typeChipsHtml + '</div></div>' +
							'<div class="apptook-st-section"><p class="apptook-st-purchase-label">ตัวเลือกสินค้า</p><div class="apptook-st-chip-row" data-apptook-product-grid><button type="button" class="modal-choice-btn is-active" data-choice="product">Standard</button><button type="button" class="modal-choice-btn" data-choice="product">Plus</button></div></div>' +
							'<div class="apptook-st-promo"><button type="button" class="apptook-st-promo-toggle" data-apptook-promo-toggle><span>Have a Promo Code or Coupon?</span><span class="material-symbols-outlined" data-apptook-promo-icon>expand_more</span></button><div class="apptook-st-promo-panel hidden" data-apptook-promo-panel><div class="apptook-st-promo-row"><input class="apptook-st-promo-input" placeholder="Enter promo code" type="text"/><button type="button" class="apptook-st-promo-apply">Apply</button></div></div></div>' +
						'</div>' +
					'</div>' +
					'<div class="apptook-st-purchase-actions">' +
						'<button type="button" class="apptook-st-purchase-cancel" data-apptook-close>Cancel</button>' +
						'<button type="button" class="apptook-st-purchase-confirm">Go to payment</button>' +
					'</div>' +
				'</div>' +
			'</div>';

		var wrap = document.createElement('div');
		wrap.innerHTML = html;
		var overlay = wrap.firstChild;
		document.body.appendChild(overlay);

		function getActiveDuration() {
			var activeChip = overlay.querySelector('.duration-chip.chip-active');
			if (!activeChip) {
				return { months: 1, base: 0 };
			}
			return {
				months: parseInt(activeChip.getAttribute('data-months') || '1', 10) || 1,
				base: parseFloat(activeChip.getAttribute('data-price') || '0') || 0,
			};
		}

		function getActiveTypeModifier() {
			var activeType = overlay.querySelector('.modal-choice-btn[data-choice="account"].is-active');
			if (!activeType) {
				return 0;
			}
			return parseFloat(activeType.getAttribute('data-modifier') || '0') || 0;
		}

		function formatTHB(value) {
			return '฿' + Number(value || 0).toFixed(2);
		}

		function updateLivePrice() {
			var activeDuration = getActiveDuration();
			var modifier = getActiveTypeModifier();
			var monthly = activeDuration.base + modifier;
			var total = monthly * activeDuration.months;
			var priceEl = overlay.querySelector('[data-apptook-live-price]');
			var subEl = overlay.querySelector('[data-apptook-live-price-sub]');
			if (priceEl) priceEl.textContent = formatTHB(total);
			if (subEl) subEl.textContent = formatTHB(monthly) + ' / month';
		}

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay || e.target.closest('[data-apptook-close]')) {
				overlay.remove();
				return;
			}

			var durationBtn = e.target.closest('.duration-chip');
			if (durationBtn) {
				overlay.querySelectorAll('.duration-chip').forEach(function (chip) {
					chip.classList.remove('chip-active');
				});
				durationBtn.classList.add('chip-active');
				updateLivePrice();
				return;
			}

			var typeBtn = e.target.closest('.modal-choice-btn[data-choice="account"]');
			if (typeBtn) {
				overlay.querySelectorAll('.modal-choice-btn[data-choice="account"]').forEach(function (btn) {
					btn.classList.remove('is-active');
				});
				typeBtn.classList.add('is-active');
				updateLivePrice();
				return;
			}

			var productBtn = e.target.closest('.modal-choice-btn[data-choice="product"]');
			if (productBtn) {
				overlay.querySelectorAll('.modal-choice-btn[data-choice="product"]').forEach(function (btn) {
					btn.classList.remove('is-active');
				});
				productBtn.classList.add('is-active');
				return;
			}

			var promoToggle = e.target.closest('[data-apptook-promo-toggle]');
			if (promoToggle) {
				var panel = overlay.querySelector('[data-apptook-promo-panel]');
				var icon = overlay.querySelector('[data-apptook-promo-icon]');
				if (panel) {
					var open = panel.classList.contains('hidden');
					panel.classList.toggle('hidden');
					if (icon) icon.textContent = open ? 'expand_less' : 'expand_more';
				}
				return;
			}
		});

		var confirmBtn = overlay.querySelector('.apptook-st-purchase-confirm');
		if (confirmBtn) {
			confirmBtn.addEventListener('click', function () {
				overlay.remove();
				startOrderFlow(payload.productId, payload.triggerBtn || null);
			});
		}

		updateLivePrice();
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
		dlog('event apptook:open-purchase received', detail);
		openStitchPurchaseModal(detail);
	});

	document.addEventListener('change', function () {
		// kept intentionally for backward compatibility if legacy select returns
	});

	document.addEventListener('click', function (e) {
		var pdDurationBtn = e.target.closest('.apptook-ds-pd-duration-pill[data-pd-month]');
		if (pdDurationBtn) {
			e.preventDefault();
			var pdMainWrap = pdDurationBtn.closest('.apptook-ds-pd-main') || document;
			pdMainWrap.querySelectorAll('.apptook-ds-pd-duration-pill[data-pd-month]').forEach(function (pill) {
				pill.classList.remove('is-active');
			});
			pdDurationBtn.classList.add('is-active');
			var pdBuyBtn = document.querySelector('.apptook-ds-pd-buy-btn.apptook-ds-buy');
			if (pdBuyBtn) {
				pdBuyBtn.setAttribute('data-pd-selected-month', pdDurationBtn.getAttribute('data-pd-month') || '');
			}
			return;
		}

		var pdAccountBtn = e.target.closest('.apptook-ds-pd-choice-btn[data-pd-account-choice]');
		if (pdAccountBtn) {
			e.preventDefault();
			var accountWrap = pdAccountBtn.closest('[data-pd-account-choice-grid]') || document;
			accountWrap.querySelectorAll('.apptook-ds-pd-choice-btn[data-pd-account-choice]').forEach(function (btn) {
				btn.classList.remove('is-active');
			});
			pdAccountBtn.classList.add('is-active');
			var pdBuyBtnType = document.querySelector('.apptook-ds-pd-buy-btn.apptook-ds-buy');
			if (pdBuyBtnType) {
				pdBuyBtnType.setAttribute('data-pd-selected-type', pdAccountBtn.getAttribute('data-type-key') || '');
			}
			return;
		}

		var pdProductBtn = e.target.closest('.apptook-ds-pd-choice-btn[data-pd-product-choice]');
		if (pdProductBtn) {
			e.preventDefault();
			var productWrap = pdProductBtn.closest('[data-pd-product-choice-grid]') || document;
			productWrap.querySelectorAll('.apptook-ds-pd-choice-btn[data-pd-product-choice]').forEach(function (btn) {
				btn.classList.remove('is-active');
			});
			pdProductBtn.classList.add('is-active');
			return;
		}

		var pdPromoToggle = e.target.closest('[data-pd-promo-toggle]');
		if (pdPromoToggle) {
			e.preventDefault();
			var panel = document.querySelector('[data-pd-promo-panel]');
			var icon = document.querySelector('[data-pd-promo-icon]');
			if (panel) {
				var isHidden = panel.hasAttribute('hidden');
				if (isHidden) {
					panel.removeAttribute('hidden');
				} else {
					panel.setAttribute('hidden', 'hidden');
				}
				if (icon) {
					icon.textContent = isHidden ? 'expand_less' : 'expand_more';
				}
			}
			return;
		}

		var buyBtn = e.target.closest('.apptook-ds-buy[data-product-id], .buy-btn[data-product-id]');
		if (!buyBtn) {
			return;
		}
		if (buyBtn.disabled) {
			e.preventDefault();
			return;
		}
		e.preventDefault();

		var card = buyBtn.closest('.product-card');
		var productName = buyBtn.getAttribute('data-product-name') || '';
		var priceText = buyBtn.getAttribute('data-price-text') || '';
		var iconHtml = buyBtn.getAttribute('data-icon-html') || '';

		if (card) {
			var titleEl = card.querySelector('.st-card-title');
			var priceEl = card.querySelector('.st-card-price');
			var iconEl = card.querySelector('.st-card-top img, .st-card-top .st-card-icon-lg, .st-card-top .material-symbols-outlined');
			productName = titleEl ? titleEl.textContent.trim() : productName;
			if (priceEl) {
				priceText = priceEl.textContent.replace(/\s*\/\s*เดือน.*/i, '').trim();
			}
			iconHtml = iconEl ? iconEl.outerHTML : iconHtml;
		}

		var durationsRaw = buyBtn.getAttribute('data-durations') || '[]';
		var typesRaw = buyBtn.getAttribute('data-types') || '[]';
		var typeEnabled = buyBtn.getAttribute('data-type-enabled') === '1';
		var durationEnabled = buyBtn.getAttribute('data-duration-enabled') === '1';
		var durations = [];
		var types = [];
		try { durations = JSON.parse(durationsRaw); } catch (err) { durations = []; }
		try { types = JSON.parse(typesRaw); } catch (err2) { types = []; }

		var selectedMonth = parseInt(buyBtn.getAttribute('data-pd-selected-month') || '', 10);
		if (!isNaN(selectedMonth) && Array.isArray(durations) && durations.length) {
			var foundMonth = false;
			durations = durations.map(function (d) {
				var clone = Object.assign({}, d);
				if (parseInt(clone.months, 10) === selectedMonth) {
					clone.is_default = 1;
					foundMonth = true;
				} else {
					clone.is_default = 0;
				}
				return clone;
			});
			if (!foundMonth && durations[0]) {
				durations[0].is_default = 1;
			}
		}

		var selectedTypeKey = buyBtn.getAttribute('data-pd-selected-type') || '';
		if (selectedTypeKey && Array.isArray(types) && types.length) {
			var foundType = false;
			types = types.map(function (t) {
				var cloneT = Object.assign({}, t);
				if ((cloneT.type_key || '') === selectedTypeKey) {
					cloneT.is_default = 1;
					foundType = true;
				} else {
					cloneT.is_default = 0;
				}
				return cloneT;
			});
			if (!foundType && types[0]) {
				types[0].is_default = 1;
			}
		}

		openStitchPurchaseModal({
			productId: buyBtn.getAttribute('data-product-id'),
			productName: productName,
			priceText: priceText,
			iconHtml: iconHtml,
			durations: durations,
			types: types,
			typeEnabled: typeEnabled,
			durationEnabled: durationEnabled,
			triggerBtn: buyBtn
		});
	});

})();
