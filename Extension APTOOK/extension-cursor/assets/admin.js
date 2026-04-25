jQuery(function ($) {
  const tabs = $('.ec-tab');
  const panels = $('.ec-panel');
  const notice = $('#ecNotice');
  const refreshButton = $('#ecRefreshList');
  const importButton = $('#ecAddLicenceToPool');
  const clearLicenceForm = $('#ecClearLicenceForm');
  const saveKeyButton = $('#ecSaveKey');
  const generateKeyButton = $('#ecGenerateKey');
  const resetKeyButton = $('#ecResetKey');
  const assignButton = $('#ecAssignSelected');
  const unassignButton = $('#ecUnassignSelected');
  const replaceButton = $('#ecReplaceSelected');
  const licenceList = $('#ecLicenceList');
  const monitorRows = $('#ecMonitorRows');
  const selectKey = $('#ecSelectKey');
  const licenceCode = $('#ecLicenceKey');
  const tokenCapacity = $('#ecTokenCapacity');
  const activeDays = $('#ecActiveDays');
  const importRemark = $('#ecImportRemark');
  const apptookKey = $('#ecApptookKey');
  const note = $('#ecKeyNote');
  const expiry = $('#ecExpiry');
  const debugAvailableButton = $('#ecDebugAvailable');
  const debugAssignmentButton = $('#ecDebugAssignment');

  let noticeTimer = null;
  let loadingKey = false;

  function setNotice(message, type = 'success') {
    notice.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(message).addClass('is-visible');
    window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => notice.removeClass('is-visible'), 12000);
  }

  function setActiveTab(name) {
    tabs.removeClass('is-active');
    panels.removeClass('is-active');
    tabs.filter(`[data-tab="${name}"]`).addClass('is-active');
    panels.filter(`[data-panel="${name}"]`).addClass('is-active');
  }

  function selectedLicenceIds() {
    return licenceList.find('input[type="checkbox"]:checked').map(function () {
      return $(this).val();
    }).get();
  }

  function renderStateFromSnapshot(data, keyId) {
    if (!data) return;
    $('.ec-stat').eq(0).find('.ec-value').text(data.snapshot.keys_ready ?? 0);
    $('.ec-stat').eq(1).find('.ec-value').text(data.snapshot.licences_available ?? 0);
    $('.ec-stat').eq(2).find('.ec-value').text(data.snapshot.mapped_licences ?? 0);
    $('.ec-stat').eq(3).find('.ec-value').text(data.snapshot.needs_review ?? 0);

    const options = Array.isArray(data.keys) ? data.keys : [];
    const current = keyId || selectKey.val();
    selectKey.empty();
    if (!options.length) {
      selectKey.append('<option value="">No key found</option>');
    } else {
      options.forEach((key) => {
        const selected = current && String(current) === String(key.id) ? ' selected' : '';
        selectKey.append(`<option value="${key.id}"${selected}>${escapeHtml(key.key_code)}</option>`);
      });
    }

    const licences = Array.isArray(data.licences) ? data.licences : [];
    licenceList.html(licences.length ? licences.map((licence) => `
      <label class="ec-checkbox-item ec-licence-item">
        <input type="checkbox" value="${licence.id}">
        <span><strong>${escapeHtml(licence.token)}</strong><small>${escapeHtml(String(licence.duration_days))} days • ${escapeHtml(String(licence.token_limit))} tokens • ${escapeHtml(licence.status)}</small></span>
        <button class="ec-btn ec-btn-soft ec-delete-licence" type="button" data-id="${licence.id}">Delete</button>
      </label>
    `).join('') : '<div class="ec-mini-note">ยังไม่มี licence ในฐานข้อมูล กรุณา import ข้อมูลก่อน</div>');

    const monitor = data.monitor || {};
    const monitorRowsData = Array.isArray(data.monitor_rows) ? data.monitor_rows : [];
    monitorRows.html(monitorRowsData.length ? monitorRowsData.map((row) => `
      <tr data-monitor-key="${row.id}">
        <td><button class="ec-btn ec-btn-soft ec-monitor-key" type="button">${escapeHtml(row.key_code)}</button></td>
        <td>${escapeHtml(String(row.licence_count))}</td>
        <td>${escapeHtml(row.expiry_date || '-')}</td>
        <td>${escapeHtml(String(row.licence_count))}</td>
        <td>${escapeHtml(row.status)}</td>
        <td><button class="ec-btn ec-btn-soft ec-delete-key" type="button" data-id="${row.id}">Delete</button></td>
      </tr>
    `).join('') : '<tr><td colspan="6">ยังไม่มี key ในฐานข้อมูล</td></tr>');

    if (monitor.key) {
      $('#ecMonitorKeyTitle').text(monitor.key.key_code || '-');
      $('#ecMonitorKeyDescription').text('สรุปรายละเอียดของ APPTOOK key ที่เลือก รวมถึงวันหมดอายุและ usage ที่เหลือจาก token capacity รวมของ licences ที่ผูกอยู่ครับ');
      $('#ecMonitorExpiry').text(monitor.key.expiry_date || '-');
      $('#ecMonitorUsage').text((monitor.usage ?? 0) + '%');
      $('#ecMonitorNote').text('ในระบบจริง ค่านี้จะอัปเดตจาก usage ที่ส่งมาจาก Extension ของ user และใช้คำนวณ % คงเหลือของ key อีกทีครับ');
      const rows = Array.isArray(monitor.licences) && monitor.licences.length ? monitor.licences.map((licence) => `
        <tr><td>${escapeHtml(licence.token)}</td><td>${escapeHtml(String(licence.duration_days))} days</td><td>${escapeHtml(String(licence.token_limit))}</td><td>${escapeHtml(String(licence.raw_use))}</td><td>${escapeHtml(licence.runtime_expiry || '-')}</td></tr>
      `).join('') : '<tr><td colspan="5">ยังไม่มี licence ที่เชื่อมกับ key นี้</td></tr>';
      $('#ecMonitorLicenceTable').html(rows);
    } else {
      $('#ecMonitorKeyTitle').text('-');
      $('#ecMonitorExpiry').text('-');
      $('#ecMonitorUsage').text('0%');
      $('#ecMonitorLicenceTable').html('<tr><td colspan="5">ยังไม่มี licence ที่เชื่อมกับ key นี้</td></tr>');
    }
  }

  function pressEffect($el) {
    $el.addClass('is-pressed');
    window.setTimeout(() => $el.removeClass('is-pressed'), 160);
  }

  function setLoading($button, isLoading, text) {
    if (!$button || !$button.length) return;
    if (isLoading) {
      $button.data('original-text', $button.text());
      $button.prop('disabled', true).addClass('is-loading').text(text || 'Saving...');
    } else {
      $button.prop('disabled', false).removeClass('is-loading');
      const original = $button.data('original-text');
      if (original) $button.text(original);
    }
  }

  function reloadDashboard(keyId) {
    return $.post(ExtensionCursorAdmin.ajaxUrl, {
      action: 'extension_cursor_dashboard_snapshot',
      nonce: ExtensionCursorAdmin.nonce,
      key_id: keyId || selectKey.val() || 0,
    }).done((response) => {
      if (!response || !response.success || !response.data) return;
      renderStateFromSnapshot(response.data, keyId);
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function post(action, data) {
    return $.post(ExtensionCursorAdmin.ajaxUrl, Object.assign({ action, nonce: ExtensionCursorAdmin.nonce }, data || {}));
  }

  function ajaxFeedback(promise, successMessage, onSuccess, onError) {
    promise.done((response) => {
      if (response && response.success) {
        setNotice(successMessage || (response.data && response.data.message) || 'Saved successfully.');
        if (typeof onSuccess === 'function') onSuccess(response);
      } else {
        const message = (response && response.data && response.data.message) || 'Operation failed.';
        setNotice(message, 'error');
        if (typeof onError === 'function') onError(response);
      }
    }).fail((xhr) => {
      const msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Operation failed.';
      setNotice(msg, 'error');
      if (typeof onError === 'function') onError(xhr);
    });
  }

  tabs.on('click', function () { setActiveTab($(this).data('tab')); });

  clearLicenceForm.on('click', function () {
    pressEffect(clearLicenceForm);
    licenceCode.val('');
    tokenCapacity.val('10');
    activeDays.val('1');
    importRemark.val('');
    setNotice('ฟอร์ม import ถูกล้างแล้ว', 'success');
  });

  importButton.on('click', function () {
    pressEffect(importButton);
    const token = licenceCode.val().trim();
    const token_limit = parseInt(tokenCapacity.val(), 10) || 0;
    const duration_days = parseInt(activeDays.val(), 10) || 1;
    const noteValue = importRemark.val().trim();
    if (!token || token_limit < 1) {
      setNotice('กรุณากรอก Licence Code และ Token limit ให้ครบ', 'error');
      return;
    }
    ajaxFeedback(post('extension_cursor_save_licence', { token, token_limit, duration_days, note: noteValue }), 'บันทึก licence เข้าฐานข้อมูลแล้ว', reloadDashboard);
  });

  saveKeyButton.on('click', function () {
    if (loadingKey) return;
    const $btn = $(this);
    pressEffect($btn);
    const keyId = parseInt(selectKey.val(), 10) || 0;
    const payload = { id: keyId, key_code: apptookKey.val().trim(), note: note.val().trim(), expiry_date: expiry.val() };
    if (!payload.key_code) {
      setNotice('กรุณากรอก APPTOOK Key ก่อนบันทึก', 'error');
      return;
    }
    loadingKey = true;
    setLoading($btn, true, 'Saving...');
    ajaxFeedback(post('extension_cursor_save_key', payload), 'บันทึก APPTOOK key แล้ว', function () {
      reloadDashboard().always(() => {
        loadingKey = false;
        setLoading($btn, false);
      });
    });
  });

  generateKeyButton.on('click', function () {
    pressEffect(generateKeyButton);
    const generated = `APT-${Math.random().toString(36).slice(2, 8).toUpperCase()}`;
    apptookKey.val(generated);
    setNotice('สร้าง key ชั่วคราวให้แล้ว', 'success');
  });

  resetKeyButton.on('click', function () {
    pressEffect(resetKeyButton);
    apptookKey.val('');
    note.val('');
    expiry.val('');
    setNotice('ฟอร์ม key ถูกรีเซ็ตแล้ว', 'success');
  });

  assignButton.on('click', function () {
    pressEffect(assignButton);
    const keyId = parseInt(selectKey.val(), 10) || 0;
    const ids = selectedLicenceIds();
    if (!keyId || !ids.length) {
      setNotice('กรุณาเลือก key และ licence อย่างน้อย 1 รายการ', 'error');
      return;
    }
    ajaxFeedback(post('extension_cursor_assign_licences', { key_id: keyId, licence_ids: ids }), 'assign licence สำเร็จ', function (response) {
      const rows = response && response.data && Array.isArray(response.data.current_relations) ? response.data.current_relations : [];
      reloadDashboard(keyId).always(() => {
        selectKey.val(String(keyId));
        setNotice(`Assign complete. relations now: ${rows.length}.`, 'success');
      });
    }, function (response) {
      setNotice(`Assign failed. ${response && response.data && response.data.received ? JSON.stringify(response.data.received) : ''}`, 'error');
    });
  });

  unassignButton.on('click', function () {
    pressEffect(unassignButton);
    const keyId = parseInt(selectKey.val(), 10) || 0;
    const ids = selectedLicenceIds();
    if (!keyId || !ids.length) {
      setNotice('กรุณาเลือก key และ licence อย่างน้อย 1 รายการ', 'error');
      return;
    }
    ajaxFeedback(post('extension_cursor_unassign_licences', { key_id: keyId, licence_ids: ids }), 'unassign licence สำเร็จ', function () {
      reloadDashboard(keyId).always(() => {
        selectKey.val(String(keyId));
      });
    });
  });

  replaceButton.on('click', function () {
    pressEffect(replaceButton);
    const keyId = parseInt(selectKey.val(), 10) || 0;
    const ids = selectedLicenceIds();
    if (!keyId || !ids.length) {
      setNotice('กรุณาเลือก key และ licence อย่างน้อย 1 รายการ', 'error');
      return;
    }
    ajaxFeedback(post('extension_cursor_replace_licences', { key_id: keyId, licence_ids: ids }), 'replace licence สำเร็จ', function () {
      reloadDashboard(keyId).always(() => {
        selectKey.val(String(keyId));
      });
    });
  });

  refreshButton.on('click', function () {
    pressEffect(refreshButton);
    reloadDashboard();
  });

  $(document).on('click', '.ec-delete-licence', function (event) {
    event.preventDefault();
    event.stopPropagation();
    const button = $(this);
    pressEffect(button);
    ajaxFeedback(post('extension_cursor_delete_licence', { id: button.data('id') }), 'ลบ licence เรียบร้อย', function () {
      reloadDashboard(selectKey.val() || 0);
    });
  });

  $(document).on('click', '.ec-delete-key', function (event) {
    event.preventDefault();
    event.stopPropagation();
    const button = $(this);
    pressEffect(button);
    ajaxFeedback(post('extension_cursor_delete_key', { id: button.data('id') }), 'ลบ key เรียบร้อย', function (response) {
      reloadDashboard(selectKey.val() || 0).always(() => {
        if (response && response.success) {
          setNotice('ลบ key เรียบร้อย และอัปเดตตารางแล้ว', 'success');
        }
      });
    });
  });

  $(document).on('click', '.ec-monitor-table tbody tr, .ec-monitor-key', function () {
    const row = $(this).closest('tr');
    $('.ec-monitor-table tbody tr').removeClass('is-active');
    row.addClass('is-active');
    pressEffect($(this).is('button') ? $(this) : row.find('.ec-monitor-key'));
    const key = row.find('.ec-monitor-key').text().trim();
    $('#ecMonitorKeyTitle').text(key || '-');
    $('#ecMonitorKeyDescription').text('สรุปรายละเอียดของ APPTOOK key ที่เลือก รวมถึงวันหมดอายุและ usage ที่เหลือจาก token capacity รวมของ licences ที่ผูกอยู่ครับ');
    $('#ecMonitorExpiry').text(row.find('td').eq(2).text().trim());
    $('#ecMonitorUsage').text(row.find('td').eq(3).text().trim());
    $('#ecMonitorNote').text('ในระบบจริง ค่านี้จะอัปเดตจาก usage ที่ส่งมาจาก Extension ของ user และใช้คำนวณ % คงเหลือของ key อีกทีครับ');
  });

  debugAvailableButton.on('click', function () {
    pressEffect(debugAvailableButton);
    post('extension_cursor_debug_available_licences', {}).done((response) => {
      if (response && response.success) {
        const rows = Array.isArray(response.data.rows) ? response.data.rows : [];
        setNotice(rows.length ? `Available licences: ${rows.map((row) => `${row.id}:${row.token}`).join(', ')}` : 'No available licences found', 'success');
      } else {
        setNotice('Unable to load available licences.', 'error');
      }
    }).fail(() => setNotice('Unable to load available licences.', 'error'));
  });

  debugAssignmentButton.on('click', function () {
    pressEffect(debugAssignmentButton);
    post('extension_cursor_debug_assignment_state', {}).done((response) => {
      if (!response || !response.success || !response.data) {
        setNotice('Unable to load assignment debug state.', 'error');
        return;
      }
      const licences = Array.isArray(response.data.licences) ? response.data.licences : [];
      const relations = Array.isArray(response.data.relations) ? response.data.relations : [];
      const keys = Array.isArray(response.data.keys) ? response.data.keys : [];
      const assigned = licences.filter((item) => String(item.status) === 'assigned');
      const activeRelations = relations.filter((item) => String(item.status) === 'active');
      setNotice(
        `Debug assignment — assigned licences: ${assigned.length}; active relations: ${activeRelations.length}; keys: ${keys.length}. ` +
        `Assigned IDs: ${assigned.map((item) => item.id).join(', ') || '-'} | Active relation licence IDs: ${activeRelations.map((item) => item.licence_id).join(', ') || '-'}`,
        'success'
      );
    }).fail(() => setNotice('Unable to load assignment debug state.', 'error'));
  });

  setActiveTab('main');
  reloadDashboard();
});
