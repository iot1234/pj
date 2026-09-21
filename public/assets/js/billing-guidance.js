/* คำแนะนำก่อนออกบิล: แสดงสาเหตุและเลือกหน้าที่แก้ไขได้ โดยไม่ทำรายการแทนผู้ใช้ */
(() => {
  'use strict';
  const validPeriod = (value) => typeof value === 'string' && /^\d{4}-(0[1-9]|1[0-2])$/.test(value);
  const safeId = (value) => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null;
  function fields(values) {
    const amount = String(values.other_amount ?? '').trim();
    const description = String(values.other_description ?? '').trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(amount) || !Number.isFinite(Number(amount)) || Number(amount) > 9999999.99)
      return [{ code: 'OTHER_AMOUNT_INVALID', title: 'ตรวจจำนวนเงินอื่นต่อห้อง', detail: 'ใส่จำนวนตั้งแต่ 0 และทศนิยมไม่เกิน 2 ตำแหน่ง ไม่เกิน 9,999,999.99 บาท', target: 'bills', focus: 'other_amount', action: 'ไปแก้จำนวนเงิน' }];
    if (description && Number(amount) === 0)
      return [{ code: 'OTHER_AMOUNT_REQUIRED', title: 'มีชื่อรายการอื่น แต่ยอดเงินเป็น 0', detail: 'กรอกยอดเงิน หรือกด “ไม่คิดรายการอื่น” เพื่อล้างชื่อและยอด ระบบไม่เดาว่าข้อความในช่องชื่อคือจำนวนเงิน', target: 'bills', focus: 'other_amount', action: 'ไปกรอกยอดเงิน', clearExtra: true }];
    if (!description && Number(amount) > 0)
      return [{ code: 'OTHER_DESCRIPTION_REQUIRED', title: 'ยังไม่มีชื่อรายการอื่น', detail: 'ระบุชื่อค่าใช้จ่าย ยอดนี้จะเพิ่มให้แต่ละห้องที่เลือก ไม่ใช่ยอดรวมของทุกห้อง', target: 'bills', focus: 'other_description', action: 'ไประบุชื่อรายการ' }];
    return [];
  }
  function blockers(state) {
    const items = [];
    if (state.settingsState === 'loading' || state.dataState === 'loading') return [{ code: 'BILL_DATA_LOADING', title: 'กำลังตรวจข้อมูลล่าสุด', detail: 'รอค่ารายเดือนและห้องของงวดที่เลือกก่อน ยังไม่มีการออกบิล', target: null }];
    if (state.settingsState !== 'ready') items.push(explain({ code: 'BILL_SETTINGS_UNAVAILABLE' }));
    else if (state.configured !== true) items.push(explain({ code: 'BILLING_SETTINGS_NOT_CONFIRMED' }));
    if (state.dataState !== 'ready') items.push(explain({ code: 'BILL_DATA_UNAVAILABLE' }));
    if (!validPeriod(state.period) || state.period > state.currentPeriod) items.push(explain({ code: 'BILL_PERIOD_INVALID' }));
    else if (state.period === state.currentPeriod && !state.confirmed) items.push(explain({ code: 'CURRENT_BILLING_PERIOD_NOT_FINALIZED' }));
    items.push(...fields(state));
    if (state.dataState === 'ready' && state.selected === 0) items.push(explain({ code: state.candidates === 0 ? 'NO_BILLING_CANDIDATES' : 'NO_ROOMS_SELECTED' }));
    return items;
  }
  // ปลายทางทั้งหมดอยู่ในรายการนี้ ไม่ตีความ URL หรือ selector ที่ผู้ให้บริการส่งมา
  const routes = {
    BILLING_SETTINGS_NOT_CONFIRMED: ['ยังไม่ยืนยันค่าน้ำ ค่าไฟ และระยะชำระ', 'ตรวจราคาจริงแล้วบันทึกที่หน้าตั้งค่าเพียงครั้งเดียว จากนั้นกลับมาตรวจยอดได้ ค่า 0 ใช้ได้เมื่อคุณตั้งใจยืนยันว่าไม่คิดค่าบริการนั้น', 'settings', 'water_rate', 'ไปยืนยันค่ารายเดือน'],
    BILL_SETTINGS_UNAVAILABLE: ['อ่านค่ารายเดือนไม่สำเร็จ', 'ลองโหลดใหม่ ระบบจะไม่ใช้ค่าศูนย์หรือข้อมูลเก่าแทนค่าที่อ่านไม่ได้', 'reload', '', 'ลองโหลดข้อมูลใหม่'],
    BILL_DATA_UNAVAILABLE: ['อ่านข้อมูลห้องหรือบิลไม่สำเร็จ', 'ต้องทราบข้อมูลของงวดนี้ก่อน เพื่อไม่ออกบิลซ้ำหรือเลือกห้องผิดงวด', 'reload', '', 'ลองโหลดข้อมูลใหม่'],
    BILL_PERIOD_INVALID: ['ตรวจรอบเดือนที่จะออกบิล', 'เลือกเดือนไม่เกินเดือนปัจจุบัน ตามช่วงที่ผู้พักเข้าอยู่จริง', 'bills', 'period', 'ไปเลือกรอบเดือน'],
    CURRENT_BILLING_PERIOD_NOT_FINALIZED: ['ยังไม่ได้ยืนยันปิดยอดเดือนปัจจุบัน', 'จดมิเตอร์ให้ครบก่อน แล้วอ่านและเลือกยืนยันปิดยอดเดือนนี้ด้วยตนเอง ระบบไม่ทำเครื่องหมายให้แทน', 'bills', 'confirm_current_period', 'ไปตรวจการปิดยอด'],
    NO_BILLING_CANDIDATES: ['ไม่มีห้องที่มีผู้พักในงวดนี้', 'ตรวจเดือนและวันเข้าพักจริง ห้องที่เพิ่งมีผู้พักภายหลังไม่ควรถูกออกบิลย้อนหลังเป็นเดือนที่ยังไม่ได้เข้าอยู่', 'bills', 'period', 'ไปเลือกรอบเดือน'],
    NO_ROOMS_SELECTED: ['ยังไม่ได้เลือกห้องที่ออกบิลได้', 'เลือกห้องที่ต้องการ ห้องที่มีบิลแล้วจะไม่เปิดให้เลือกซ้ำ', 'bills', 'rooms', 'ไปเลือกห้อง'],
    METER_OPENING_REQUIRED: ['ยังขาดเลขมิเตอร์ ณ วันเข้าพัก', 'เปิดผู้พักรอบนี้ แล้วเติมเลขน้ำและไฟจริง ณ วันส่งมอบห้อง ไม่ใส่ 0 แทนเลขที่ไม่ทราบ', 'residents', 'opening', 'ไปเติมเลขเริ่มต้น'],
    MISSING_METER: ['ยังจดมิเตอร์ของงวดนี้ไม่ครบ', 'ระบบจะเลือกเดือนและห้องให้ จดเลขที่ขาด บันทึก แล้วกลับมาตรวจยอดใหม่', 'meters', '', 'ไปจดมิเตอร์ห้องนี้'],
    METER_HISTORY_GAP: ['ขาดประวัติมิเตอร์งวดก่อน', 'หน้ามิเตอร์จะบอกงวดแรกที่ขาด กดเติมงวดตามลำดับแล้วกลับมา ไม่ใช้เลขของผู้พักคนก่อนและไม่เติมศูนย์เพื่อข้าม', 'meters', '', 'ไปเติมงวดที่ขาด'],
    METER_BASELINE_MISMATCH: ['เลขตั้งต้นไม่ตรงกับประวัติ', 'เปิดประวัติเพื่อตรวจเลขเดิมและเลข ณ วันเข้าพัก ห้ามแก้ทับหลักฐานที่มีบิลแล้ว', 'meters', '', 'ไปตรวจประวัติมิเตอร์'],
    METER_OCCUPANCY_MISMATCH: ['มิเตอร์เป็นของผู้พักคนละรอบ', 'ตรวจห้องและวันเข้า–ออก ห้ามย้ายมิเตอร์ข้ามผู้พักเพื่อให้ออกบิลผ่าน', 'residents', '', 'ไปตรวจข้อมูลผู้พัก'],
    AMBIGUOUS_OCCUPANCY: ['พบผู้พักซ้อนกันในงวดเดียว', 'ตรวจวันเข้า–ออกและรอบที่ต้องคิดเงินจริงก่อน ระบบไม่เลือกผู้พักให้เองและไม่เปลี่ยนวันย้อนหลัง', 'residents', '', 'ไปตรวจข้อมูลผู้พัก'],
    NO_OCCUPANCY: ['ไม่มีผู้พักในงวดที่เลือก', 'ตรวจเดือนและวันเข้าพัก หรือยกเลิกเลือกห้องนี้ ห้ามเปลี่ยนวันเข้าพักเพื่อบังคับออกบิล', 'residents', '', 'ไปดูผู้พักของห้อง'],
    BILL_PREVIEW_CHANGED: ['ข้อมูลเปลี่ยนหลังตรวจยอด', 'ค่ามิเตอร์ ราคา หรือสถานะบิลเปลี่ยนแล้ว ต้องอ่านข้อมูลและคำนวณใหม่ก่อนยืนยัน', 'reload', '', 'โหลดใหม่และตรวจยอด'],
    BILL_PREVIEW_EXPIRED: ['ผลตรวจยอดหมดอายุ', 'ตรวจยอดใหม่จากข้อมูลล่าสุดก่อนยืนยันออกบิล', 'reload', '', 'โหลดใหม่และตรวจยอด'],
    MUTATION_OUTCOME_UNKNOWN: ['ยังไม่ทราบผลการออกบิลครั้งก่อน', 'ตรวจรายชื่อบิลก่อนทำซ้ำ ระบบไม่ส่งคำขอออกบิลซ้ำให้อัตโนมัติ', 'reload', '', 'ตรวจผลรายการเดิม'],
  };
  function explain(issue, context = {}) {
    const code = typeof issue?.code === 'string' ? issue.code : 'UNKNOWN';
    const route = (Object.hasOwn(routes, code) ? routes[code] : null) || ['ยังไม่สามารถทำรายการนี้ได้', 'โหลดข้อมูลล่าสุดและตรวจอีกครั้ง หากยังพบปัญหา ให้แจ้งรหัสข้อผิดพลาดแก่ผู้ดูแล ระบบจะไม่ข้ามการตรวจเพื่อออกบิล', 'reload', '', 'โหลดข้อมูลล่าสุด'];
    return { code, title: route[0], detail: route[1], target: route[2], focus: route[3], action: route[4],
      room_id: safeId(issue?.room_id), occupancy_id: safeId(issue?.occupancy_id),
      room_code: typeof issue?.room_code === 'string' ? issue.room_code.slice(0, 50) : '',
      period: validPeriod(context.period) ? context.period : '',
      required_previous_period: validPeriod(issue?.required_previous_period) ? issue.required_previous_period : '',
    };
  }
  function expand(issues, context) {
    if (!Array.isArray(issues)) return [explain({}, context)];
    const result = [];
    for (const issue of issues.slice(0, 1000)) {
      if (issue?.code === 'METER_CHAIN_INVALID' && Array.isArray(issue.meter_issues) && issue.meter_issues.length) {
        for (const child of issue.meter_issues.slice(0, 2)) result.push(explain({ ...child, room_id: issue.room_id, room_code: issue.room_code, occupancy_id: issue.occupancy_id }, context));
      } else result.push(explain(issue, context));
    }
    return result.filter((item, index) => result.findIndex((other) => other.code === item.code && other.room_id === item.room_id && other.required_previous_period === item.required_previous_period) === index);
  }
  window.DormBillingGuide = Object.freeze({ fields, blockers, explain, expand, validPeriod });
})();
