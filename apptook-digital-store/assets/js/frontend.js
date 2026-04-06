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
			'<div class="apptook-ds-modal-qr" data-apptook-preview-wrap style="display:none;min-height:unset;">' +
			'<img data-apptook-slip-preview alt="Slip preview" />' +
			'</div>' +
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
		var previewWrap = qs('[data-apptook-preview-wrap]', overlay);
		var previewImg = qs('[data-apptook-slip-preview]', overlay);

		if (fileInput && previewWrap && previewImg) {
			fileInput.addEventListener('change', function () {
				var f = fileInput.files && fileInput.files[0];
				if (!f) {
					previewWrap.style.display = 'none';
					previewImg.removeAttribute('src');
					return;
				}
				fileToDataUrl(f).then(function (dataUrl) {
					if (!dataUrl) {
						previewWrap.style.display = 'none';
						previewImg.removeAttribute('src');
						return;
					}
					previewImg.src = dataUrl;
					previewWrap.style.display = 'flex';
				});
			});
		}

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
					showToastSuccess(res.data && res.data.message ? res.data.message : 'อัปโหลดสลิปแล้ว');
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
		dlog('openStitchPurchaseModal called', payload);
		if (!payload || !payload.productId) {
			dlog('openStitchPurchaseModal aborted: invalid payload');
			return;
		}

		if (window.__apptookPurchaseModalLock) {
			dlog('openStitchPurchaseModal blocked by lock');
			return;
		}
		window.__apptookPurchaseModalLock = true;
		dlog('purchase modal lock on');
		setTimeout(function () {
			window.__apptookPurchaseModalLock = false;
			dlog('purchase modal lock off');
		}, 120);

		var existingOverlays = document.querySelectorAll('.apptook-st-purchase-overlay');
		dlog('existing overlays before remove', existingOverlays.length);
		existingOverlays.forEach(function (existing) {
			existing.remove();
		});

		var title = payload.productName || 'Product';
		var price = payload.priceText || '';
		var iconHtml = payload.iconHtml || '<span class="material-symbols-outlined">inventory_2</span>';
		var durations = Array.isArray(payload.durations) ? payload.durations : [];
		var types = Array.isArray(payload.types) ? payload.types : [];
		var hasType = !!payload.typeEnabled && types.length > 0;
		var hasDuration = !!payload.durationEnabled && durations.length > 0;

		if (!hasDuration) {
			durations = [{ months: 1, price: 0, is_default: 1 }];
		}

		var hasDurationDefault = durations.some(function (d) {
			return String(d && d.is_default ? d.is_default : '0') === '1';
		});
		var chipsHtml = durations.map(function (d, i) {
			var months = parseInt(d.months || '1', 10);
			var isDefault = String(d.is_default || '0') === '1';
			var active = hasDurationDefault ? (isDefault ? ' is-active' : '') : (i === 0 ? ' is-active' : '');
			return '<button type="button" class="apptook-st-duration-chip' + active + '" data-months="' + esc(String(months)) + '" data-price="' + esc(String(d.price || '0')) + '">' + esc(String(months)) + ' month' + (months > 1 ? 's' : '') + '</button>';
		}).join('');

		var typeHtml = '';
		if (hasType) {
			var hasTypeDefault = types.some(function (t) {
				return String(t && t.is_default ? t.is_default : '0') === '1';
			});
			var options = types.map(function (t, i) {
				var label = t.type_label || t.type_key || ('Type ' + (i + 1));
				var isDefaultType = String(t.is_default || '0') === '1';
				var selected = hasTypeDefault ? (isDefaultType ? ' selected' : '') : (i === 0 ? ' selected' : '');
				return '<option value="' + esc(String(t.type_key || '')) + '" data-modifier="' + esc(String(t.price_modifier || '0')) + '"' + selected + '>' + esc(String(label)) + '</option>';
			}).join('');
			typeHtml = '<label class="apptook-st-purchase-label">Select Type</label><select class="apptook-st-purchase-select" data-apptook-purchase-type>' + options + '</select>';
		}

		var durationHtml = '';
		if (hasDuration) {
			durationHtml = '<label class="apptook-st-purchase-label">Purchase months</label>' +
				'<div class="apptook-st-purchase-chip-grid">' + chipsHtml + '</div>';
		}

		var html =
			'<div class="apptook-st-purchase-overlay" role="dialog" aria-modal="true">' +
			'<div class="apptook-st-purchase-modal">' +
			'<button type="button" class="apptook-st-purchase-close" data-apptook-close><span class="material-symbols-outlined">close</span></button>' +
			'<div class="apptook-st-purchase-head">' +
			'<div class="apptook-st-purchase-head-left">' +
			'<div class="apptook-st-purchase-icon">' + iconHtml + '</div>' +
			'<div><h3 class="apptook-st-purchase-title">' + esc(title) + '</h3><p class="apptook-st-purchase-sub">Premium Subscription</p></div>' +
			'</div>' +
			'<div class="apptook-st-purchase-head-right"><div class="apptook-st-purchase-price" data-apptook-live-price>' + esc(price || '-') + '</div><div class="apptook-st-purchase-price-sub" data-apptook-live-price-sub>' + esc(price || '-') + ' / month</div></div>' +
			'</div>' +
			'<div class="apptook-st-purchase-body">' +
			durationHtml +
			typeHtml +
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

		overlay.style.position = 'fixed';
		overlay.style.top = '0';
		overlay.style.right = '0';
		overlay.style.bottom = '0';
		overlay.style.left = '0';
		overlay.style.inset = '0';
		overlay.style.width = '100vw';
		overlay.style.height = '100vh';
		overlay.style.margin = '0';
		overlay.style.display = 'flex';
		overlay.style.alignItems = 'center';
		overlay.style.justifyContent = 'center';
		overlay.style.zIndex = '2147483647';

		dlog('purchase overlay appended', {
			currentOverlayCount: document.querySelectorAll('.apptook-st-purchase-overlay').length,
			productId: payload.productId
		});

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay || e.target.closest('[data-apptook-close]')) {
				overlay.remove();
			}
		});

		function updateLivePrice() {
			var activeChip = overlay.querySelector('.apptook-st-duration-chip.is-active');
			if (!activeChip) return;
			var base = parseFloat(activeChip.getAttribute('data-price') || '0') || 0;
			var modifier = 0;
			var typeSelect = overlay.querySelector('[data-apptook-purchase-type]');
			if (typeSelect) {
				var selected = typeSelect.options[typeSelect.selectedIndex];
				if (selected) {
					modifier = parseFloat(selected.getAttribute('data-modifier') || '0') || 0;
				}
			}
			var total = base + modifier;
			var text = '฿' + total.toFixed(2);
			var priceEl = overlay.querySelector('[data-apptook-live-price]');
			var subEl = overlay.querySelector('[data-apptook-live-price-sub]');
			if (priceEl) priceEl.textContent = text;
			if (subEl) subEl.textContent = text + ' / month';
		}

		overlay.querySelectorAll('.apptook-st-duration-chip').forEach(function (chip) {
			chip.addEventListener('click', function () {
				overlay.querySelectorAll('.apptook-st-duration-chip').forEach(function (c) {
					c.classList.remove('is-active');
				});
				chip.classList.add('is-active');
				updateLivePrice();
			});
		});

		var typeSelect = overlay.querySelector('[data-apptook-purchase-type]');
		if (typeSelect) {
			typeSelect.addEventListener('change', updateLivePrice);
		}
		updateLivePrice();

		var confirmBtn = overlay.querySelector('.apptook-st-purchase-confirm');
		confirmBtn.addEventListener('click', function () {
			dlog('purchase confirm clicked', { productId: payload.productId });
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
		dlog('event apptook:open-purchase received', detail);
		openStitchPurchaseModal(detail);
	});

	document.addEventListener('change', function (e) {
		var pdTypeSelect = e.target.closest('[data-pd-type-select]');
		if (!pdTypeSelect) {
			return;
		}
		var pdWrap = pdTypeSelect.closest('.apptook-ds-pd-purchase-card') || document;
		var pdBuyBtn = pdWrap.querySelector('.apptook-ds-pd-buy-btn.apptook-ds-buy');
		if (pdBuyBtn) {
			pdBuyBtn.setAttribute('data-pd-selected-type', pdTypeSelect.value || '');
		}
	});

	document.addEventListener('click', function (e) {
		var pdDurationBtn = e.target.closest('.apptook-ds-pd-duration-pill[data-pd-month]');
		if (pdDurationBtn) {
			e.preventDefault();
			var pdWrap = pdDurationBtn.closest('.apptook-ds-pd-purchase-card') || document;
			pdWrap.querySelectorAll('.apptook-ds-pd-duration-pill[data-pd-month]').forEach(function (pill) {
				pill.classList.remove('is-active');
			});
			pdDurationBtn.classList.add('is-active');
			var pdBuyBtn = pdWrap.querySelector('.apptook-ds-pd-buy-btn.apptook-ds-buy');
			if (pdBuyBtn) {
				pdBuyBtn.setAttribute('data-pd-selected-month', pdDurationBtn.getAttribute('data-pd-month') || '');
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
