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
    dashboardTokens: [],
    localStockKeys: [],
    localGroups: [],
    localApptookKeys: [],
    runtimeMonitor: null
  };

  const $ = (id) => document.getElementById(id);

  const statusText = $('statusText');
  const responseBox = $('responseBox');


  const localStockKeysText = $('localStockKeysText');
  const localStockSearch = $('localStockSearch');
  const localStockClearButton = $('localStockClearButton');
  const localGroupSearch = $('localGroupSearch');
  const localGroupClearButton = $('localGroupClearButton');
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

  const tabMainButton = $('tabMainButton');
  const tabMainContent = $('tabMainContent');


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

  function switchTab(tab) {
    const isSimulation = tab === 'simulation';
    if (tabMainButton) {
      tabMainButton.classList.toggle('active', !isSimulation);
      tabMainButton.setAttribute('aria-selected', String(!isSimulation));
    }
    if (tabMainContent) tabMainContent.classList.toggle('active', !isSimulation);
  }

  function renderSimulationLicenses(_items) {
    return;
  }

  function buildRandomApptookKey() {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let suffix = '';
    for (let i = 0; i < 10; i += 1) suffix += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
    return `apptook_${suffix}`;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
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

  function matchesSearch(item, query) {
    if (!query) return true;
    const haystack = Object.values(item || {}).map((v) => String(v ?? '').toLowerCase()).join(' ');
    return haystack.includes(query);
  }

  function renderLocalStockTable(items) {
    if (!localStockTable) return;
    const query = String((localStockSearch && localStockSearch.value) || '').trim().toLowerCase();
    const filtered = Array.isArray(items) ? items.filter((item) => matchesSearch(item, query)) : [];
    if (!filtered.length) {
      localStockTable.innerHTML = '<div class="empty">No local stock keys loaded.</div>';
      return;
    }

    const rows = filtered.map((item) => {
      const id = Number(item.id || 0);
      return `
        <tr data-stock-key-id="${id}">
          <td>${escapeHtml(id)}</td>
          <td>${escapeHtml(item.source_key || '')}</td>
          <td>${escapeHtml(item.status || '')}</td>
          <td>${escapeHtml(item.provider || '')}</td>
          <td>${escapeHtml(item.expire_at || '')}</td>
          <td>${escapeHtml(item.max_devices || '')}</td>
          <td>${escapeHtml(item.token_capacity || '')}</td>
          <td>
            <button class="btn-secondary local-stock-delete" data-id="${id}" type="button">Delete</button>
          </td>
        </tr>
      `;
    }).join('');

    localStockTable.innerHTML = `
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Source Key</th>
            <th>Status</th>
            <th>Provider</th>
            <th>Expire</th>
            <th>Max Devices</th>
            <th>Token Capacity</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;

    localStockTable.querySelectorAll('.local-stock-delete').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        if (!window.confirm('Delete this stock key?')) return;
        try {
          const result = await callLocalRest(`/stock-keys/${id}`, 'DELETE');
          setResponse(result);
          setStatus(result.message || 'Stock key deleted.');
          await refreshLocalData(false);
        } catch (err) {
          setResponse({ ok: false, message: err.message || String(err) });
          setStatus(err.message || String(err));
        }
      });
    });
  }

  function renderLocalGroupsTable(items) {
    if (!localGroupsTable) return;
    const query = String((localGroupSearch && localGroupSearch.value) || '').trim().toLowerCase();
    const filtered = Array.isArray(items) ? items.filter((item) => matchesSearch(item, query)) : [];
    if (!filtered.length) {
      localGroupsTable.innerHTML = '<div class="empty">No groups loaded.</div>';
      return;
    }

    const rows = filtered.map((item) => {
      const id = Number(item.id || 0);
      return `
        <tr data-group-id="${id}">
          <td>${escapeHtml(id)}</td>
          <td>${escapeHtml(item.group_code || '')}</td>
          <td>${escapeHtml(item.name || '')}</td>
          <td>${escapeHtml(item.mode || '')}</td>
          <td>${escapeHtml(item.status || '')}</td>
          <td>
            <button class="btn-secondary local-group-delete" data-id="${id}" type="button">Delete</button>
          </td>
        </tr>
      `;
    }).join('');

    localGroupsTable.innerHTML = `
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Group Code</th>
            <th>Name</th>
            <th>Mode</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    `;

    localGroupsTable.querySelectorAll('.local-group-delete').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        if (!window.confirm('Delete this group and its keys?')) return;
        try {
          const result = await callLocalRest(`/groups/${id}`, 'DELETE');
          setResponse(result);
          setStatus(result.message || 'Group deleted.');
          await refreshLocalData(false);
        } catch (err) {
          setResponse({ ok: false, message: err.message || String(err) });
          setStatus(err.message || String(err));
        }
      });
    });
  }

  function renderSimulationLicenses(items) {
    if (!simulationLicensesTable) return;
    const rows = Array.isArray(items) ? items : [];
    if (!rows.length) {
      simulationLicensesTable.innerHTML = '<div class="empty">No simulation licences loaded.</div>';
      return;
    }

    const header = ['id', 'license_code', 'name', 'status', 'mode', 'token_capacity', 'current_raw_usage', 'usage_percent', 'note', 'actions'];
    const headerHtml = header.map((item) => `<th>${escapeHtml(item)}</th>`).join('');
    const bodyHtml = rows.map((row) => {
      const capacity = Number(row.token_capacity || 0);
      const raw = Number(row.current_raw_usage || 0);
      const pct = capacity > 0 ? ((raw / capacity) * 100).toFixed(2) : '0.00';
      return `<tr>
        <td>${escapeHtml(row.id)}</td>
        <td>${escapeHtml(row.license_code)}</td>
        <td>${escapeHtml(row.name)}</td>
        <td>${escapeHtml(row.status)}</td>
        <td>${escapeHtml(row.mode)}</td>
        <td>${escapeHtml(row.token_capacity)}</td>
        <td>${escapeHtml(row.current_raw_usage)}</td>
        <td>${escapeHtml(pct)}%</td>
        <td>${escapeHtml(row.note || '')}</td>
        <td>
          <button class="btn-secondary simulation-start" data-id="${escapeHtml(row.id)}" type="button">Start</button>
          <button class="btn-secondary simulation-stop" data-id="${escapeHtml(row.id)}" type="button">Stop</button>
          <button class="btn-secondary simulation-reset" data-id="${escapeHtml(row.id)}" type="button">Reset Usage</button>
          <button class="btn-secondary simulation-delete" data-id="${escapeHtml(row.id)}" type="button">Delete</button>
        </td>
      </tr>`;
    }).join('');
    simulationLicensesTable.innerHTML = `<table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table>`;

    simulationLicensesTable.querySelectorAll('.simulation-start').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        try {
          const result = await callLocalRest(`/simulation-licenses/${id}/start`, 'POST');
          setResponse(result);
          setStatus(result.message || 'Simulation licence started.');
          await refreshSimulationLicenses(false);
        } catch (err) {
          setResponse({ ok: false, message: err.message || String(err) });
          setStatus(err.message || String(err));
        }
      });
    });

    simulationLicensesTable.querySelectorAll('.simulation-stop').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        try {
          const result = await callLocalRest(`/simulation-licenses/${id}/stop`, 'POST');
          setResponse(result);
          setStatus(result.message || 'Simulation licence stopped.');
          await refreshSimulationLicenses(false);
        } catch (err) {
          setResponse({ ok: false, message: err.message || String(err) });
          setStatus(err.message || String(err));
        }
      });
    });

    simulationLicensesTable.querySelectorAll('.simulation-reset').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        try {
          const result = await callLocalRest(`/simulation-licenses/${id}/reset`, 'POST');
          setResponse(result);
          setStatus(result.message || 'Simulation usage reset.');
          await refreshSimulationLicenses(false);
        } catch (err) {
          setResponse({ ok: false, message: err.message || String(err) });
          setStatus(err.message || String(err));
        }
      });
    });

    simulationLicensesTable.querySelectorAll('.simulation-delete').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.id || 0);
        if (!id) return;
        if (!window.confirm('Delete this simulation licence?')) return;
        try {
          const result = await callLocalRest(`/simulation-licenses/${id}`, 'DELETE');
          setResponse(result);
          setStatus(result.message || 'Simulation licence deleted.');
          await refreshSimulationLicenses(false);
        } catch (err) {
          setResponse({ ok: false, message: err.message || String(err) });
          setStatus(err.message || String(err));
        }
      });
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

      renderLocalStockTable(state.localStockKeys);
      renderLocalGroupsTable(state.localGroups);
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


  if (localRefreshButton) {
    localRefreshButton.addEventListener('click', () => {
      refreshLocalData(true);
    });
  }
  if (localStockSearch) {
    localStockSearch.addEventListener('input', () => renderLocalStockTable(state.localStockKeys));
  }
  if (localStockClearButton) {
    localStockClearButton.addEventListener('click', () => {
      if (localStockSearch) localStockSearch.value = '';
      renderLocalStockTable(state.localStockKeys);
    });
  }
  if (localGroupSearch) {
    localGroupSearch.addEventListener('input', () => renderLocalGroupsTable(state.localGroups));
  }
  if (localGroupClearButton) {
    localGroupClearButton.addEventListener('click', () => {
      if (localGroupSearch) localGroupSearch.value = '';
      renderLocalGroupsTable(state.localGroups);
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

  function getSimulationUsagePercent(item) {
    const capacity = Number(item && item.token_capacity ? item.token_capacity : 0);
    const raw = Number(item && item.current_raw_usage ? item.current_raw_usage : 0);
    return capacity > 0 ? (raw / capacity) * 100 : 0;
  }

  async function tickSimulationLicense(id) {
    if (!id) return null;
    const result = await callLocalRest(`/simulation-licenses/${id}/tick`, 'POST');
    setResponse(result);
    return result;
  }

  function startSimulationAutoTick(id) {
    stopSimulationAutoTick();
    if (!id) return;
    state.simulationAutoTickTimer = setInterval(async () => {
      try {
        const current = (state.simulationLicenses || []).find((item) => Number(item.id || 0) === Number(id));
        if (!current) return;
        const pct = getSimulationUsagePercent(current);
        if (pct >= 100) {
          stopSimulationAutoTick();
          setStatus('Simulation licence reached 100%.');
          return;
        }
        await tickSimulationLicense(id);
        await refreshSimulationLicenses(false);
      } catch (err) {
        stopSimulationAutoTick();
        setStatus(err.message || String(err));
      }
    }, 1000);
  }

  function stopSimulationAutoTick() {
    if (state.simulationAutoTickTimer) {
      clearInterval(state.simulationAutoTickTimer);
      state.simulationAutoTickTimer = null;
    }
  }

  async function refreshSimulationLicenses(showSuccess = true) {
    if (!simulationLicensesTable) return;
    if (simulationRefreshButton) setBusy(simulationRefreshButton, true, 'Refreshing...');
    try {
      const result = await callLocalRest('/simulation-licenses', 'GET');
      state.simulationLicenses = Array.isArray(result.items) ? result.items : [];
      renderSimulationLicenses(state.simulationLicenses);
      if (showSuccess) setStatus(`Loaded ${state.simulationLicenses.length} simulation licences.`);
    } catch (err) {
      setResponse({ ok: false, message: err.message || String(err) });
      setStatus(err.message || String(err));
    } finally {
      if (simulationRefreshButton) setBusy(simulationRefreshButton, false);
    }
  }

  if (simulationCreateButton) {
    simulationCreateButton.addEventListener('click', async () => {
      const licenseCode = String((simulationLicenseCode && simulationLicenseCode.value) || '').trim();
      const name = String((simulationLicenseName && simulationLicenseName.value) || '').trim();
      if (!licenseCode || !name) {
        setStatus('License code and name are required.');
        return;
      }
      setBusy(simulationCreateButton, true, 'Creating...');
      try {
        const result = await callLocalRest('/simulation-licenses', 'POST', {
          licenseCode,
          name,
          tokenCapacity: simulationTokenCapacity ? Number(simulationTokenCapacity.value || 100) : 100,
          currentRawUsage: simulationCurrentRawUsage ? Number(simulationCurrentRawUsage.value || 0) : 0,
          mode: simulationMode ? simulationMode.value : 'simulation',
          status: simulationStatus ? simulationStatus.value : 'active',
          note: simulationNote ? simulationNote.value : ''
        });
        setResponse(result);
        setStatus(result.message || 'Simulation licence created successfully.');
        if (simulationLicenseCode) simulationLicenseCode.value = '';
        if (simulationLicenseName) simulationLicenseName.value = '';
        if (simulationCurrentRawUsage) simulationCurrentRawUsage.value = '0';
        if (simulationNote) simulationNote.value = '';
        await refreshSimulationLicenses(false);
      } catch (err) {
        setResponse({ ok: false, message: err.message || String(err) });
        setStatus(err.message || String(err));
      } finally {
        setBusy(simulationCreateButton, false);
      }
    });
  }

  if (simulationRefreshButton) {
    simulationRefreshButton.addEventListener('click', () => refreshSimulationLicenses(true));
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

      const rows = normalizeRowsFromObjects(sanitizedItems);
      if (rows.length) {
        const header = rows[0];
        const headerHtml = header.map((item) => `<th>${escapeHtml(item)}</th>`).join('') + '<th>Actions</th>';
        const bodyHtml = rows.slice(1)
          .map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}<td><button class="btn-ghost local-apptook-delete" data-id="${escapeHtml(row[0])}" type="button">Delete</button></td></tr>`)
          .join('');
        localApptookKeysTable.innerHTML = `<table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table>`;
      } else {
        renderTable(localApptookKeysTable, rows);
      }

      localApptookKeysTable.querySelectorAll('.local-apptook-delete').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const id = Number(btn.dataset.id || 0);
          if (!id) return;
          if (!window.confirm('Delete this APTOOK key?')) return;
          try {
            const result = await callLocalRest(`/apptook-keys/${id}`, 'DELETE');
            setResponse(result);
            setStatus(result.message || 'APTOOK key deleted.');
            await refreshLocalApptookKeys(false);
          } catch (err) {
            setResponse({ ok: false, message: err.message || String(err) });
            setStatus(err.message || String(err));
          }
        });
      });

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
  if (tabMainButton) {
    tabMainButton.addEventListener('click', () => switchTab('main'));
  }
  if (tabSimulationButton) {
    tabSimulationButton.addEventListener('click', () => switchTab('simulation'));
  }

  refreshLocalData(false);
  refreshLocalApptookKeys(false);
  refreshSimulationLicenses(false);
})();
