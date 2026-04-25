<?php if (! defined('ABSPATH')) { exit; } ?>
<section class="ec-card ec-monitor-edit-panel" id="ecMonitorEditPanel" style="display:none; margin-top:24px;">
	<div class="ec-card-head">
		<div>
			<p class="ec-eyebrow">Edit</p>
			<h2>Edit APPTOOK Key Licences</h2>
			<p>ถอด Licence ออกจาก key นี้ หรือเพิ่ม Licence ที่ยังไม่ถูกผูกเข้าไปใหม่ได้จากตารางด้านล่างครับ</p>
		</div>
		<div class="ec-badge">Inline editor</div>
	</div>
	<div class="ec-monitor-edit-summary">
		<div class="ec-mini-note ec-monitor-edit-summary-item">Selected Key: <strong id="ecMonitorEditKeyTitle">-</strong></div>
		<div class="ec-mini-note ec-monitor-edit-summary-item">Key expiry: <strong id="ecMonitorEditExpiry">-</strong></div>
	</div>
	<div class="ec-actions compact" style="margin-bottom:16px;">
		<button class="ec-btn ec-btn-primary" id="ecMonitorEditLoadAvailable" type="button">Load Available Licence(s)</button>
		<button class="ec-btn ec-btn-soft" id="ecMonitorEditClose" type="button">Close</button>
	</div>
	<div class="ec-monitor-edit-lists">
		<div class="ec-field">
			<label>Assigned Licence(s)</label>
			<div class="ec-checkbox-list ec-licence-select-list" id="ecMonitorAssignedList">
				<div class="ec-mini-note">ยังไม่มีข้อมูล กรุณาเลือก key ด้านบนก่อน</div>
			</div>
			<div class="ec-help">กด Unassign เพื่อถอด licence ออกจาก key ที่เลือก</div>
		</div>
		<div class="ec-field">
			<label>Select Licence(s)</label>
			<div class="ec-checkbox-list ec-licence-select-list" id="ecMonitorAvailableList">
				<div class="ec-mini-note">กด Load Available Licence(s) เพื่อแสดงรายการ</div>
			</div>
			<div class="ec-help">จะแสดงเฉพาะ licence ที่ยังไม่มีการผูกเหมือนกับ step 3</div>
		</div>
	</div>
	<div class="ec-actions compact" style="margin-top:16px;">
		<button class="ec-btn ec-btn-primary" id="ecMonitorEditAssign" type="button">Assign Selected</button>
		<button class="ec-btn ec-btn-soft" id="ecMonitorEditUnassign" type="button">Unassign Selected</button>
	</div>
</section>
