<?php if (! defined('ABSPATH')) { exit; } ?>
<article class="ec-card">
	<div class="ec-card-head"><div><p class="ec-eyebrow">Step 1</p><h2>เตรียม licence pool</h2><p>เพิ่ม licence ทีละรายการลงในคลังกลางก่อน เพื่อให้พร้อมนำไปผูกกับ key ในขั้นตอนถัดไป</p></div><div class="ec-badge">Quick add</div></div>
	<div class="ec-fields two">
		<div class="ec-field"><label for="ecLicenceKey">Licence Code</label><input id="ecLicenceKey" class="ec-input" type="text" placeholder="LIC-9001"></div>
		<div class="ec-field"><label for="ecTokenCapacity">Token limit</label><input id="ecTokenCapacity" class="ec-input" type="number" min="1" step="1" value="10"></div>
	</div>
	<div class="ec-fields two">
		<div class="ec-field"><label for="ecActiveDays">Duration</label><select id="ecActiveDays" class="ec-input"><option value="1">1 day</option><option value="7">7 days</option><option value="14">14 days</option><option value="30">30 days</option><option value="90">90 days</option><option value="365">365 days</option></select></div>
		<div class="ec-field"><label for="ecImportRemark">Note</label><input id="ecImportRemark" class="ec-input" type="text" placeholder="Optional internal note"><div class="ec-help">เมื่อกด add จะถูกตั้งสถานะเป็น <strong>available</strong> ทันที</div></div>
	</div>
	<div class="ec-actions"><button class="ec-btn ec-btn-primary" id="ecAddLicenceToPool" type="button">Add to Pool</button><button class="ec-btn ec-btn-soft" id="ecClearLicenceForm" type="button">Clear</button><button class="ec-btn ec-btn-soft" id="ecDebugAvailable" type="button">Debug Available</button><button class="ec-btn ec-btn-soft" id="ecDebugAssignment" type="button">Debug Assignment</button></div>
</article>
