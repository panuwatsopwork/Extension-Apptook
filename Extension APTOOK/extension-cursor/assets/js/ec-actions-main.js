window.ExtensionCursorModules = window.ExtensionCursorModules || {};
window.ExtensionCursorModules.createEcActionsMain = function createEcActionsMain({ api, ui, renderers, state, $, elements, monitorEdit }) {
  const { tabs, panels, refreshButton, importButton, clearLicenceForm, saveKeyButton, generateKeyButton, resetKeyButton, assignButton, licenceList, monitorRows, selectKey, licenceCode, tokenCapacity, activeDays, importRemark, apptookKey, note, expiry, debugAvailableButton, debugAssignmentButton } = elements;

  function selectedLicenceIds() {
    return licenceList.find('input[type="checkbox"]:checked').map(function () {
      return $(this).val();
    }).get();
  }

  function setActiveTab(name) {
    tabs.removeClass('is-active');
    panels.removeClass('is-active');
    tabs.filter(`[data-tab="${name}"]`).addClass('is-active');
    panels.filter(`[data-panel="${name}"]`).addClass('is-active');
  }

  function reloadDashboard(keyId) {
    const candidateKeyId = keyId || selectKey.val() || 0;
    return api.post('extension_cursor_dashboard_snapshot', { key_id: candidateKeyId }).done((response) => {
      if (!response || !response.success || !response.data) return;
      const data = response.data;
      $('.ec-stat').eq(0).find('.ec-value').text(data.snapshot.keys_ready ?? 0);
      $('.ec-stat').eq(1).find('.ec-value').text(data.snapshot.licences_available ?? 0);
      $('.ec-stat').eq(2).find('.ec-value').text(data.snapshot.mapped_licences ?? 0);
      $('.ec-stat').eq(3).find('.ec-value').text(data.snapshot.needs_review ?? 0);
      const options = Array.isArray(data.keys) ? data.keys : [];
      const current = selectKey.val();
      selectKey.empty();
      if (!options.length) {
        selectKey.append('<option value="">No key found</option>');
      } else {
        options.forEach((key) => {
          const selected = current && String(current) === String(key.id) ? ' selected' : '';
          selectKey.append(`<option value="${key.id}"${selected}>${ui.escapeHtml(key.key_code)}${key.status ? ` (${ui.escapeHtml(String(key.status))})` : ''}</option>`);
        });
      }
      licenceList.html(renderers.renderLicenceList(Array.isArray(data.licences) ? data.licences : []));
      monitorRows.html(renderers.renderMonitorRows(Array.isArray(data.monitor_rows) ? data.monitor_rows : []));
      renderers.renderMonitorDetail(data.monitor || {});
    });
  }

  function ajaxFeedback(promise, successMessage, onSuccess, onError) {
    promise.done((response) => {
      if (response && response.success) {
        ui.setNotice(successMessage || (response.data && response.data.message) || 'Saved successfully.');
        if (typeof onSuccess === 'function') onSuccess(response);
      } else {
        const message = (response && response.data && response.data.message) || 'Operation failed.';
        ui.setNotice(message, 'error');
        if (typeof onError === 'function') onError(response);
      }
    }).fail((xhr) => {
      const msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Operation failed.';
      ui.setNotice(msg, 'error');
      if (typeof onError === 'function') onError(xhr);
    });
  }

  function bindEvents() {
    tabs.on('click', function (event) {
      event.preventDefault();
      setActiveTab($(this).data('tab'));
    });

    selectKey.on('change', function () {
      const selectedId = $(this).val() || 0;
      reloadDashboard(selectedId);
    });

    clearLicenceForm.on('click', function () {
      ui.pressEffect(clearLicenceForm);
      licenceCode.val('');
      tokenCapacity.val('10');
      activeDays.val('1');
      importRemark.val('');
      ui.setNotice('ฟอร์ม import ถูกล้างแล้ว', 'success');
    });

    importButton.on('click', function () {
      ui.pressEffect(importButton);
      const token = licenceCode.val().trim();
      const token_limit = parseInt(tokenCapacity.val(), 10) || 0;
      const duration_days = parseInt(activeDays.val(), 10) || 1;
      const noteValue = importRemark.val().trim();
      if (!token || token_limit < 1) { ui.setNotice('กรุณากรอก Licence Code และ Token limit ให้ครบ', 'error'); return; }
      ajaxFeedback(api.post('extension_cursor_save_licence', { token, token_limit, duration_days, note: noteValue }), 'บันทึก licence เข้าฐานข้อมูลแล้ว', reloadDashboard);
    });

    saveKeyButton.on('click', function () {
      if (state.loadingKey) return;
      const $btn = $(this);
      ui.pressEffect($btn);
      const keyId = parseInt(selectKey.val(), 10) || 0;
      const payload = { id: keyId, key_code: apptookKey.val().trim(), note: note.val().trim(), expiry_date: expiry.val() };
      if (!payload.key_code) { ui.setNotice('กรุณากรอก APPTOOK Key ก่อนบันทึก', 'error'); return; }
      state.loadingKey = true;
      ui.setLoading($btn, true, 'Saving...');
      ajaxFeedback(api.post('extension_cursor_save_key', payload), 'บันทึก APPTOOK key แล้ว', function () {
        reloadDashboard(keyId).always(() => { state.loadingKey = false; ui.setLoading($btn, false); });
      });
    });

    generateKeyButton.on('click', function () { ui.pressEffect(generateKeyButton); apptookKey.val(`APT-${Math.random().toString(36).slice(2, 8).toUpperCase()}`); ui.setNotice('สร้าง key ชั่วคราวให้แล้ว', 'success'); });
    resetKeyButton.on('click', function () { ui.pressEffect(resetKeyButton); apptookKey.val(''); note.val(''); expiry.val(''); ui.setNotice('ฟอร์ม key ถูกรีเซ็ตแล้ว', 'success'); });

    assignButton.on('click', function () {
      ui.pressEffect(assignButton);
      const keyId = parseInt(selectKey.val(), 10) || 0;
      const ids = selectedLicenceIds();
      if (!keyId || !ids.length) { ui.setNotice('กรุณาเลือก key และ licence อย่างน้อย 1 รายการ', 'error'); return; }
      ajaxFeedback(api.post('extension_cursor_assign_licences', { key_id: keyId, licence_ids: ids }), 'assign licence สำเร็จ', function () { reloadDashboard(keyId).always(() => selectKey.val(String(keyId))); }, function (response) { ui.setNotice(`Assign failed. ${response && response.data && response.data.received ? JSON.stringify(response.data.received) : ''}`, 'error'); });
    });

    $(document).on('extension-cursor:state-changed', function () {
      reloadDashboard(selectKey.val() || 0);
    });

    refreshButton.on('click', function () { ui.pressEffect(refreshButton); reloadDashboard(); });

    $(document).on('click', '.ec-delete-licence', function (event) {
      event.preventDefault();
      event.stopPropagation();
      const button = $(this);
      ui.pressEffect(button);
      ajaxFeedback(api.post('extension_cursor_delete_licence', { id: button.data('id') }), 'ลบ licence เรียบร้อย', function () {
        reloadDashboard(selectKey.val() || 0);
      });
    });

    $(document).on('click', '.ec-delete-key', function (event) {
      event.preventDefault(); event.stopPropagation();
      const button = $(this);
      ui.pressEffect(button);
      ajaxFeedback(api.post('extension_cursor_delete_key', { id: button.data('id') }), 'ลบ key เรียบร้อย', function (response) { reloadDashboard(selectKey.val() || 0).always(() => { if (response && response.success) ui.setNotice('ลบ key เรียบร้อย และอัปเดตตารางแล้ว', 'success'); }); });
    });

    $(document).on('click', '.ec-monitor-table tbody tr', function (event) {
      event.preventDefault();
      const row = $(this);
      const keyId = parseInt(row.data('monitor-key'), 10) || 0;
      $('.ec-monitor-table tbody tr').removeClass('is-active');
      row.addClass('is-active');
      ui.pressEffect(row.find('.ec-monitor-key'));
      if (!keyId) {
        ui.setNotice('ไม่พบ key สำหรับแสดงรายละเอียด', 'error');
        return;
      }
      selectKey.val(String(keyId));
      reloadDashboard(keyId);
    });

    $(document).on('click', '.ec-monitor-edit', function (event) {
      event.preventDefault();
      event.stopPropagation();
      const keyId = parseInt($(this).data('id'), 10) || 0;
      if (!keyId) return;
      setActiveTab('monitor');
      const editor = monitorEdit || window.ExtensionCursorMonitorEdit;
      if (editor && typeof editor.open === 'function') {
        editor.open(keyId);
      }
    });

    debugAvailableButton.on('click', function () {
      ui.pressEffect(debugAvailableButton);
      api.post('extension_cursor_debug_available_licences', {}).done((response) => {
        if (response && response.success) {
          const rows = Array.isArray(response.data.rows) ? response.data.rows : [];
          ui.setNotice(rows.length ? `Available licences: ${rows.map((row) => `${row.id}:${row.token}`).join(', ')}` : 'No available licences found', 'success');
        } else {
          ui.setNotice('Unable to load available licences.', 'error');
        }
      }).fail(() => ui.setNotice('Unable to load available licences.', 'error'));
    });

    debugAssignmentButton.on('click', function () {
      ui.pressEffect(debugAssignmentButton);
      api.post('extension_cursor_debug_assignment_state', {}).done((response) => {
        if (!response || !response.success || !response.data) { ui.setNotice('Unable to load assignment debug state.', 'error'); return; }
        const licences = Array.isArray(response.data.licences) ? response.data.licences : [];
        const relations = Array.isArray(response.data.relations) ? response.data.relations : [];
        const keys = Array.isArray(response.data.keys) ? response.data.keys : [];
        const assigned = licences.filter((item) => String(item.status) === 'assigned');
        const activeRelations = relations.filter((item) => String(item.status) === 'active');
        ui.setNotice(`Debug assignment — assigned licences: ${assigned.length}; active relations: ${activeRelations.length}; keys: ${keys.length}. Assigned IDs: ${assigned.map((item) => item.id).join(', ') || '-'} | Active relation licence IDs: ${activeRelations.map((item) => item.licence_id).join(', ') || '-'}`, 'success');
      }).fail(() => ui.setNotice('Unable to load assignment debug state.', 'error'));
    });
  }

  return { bindEvents, reloadDashboard, setActiveTab };
};
