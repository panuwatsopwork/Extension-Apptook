(function () {
  const cfg = window.ExtensionCursorAdmin || {};
  const nonce = cfg.nonce || '';
  const restNonce = cfg.restNonce || '';
  const restUrl = String(cfg.restUrl || '').trim();

  const localizedAjaxUrl = String(cfg.ajaxUrl || '').trim();
  const sameOriginAjaxUrl = new URL('admin-ajax.php', window.location.href).toString();
  const ajaxUrl = (function resolveAjaxUrl() {
    if (!localizedAjaxUrl) {
      return sameOriginAjaxUrl;
    }

    try {
      const localizedUrl = new URL(localizedAjaxUrl, window.location.origin);
      if (localizedUrl.origin !== window.location.origin) {
        return sameOriginAjaxUrl;
      }
      return localizedUrl.toString();
    } catch (_err) {
      return sameOriginAjaxUrl;
    }
  })();

  if (!nonce) {
    return;
  }

  const state = {
    sourceLicenses: [],
    apptookKeys: [],
    apptookKeySources: [],
    dashboardTokens: [],
    localStockKeys: [],
    localGroups: [],
    localApptookKeys: [],
    runtimeMonitor: null,
    runtimeSimTimer: null,
    runtimeSimRefreshTimer: null,
    runtimeSimBusy: false,
    runtimeSimAwaitRefresh: false
  };

  const $ = (id) => document.getElementById(id);

  const adminToken = $('adminToken');
  const setupButton = $('setupButton');
  const refreshButton = $('refreshButton');
  const debugPingButton = $('debugPingButton');
  const statusText = $('statusText');
  const responseBox = $('responseBox');

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

  const localStockKeysText = $('localStockKeysText');
  const localProvider = $('localProvider');
  const localExpireAt = $('localExpireAt');
  const localMaxDevices = $('localMaxDevices');
  const localTokenCapacity = $('localTokenCapacity');
  const localNote = $('localNote');
  const localImportButton = $('localImportButton');
  const localRefreshButton = $('localRefreshButton');
  const localStockTable = $('localStockTable');

  const localGroupCode = $('localGroupCode');
  const localGroupName = $('localGroupName');
  const localGroupMode = $('localGroupMode');
  const localGroupNote = $('localGroupNote');
  const localCreateGroupButton = $('localCreateGroupButton');
  const localGroupsTable = $('localGroupsTable');

  const mapGroupSelect = $('mapGroupSelect');
  const mapStockKeyIds = $('mapStockKeyIds');
  const mapAttachKeysButton = $('mapAttachKeysButton');
  const mapLoadGroupKeysButton = $('mapLoadGroupKeysButton');
  const mapGroupKeysTable = $('mapGroupKeysTable');

  const localApptookKey = $('localApptookKey');
  const localApptookGroupSelect = $('localApptookGroupSelect');
  const localApptookKeyType = $('localApptookKeyType');
  const localApptookExpireAt = $('localApptookExpireAt');
  const localApptookNote = $('localApptookNote');
  const localCreateApptookButton = $('localCreateApptookButton');
  const localRefreshApptookButton = $('localRefreshApptookButton');
  const localApptookKeysTable = $('localApptookKeysTable');

  const rtApptookKey = $('rtApptookKey');
  const rtDeviceId = $('rtDeviceId');
  const rtReason = $('rtReason');
  const rtRawUsage = $('rtRawUsage');
  const rtDisplayUsage = $('rtDisplayUsage');
  const rtLoginButton = $('rtLoginButton');
  const rtLoopNextButton = $('rtLoopNextButton');
  const rtDashboardSyncButton = $('rtDashboardSyncButton');

  const tabMainButton = $('tabMainButton');
  const tabRuntimeMonitorButton = $('tabRuntimeMonitorButton');
  const tabMainContent = $('tabMainContent');
  const tabRuntimeMonitorContent = $('tabRuntimeMonitorContent');

  const rmApptookKey = $('rmApptookKey');
  const rmLoadButton = $('rmLoadButton');
  const rmRefreshButton = $('rmRefreshButton');
  const rmUsagePercent = $('rmUsagePercent');
  const rmCurrentSourceKey = $('rmCurrentSourceKey');
  const rmTotalCapacity = $('rmTotalCapacity');
  const rmLicensesTable = $('rmLicensesTable');
  const rmSimThreshold = $('rmSimThreshold');
  const rmSimIntervalMs = $('rmSimIntervalMs');
  const rmSimTargetSeconds = $('rmSimTargetSeconds');
  const rmSimStartButton = $('rmSimStartButton');
  const rmSimStopButton = $('rmSimStopButton');
  const rmSimResetButton = $('rmSimResetButton');

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

  async function callWpAjax(actionName, fields = {}) {
    const body = new URLSearchParams();
    body.set('action', actionName);
    body.set('nonce', nonce);
    Object.keys(fields || {}).forEach((key) => {
      body.set(key, fields[key]);
    });

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
      const raw = data && data.data && data.data.raw ? String(data.data.raw) : '';
      const rawPreview = raw ? ` Raw preview: ${raw.slice(0, 220).replace(/\s+/g, ' ').trim()}` : '';
      const debug = data && data.data && data.data.debug ? data.data.debug : null;
      if (debug) {
        setResponse({ ok: false, message, rawPreview, debug });
      }
      throw new Error(message + rawPreview);
    }

    return data.data;
  }

  async function dispatchToWp(payload) {
    return callWpAjax('extension_cursor_admin_dispatch', {
      payload_b64: btoa(unescape(encodeURIComponent(JSON.stringify(payload || {}))))
    });
  }

  async function dispatchViaRest(action, payload = {}) {
    if (!restUrl || !restNonce) {
      throw new Error('REST route is not configured.');
    }

    const token = String((adminToken && adminToken.value) || '').trim();
    const res = await fetch(restUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': restNonce
      },
      body: JSON.stringify({ action, token, payload: payload || {} })
    });

    const raw = await res.text();
    let data = null;
    try {
      data = JSON.parse(raw);
    } catch (_err) {
      const preview = String(raw || '').slice(0, 180).replace(/\s+/g, ' ').trim();
      throw new Error(`WP REST did not return JSON. Preview: ${preview || '(empty response)'}`);
    }

    if (!res.ok || !data || data.ok === false) {
      const message = data && data.message ? data.message : `REST request failed (${res.status}).`;
      throw new Error(message);
    }

    return data;
  }

  async function dispatchToWpV2(action, payload = {}) {
    const token = String((adminToken && adminToken.value) || '').trim();
    return callWpAjax('ec_data', {
      ec_action: action,
      ec_token: token,
      ec_payload: JSON.stringify(payload || {})
    });
  }

  async function callLocalRest(path, method = 'GET', body = null, customBase = '') {
    if (!restNonce) {
      throw new Error('REST nonce is missing. Please refresh the page.');
    }

    const base = String(customBase || cfg.restApiBase || '').trim() || String(window.location.origin).replace(/\/+$/, '') + '/wp-json/extension-cursor/v1';
    const normalizedPath = String(path || '').startsWith('/') ? String(path) : `/${String(path || '')}`;
    const cacheBust = method === 'GET' ? `${normalizedPath.includes('?') ? '&' : '?'}_=${Date.now()}` : '';
    const url = `${base.replace(/\/+$/, '')}${normalizedPath}${cacheBust}`;
    const options = {
      method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'X-WP-Nonce': restNonce,
        'Content-Type': 'application/json'
      }
    };

    if (body !== null) {
      options.body = JSON.stringify(body);
    }

    const res = await fetch(url, options);
    const raw = await res.text();
    let data = null;

    try {
      data = JSON.parse(raw);
    } catch (_err) {
      const preview = String(raw || '').slice(0, 220).replace(/\s+/g, ' ').trim();
      throw new Error(`Local REST did not return JSON. Preview: ${preview || '(empty response)'}`);
    }

    if (!res.ok || !data || data.ok === false) {
      const message = data && data.message ? data.message : `Local REST failed (${res.status}).`;
      throw new Error(message);
    }

    return data;
  }

  async function postAction(action, payload = {}) {
    try {
      const data = await dispatchViaRest(action, payload);
      setResponse(data);
      return data;
    } catch (_restErr) {
      try {
        const data = await dispatchToWpV2(action, payload);
        setResponse(data);
        return data;
      } catch (_v2Err) {
        const token = String((adminToken && adminToken.value) || '').trim();
        const data = await dispatchToWp({ action, token, ...payload });
        setResponse(data);
        return data;
      }
    }
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

  function normalizeRowsFromObjects(items) {
    if (!Array.isArray(items) || !items.length) {
      return [];
    }

    const header = Object.keys(items[0] || {});
    return [header, ...items.map((item) => header.map((key) => (item && item[key] != null ? String(item[key]) : '')))];
  }

  function hydrateMapGroupOptions() {
    if (!mapGroupSelect) return;
    const current = String(mapGroupSelect.value || '');
    mapGroupSelect.innerHTML = '<option value="">Select group</option>';

    (state.localGroups || []).forEach((group) => {
      const id = Number(group && group.id);
      if (!id) return;
      const option = document.createElement('option');
      option.value = String(id);
      option.textContent = `${group.group_code || `group_${id}`} (${group.mode || 'loop'})`;
      mapGroupSelect.appendChild(option);
    });

    if (current) {
      mapGroupSelect.value = current;
    }
  }

  function hydrateApptookGroupOptions() {
    if (!localApptookGroupSelect) return;
    const current = String(localApptookGroupSelect.value || '');
    localApptookGroupSelect.innerHTML = '<option value="">Select group</option>';

    (state.localGroups || []).forEach((group) => {
      const id = Number(group && group.id);
      if (!id) return;
      const option = document.createElement('option');
      option.value = String(id);
      option.textContent = `${group.group_code || `group_${id}`} - ${group.name || ''}`;
      localApptookGroupSelect.appendChild(option);
    });

    if (current) {
      localApptookGroupSelect.value = current;
    }
  }

  async function moveGroupKey(groupKeyId, direction, triggerBtn = null) {
    const groupId = Number((mapGroupSelect && mapGroupSelect.value) || 0);
    if (!groupId || !groupKeyId) {
      setStatus('Cannot reorder: group or key is missing.');
      return;
    }

    if (triggerBtn) {
      triggerBtn.classList.add('map-reordering');
      triggerBtn.disabled = true;
    }

    try {
      const result = await callLocalRest(`/groups/${groupId}/keys/reorder`, 'POST', {
        groupKeyId,
        direction
      });
      setResponse({ ok: true, action: 'reorder', groupId, groupKeyId, direction, result });
      await loadSelectedGroupKeys(false);

      const movedRow = mapGroupKeysTable ? mapGroupKeysTable.querySelector(`tr[data-group-key-id="${groupKeyId}"]`) : null;
      if (movedRow) {
        movedRow.classList.add('map-updated');
        setTimeout(() => movedRow.classList.remove('map-updated'), 900);
      }

      setStatus(result.message || 'Group key order updated.');
    } catch (err) {
      setResponse({ ok: false, action: 'reorder', groupId, groupKeyId, direction, message: err.message || String(err) });
      setStatus(err.message || String(err));
    } finally {
      if (triggerBtn) {
        triggerBtn.classList.remove('map-reordering');
        triggerBtn.disabled = false;
      }
    }
  }

  function renderGroupKeysWithActions(items) {
    if (!mapGroupKeysTable) return;

    if (!Array.isArray(items) || !items.length) {
      mapGroupKeysTable.innerHTML = '<div class="empty">No group keys loaded.</div>';
      return;
    }

    const rowsHtml = items.map((item) => {
      const id = Number(item.id || 0);
      const seq = Number(item.sequence || 0);
      return `
        <tr data-group-key-id="${id}">
          <td>${escapeHtml(id)}</td>
          <td>${escapeHtml(item.source_key || '')}</td>
          <td>${escapeHtml(seq)}</td>
          <td>${escapeHtml(item.status || '')}</td>
          <td>
            <div class="actions map-actions-inline" style="margin-top:0; gap:8px; align-items:center; justify-content:flex-start;">
              <button class="btn-ghost map-move-up" data-id="${id}" type="button">Up</button>
              <button class="btn-ghost map-move-down" data-id="${id}" type="button">Down</button>
              <button class="btn-secondary map-remove" data-id="${id}" type="button">Remove</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    mapGroupKeysTable.innerHTML = `
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Source Key</th>
            <th>Sequence</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>${rowsHtml}</tbody>
      </table>
    `;

    mapGroupKeysTable.querySelectorAll('.map-move-up').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        moveGroupKey(Number(btn.dataset.id || 0), 'up', btn);
      });
    });

    mapGroupKeysTable.querySelectorAll('.map-move-down').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        moveGroupKey(Number(btn.dataset.id || 0), 'down', btn);
      });
    });

    mapGroupKeysTable.querySelectorAll('.map-remove').forEach((btn) => {
      btn.addEventListener('click', () => removeGroupKey(Number(btn.dataset.id || 0)));
    });
  }

  async function refreshLocalData(showSuccess = true) {
    if (!localStockTable && !localGroupsTable) {
      return;
    }

    if (localRefreshButton) setBusy(localRefreshButton, true, 'Refreshing...');
    setStatus('Loading local WP DB data...');

    try {
      const [stockRes, groupsRes] = await Promise.all([
        callLocalRest('/stock-keys', 'GET'),
        callLocalRest('/groups', 'GET')
      ]);

      state.localStockKeys = Array.isArray(stockRes.items) ? stockRes.items : [];
      state.localGroups = Array.isArray(groupsRes.items) ? groupsRes.items : [];

      renderTable(localStockTable, normalizeRowsFromObjects(state.localStockKeys));
      renderTable(localGroupsTable, normalizeRowsFromObjects(state.localGroups));
      hydrateMapGroupOptions();
      hydrateApptookGroupOptions();

      if (showSuccess) {
        setStatus(`Local DB loaded. Stock keys: ${state.localStockKeys.length}, groups: ${state.localGroups.length}.`);
      }
    } catch (err) {
      setStatus(err.message || String(err));
      setResponse({ ok: false, message: err.message || String(err) });
    } finally {
      if (localRefreshButton) setBusy(localRefreshButton, false);
    }
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

  if (debugPingButton) {
    debugPingButton.addEventListener('click', async () => {
      setBusy(debugPingButton, true, 'Pinging...');
      setStatus('Running WP AJAX debug ping...');
      try {
        const result = await callWpAjax('extension_cursor_admin_debug_ping');
        setResponse({ ok: true, debug: result });
        setStatus(result.message || 'Debug ping OK.');
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err) });
        setStatus(err.message || String(err));
      } finally {
        setBusy(debugPingButton, false);
      }
    });
  }

  if (localRefreshButton) {
    localRefreshButton.addEventListener('click', () => {
      refreshLocalData(true);
    });
  }

  if (localImportButton) {
    localImportButton.addEventListener('click', async () => {
      const keysText = String((localStockKeysText && localStockKeysText.value) || '').trim();
      if (!keysText) {
        setStatus('Please provide stock keys to import.');
        return;
      }

      setBusy(localImportButton, true, 'Importing...');
      setStatus('Importing stock keys to local WP DB...');

      try {
        const result = await callLocalRest('/stock-keys/import', 'POST', {
          keysText,
          provider: localProvider ? localProvider.value : '',
          expireAt: localExpireAt ? localExpireAt.value : '',
          maxDevices: localMaxDevices ? Number(localMaxDevices.value || 1) : 1,
          tokenCapacity: localTokenCapacity ? Number(localTokenCapacity.value || 100) : 100,
          note: localNote ? localNote.value : ''
        });

        setResponse(result);
        setStatus(result.message || 'Stock keys imported successfully.');

        if (localStockKeysText) localStockKeysText.value = '';
        await refreshLocalData(false);
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err) });
        setStatus(err.message || String(err));
      } finally {
        setBusy(localImportButton, false);
      }
    });
  }

  if (localCreateGroupButton) {
    localCreateGroupButton.addEventListener('click', async () => {
      const groupCode = String((localGroupCode && localGroupCode.value) || '').trim();
      const name = String((localGroupName && localGroupName.value) || '').trim();
      const mode = String((localGroupMode && localGroupMode.value) || 'loop').trim() === 'single' ? 'single' : 'loop';

      if (!groupCode || !name) {
        setStatus('Group code and group name are required.');
        return;
      }

      setBusy(localCreateGroupButton, true, 'Creating...');
      setStatus('Creating local group...');

      try {
        const result = await callLocalRest('/groups', 'POST', {
          groupCode,
          name,
          mode,
          note: localGroupNote ? localGroupNote.value : ''
        });

        setResponse(result);
        setStatus(result.message || 'Group created successfully.');

        if (localGroupCode) localGroupCode.value = '';
        if (localGroupName) localGroupName.value = '';
        if (localGroupNote) localGroupNote.value = '';

        await refreshLocalData(false);
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err) });
        setStatus(err.message || String(err));
      } finally {
        setBusy(localCreateGroupButton, false);
      }
    });
  }

  async function refreshLocalApptookKeys(showSuccess = true) {
    if (!localApptookKeysTable) return;

    if (localRefreshApptookButton) setBusy(localRefreshApptookButton, true, 'Refreshing...');
    try {
      const result = await callLocalRest('/apptook-keys', 'GET');
      state.localApptookKeys = Array.isArray(result.items) ? result.items : [];

      const sanitizedItems = state.localApptookKeys.map((item) => {
        const clone = { ...(item || {}) };
        delete clone.note;
        return clone;
      });

      renderTable(localApptookKeysTable, normalizeRowsFromObjects(sanitizedItems));
      if (showSuccess) setStatus(`Loaded ${state.localApptookKeys.length} APTOOK keys.`);
    } catch (err) {
      setResponse({ ok: false, message: err.message || String(err) });
      setStatus(err.message || String(err));
    } finally {
      if (localRefreshApptookButton) setBusy(localRefreshApptookButton, false);
    }
  }

  async function loadSelectedGroupKeys(showSuccess = true) {
    const groupId = Number((mapGroupSelect && mapGroupSelect.value) || 0);
    if (!groupId) {
      renderGroupKeysWithActions([]);
      if (showSuccess) setStatus('Please select a group.');
      return;
    }

    try {
      const result = await callLocalRest(`/groups/${groupId}/keys`, 'GET');
      state.localSelectedGroupKeys = Array.isArray(result.items) ? result.items : [];
      renderGroupKeysWithActions(state.localSelectedGroupKeys);
      if (showSuccess) setStatus(`Loaded ${state.localSelectedGroupKeys.length} keys from group.`);
    } catch (err) {
      setResponse({ ok: false, message: err.message || String(err) });
      setStatus(err.message || String(err));
    }
  }

  async function removeGroupKey(groupKeyId) {
    if (!groupKeyId) return;

    try {
      const result = await callLocalRest(`/group-keys/${groupKeyId}`, 'DELETE');
      setResponse(result);
      setStatus(result.message || 'Removed key from group.');
      await loadSelectedGroupKeys(false);
      await refreshLocalData(false);
    } catch (err) {
      setResponse({ ok: false, message: err.message || String(err) });
      setStatus(err.message || String(err));
    }
  }

  if (mapLoadGroupKeysButton) {
    mapLoadGroupKeysButton.addEventListener('click', () => {
      loadSelectedGroupKeys(true);
    });
  }

  if (mapAttachKeysButton) {
    mapAttachKeysButton.addEventListener('click', async () => {
      const groupId = Number((mapGroupSelect && mapGroupSelect.value) || 0);
      if (!groupId) {
        setStatus('Please select a group.');
        return;
      }

      const rawTokens = String((mapStockKeyIds && mapStockKeyIds.value) || '')
        .split(',')
        .map((v) => String(v || '').trim())
        .filter(Boolean);

      const sourceKeyToId = {};
      (state.localStockKeys || []).forEach((item) => {
        const key = String(item && item.source_key ? item.source_key : '').trim();
        const id = Number(item && item.id ? item.id : 0);
        if (key && id) sourceKeyToId[key] = id;
      });

      const ids = Array.from(new Set(rawTokens
        .map((token) => {
          const asInt = parseInt(token, 10);
          if (Number.isInteger(asInt) && asInt > 0) {
            return asInt;
          }
          return sourceKeyToId[token] || 0;
        })
        .filter((v) => Number.isInteger(v) && v > 0)));

      if (!ids.length) {
        setStatus('Please provide valid stock key IDs or source_key values (comma separated).');
        return;
      }

      setBusy(mapAttachKeysButton, true, 'Attaching...');
      setStatus('Attaching stock keys to group...');

      try {
        const result = await callLocalRest(`/groups/${groupId}/keys`, 'POST', {
          stockKeyIds: ids
        });
        setResponse(result);
        setStatus(result.message || 'Attached keys successfully.');
        if (mapStockKeyIds) mapStockKeyIds.value = '';
        await loadSelectedGroupKeys(false);
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err) });
        setStatus(err.message || String(err));
      } finally {
        setBusy(mapAttachKeysButton, false);
      }
    });
  }

  if (mapGroupSelect) {
    mapGroupSelect.addEventListener('change', () => {
      loadSelectedGroupKeys(false);
    });
  }

  function switchTab(tab) {
    const isRuntime = tab === 'runtime';
    if (tabMainButton) {
      tabMainButton.classList.toggle('active', !isRuntime);
      tabMainButton.setAttribute('aria-selected', String(!isRuntime));
    }
    if (tabRuntimeMonitorButton) {
      tabRuntimeMonitorButton.classList.toggle('active', isRuntime);
      tabRuntimeMonitorButton.setAttribute('aria-selected', String(isRuntime));
    }
    if (tabMainContent) tabMainContent.classList.toggle('active', !isRuntime);
    if (tabRuntimeMonitorContent) tabRuntimeMonitorContent.classList.toggle('active', isRuntime);
  }

  function renderRuntimeMonitorData(result) {
    if (!result) return;

    if (rmUsagePercent) rmUsagePercent.textContent = `${Number(result.apptookUsagePercent || 0).toFixed(2)}%`;
    if (rmCurrentSourceKey) rmCurrentSourceKey.textContent = String(result.currentSourceKey || '-');
    if (rmTotalCapacity) rmTotalCapacity.textContent = String(result.totalTokenCapacity || 0);

    const licenses = Array.isArray(result.licenses) ? result.licenses : [];
    const rows = licenses.length
      ? [
          ['sequence', 'sourceKey', 'tokenCapacity', 'consumedRaw', 'usagePercent', 'isCurrent'],
          ...licenses.map((x) => [x.sequence, x.sourceKey, x.tokenCapacity, x.consumedRaw, `${x.usagePercent}%`, x.isCurrent ? 'Running' : ''])
        ]
      : [];
    renderTable(rmLicensesTable, rows);
  }

  async function loadRuntimeMonitor(showSuccess = true) {
    const apptookKeyValue = String((rmApptookKey && rmApptookKey.value) || '').trim();
    if (!apptookKeyValue) {
      setStatus('Please provide APTOOK Key for Runtime Monitor.');
      return null;
    }

    if (rmLoadButton) setBusy(rmLoadButton, true, 'Loading...');
    if (rmRefreshButton) setBusy(rmRefreshButton, true, 'Refreshing...');

    try {
      const query = `/runtime/monitor?apptookKey=${encodeURIComponent(apptookKeyValue)}`;
      const result = await callLocalRest(query, 'GET');
      state.runtimeMonitor = result;
      setResponse(result);

      renderRuntimeMonitorData(result);

      if (showSuccess) setStatus(`Runtime monitor loaded for ${apptookKeyValue}.`);
      return result;
    } catch (err) {
      setResponse({ ok: false, message: err.message || String(err) });
      setStatus(err.message || String(err));
      return null;
    } finally {
      if (rmLoadButton) setBusy(rmLoadButton, false);
      if (rmRefreshButton) setBusy(rmRefreshButton, false);
    }
  }

  function stopRuntimeSimulation(silent = false) {
    if (state.runtimeSimTimer) {
      clearInterval(state.runtimeSimTimer);
      state.runtimeSimTimer = null;
    }
    if (state.runtimeSimRefreshTimer) {
      clearInterval(state.runtimeSimRefreshTimer);
      state.runtimeSimRefreshTimer = null;
    }
    state.runtimeSimBusy = false;
    state.runtimeSimAwaitRefresh = false;
    state.runtimeSimCtx = null;
    if (rmSimStartButton) rmSimStartButton.disabled = false;
    if (rmSimStopButton) rmSimStopButton.disabled = false;
    if (!silent) setStatus('Runtime simulation stopped.');
  }

  async function startRuntimeSimulation() {
    const apptookKeyValue = String((rmApptookKey && rmApptookKey.value) || '').trim();
    if (!apptookKeyValue) {
      setStatus('Please provide APTOOK Key before starting simulation.');
      return;
    }

    const thresholdPercent = Math.max(1, Math.min(100, Number((rmSimThreshold && rmSimThreshold.value) || 95)));
    const intervalMs = 100;
    if (rmSimIntervalMs) rmSimIntervalMs.value = '100';
    const targetSeconds = Math.max(1, Number((rmSimTargetSeconds && rmSimTargetSeconds.value) || 5));

    const monitor = await loadRuntimeMonitor(false);
    if (!monitor) return;

    stopRuntimeSimulation(true);
    if (rmSimStartButton) rmSimStartButton.disabled = true;
    if (rmSimStopButton) rmSimStopButton.disabled = false;

    setStatus(`Simulation running (threshold ${thresholdPercent}%, interval ${intervalMs}ms).`);

    state.runtimeSimCtx = null;

    state.runtimeSimTimer = setInterval(async () => {
      if (state.runtimeSimBusy || state.runtimeSimAwaitRefresh) return;
      state.runtimeSimBusy = true;

      try {
        const latest = state.runtimeMonitor;
        const licenses = latest && Array.isArray(latest.licenses) ? latest.licenses : [];
        const active = licenses.find((x) => !!x.isCurrent) || licenses[0];
        if (!active) return;

        const activeId = Number(active.groupKeyId || 0);
        const consumedRaw = Number(active.consumedRaw || 0);
        const capacity = Number(active.tokenCapacity || 100);
        const targetRaw = (capacity * thresholdPercent) / 100;
        const nowMs = Date.now();

        if (!state.runtimeSimCtx || state.runtimeSimCtx.activeId !== activeId) {
          state.runtimeSimCtx = {
            activeId,
            startConsumedRaw: consumedRaw,
            targetRaw,
            startedAtMs: nowMs
          };
        }

        const elapsedMs = nowMs - Number(state.runtimeSimCtx.startedAtMs || nowMs);
        const progress = Math.min(1, elapsedMs / (targetSeconds * 1000));
        const startRaw = Number(state.runtimeSimCtx.startConsumedRaw || 0);
        const simTargetRaw = Number(state.runtimeSimCtx.targetRaw || targetRaw);
        let nextRaw = startRaw + (simTargetRaw - startRaw) * progress;
        if (nextRaw <= consumedRaw) {
          nextRaw = consumedRaw + 0.000001;
        }

        if (latest && Array.isArray(latest.licenses)) {
          const activeLicense = latest.licenses.find((x) => Number(x.groupKeyId || 0) === activeId);
          if (activeLicense) {
            activeLicense.consumedRaw = Number(nextRaw.toFixed(6));
            activeLicense.usagePercent = capacity > 0 ? Number(((activeLicense.consumedRaw / capacity) * 100).toFixed(2)) : 0;
          }
          const totalCap = latest.licenses.reduce((sum, x) => sum + Number(x.tokenCapacity || 0), 0);
          const totalUsed = latest.licenses.reduce((sum, x) => sum + Number(x.consumedRaw || 0), 0);
          latest.totalTokenCapacity = Number(totalCap.toFixed(6));
          latest.totalConsumedRaw = Number(totalUsed.toFixed(6));
          latest.apptookUsagePercent = totalCap > 0 ? Number(((totalUsed / totalCap) * 100).toFixed(2)) : 0;
          renderRuntimeMonitorData(latest);
        }

        const syncRes = await callLocalRest('/runtime/dashboard-sync', 'POST', {
          apptookKey: apptookKeyValue,
          deviceId: String((rtDeviceId && rtDeviceId.value) || 'sim-device').trim() || 'sim-device',
          rawUsage: nextRaw,
          displayUsage: nextRaw
        });

        if (!syncRes || syncRes.ok === false) {
          throw new Error(syncRes && syncRes.message ? syncRes.message : 'dashboard-sync failed');
        }

        if (String(syncRes.message || '').includes('exhausted')) {
          stopRuntimeSimulation(true);
          setStatus('Simulation stopped: APTOOK usage reached 100% (all licenses >= 95%).');
          return;
        }

        const activeUsagePercent = Number(active.usagePercent || 0);
        if (progress >= 1 || activeUsagePercent >= thresholdPercent) {
          const loopRes = await callLocalRest('/runtime/loop-next', 'POST', {
            apptookKey: apptookKeyValue,
            reason: `auto_switch_threshold_${thresholdPercent}`,
            deviceId: String((rtDeviceId && rtDeviceId.value) || 'sim-device').trim() || 'sim-device'
          });

          state.runtimeSimCtx = null;
          state.runtimeSimAwaitRefresh = true;
          await loadRuntimeMonitor(false);
          state.runtimeSimAwaitRefresh = false;

          if (loopRes && loopRes.ok === false && String(loopRes.message || '').includes('exhausted')) {
            stopRuntimeSimulation(true);
            setStatus('Simulation stopped: APTOOK usage reached 100% (all licenses >= 95%).');
            return;
          }
        }
      } catch (err) {
        const msg = err.message || String(err);
        if (String(msg).includes('exhausted')) {
          stopRuntimeSimulation(true);
          setStatus('Simulation stopped: APTOOK usage reached 100% (all licenses >= 95%).');
        } else {
          setResponse({ ok: false, message: msg, context: 'simulation-step' });
        }
      } finally {
        state.runtimeSimBusy = false;
      }
    }, intervalMs);

    const monitorRefreshMs = Math.max(150, Math.min(1000, intervalMs * 2));
    state.runtimeSimRefreshTimer = setInterval(() => {
      if (!state.runtimeSimBusy && !state.runtimeSimAwaitRefresh) {
        loadRuntimeMonitor(false);
      }
    }, monitorRefreshMs);
  }

  if (tabMainButton) {
    tabMainButton.addEventListener('click', () => switchTab('main'));
  }
  if (tabRuntimeMonitorButton) {
    tabRuntimeMonitorButton.addEventListener('click', () => switchTab('runtime'));
  }

  if (rmLoadButton) {
    rmLoadButton.addEventListener('click', () => loadRuntimeMonitor(true));
  }
  if (rmRefreshButton) {
    rmRefreshButton.addEventListener('click', () => loadRuntimeMonitor(true));
  }
  if (rmSimStartButton) {
    rmSimStartButton.addEventListener('click', () => startRuntimeSimulation());
  }
  if (rmSimStopButton) {
    rmSimStopButton.disabled = true;
    rmSimStopButton.addEventListener('click', () => {
      stopRuntimeSimulation(false);
    });
  }

  if (rmSimResetButton) {
    rmSimResetButton.addEventListener('click', async () => {
      const apptookKeyValue = String((rmApptookKey && rmApptookKey.value) || '').trim();
      if (!apptookKeyValue) {
        setStatus('Please provide APTOOK Key before reset.');
        return;
      }

      stopRuntimeSimulation(true);
      setBusy(rmSimResetButton, true, 'Resetting...');

      try {
        const result = await callLocalRest('/runtime/reset-sim', 'POST', { apptookKey: apptookKeyValue });
        setResponse(result);
        await loadRuntimeMonitor(false);
        setStatus('Reset completed. You can run simulation again.');
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err), context: 'reset-sim' });
        setStatus(err.message || String(err));
      } finally {
        setBusy(rmSimResetButton, false);
      }
    });
  }

  if (localRefreshApptookButton) {
    localRefreshApptookButton.addEventListener('click', () => {
      refreshLocalApptookKeys(true);
    });
  }

  async function callRuntime(path, body, button, busyText) {
    if (button) setBusy(button, true, busyText || 'Running...');
    try {
      const base = String(window.location.origin).replace(/\/+$/, '') + '/wp-json/extension-cursor/v1';
      const result = await callLocalRest(path, 'POST', body, base);
      setResponse(result);
      setStatus(result.message || 'Runtime test success.');
      return result;
    } catch (err) {
      setResponse({ ok: false, message: err.message || String(err), path, body });
      setStatus(err.message || String(err));
      return null;
    } finally {
      if (button) setBusy(button, false);
    }
  }

  if (rtLoginButton) {
    rtLoginButton.addEventListener('click', async () => {
      const apptookKeyValue = String((rtApptookKey && rtApptookKey.value) || '').trim();
      if (!apptookKeyValue) {
        setStatus('Please provide APTOOK Key for runtime test.');
        return;
      }

      await callRuntime('/runtime/login', {
        apptookKey: apptookKeyValue,
        deviceId: String((rtDeviceId && rtDeviceId.value) || '').trim()
      }, rtLoginButton, 'Testing login...');
    });
  }

  if (rtLoopNextButton) {
    rtLoopNextButton.addEventListener('click', async () => {
      const apptookKeyValue = String((rtApptookKey && rtApptookKey.value) || '').trim();
      if (!apptookKeyValue) {
        setStatus('Please provide APTOOK Key for runtime test.');
        return;
      }

      await callRuntime('/runtime/loop-next', {
        apptookKey: apptookKeyValue,
        reason: String((rtReason && rtReason.value) || 'manual_switch').trim() || 'manual_switch',
        deviceId: String((rtDeviceId && rtDeviceId.value) || '').trim()
      }, rtLoopNextButton, 'Testing loop-next...');
    });
  }

  if (rtDashboardSyncButton) {
    rtDashboardSyncButton.addEventListener('click', async () => {
      const apptookKeyValue = String((rtApptookKey && rtApptookKey.value) || '').trim();
      if (!apptookKeyValue) {
        setStatus('Please provide APTOOK Key for runtime test.');
        return;
      }

      const rawUsageValue = String((rtRawUsage && rtRawUsage.value) || '').trim();
      const displayUsageValue = String((rtDisplayUsage && rtDisplayUsage.value) || '').trim();

      await callRuntime('/runtime/dashboard-sync', {
        apptookKey: apptookKeyValue,
        deviceId: String((rtDeviceId && rtDeviceId.value) || '').trim(),
        rawUsage: rawUsageValue === '' ? null : Number(rawUsageValue),
        displayUsage: displayUsageValue === '' ? null : Number(displayUsageValue)
      }, rtDashboardSyncButton, 'Testing dashboard-sync...');
    });
  }

  if (localCreateApptookButton) {
    localCreateApptookButton.addEventListener('click', async () => {
      const keyValue = String((localApptookKey && localApptookKey.value) || '').trim();
      const groupId = Number((localApptookGroupSelect && localApptookGroupSelect.value) || 0);
      const keyTypeValue = String((localApptookKeyType && localApptookKeyType.value) || 'loop').trim() === 'single' ? 'single' : 'loop';

      if (!keyValue || !groupId) {
        setStatus('APTOOK key and group are required.');
        return;
      }

      setBusy(localCreateApptookButton, true, 'Creating...');
      setStatus('Creating APTOOK key...');

      try {
        const result = await callLocalRest('/apptook-keys', 'POST', {
          apptookKey: keyValue,
          groupId,
          keyType: keyTypeValue,
          expireAt: localApptookExpireAt ? localApptookExpireAt.value : '',
          note: localApptookNote ? localApptookNote.value : ''
        });

        setResponse(result);
        setStatus(result.message || 'APTOOK key created successfully.');

        if (localApptookKey) localApptookKey.value = '';
        if (localApptookExpireAt) localApptookExpireAt.value = '';
        if (localApptookNote) localApptookNote.value = '';

        await refreshLocalApptookKeys(false);
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err) });
        setStatus(err.message || String(err));
      } finally {
        setBusy(localCreateApptookButton, false);
      }
    });
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
  refreshLocalData(false);
  refreshLocalApptookKeys(false);
})();
