(function () {
	'use strict';

	if (window.__apptookMarketplaceBooted) {
		return;
	}
	window.__apptookMarketplaceBooted = true;

	var APTOOK_DEBUG = true;
	function dlog() {
		if (!APTOOK_DEBUG || !window.console || typeof console.log !== 'function') return;
		var args = Array.prototype.slice.call(arguments);
		args.unshift('[APTOOK-DEBUG][marketplace]');
		console.log.apply(console, args);
	}

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function initLoginModal(root) {
		var modal = root.querySelector('#apptook-st-login-modal');
		if (!modal) {
			return;
		}
		var registerModal = root.querySelector('#apptook-st-register-modal');
		var opens = root.querySelectorAll('.st-nav__open-login');
		var registerOpens = root.querySelectorAll('.st-nav__open-register');
		var closes = modal.querySelectorAll('.apptook-st-close-modal');
		var registerCloses = registerModal ? registerModal.querySelectorAll('.apptook-st-close-register-modal') : [];
		var openByQuery = false;
		var hasLoginErrorQuery = false;
		try {
			var qs = new URLSearchParams(window.location.search);
			openByQuery = qs.get('apptook_open_login') === '1';
			hasLoginErrorQuery = !!qs.get('apptook_err');
		} catch (err) {
			openByQuery = false;
			hasLoginErrorQuery = false;
		}

		function openModal() {
			modal.classList.add('is-open');
			modal.setAttribute('aria-hidden', 'false');
			var first = modal.querySelector('#apptook_st_user_login, input[name="log"]');
			if (first) {
				setTimeout(function () {
					first.focus();
				}, 100);
			}
		}

		function closeModal() {
			modal.classList.remove('is-open');
			modal.setAttribute('aria-hidden', 'true');
		}

		function openRegisterModal() {
			if (!registerModal) {
				return;
			}
			registerModal.classList.add('is-open');
			registerModal.setAttribute('aria-hidden', 'false');
			var first = registerModal.querySelector('#apptook_st_reg_email, input[name="user_email"]');
			if (first) {
				setTimeout(function () {
					first.focus();
				}, 100);
			}
		}

		function closeRegisterModal() {
			if (!registerModal) {
				return;
			}
			registerModal.classList.remove('is-open');
			registerModal.setAttribute('aria-hidden', 'true');
		}

		function cleanupLoginQueryParams() {
			try {
				var url = new URL(window.location.href);
				url.searchParams.delete('apptook_open_login');
				url.searchParams.delete('apptook_err');
				window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
			} catch (err) {
				// no-op
			}
		}

		function initPasswordToggle() {
			var toggles = modal.querySelectorAll('[data-apptook-toggle-password]');

			function syncToggleState(btn, input) {
				var hasValue = !!(input && input.value && input.value.length > 0);
				var wrap = input ? input.closest('.apptook-st-password-wrap') : null;
				if (wrap) {
					wrap.classList.toggle('is-empty', !hasValue);
					wrap.classList.toggle('has-value', hasValue);
				}

				btn.style.setProperty('opacity', hasValue ? '1' : '0', 'important');
				btn.style.setProperty('visibility', hasValue ? 'visible' : 'hidden', 'important');
				btn.style.setProperty('pointer-events', hasValue ? 'auto' : 'none', 'important');
				btn.setAttribute('aria-hidden', hasValue ? 'false' : 'true');

				if (!hasValue && input.getAttribute('type') !== 'password') {
					input.setAttribute('type', 'password');
					var iconReset = btn.querySelector('.material-symbols-outlined');
					if (iconReset) {
						iconReset.textContent = 'visibility';
					}
					btn.setAttribute('aria-label', 'แสดงรหัสผ่าน');
				}
			}

			toggles.forEach(function (btn) {
				var inputId = btn.getAttribute('data-apptook-toggle-password');
				if (!inputId) {
					return;
				}
				var input = modal.querySelector('#' + inputId);
				if (!input) {
					return;
				}

				syncToggleState(btn, input);

				input.addEventListener('input', function () {
					syncToggleState(btn, input);
				});
				input.addEventListener('keyup', function () {
					syncToggleState(btn, input);
				});
				input.addEventListener('change', function () {
					syncToggleState(btn, input);
				});

				btn.addEventListener('mousedown', function (e) {
					e.preventDefault();
				});

				btn.addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					if (!input.value) {
						return;
					}
					var icon = btn.querySelector('.material-symbols-outlined');
					var isPassword = input.getAttribute('type') === 'password';
					input.setAttribute('type', isPassword ? 'text' : 'password');
					btn.setAttribute('aria-label', isPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
					if (icon) {
						icon.textContent = isPassword ? 'visibility_off' : 'visibility';
					}
					input.focus();
				});
			});
		}

		opens.forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				closeRegisterModal();
				openModal();
				cleanupLoginQueryParams();
			});
		});

		registerOpens.forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				closeModal();
				openRegisterModal();
			});
		});

		closes.forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				closeModal();
			});
		});

		if (registerModal) {
			registerCloses.forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.preventDefault();
					closeRegisterModal();
				});
			});
		}

		modal.addEventListener('click', function (e) {
			if (e.target === modal) {
				closeModal();
			}
		});

		if (registerModal) {
			registerModal.addEventListener('click', function (e) {
				if (e.target === registerModal) {
					closeRegisterModal();
				}
			});
		}

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.classList.contains('is-open')) {
				closeModal();
			}
			if (e.key === 'Escape' && registerModal && registerModal.classList.contains('is-open')) {
				closeRegisterModal();
			}
		});

		initPasswordToggle();

		if (openByQuery || hasLoginErrorQuery) {
			openModal();
			cleanupLoginQueryParams();
		}
	}

	function initProfileDropdown(root) {
		var trigger = root.querySelector('.st-nav__profile-trigger');
		var dropdown = root.querySelector('.st-nav__dropdown');
		if (!trigger || !dropdown) {
			return;
		}
		trigger.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = dropdown.classList.toggle('is-open');
			trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		document.addEventListener('click', function () {
			dropdown.classList.remove('is-open');
			trigger.setAttribute('aria-expanded', 'false');
		});
		dropdown.addEventListener('click', function (e) {
			e.stopPropagation();
		});
	}

	function setTabActive(btn, allBtns) {
		allBtns.forEach(function (b) {
			b.classList.remove('is-active');
			b.classList.add('is-inactive');
			var ic = b.querySelector('.material-symbols-outlined');
			if (ic) {
				ic.classList.remove('ms-fill');
				ic.style.fontVariationSettings = "'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24";
			}
		});
		btn.classList.add('is-active');
		btn.classList.remove('is-inactive');
		var activeIcon = btn.querySelector('.material-symbols-outlined');
		if (activeIcon) {
			activeIcon.classList.add('ms-fill');
			activeIcon.style.fontVariationSettings = "'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24";
		}
	}

	ready(function () {
		document.querySelectorAll('.apptook-stitch').forEach(function (root) {
			initLoginModal(root);
			initProfileDropdown(root);

			var grid = root.querySelector('[data-apptook-grid]');
			var searchInput = root.querySelector('[data-apptook-search]');
			var searchBtn = root.querySelector('[data-apptook-search-btn]');
			var sortSelect = root.querySelector('[data-apptook-sort]');
			var categoryBtns = root.querySelectorAll('.category-btn');
			var showMore = root.querySelector('[data-apptook-show-more]');
			var emptyEl = root.querySelector('[data-apptook-empty]');

			if (!grid) {
				return;
			}

			var activeCategory = 'all';

			function cards() {
				return Array.prototype.slice.call(grid.querySelectorAll('.product-card'));
			}

			function sortGrid(mode) {
				var list = cards();
				function price(el) {
					return parseFloat(el.getAttribute('data-price') || '0', 10) || 0;
				}
				function dateTs(el) {
					return parseInt(el.getAttribute('data-date') || '0', 10) || 0;
				}
				function name(el) {
					return (el.getAttribute('data-name') || '').toLowerCase();
				}
				list.sort(function (a, b) {
					switch (mode) {
						case 'price_asc':
							return price(a) - price(b);
						case 'price_desc':
							return price(b) - price(a);
						case 'date_asc':
							return dateTs(a) - dateTs(b);
						case 'date_desc':
							return dateTs(b) - dateTs(a);
						case 'title_asc':
							return name(a).localeCompare(name(b), 'th');
						case 'title_desc':
							return name(b).localeCompare(name(a), 'th');
						default:
							return dateTs(b) - dateTs(a);
					}
				});
				list.forEach(function (c) {
					grid.appendChild(c);
				});
			}

			function applyFilters() {
				var q = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
				var visible = 0;
				cards().forEach(function (card) {
					var cats = card.getAttribute('data-category') || '';
					var pname = (card.getAttribute('data-name') || '').toLowerCase();
					var okCat =
						activeCategory === 'all' ||
						cats.split(/\s+/).filter(Boolean).indexOf(activeCategory) !== -1;
					var okSearch = !q || pname.indexOf(q) !== -1;
					if (okCat && okSearch) {
						card.classList.remove('is-hidden');
						visible++;
					} else {
						card.classList.add('is-hidden');
					}
				});
				if (emptyEl) {
					emptyEl.classList.toggle('is-visible', visible === 0);
				}
				if (showMore) {
					if (activeCategory !== 'all') {
						showMore.classList.add('is-hidden');
					} else {
						showMore.classList.remove('is-hidden');
					}
				}
			}

			categoryBtns.forEach(function (btn) {
				btn.addEventListener('click', function () {
					activeCategory = btn.getAttribute('data-category') || 'all';
					setTabActive(btn, categoryBtns);
					applyFilters();
				});
			});

			if (searchInput) {
				searchInput.addEventListener('input', applyFilters);
				searchInput.addEventListener('keydown', function (e) {
					if (e.key === 'Enter') {
						e.preventDefault();
						applyFilters();
					}
				});
			}
			if (searchBtn) {
				searchBtn.addEventListener('click', applyFilters);
			}
			if (sortSelect) {
				sortSelect.addEventListener('change', function () {
					sortGrid(sortSelect.value);
					applyFilters();
				});
			}

			var initialSort = sortSelect ? sortSelect.value : 'date_desc';
			sortGrid(initialSort);
			applyFilters();


		});
	});
})();
