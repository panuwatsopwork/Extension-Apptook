(function () {
	'use strict';

	function parseRows(source, type) {
		if (!source) return [];
		return source
			.split(/\r?\n/)
			.map(function (line) {
				return line.trim();
			})
			.filter(Boolean)
			.map(function (line) {
				var parts = line.split('|');
				if (type === 'duration') {
					return {
						months: (parts[0] || '').trim(),
						price: (parts[1] || '').trim(),
						isDefault: (parts[2] || '0').trim() === '1'
					};
				}
				return {
					key: (parts[0] || '').trim(),
					label: (parts[1] || '').trim(),
					modifier: (parts[2] || '').trim(),
					isDefault: (parts[3] || '0').trim() === '1'
				};
			});
	}

	function serializeRows(rows, type) {
		return rows
			.filter(function (row) {
				if (type === 'duration') {
					return row.months || row.price || row.isDefault;
				}
				return row.key || row.label || row.modifier || row.isDefault;
			})
			.map(function (row) {
				if (type === 'duration') {
					return [row.months || '0', row.price || '0', row.isDefault ? '1' : '0'].join('|');
				}
				return [row.key || '', row.label || '', row.modifier || '0', row.isDefault ? '1' : '0'].join('|');
			})
			.join('\n');
	}

	function rowTemplate(type, row) {
		if (type === 'duration') {
			return (
				'<div class="apptook-ds-table apptook-ds-table-row">' +
				'<div><input type="number" min="1" step="1" class="small-text" data-field="months" value="' + (row.months || '') + '" placeholder="1" /></div>' +
				'<div><input type="number" min="0" step="0.01" class="regular-text" data-field="price" value="' + (row.price || '') + '" placeholder="450" /></div>' +
				'<div class="apptook-ds-center"><input type="radio" name="apptook_duration_default" data-field="isDefault" ' + (row.isDefault ? 'checked' : '') + ' /></div>' +
				'<div><button type="button" class="button-link-delete" data-remove-row>ลบ</button></div>' +
				'</div>'
			);
		}

		return (
			'<div class="apptook-ds-table apptook-ds-table-row apptook-ds-table-type">' +
			'<div><input type="text" class="regular-text" data-field="key" value="' + (row.key || '') + '" placeholder="shared" /></div>' +
			'<div><input type="text" class="regular-text" data-field="label" value="' + (row.label || '') + '" placeholder="1 profile Shared" /></div>' +
			'<div><input type="number" step="0.01" class="small-text" data-field="modifier" value="' + (row.modifier || '') + '" placeholder="0" /></div>' +
			'<div class="apptook-ds-center"><input type="radio" name="apptook_type_default" data-field="isDefault" ' + (row.isDefault ? 'checked' : '') + ' /></div>' +
			'<div><button type="button" class="button-link-delete" data-remove-row>ลบ</button></div>' +
			'</div>'
		);
	}

	function collectRows(container, type) {
		var rows = [];
		container.querySelectorAll('.apptook-ds-table-row').forEach(function (rowEl) {
			if (type === 'duration') {
				rows.push({
					months: rowEl.querySelector('[data-field="months"]').value.trim(),
					price: rowEl.querySelector('[data-field="price"]').value.trim(),
					isDefault: rowEl.querySelector('[data-field="isDefault"]').checked
				});
				return;
			}
			rows.push({
				key: rowEl.querySelector('[data-field="key"]').value.trim(),
				label: rowEl.querySelector('[data-field="label"]').value.trim(),
				modifier: rowEl.querySelector('[data-field="modifier"]').value.trim(),
				isDefault: rowEl.querySelector('[data-field="isDefault"]').checked
			});
		});
		return rows;
	}

	function setSingleDefault(body, currentRadio) {
		body.querySelectorAll('[data-field="isDefault"]').forEach(function (radio) {
			if (radio !== currentRadio) radio.checked = false;
		});
	}

	function mountBuilder(builder) {
		var type = builder.getAttribute('data-builder');
		var source = document.querySelector('.apptook-ds-hidden-source[data-source="' + type + '"]');
		if (!source) return;

		var body = builder.querySelector('[data-rows]');
		var addBtn = builder.querySelector('[data-add-row]');
		var initialRows = parseRows(source.value, type);
		if (!initialRows.length) {
			initialRows = type === 'duration'
				? [{ months: '1', price: '', isDefault: true }]
				: [{ key: 'shared', label: '1 profile Shared', modifier: '0', isDefault: true }];
		}

		initialRows.forEach(function (row) {
			body.insertAdjacentHTML('beforeend', rowTemplate(type, row));
		});

		function syncSource() {
			source.value = serializeRows(collectRows(body, type), type);
		}

		addBtn.addEventListener('click', function () {
			var row = type === 'duration'
				? { months: '', price: '', isDefault: false }
				: { key: '', label: '', modifier: '0', isDefault: false };
			body.insertAdjacentHTML('beforeend', rowTemplate(type, row));
			syncSource();
		});

		body.addEventListener('click', function (event) {
			var target = event.target;
			if (!target.matches('[data-remove-row]')) return;
			var row = target.closest('.apptook-ds-table-row');
			if (!row) return;
			row.remove();
			syncSource();
		});

		body.addEventListener('change', function (event) {
			var target = event.target;
			if (target.matches('[data-field="isDefault"]')) {
				setSingleDefault(body, target);
			}
			syncSource();
		});

		body.addEventListener('input', syncSource);
		syncSource();
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.apptook-ds-table-builder').forEach(mountBuilder);
	});
})();
