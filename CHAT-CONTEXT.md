# Apptook — บริบทโปรเจกต์ (สำหรับแชทใหม่)

**เวลาเปิดแชทใหม่:** ให้ AI อ่านไฟล์นี้ก่อน (`@CHAT-CONTEXT.md`) แล้วค่อยลุยงานต่อ

---

## โปรเจกต์นี้คืออะไร

โฟลเดอร์ **`Apptook`** เป็น workspace เดียวที่รวม **ปลั๊กอิน WordPress หลายตัว** (และโฟลเดอร์อื่นที่เกี่ยวข้อง) สำหรับไซต์ **`apptook`** ใต้ `public_html` บนโฮสต์

### ปลั๊กอินหลัก

| โฟลเดอร์ในเครื่อง | ชื่อปลั๊กอิน | บนเซิร์ฟเวอร์ (ปลายทาง deploy) |
|-------------------|-------------|--------------------------------|
| `apptook-digital-store/` | Apptook Digital Store | `.../wp-content/plugins/apptook-digital-store/` |
| `apptook-member-records/` | Apptook Member Records | `.../wp-content/plugins/apptook-member-records/` |

- **Digital Store:** ร้านดิจิทัล (PromptPay QR, สลิป, แอดมินยืนยัน, คัง key) — PHP 8.1+, WP 6.4+
- **Member Records:** เก็บข้อมูลสมาชิก/ลูกค้าในตารางแยก, แอดมิน, ทำงานคู่กับ Digital Store — PHP 8.1+, WP 6.4+

### อื่นๆ ใน repo

- `stitch_sign_up/` — โฟลเดอร์อ้างอิง UI / โค้ด (ไม่ใช่ปลั๊กอิน WP โดยตรง ถ้าไม่ได้ระบุเป็นอย่างอื่น)

---

## วิธีเปิดโปรเจกต์ใน Cursor

- เปิดโฟลเดอร์ **`Apptook`** เป็น **ราก workspace เดียว** (`File → Open Folder → Apptook`)
- **อย่า** เพิ่มปลั๊กอินเป็นรากแยก (multi-root) ถ้าไม่จำเป็น — จะทำให้ extension SFTP หา config ไม่เจอ (`Config Not Found`)

---

## Deploy ขึ้นเซิร์ฟเวอร์ (SFTP / Natizyskunk)

- ไฟล์ตั้งค่า: **`.vscode/sftp.json`** (ที่ราก `Apptook`)
- เป็น **array หลายโปรไฟล์** — แต่ละปลั๊กอินมี `context` + `remotePath` ของตัวเอง
- **`context`** ชี้ไปที่ `./ชื่อโฟลเดอร์ปลั๊กอิน` เพื่อไม่ให้ชื่อโฟลเดอร์ปลั๊กอินซ้อนซ้ำบนเซิร์ฟเวอร์
- **`remotePath`** ชี้ไปที่โฟลเดอร์ปลั๊กอินจริงบน WordPress

### การอัปเดทโค้ด

- เปิด `uploadOnSave` แล้ว: **แก้ไฟล์ → บันทึก** = อัปโหลดไฟล์นั้น
- ใช้ **`Sync Local → Remote`** แทน **`Upload Folder`** บนโฟลเดอร์ปลั๊กอิน เพื่อลดโอกาสสร้างโฟลเดอร์แม่เกินมา
- คำสั่งจาก Command Palette: `F1` → พิมพ์ `SFTP:`

### ความปลอดภัย

- **อย่า commit** `.vscode/sftp.json` ที่มีรหัสผ่าน — ใส่ใน `.gitignore` หรือใช้วิธีเก็บความลับแยก
- ไฟล์นี้ (`CHAT-CONTEXT.md`) **ไม่** ใส่รหัสผ่านหรือ credential

---

## คำสั่งเริ่มแชทใหม่ (คัดลอกไปวางได้)

```text
อ่าน CHAT-CONTEXT.md ก่อน แล้วช่วย [อธิบายงานที่จะทำ]
workspace รากคือโฟลเดอร์ Apptook มีปลั๊กอิน apptook-digital-store กับ apptook-member-records
```

---

## อัปเดตไฟล์นี้

เมื่อเพิ่มปลั๊กอินใหม่, เปลี่ยน path บนเซิร์ฟเวอร์, หรือเปลี่ยน workflow deploy — ควรแก้ `CHAT-CONTEXT.md` กับ `.vscode/sftp.json` ให้สอดคล้องกัน
