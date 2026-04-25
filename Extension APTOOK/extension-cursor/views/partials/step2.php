<?php if (! defined('ABSPATH')) { exit; } ?>
<article class="ec-card">
	<div class="ec-card-head"><div><p class="ec-eyebrow">Step 2</p><h2>สร้าง APPTOOK key</h2><p>สร้าง key ใหม่ให้ผู้ใช้งาน แล้วค่อยกำหนดสถานะและวันหมดอายุก่อนเริ่มผูก licence ครับ</p></div><div class="ec-badge">Primary key</div></div>
	<div class="ec-fields two">
		<div class="ec-field"><label for="ecApptookKey">APPTOOK Key</label><input id="ecApptookKey" class="ec-input" type="text" placeholder="Auto generate or paste key"></div>
		<div class="ec-field"><label for="ecKeyNote">Note</label><input id="ecKeyNote" class="ec-input" type="text" placeholder="Optional note for internal use"></div>
	</div>
	<div class="ec-fields two">
		<div class="ec-field"><label for="ecExpiry">Expiry</label><input id="ecExpiry" class="ec-input" type="date"></div>
		<div class="ec-field"><label>&nbsp;</label><div class="ec-mini-note">Key ใหม่จะถูกสร้างด้วยสถานะ <strong>inactive</strong> เสมอ และ backend จะอัปเดตสถานะเมื่อมีการใช้งานจริง</div></div>
	</div>
	<div class="ec-actions"><button class="ec-btn ec-btn-primary" id="ecSaveKey" type="button">Save Key</button><button class="ec-btn ec-btn-soft" id="ecGenerateKey" type="button">Generate Key</button><button class="ec-btn ec-btn-soft" id="ecResetKey" type="button">Reset</button></div>
</article>
