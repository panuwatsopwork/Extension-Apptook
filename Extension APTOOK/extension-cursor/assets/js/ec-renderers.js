window.ExtensionCursorModules = window.ExtensionCursorModules || {};
window.ExtensionCursorModules.createEcRenderers = function createEcRenderers($, { escapeHtml }) {
  function renderLicenceList(licences) {
    return licences.length ? licences.map((licence) => `
      <div class="ec-licence-item">
        <label class="ec-checkbox-item">
          <input type="checkbox" value="${licence.id}">
          <span><strong>${escapeHtml(licence.token)}</strong><small>${escapeHtml(String(licence.duration_days))} days • ${escapeHtml(String(licence.token_limit))} tokens • ${escapeHtml(licence.status)}</small></span>
        </label>
        <button class="ec-btn ec-btn-soft ec-delete-licence" type="button" data-id="${licence.id}">Delete</button>
      </div>
    `).join('') : '<div class="ec-mini-note">ยังไม่มี licence ในฐานข้อมูล กรุณา import ข้อมูลก่อน</div>';
  }

  function renderMonitorRows(rows) {
    return rows.length ? rows.map((row) => `
      <tr data-monitor-key="${row.id}">
        <td><button class="ec-btn ec-btn-soft ec-monitor-key" type="button">${escapeHtml(row.key_code)}</button></td>
        <td>${escapeHtml(String(row.licence_count))}</td>
        <td>${escapeHtml(row.expiry_date || '-')}</td>
        <td>-</td>
        <td>${escapeHtml(row.status)}</td>
        <td><button class="ec-btn ec-btn-soft ec-monitor-edit" type="button" data-id="${row.id}">Edit</button><button class="ec-btn ec-btn-soft ec-delete-key" type="button" data-id="${row.id}">Delete</button></td>
      </tr>
    `).join('') : '<tr><td colspan="6">ยังไม่มี key ในฐานข้อมูล</td></tr>';
  }

  function renderMonitorDetail(monitor) {
    if (monitor && monitor.key) {
      $('#ecMonitorKeyTitle').text(monitor.key.key_code || '-');
      $('#ecMonitorKeyDescription').text('สรุปรายละเอียดของ APPTOOK key ที่เลือก รวมถึงวันหมดอายุและ usage ที่เหลือจาก token capacity รวมของ licences ที่ผูกอยู่ครับ');
      $('#ecMonitorExpiry').text(monitor.key.expiry_date || '-');
      $('#ecMonitorUsage').text((monitor.usage ?? 0) + '%');
      $('#ecMonitorNote').text('ในระบบจริง ค่านี้จะอัปเดตจาก usage ที่ส่งมาจาก Extension ของ user และใช้คำนวณ % คงเหลือของ key อีกทีครับ');
      const rows = Array.isArray(monitor.licences) && monitor.licences.length ? monitor.licences.map((licence) => `
        <tr><td>${escapeHtml(licence.token)}</td><td>${escapeHtml(String(licence.duration_days))} days</td><td>${escapeHtml(String(licence.token_limit))}</td><td>${escapeHtml(String(licence.raw_use))}</td><td>${escapeHtml(licence.runtime_expiry || '-')}</td></tr>
      `).join('') : '<tr><td colspan="5">ยังไม่มี licence ที่เชื่อมกับ key นี้</td></tr>';
      $('#ecMonitorLicenceTable').html(rows);
      return;
    }
    $('#ecMonitorKeyTitle').text('-');
    $('#ecMonitorExpiry').text('-');
    $('#ecMonitorUsage').text('0%');
    $('#ecMonitorLicenceTable').html('<tr><td colspan="5">ยังไม่มี licence ที่เชื่อมกับ key นี้</td></tr>');
  }

  return { renderLicenceList, renderMonitorRows, renderMonitorDetail };
}
