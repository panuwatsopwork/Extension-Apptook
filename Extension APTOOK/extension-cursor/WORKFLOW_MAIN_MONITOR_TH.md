# ลำดับขั้นตอนการทำงานของหน้า Main และ Licence Monitor

เอกสารฉบับนี้สรุปลำดับการทำงานของหน้า `Main` และ `Licence Monitor` ของปลั๊กอิน `Extension Cursor` เพื่อใช้เป็นแนวทางอ้างอิงตอนแก้บัคและพัฒนาฟังก์ชันต่อไป

---

## ภาพรวมระบบ

หน้าจอแอดมินของปลั๊กอินแบ่งเป็น 2 ส่วนหลัก

1. `Main`
   - ใช้จัดการ `licence pool`
   - ใช้สร้างและบันทึก `APPTOOK key`
   - ใช้ผูก / ถอน / เปลี่ยน `licence` ที่จะใช้งานกับ key

2. `Licence Monitor`
   - ใช้ดูภาพรวมของ key ทั้งหมด
   - ใช้ดูรายละเอียด key ที่เลือก
   - ใช้ดูรายการ licence ที่ผูกอยู่กับ key นั้น

ข้อมูลทั้งหมดดึงจากฐานข้อมูลจริง ไม่ใช่ mock data

---

## โครงสร้างไฟล์ที่เกี่ยวข้อง

### ฝั่ง PHP
- `includes/class-extension-cursor-admin.php`
  - ควบคุมหน้า admin
  - ลงทะเบียน AJAX handlers
  - render หน้า admin
- `includes/class-extension-cursor-repository.php`
  - query และคำสั่งที่คุยกับฐานข้อมูล
- `includes/class-extension-cursor-service.php`
  - ชั้นบริการกลาง เรียก repository อีกทอด
- `includes/class-extension-cursor-loader.php`
  - helper สำหรับ include view และหา version ของ asset
- `views/admin-page.php`
  - entry ของหน้า admin
- `views/partials/layout.php`
  - layout หลักของหน้า
- `views/partials/header.php`
  - ส่วนหัวของหน้าและแท็บ
- `views/partials/stats.php`
  - การ์ดสรุปสถานะระบบ
- `views/partials/step1.php`
  - ฟอร์มเพิ่ม licence ไปยัง pool
- `views/partials/step2.php`
  - ฟอร์มสร้าง APPTOOK key
- `views/partials/step3.php`
  - ฟอร์มผูก key กับ licence
- `views/partials/monitor.php`
  - wrapper ของหน้า monitor
- `views/partials/monitor-table.php`
  - ตาราง key ทั้งหมด
- `views/partials/monitor-detail.php`
  - รายละเอียด key และ licence ที่ผูกอยู่
- `views/partials/scripts.php`
  - ส่งข้อมูลเริ่มต้นให้หน้า และมี fallback logic บางส่วน

### ฝั่ง JS
- `assets/js/admin.js`
  - bootstrap ของหน้า admin
- `assets/js/ec-api.js`
  - helper สำหรับเรียก AJAX
- `assets/js/ec-ui.js`
  - helper สำหรับ notice, loading state, press effect
- `assets/js/ec-renderers.js`
  - helper สำหรับ render list และ monitor
- `assets/js/ec-state.js`
  - state ของหน้า
- `assets/js/ec-actions.js`
  - ผูก event และควบคุม flow ของหน้า

---

# หน้า Main

หน้า `Main` ประกอบด้วย 3 ขั้นตอนหลัก

## Step 1 เตรียม licence pool

### เป้าหมาย
เพิ่ม licence ใหม่เข้าคลังกลาง เพื่อให้พร้อมนำไปผูกกับ key

### องค์ประกอบบนหน้า
- `Licence Code`
- `Token limit`
- `Duration`
- `Note`
- ปุ่ม
  - `Add to Pool`
  - `Clear`
  - `Debug Available`
  - `Debug Assignment`

### ลำดับการทำงาน
1. ผู้ใช้กรอกข้อมูล licence
2. กด `Add to Pool`
3. ฝั่งหน้าเว็บตรวจความถูกต้องเบื้องต้น
   - ต้องมี `Licence Code`
   - `Token limit` ต้องมากกว่า 0
4. หน้าเว็บส่ง AJAX ไปที่ action `extension_cursor_save_licence`
5. ฝั่ง PHP รับข้อมูล
6. `ajax_save_licence()` เรียก service ให้บันทึก licence ใหม่
7. repository insert ลงตาราง licence
8. status ของ licence ถูกตั้งเป็น `available`
9. response ส่งกลับมาหา frontend
10. หน้าแสดงข้อความสำเร็จและ refresh ข้อมูลใหม่

### ผลลัพธ์ที่คาดหวัง
- licence ใหม่ถูกบันทึกจริงใน DB
- licence ใหม่โผล่ใน `Select Licence(s)` ถ้ายังไม่มีการผูก
- dashboard count เปลี่ยนตามจริง

---

## Step 2 สร้าง APPTOOK key

### เป้าหมาย
สร้างหรืออัปเดต APPTOOK key เพื่อใช้เป็น key หลักในการผูก licence

### องค์ประกอบบนหน้า
- `APPTOOK Key`
- `Note`
- `Expiry`
- ปุ่ม
  - `Save Key`
  - `Generate Key`
  - `Reset`

### ลำดับการทำงาน
1. ผู้ใช้กด `Generate Key` หากต้องการ key ชั่วคราว
2. ช่อง `APPTOOK Key` ถูกเติมค่ารูปแบบ `APT-XXXXXX`
3. ผู้ใช้กรอก note และ expiry เพิ่มเติมถ้าต้องการ
4. กด `Save Key`
5. หน้าเว็บตรวจว่า `APPTOOK Key` ไม่ว่าง
6. หน้าเว็บส่ง AJAX ไปที่ action `extension_cursor_save_key`
7. ฝั่ง PHP รับข้อมูล
8. ถ้า `id` เป็น 0 หรือไม่มี key เดิม จะ insert key ใหม่
9. ถ้ามี `id` อยู่แล้ว จะ update key เดิม
10. status key ถูกตั้งเป็น `inactive` โดยค่าเริ่มต้น
11. response ส่งกลับไป frontend
12. หน้าแจ้งสำเร็จและ refresh ข้อมูล

### ผลลัพธ์ที่คาดหวัง
- key ถูกบันทึกจริงใน DB
- key ใหม่โผล่ใน `Select APPTOOK Key` ถ้ายังไม่มี licence active ผูกอยู่
- monitor table อัปเดตตามข้อมูลใหม่

---

## Step 3 ผูก key กับ licence

### เป้าหมาย
เลือก key หนึ่งตัว แล้วผูก licence ได้หลายรายการพร้อมกัน

### องค์ประกอบบนหน้า
- `Select APPTOOK Key`
- `Search / Filter Licence`
- `Select Licence(s)`
- ปุ่ม
  - `Assign Selected`
  - `Refresh List`
  - `Unassign Selected`
  - `Replace Selected`

### ลำดับการทำงาน
1. ผู้ใช้เลือก `APPTOOK Key`
2. ผู้ใช้เลือก licence ได้หลายรายการจาก `Select Licence(s)`
3. กด `Assign Selected`
4. หน้าเว็บตรวจว่า
   - มี key ที่เลือก
   - มี licence อย่างน้อย 1 รายการ
5. ส่ง AJAX ไปที่ action `extension_cursor_assign_licences`
6. ฝั่ง PHP รับ `key_id` และ `licence_ids`
7. repository ตรวจว่ามี relation เดิมหรือไม่
8. ถ้ามี relation เดิม
   - ปรับ status ของ relation เป็น `active`
   - ปรับลำดับ sort order ใหม่
9. ถ้าไม่มี relation เดิม
   - insert relation ใหม่ลงตาราง `key_licences`
10. จากนั้น update status ของ licence เป็น `assigned`
11. response ส่งกลับ frontend พร้อม snapshot ใหม่
12. หน้า reload รายการ licence และ monitor

### ผลลัพธ์ที่คาดหวัง
- licence ที่ถูก assign จะไม่ควรแสดงใน `Select Licence(s)` อีก
- monitor ของ key นั้นควรเห็น licence ที่เพิ่งผูก
- dashboard count ของ mapped licences ควรเพิ่มขึ้น

---

# หน้า Licence Monitor

หน้า `Licence Monitor` ใช้สำหรับดูภาพรวม key และรายละเอียดการผูก licence

## ส่วนที่ 1 ตาราง key ทั้งหมด

### เป้าหมาย
แสดงรายการ key ทั้งหมดในระบบ พร้อมจำนวน licence ที่ผูกอยู่

### องค์ประกอบบนหน้า
- ตาราง `APPTOOK Key`
- `Licence Count`
- `Key Expiry`
- `Key Usage %`
- `Status`
- ปุ่ม `Delete`

### ลำดับการทำงาน
1. หน้า monitor โหลดขึ้นมา
2. เรียก `get_monitor_rows()`
3. ดึง key ทั้งหมดพร้อมจำนวน relation active
4. render เป็นตาราง
5. ผู้ใช้สามารถคลิก key ในตารางเพื่อดูรายละเอียดด้านล่าง

### ผลลัพธ์ที่คาดหวัง
- เห็น key ทุกตัวที่มีอยู่ในระบบ
- รู้จำนวน licence ที่ผูกอยู่กับแต่ละ key
- เลือก key เพื่อดูรายละเอียดได้

---

## ส่วนที่ 2 รายละเอียด key ที่เลือก

### เป้าหมาย
แสดงรายละเอียดเชิงลึกของ key ที่ถูกเลือก

### องค์ประกอบบนหน้า
- `APPTOOK Key Detail`
- `Key expiry`
- `Remaining %`
- `Licence Details`
- ตารางรายการ licence ที่ผูกอยู่

### ลำดับการทำงาน
1. เมื่อผู้ใช้คลิก key จากตาราง monitor
2. หน้าเว็บส่งผลให้ row นั้น active
3. ฝั่ง JS อัปเดต title, expiry, usage และ note
4. ตาราง licence ที่ผูกอยู่ถูก render ใหม่
5. ถ้ามี licence ผูกอยู่ จะเห็นรายละเอียดแต่ละตัว
6. ถ้าไม่มี licence จะเห็นข้อความว่าไม่มีรายการที่เชื่อมกับ key นี้

### ผลลัพธ์ที่คาดหวัง
- ใช้ตรวจสอบว่ามี licence ผูกจริงหรือไม่
- ใช้ตรวจ status ของ key และ usage summary
- ใช้ debug ว่า assignment ทำงานจริงไหม

---

# Data Flow ที่สำคัญ

## 1) จากหน้าไป backend
- user กดปุ่ม
- JS ส่ง AJAX
- PHP AJAX handler รับ request
- service เรียก repository
- repository คุยกับ database
- response ส่งกลับ frontend

## 2) จาก backend กลับมาหน้า
- frontend รับ response
- render list / table ใหม่
- update notice
- refresh state

---

# Status Semantics ที่ใช้ในระบบ

## `licences.status`
- `available` = licence พร้อมให้เลือกใช้งาน
- `assigned` = licence ถูกผูกกับ key แล้ว
- `expired` = หมดอายุ
- `revoked` = ยกเลิก

## `keys.status`
- `inactive` = key สร้างแล้วแต่ยังไม่ถูกใช้งานเต็มรูปแบบ
- `active` = key ใช้งานอยู่
- `revoked` = key ถูกยกเลิก

## `key_licences.status`
- `active` = relation ระหว่าง key กับ licence ที่ใช้งานอยู่
- `inactive` = relation ที่เคยใช้งาน แต่ถูกถอดออกแล้ว

---

# จุดที่ควรระวังเวลาแก้บัค

## 1) Query กับ UI ต้องสอดคล้องกัน
ถ้า query กรอง status อย่างหนึ่ง แต่ UI คาดอีกแบบ จะทำให้ list หายหรือโผล่ผิด

## 2) Reload หลัง AJAX สำคัญมาก
หลาย flow ต้อง refresh หน้า หรือ reload dashboard เพื่อให้ข้อมูลล่าสุดมาแทน cache ใน DOM

## 3) ปุ่มลบต้องแยกจาก label / checkbox
ถ้าปุ่ม delete อยู่ซ้อนใน label อาจทำให้ click event ชนกับ checkbox ได้

## 4) Status ของ relation ต้องตรงกับ logic จริง
ถ้า relation ยังเป็น `active` อยู่ แต่ licence ถูก set เป็น `available` หรือกลับกัน จะทำให้ list เพี้ยน

---

# งานต่อที่แนะนำ

1. ทำให้ `scripts.php` เหลือแค่ data payload และลด fallback logic
2. ย้าย handler ที่ยังอยู่ใน `scripts.php` ไป module จริง
3. แยก `monitor-detail.php` ถ้าต้องการให้เล็กลงอีก
4. ทำ debug panel แสดงเหตุผลว่าทำไม key หรือ licence บางรายการไม่ถูกแสดง
5. เพิ่ม unit of behavior ชัดเจนสำหรับ assign / unassign / delete

---

# สรุปสั้น

### Main
ใช้สำหรับเตรียม licence pool, สร้าง key และผูก key กับ licence

### Licence Monitor
ใช้สำหรับดูภาพรวม key และรายละเอียด licence ที่ผูกอยู่

### หลักการสำคัญ
- key และ licence ต้องสอดคล้องกับ status semantics
- หลังทำ action สำคัญต้อง reload data ใหม่จาก backend
- ถ้า query กรองผิด จะทำให้ UI ดูเหมือนข้อมูลหาย

---

หากต้องการ ผมสามารถทำไฟล์สรุปอีกฉบับเป็นแบบ “flowchart เชิงข้อความ” หรือ “รายการ API action และผลลัพธ์” ให้ต่อได้ครับ
