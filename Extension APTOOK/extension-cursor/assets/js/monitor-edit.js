window.ExtensionCursorModules = window.ExtensionCursorModules || {};
window.ExtensionCursorModules.createMonitorEdit = function createMonitorEdit({ api, ui, renderers, $, elements, onStateChanged }) {
  const { monitorEditPanel, monitorEditKeyTitle, monitorEditExpiry, monitorAssignedList, monitorAvailableList, monitorEditLoadAvailable, monitorEditClose, monitorEditAssign, monitorEditUnassign } = elements;
  let activeKeyId = 0;

  function getSelectedIds($container) {
    return $container.find('input[type="checkbox"]:checked').map(function () {
      return $(this).val();
    }).get();
  }

  function renderAssigned(licences, key) {
    const html = Array.isArray(licences) && licences.length ? licences.map((licence) => `
      <div class="ec-licence-item">
        <label class="ec-checkbox-item">
          <input type="checkbox" value="${licence.id}">
          <span><strong>${ui.escapeHtml(licence.token)}</strong><small>${ui.escapeHtml(String(licence.duration_days))} days • ${ui.escapeHtml(String(licence.token_limit))} tokens • ${ui.escapeHtml(licence.status)}</small></span>
        </label>
      </div>
    `).join('') : '<div class="ec-mini-note">ยังไม่มี licence ที่ผูกกับ key นี้</div>';
    monitorAssignedList.html(html);
    monitorEditKeyTitle.text(key && key.key_code ? key.key_code : '-');
    monitorEditExpiry.text(key && key.expiry_date ? key.expiry_date : '-');
  }

  function renderAvailable(licences) {
    const html = Array.isArray(licences) && licences.length ? licences.map((licence) => `
      <div class="ec-licence-item">
        <label class="ec-checkbox-item">
          <input type="checkbox" value="${licence.id}">
          <span><strong>${ui.escapeHtml(licence.token)}</strong><small>${ui.escapeHtml(String(licence.duration_days))} days • ${ui.escapeHtml(String(licence.token_limit))} tokens • ${ui.escapeHtml(licence.status)}</small></span>
        </label>
      </div>
    `).join('') : '<div class="ec-mini-note">ยังไม่มี licence ที่ยังไม่ถูกผูก</div>';
    monitorAvailableList.html(html);
  }

  function loadAssigned() {
    if (!activeKeyId) return;
    return api.post('extension_cursor_dashboard_snapshot', { key_id: activeKeyId }).done((response) => {
      if (!response || !response.success || !response.data) return;
      const monitor = response.data.monitor || {};
      renderAssigned(Array.isArray(monitor.licences) ? monitor.licences : [], monitor.key || {});
    });
  }

  function loadAvailable() {
    return api.post('extension_cursor_list_licences', {}).done((response) => {
      if (!response || !response.success) return;
      renderAvailable(Array.isArray(response.data.rows) ? response.data.rows : []);
    });
  }

  function open(keyId) {
    activeKeyId = parseInt(keyId, 10) || 0;
    if (!activeKeyId) return;
    monitorEditPanel.stop(true, true).slideDown(180);
    window.setTimeout(function () {
      if (!monitorEditPanel.length) return;
      var offset = monitorEditPanel.offset();
      var target = offset ? Math.max(0, offset.top - 24) : 0;
      window.scrollTo({ top: target, behavior: 'smooth' });
    }, 500);
    loadAssigned();
    loadAvailable();
  }

  function close() {
    activeKeyId = 0;
    monitorEditPanel.stop(true, true).slideUp(120);
    monitorAssignedList.html('<div class="ec-mini-note">ยังไม่มีข้อมูล กรุณาเลือก key ด้านบนก่อน</div>');
    monitorAvailableList.html('<div class="ec-mini-note">กด Load Available Licence(s) เพื่อแสดงรายการ</div>');
    monitorEditKeyTitle.text('-');
    monitorEditExpiry.text('-');
  }

  function notifyStateChanged() {
    if (typeof onStateChanged === 'function') {
      onStateChanged();
    }
    $(document).trigger('extension-cursor:state-changed');
  }

  function bindEvents() {
    monitorEditLoadAvailable.on('click', function () {
      ui.pressEffect(monitorEditLoadAvailable);
      loadAvailable();
    });

    monitorEditClose.on('click', function () {
      ui.pressEffect(monitorEditClose);
      close();
    });

    monitorEditAssign.on('click', function () {
      if (!activeKeyId) return;
      const ids = getSelectedIds(monitorAvailableList);
      if (!ids.length) { ui.setNotice('กรุณาเลือก licence อย่างน้อย 1 รายการ', 'error'); return; }
      api.post('extension_cursor_assign_licences', { key_id: activeKeyId, licence_ids: ids }).done((response) => {
        if (response && response.success) {
          ui.setNotice('Assign licence สำเร็จ', 'success');
          loadAssigned();
          loadAvailable();
          notifyStateChanged();
        } else {
          ui.setNotice((response && response.data && response.data.message) || 'Assign failed.', 'error');
        }
      }).fail(() => ui.setNotice('Assign failed.', 'error'));
    });

    monitorEditUnassign.on('click', function () {
      if (!activeKeyId) return;
      const ids = getSelectedIds(monitorAssignedList);
      if (!ids.length) { ui.setNotice('กรุณาเลือก licence อย่างน้อย 1 รายการ', 'error'); return; }
      api.post('extension_cursor_unassign_licences', { key_id: activeKeyId, licence_ids: ids }).done((response) => {
        if (response && response.success) {
          ui.setNotice('Unassign licence สำเร็จ', 'success');
          loadAssigned();
          loadAvailable();
          notifyStateChanged();
        } else {
          ui.setNotice((response && response.data && response.data.message) || 'Unassign failed.', 'error');
        }
      }).fail(() => ui.setNotice('Unassign failed.', 'error'));
    });
  }

  return { open, close, loadAvailable, loadAssigned, bindEvents };
};
