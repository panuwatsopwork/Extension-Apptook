(function () {
  const cfg = window.ExtensionCursorAdmin || {};
  const ajaxUrl = cfg.ajaxUrl || '';
  const nonce = cfg.nonce || '';

  if (!ajaxUrl || !nonce) {
    return;
  }

  const state = {
    sourceLicenses: [],
    apptookKeys: [],
    apptookKeySources: [],
    dashboardTokens: []
  };

  const $ = (id) => document.getElementById(id);

  const adminToken = $('adminToken');
  const setupButton = $('setupButton');
  const refreshButton = $('refreshButton');
  const refreshMonitorButton = $('refreshMonitorButton');
  const statusText = $('statusText');
  const responseBox = $('responseBox');
  const sourceLicenseCount = $('sourceLicenseCount');
  const apptookKeyCount = $('apptookKeyCount');
  const mappingCount = $('mappingCount');

  const sourceKeysText = $('sourceKeysText');
  const sourceExpireAt = $('sourceExpireAt');
  const sourceMaxDevices = $('sourceMaxDevices');
  const sourceTokenCapacity = $('sourceTokenCapacity');
  const sourceNote = $('sourceNote');
  const importSourceKeysButton = $('importSourceKeysButton');

  const apptookKey = $('apptookKey');
  const keyType = $('keyType');
  const appKeyExpireAt = $('appKeyExpireAt');
  const appKeyNote = $('appKeyNote');
  const randomApptookKeyButton = $('randomApptookKeyButton');
  const createApptookKeyButton = $('createApptookKeyButton');

  const assignApptookKeySelect = $('assignApptookKeySelect');
  const assignKeyType = $('assignKeyType');
  const addSourceRowButton = $('addSourceRowButton');
  const sourceRows = $('sourceRows');
  const saveSourcesButton = $('saveSourcesButton');
  const sourceKeyList = $('sourceKeyList');

  const sourceLicensesTable = $('sourceLicensesTable');
  const apptookKeysTable = $('apptookKeysTable');
  const apptookKeySourcesTable = $('apptookKeySourcesTable');
  const dashboardTokensTable = $('dashboardTokensTable');

  function setStatus(message) {
    if (statusText) {
      statusText.textContent = String(message || 'Ready.');
    }
  }

  function setResponse(payload) {
    if (responseBox) {
      responseBox.textContent = JSON.stringify(payload, null, 2);
    }
  }

  function normalizeLines(value) {
    return String(value || '').split(/\r?\n/).map((x) => x.trim()).filter(Boolean);
  }

  function setBusy(button, busy, busyText) {
    if (!button) return;
    if (!button.dataset.defaultLabel) {
      button.dataset.defaultLabel = button.textContent;
    }
    button.disabled = !!busy;
    button.textContent = busy ? busyText : button.dataset.defaultLabel;
  }

  function buildRandomApptookKey() {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let suffix = '';
    for (let i = 0; i < 10; i += 1) suffix += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
    return `apptook_${suffix}`;
  }

  async function dispatchToWp(payload) {
    const body = new URLSearchParams();
    body.set('action', 'extension_cursor_admin_dispatch');
    body.set('nonce', nonce);
    body.set('payload', JSON.stringify(payload || {}));

    const res = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    });

    const raw = await res.text();
    let data = null;

    try {
      data = JSON.parse(raw);
    } catch (_err) {
      const preview = String(raw || '').slice(0, 180).replace(/\s+/g, ' ').trim();
      throw new Error(`WP AJAX did not return JSON. Preview: ${preview || '(empty response)'}`);
    }

    if (!data || !data.success) {
      const message = data && data.data && data.data.message ? data.data.message : 'Request failed.';
      throw new Error(message);
    }

    return data.data;
  }

  async function postAction(action, payload = {}) {
    const token = String((adminToken && adminToken.value) || '').trim();
    const data = await dispatchToWp({ action, token, ...payload });
    setResponse(data);
    return data;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderTable(container, rows) {
    if (!container) return;
    if (!Array.isArray(rows) || rows.length === 0) {
      container.innerHTML = '<div class="response-box">No rows available.</div>';
      return;
    }

    const header = rows[0] || [];
    const body = rows.slice(1);

    const headerHtml = header.map((item) => `<th>${escapeHtml(item)}</th>`).join('');
    const bodyHtml = body.length
      ? body.map((row) => `<tr>${header.map((_, i) => `<td>${escapeHtml(row[i])}</td>`).join('')}</tr>`).join('')
      : `<tr><td colspan="${header.length}">No records.</td></tr>`;

    container.innerHTML = `<table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table>`;
  }

  function hydrateKeyOptions() {
    if (!assignApptookKeySelect) return;
    const rows = Array.isArray(state.apptookKeys) ? state.apptookKeys : [];
    const header = rows[0] || [];
    const keyIndex = header.indexOf('apptook_key');
    const typeIndex = header.indexOf('key_type');

    const current = assignApptookKeySelect.value;
    assignApptookKeySelect.innerHTML = '<option value="">Select APPTOOK key</option>';

    rows.slice(1).forEach((row) => {
      const keyValue = String(row[keyIndex] || '').trim();
      if (!keyValue) return;
      const option = document.createElement('option');
      option.value = keyValue;
      option.textContent = `${keyValue} (${String(row[typeIndex] || 'single').trim() || 'single'})`;
      assignApptookKeySelect.appendChild(option);
    });

    if (current) assignApptookKeySelect.value = current;
  }

  function hydrateSourceKeyList() {
    if (!sourceKeyList) return;
    const rows = Array.isArray(state.sourceLicenses) ? state.sourceLicenses : [];
    const header = rows[0] || [];
    const keyIndex = header.indexOf('source_key');

    sourceKeyList.innerHTML = '';
    rows.slice(1).forEach((row) => {
      const keyValue = String(row[keyIndex] || '').trim();
      if (!keyValue) return;
      const option = document.createElement('option');
      option.value = keyValue;
      sourceKeyList.appendChild(option);
    });
  }

  function getSelectedKeyRecord() {
    const selectedKey = String((assignApptookKeySelect && assignApptookKeySelect.value) || '').trim();
    if (!selectedKey) return null;

    const rows = Array.isArray(state.apptookKeys) ? state.apptookKeys : [];
    const header = rows[0] || [];
    const keyIndex = header.indexOf('apptook_key');
    const typeIndex = header.indexOf('key_type');

    for (const row of rows.slice(1)) {
      if (String(row[keyIndex] || '').trim() !== selectedKey) continue;
      return { apptookKey: selectedKey, keyType: String(row[typeIndex] || 'single').trim() || 'single' };
    }
    return null;
  }

  function refreshSourceRowIndexes() {
    Array.from(sourceRows.children).forEach((row, i) => {
      const seq = row.querySelector('.assign-seq');
      if (seq) seq.textContent = String(i + 1);
    });
  }

  function enforceAssignKeyType() {
    const record = getSelectedKeyRecord();
    const rows = Array.from(sourceRows.children);
    const isSingle = record && record.keyType === 'single';
    if (assignKeyType) assignKeyType.value = record ? record.keyType : '-';
    if (addSourceRowButton) addSourceRowButton.disabled = !!(isSingle && rows.length >= 1);
    rows.forEach((row, index) => {
      const removeBtn = row.querySelector('.remove-source-row');
      if (removeBtn) removeBtn.disabled = !!(isSingle && rows.length <= 1 && index === 0);
    });
  }

  function createSourceRow(value = '') {
    const row = document.createElement('div');
    row.className = 'assign-row';
    row.innerHTML = `
      <div class="assign-seq"></div>
      <input type="text" class="source-key-input" list="sourceKeyList" placeholder="Enter source license" />
      <button type="button" class="btn-secondary remove-source-row">Remove</button>
    `;

    const input = row.querySelector('.source-key-input');
    input.value = value;

    row.querySelector('.remove-source-row').addEventListener('click', () => {
      row.remove();
      refreshSourceRowIndexes();
      enforceAssignKeyType();
    });

    sourceRows.appendChild(row);
    refreshSourceRowIndexes();
    return row;
  }

  function getAssignedSourceKeys(apptookKeyValue) {
    const rows = Array.isArray(state.apptookKeySources) ? state.apptookKeySources : [];
    const header = rows[0] || [];
    const keyIndex = header.indexOf('apptook_key');
    const sourceIndex = header.indexOf('source_key');
    const sequenceIndex = header.indexOf('sequence');

    return rows.slice(1)
      .filter((row) => String(row[keyIndex] || '').trim() === apptookKeyValue)
      .sort((a, b) => Number(a[sequenceIndex] || 0) - Number(b[sequenceIndex] || 0))
      .map((row) => String(row[sourceIndex] || '').trim())
      .filter(Boolean);
  }

  function refillAssignRows(apptookKeyValue) {
    sourceRows.innerHTML = '';
    const existing = getAssignedSourceKeys(apptookKeyValue);
    if (!existing.length) {
      createSourceRow('');
    } else {
      existing.forEach((v) => createSourceRow(v));
    }
    enforceAssignKeyType();
  }

  function countRows(rows) {
    return Math.max(0, Array.isArray(rows) ? rows.length - 1 : 0);
  }

  function setMonitorCounts() {
    if (sourceLicenseCount) sourceLicenseCount.textContent = String(countRows(state.sourceLicenses));
    if (apptookKeyCount) apptookKeyCount.textContent = String(countRows(state.apptookKeys));
    if (mappingCount) mappingCount.textContent = String(countRows(state.apptookKeySources));
  }

  function applyExplorerData(payload) {
    state.sourceLicenses = Array.isArray(payload.sourceLicenses) ? payload.sourceLicenses : [];
    state.apptookKeys = Array.isArray(payload.apptookKeys) ? payload.apptookKeys : [];
    state.apptookKeySources = Array.isArray(payload.apptookKeySources) ? payload.apptookKeySources : [];
    state.dashboardTokens = Array.isArray(payload.dashboardTokens) ? payload.dashboardTokens : [];

    renderTable(sourceLicensesTable, state.sourceLicenses);
    renderTable(apptookKeysTable, state.apptookKeys);
    renderTable(apptookKeySourcesTable, state.apptookKeySources);
    renderTable(dashboardTokensTable, state.dashboardTokens);

    setMonitorCounts();
    hydrateKeyOptions();
    hydrateSourceKeyList();

    if (assignApptookKeySelect && assignApptookKeySelect.value) {
      refillAssignRows(assignApptookKeySelect.value);
    } else if (sourceRows && !sourceRows.children.length) {
      createSourceRow('');
    }
    enforceAssignKeyType();
  }

  async function refreshAllData(showSuccess = true) {
    setBusy(refreshButton, true, 'Refreshing...');
    setStatus('Loading APPTOOK key data...');
    try {
      const result = await postAction('adminListKeyData');
      applyExplorerData(result);
      if (showSuccess) setStatus(result.message || 'Data loaded successfully.');
    } catch (err) {
      setStatus(err.message || String(err));
      setResponse({ ok: false, message: err.message || String(err) });
    } finally {
      setBusy(refreshButton, false);
    }
  }

  setupButton.addEventListener('click', async () => {
    setBusy(setupButton, true, 'Setting up...');
    setStatus('Running setup...');
    try {
      const result = await postAction('setup');
      setStatus(result.message || 'Setup completed.');
      await refreshAllData(false);
    } catch (err) {
      setStatus(err.message || String(err));
    } finally {
      setBusy(setupButton, false);
    }
  });

  refreshButton.addEventListener('click', () => refreshAllData(true));
  if (refreshMonitorButton) {
    refreshMonitorButton.addEventListener('click', () => refreshAllData(true));
  }

  importSourceKeysButton.addEventListener('click', async () => {
    const keys = normalizeLines(sourceKeysText.value);
    if (!keys.length) return setStatus('Please provide at least one source license.');
    if (!sourceExpireAt.value) return setStatus('Please provide source expire date.');

    setBusy(importSourceKeysButton, true, 'Importing...');
    setStatus('Importing source licenses...');
    try {
      const result = await postAction('adminImportSourceLicenses', {
        sourceKeys: keys,
        sourceExpireAt: sourceExpireAt.value,
        maxDevices: sourceMaxDevices.value || 1,
        sourceTokenCapacity: sourceTokenCapacity.value || 100,
        note: sourceNote.value || ''
      });
      setStatus(result.message || 'Imported.');
      sourceKeysText.value = '';
      await refreshAllData(false);
    } catch (err) {
      setStatus(err.message || String(err));
    } finally {
      setBusy(importSourceKeysButton, false);
    }
  });

  randomApptookKeyButton.addEventListener('click', () => {
    apptookKey.value = buildRandomApptookKey();
  });

  createApptookKeyButton.addEventListener('click', async () => {
    if (!appKeyExpireAt.value) return setStatus('Please provide APPTOOK key expire date.');

    setBusy(createApptookKeyButton, true, 'Creating...');
    setStatus('Creating APPTOOK key...');
    try {
      const result = await postAction('adminCreateApptookKey', {
        apptookKey: String(apptookKey.value || '').trim(),
        keyType: keyType.value,
        expireAt: appKeyExpireAt.value,
        note: appKeyNote.value || ''
      });

      if (result.data && result.data.apptookKey) {
        apptookKey.value = result.data.apptookKey;
        assignApptookKeySelect.value = result.data.apptookKey;
      }

      setStatus(result.message || 'APPTOOK key created.');
      await refreshAllData(false);
    } catch (err) {
      setStatus(err.message || String(err));
    } finally {
      setBusy(createApptookKeyButton, false);
    }
  });

  addSourceRowButton.addEventListener('click', () => {
    const record = getSelectedKeyRecord();
    if (record && record.keyType === 'single' && sourceRows.children.length >= 1) {
      setStatus('Single APPTOOK keys can only use one source license.');
      return;
    }
    createSourceRow('');
    enforceAssignKeyType();
  });

  assignApptookKeySelect.addEventListener('change', () => {
    const selected = String(assignApptookKeySelect.value || '').trim();
    if (!selected) {
      sourceRows.innerHTML = '';
      createSourceRow('');
      enforceAssignKeyType();
      return;
    }
    refillAssignRows(selected);
  });

  saveSourcesButton.addEventListener('click', async () => {
    const selectedKey = String(assignApptookKeySelect.value || '').trim();
    if (!selectedKey) return setStatus('Please select an APPTOOK key first.');

    const record = getSelectedKeyRecord();
    const values = Array.from(sourceRows.querySelectorAll('.source-key-input'))
      .map((input) => String(input.value || '').trim())
      .filter(Boolean);

    if (!values.length) return setStatus('Please provide at least one source mapping.');
    if (record && record.keyType === 'single' && values.length !== 1) {
      return setStatus('Single APPTOOK keys can only use one source license.');
    }

    setBusy(saveSourcesButton, true, 'Saving...');
    setStatus('Saving source mapping...');
    try {
      const result = await postAction('adminSaveApptookKeySources', {
        apptookKey: selectedKey,
        sourceKeys: values
      });
      setStatus(result.message || 'Source mapping saved.');
      await refreshAllData(false);
    } catch (err) {
      setStatus(err.message || String(err));
    } finally {
      setBusy(saveSourcesButton, false);
    }
  });

  createSourceRow('');
  enforceAssignKeyType();
  setMonitorCounts();
})();
