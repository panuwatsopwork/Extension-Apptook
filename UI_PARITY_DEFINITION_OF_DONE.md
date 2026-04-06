# UI Parity Definition of Done (DoD)

เอกสารนี้เป็นเกณฑ์บังคับสำหรับงานพัฒนา UI ที่ต้องอิง Design จากไฟล์ `stitch_sign_up/code.html` เพื่อให้ผลลัพธ์สม่ำเสมอและลด regression ในระบบจริง

---

## 1) Scope และ Source of Truth

- [ ] ต้องระบุ scope หน้าที่แก้ให้ชัดเจน (เช่น `MySubscription`, `Order History`, `Product Detail`)
- [ ] Design source of truth คือ `stitch_sign_up/code.html`
- [ ] ห้ามเดา design จากความจำ หากไม่ชัดต้องระบุ assumption ก่อนแก้
- [ ] ต้องระบุส่วนที่ห้ามแก้: business logic, auth flow, purchase flow, endpoint เดิม

**ผ่านเมื่อ:** reviewer เข้าใจชัดว่าแก้อะไร/ไม่แก้อะไร และอ้างอิง design เดียวกัน

---

## 2) Mapping Design → Implementation (บังคับก่อนแก้)

ก่อนลงมือแก้โค้ด ต้องส่ง mapping table:

- Design section -> ไฟล์/ฟังก์ชัน/คอมโพเนนต์จริง
- ระบุสิ่งที่ reuse จากระบบเดิม
- ระบุสิ่งที่จะเพิ่มใหม่
- ระบุ data source ของแต่ละ section

**ผ่านเมื่อ:** มี mapping ครบและอนุมัติก่อนเริ่มแก้

---

## 3) Global Layout Rules (บังคับทั้งระบบ)

### 3.1 Container Width (กฎบังคับ)
- [ ] ทุกหน้าที่เป็น content page ต้องใช้ `max-width: 1280px` เสมอ
- [ ] ต้องมี `margin: 0 auto`
- [ ] ใช้ container กลางร่วมกัน ห้าม hardcode ซ้ำหลายจุดโดยไม่จำเป็น

### 3.2 Gutter (อิง Marketplace baseline)
- [ ] ใช้ `padding-inline: 1.5rem` (24px) เป็น baseline
- [ ] ห้ามให้ content หลักชนขอบ viewport โดยไม่ตั้งใจ
- [ ] ถ้าจะใช้ค่าอื่น ต้องมีเหตุผลจาก design reference ชัดเจน

### 3.3 Full-bleed Exception
- [ ] อนุญาตเฉพาะ section ที่ตั้งใจ (hero/banner)
- [ ] แม้ full-bleed เนื้อหาที่อ่าน/กดได้ต้องอยู่ใน container 1280px

**ผ่านเมื่อ:** ทุกหน้าที่แก้อยู่ในระบบ container เดียวกัน และ spacing สอดคล้องกัน

---

## 4) Button System Rules (กฎบังคับ)

- [ ] ปุ่มหลัก/รองทั้งหมดต้องใช้ `border-radius: 2rem` เสมอ
- [ ] ต้องมี states ครบ: default / hover / focus-visible / active / disabled
- [ ] Focus state ต้องมองเห็นได้ชัด (ห้ามถอดออกโดยไม่มีตัวแทน)
- [ ] ห้ามสร้างปุ่มนอก design family โดยไม่จำเป็น

**ผ่านเมื่อ:** ปุ่มทุกหน้าที่แก้มี shape/interaction consistency เดียวกัน

---

## 5) Shared Components & Reuse

- [ ] Header / Footer / Shared shell ต้อง reuse ของเดิม
- [ ] Modal / Dropdown / Navigation ต้อง reuse flow เดิม
- [ ] ห้าม copy UI แยกโลกจน behavior แตกต่างจากระบบ

**ผ่านเมื่อ:** ไม่มีการ fork behavior โดยไม่จำเป็น

---

## 6) Data Contract Rules

- [ ] ห้าม hardcode ข้อมูลธุรกิจ (สินค้า, subscription, order)
- [ ] ต้องดึงจาก source จริงของระบบ (WP Query / post meta / endpoint เดิม)
- [ ] ต้องมี fallback ที่ชัดเจนเมื่อข้อมูลไม่ครบ
- [ ] ต้องรองรับ guest user อย่างเหมาะสม

**ผ่านเมื่อ:** data บน UI สอดคล้องกับข้อมูลจริงของระบบ

---

## 7) State Completeness

ทุกหน้าที่แก้ต้องมี state อย่างน้อย:

- [ ] loading state
- [ ] empty state
- [ ] error state
- [ ] guest state (ถ้าเกี่ยวข้องกับ auth)
- [ ] domain status state (เช่น active/inactive/cancelled หรือ paid/pending/rejected)

**ผ่านเมื่อ:** ไม่มี state สำคัญที่หายไป

---

## 8) Responsive & Accessibility Baseline

- [ ] รองรับ desktop + mobile
- [ ] ไม่มี horizontal overflow โดยไม่ตั้งใจ
- [ ] heading hierarchy ถูกต้อง (`h1` -> `h2` -> `h3`)
- [ ] ปุ่ม/ลิงก์มี label ชัดเจน
- [ ] keyboard focus มองเห็นได้

**ผ่านเมื่อ:** ใช้งานได้จริงทั้งจอใหญ่และจอเล็ก พร้อม a11y ขั้นพื้นฐาน

---

## 9) Non-Regression Rules

ก่อนส่งงานต้องเช็ค:

- [ ] หน้า Marketplace ปกติ
- [ ] หน้า Product Detail ปกติ
- [ ] หน้า Library / Subscription / Order History (ที่เกี่ยวข้อง) ปกติ
- [ ] ไม่มี console error ใหม่
- [ ] ไม่มี linter error ใหม่จากไฟล์ที่แก้
- [ ] ไม่แตะ business logic นอก scope (ถ้าจำเป็นต้องแตะ ต้องอธิบายเหตุผล)

**ผ่านเมื่อ:** ไม่มี regression สำคัญกับ flow เดิม

---

## 10) Performance Guardrail

- [ ] หลีกเลี่ยงเพิ่ม JS/CSS โดยไม่จำเป็น
- [ ] ลดโอกาส layout shift
- [ ] interaction หลักต้องตอบสนองดี
- [ ] เลือกวิธีที่กระทบ performance ต่ำกว่าเมื่อมี trade-off

---

## 11) สิ่งที่ต้องรายงานหลังแก้ (บังคับ)

1. รายการไฟล์ที่แก้
2. Mapping design -> implementation
3. สรุปโครงสร้าง HTML ที่เปลี่ยน
4. สรุป CSS strategy (reuse/add)
5. Data flow + fallback
6. ผลทดสอบ (desktop/mobile + logged/guest + empty/has data)
7. ความต่างจาก design ที่ยังเหลือ + เหตุผล
8. Before/After screenshots

---

## 12) Priority Rule เมื่อต้อง trade-off

ลำดับความสำคัญ:

1. ความถูกต้องของข้อมูลและ flow จริง
2. ไม่ทำให้หน้าเดิมพัง (non-regression)
3. UI parity กับ design

> Functional correctness มาก่อน แล้วค่อย pixel parity

---

## 13) Prompt Template (ใช้สั่งงาน AI ได้ทันที)

```text
ให้อัปเดต UI หน้า [ระบุหน้า] โดยอิง design จาก stitch_sign_up/code.html

ข้อบังคับ:
- ต้องส่ง Mapping Design -> Implementation ก่อนแก้
- ต้อง reuse shared layout เดิมของระบบ
- max-width ต้อง 1280px เสมอสำหรับ content page
- button ต้อง border-radius 2rem เสมอ
- ห้าม hardcode business data
- ต้องมี loading/empty/error/guest + status state ที่เกี่ยวข้อง
- ห้ามแก้ business logic นอก scope

หลังแก้ต้องรายงาน:
- ไฟล์ที่แก้
- HTML/CSS strategy
- data flow/fallback
- test result matrix
- remaining design gaps
- before/after screenshots
```
