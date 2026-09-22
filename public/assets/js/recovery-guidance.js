/* Safe recovery directions only: never retry writes, alter bills or bypass guards. */
(() => {
  'use strict';
  const fields = Object.freeze({
    phone:'เบอร์โทรศัพท์',full_name:'ชื่อผู้พัก',email:'อีเมล',username:'ชื่อผู้ใช้',password:'รหัสผ่าน',
    room_id:'ห้องพัก',room_code:'เลขห้อง',monthly_rent:'ค่าเช่ารายเดือน',floor:'ชั้น',room_type:'ประเภทห้อง',
    move_in_date:'วันที่เข้าพัก',move_out_date:'วันที่ย้ายออก',reason:'เหตุผล',
    opening_water_reading:'เลขน้ำ ณ วันเข้าพัก',opening_electric_reading:'เลขไฟ ณ วันเข้าพัก',
    water_current:'เลขน้ำล่าสุด',electric_current:'เลขไฟล่าสุด',water_rate:'ราคาน้ำต่อหน่วย',electric_rate:'ราคาไฟต่อหน่วย',
    due_date:'วันครบกำหนด',due_days:'จำนวนวันก่อนครบกำหนด',period:'รอบเดือน',
    promptpay_target:'บัญชี PromptPay',promptpay_name:'ชื่อผู้รับเงิน',slip_provider:'ผู้ให้บริการตรวจสลิป',
    payment_receiver_account_tail:'เลขท้ายบัญชีรับเงิน',slipok_branch_id:'SlipOK Branch ID',slipok_api_key:'SlipOK API key',easyslip_api_key:'EasySlip API key',
  });
  const routes = Object.freeze({
    MOVE_OUT_HAS_PENDING_BILLS:['ยังมีบิลค้าง ตรวจผลการชำระในหน้าบิลก่อนกลับมาย้ายออก ห้ามกดยืนยันว่ารับเงินแล้วโดยไม่มีหลักฐาน','bills'],
    MOVE_OUT_MISSING_BILLS:['ออกบิลของเดือนที่ขาดให้ครบ ตรวจการชำระ แล้วกลับมาย้ายออก','bills'],
    MOVE_OUT_BILL_REQUIRED:['จดมิเตอร์และออกบิลเดือนที่ย้ายออกก่อน เมื่อชำระแล้วจึงกลับมายืนยัน','bills'],
    MOVE_OUT_HAS_LATER_BILLS:['ตรวจเดือนของบิลกับวันย้ายออกจริง ไม่ลบหรือแก้หลักฐานย้อนหลังเพื่อข้ามเงื่อนไข','bills'],
    MOVE_OUT_HAS_LATER_METERS:['ตรวจงวดมิเตอร์กับวันย้ายออกจริงก่อน ห้ามเปลี่ยนวันเพื่อข้ามประวัติ','meters'],
    METER_OPENING_REQUIRED:['เปิดผู้พักห้องนี้และกดเติมเลขเริ่มต้น ใช้เลขน้ำและไฟจริง ณ วันเข้าพัก','residents'],
    METER_HISTORY_GAP:['ไปหน้ามิเตอร์และเติมงวดที่ขาดตามลำดับ ไม่ใช้ศูนย์แทนข้อมูลที่ไม่ทราบ','meters'],
    METER_CHANGED:['ค่าที่กรอกยังอยู่ ตรวจข้อมูลล่าสุดและเปรียบเทียบก่อนบันทึกอีกครั้ง','meters'],
    ROOM_NOT_AVAILABLE:['โหลดรายการห้องล่าสุดแล้วเลือกห้องว่างใหม่ การจองเดิมยังไม่สำเร็จ','rooms'],
    ROOM_OCCUPIED:['ตรวจห้องและผู้พักเดิมก่อน ไม่รับเข้าพักซ้ำในห้องเดียวกัน','residents'],
    ROOM_IN_USE:['ห้องยังมีผู้พักหรือการจอง ตรวจรายการปัจจุบันก่อน ระบบไม่ลบห้องที่ยังใช้งานอยู่','rooms'],
    BOOKING_BAD_STATE:['สถานะการจองเปลี่ยนแล้ว เปิดรายการล่าสุดก่อนเลือกขั้นตอนต่อไป ไม่ยืนยันหรือยกเลิกซ้ำ','bookings'],
    BOOKING_PHONE_ACTIVE:['ตรวจคำขอเดิมของเบอร์นี้ให้เสร็จหรือยกเลิกตามเหตุผลจริงก่อน ห้ามใช้เบอร์ผู้อื่นแทน','bookings'],
    RESIDENT_IDENTITY_IN_USE:['เบอร์นี้เป็นของผู้พักอื่น ตรวจตัวตนและเบอร์ที่ถูกต้องก่อน ห้ามเชื่อมประวัติของคนละคน','residents'],
    BILL_PREVIEW_CHANGED:['ข้อมูลเปลี่ยนหลังตรวจยอด ให้ตรวจตัวอย่างบิลใหม่ก่อนออกบิล ไม่ใช้ยอดจากหน้าที่เปิดค้าง','bills'],
    BILL_PREVIEW_EXPIRED:['ตรวจตัวอย่างยอดล่าสุดอีกครั้งก่อนยืนยันออกบิล','bills'],
    BOOKING_EXPIRED:['ตรวจสถานะการจองล่าสุด การจองที่หมดอายุต้องเริ่มคำขอใหม่','bookings'],
    BILLING_SETTINGS_UNCONFIRMED:['ตรวจราคาน้ำ ไฟ และวันครบกำหนด แล้วบันทึกยืนยันค่าก่อนออกบิล','settings'],
    PROMPTPAY_NOT_CONFIGURED:['ให้เจ้าของตั้งบัญชี PromptPay ก่อนสร้าง QR ไม่ต้องเปิดตรวจสลิปก็สร้าง QR ได้','settings'],
    PROMPTPAY_HAS_RESERVED_BILLS:['บัญชีเดิมยังไม่ถูกเปลี่ยน ไปตรวจบิลที่ล็อกยอดและการรับเงินจริงให้เสร็จก่อนกลับมาเปลี่ยนบัญชี ห้ามล้างยอดหรือบังคับว่าชำระแล้วเพื่อข้ามขั้นตอน','bills'],
    LINE_DELIVERY_RECONCILIATION_REQUIRED:['ตรวจประวัติการส่งใน LINE ก่อน คำขอเดิมอาจถูกส่งแล้ว ให้ผู้พักพิมพ์ บิล เพื่อดูข้อมูลล่าสุด ห้ามเข้าคิวซ้ำโดยยังไม่ทราบผลเดิม','bills'],
    LINE_RETRY_WINDOW_EXPIRED:['พ้นช่วงป้องกันข้อความซ้ำของ LINE ให้ตรวจประวัติการส่งก่อน ผู้พักพิมพ์ บิล เพื่อดูบิลล่าสุดได้ ไม่สร้างคำขอซ้ำอัตโนมัติ','bills'],
    SLIP_NOT_CONFIGURED:['QR ยังใช้ชำระได้ หากโอนแล้วส่งหลักฐานทาง LINE Bot ของหอพัก ไม่ต้องโอนซ้ำ','settings'],
    LINE_NOT_CONFIGURED:['ให้เจ้าของตรวจการเชื่อมต่อ LINE Bot บัญชีเดียวของหอพักแล้วบันทึกค่า','line-oas'],
    LINE_MANAGED_SEPARATELY:['ตั้งค่า LINE ในหน้าบัญชี LINE OA เท่านั้น ใช้บอทเดียวของหอพัก ไม่เพิ่มบัญชีซ้ำ','line-oas'],
    PAYMENT_ALREADY_PENDING:['มีหลักฐานรอตรวจแล้ว เปิดรายการชำระเดิมก่อน ห้ามโอนหรือส่งสลิปใหม่ซ้ำ','payments'],
    PAYMENT_VERIFICATION_IN_PROGRESS:['รอการตรวจครั้งนี้สิ้นสุด แล้วรีเฟรชรายการ ไม่กดตรวจซ้ำหลายครั้ง','payments'],
    TRANSFER_SLOTS_FULL:['ยอดสตางค์ช่วงนี้ถูกจองครบ ให้ผู้ดูแลตรวจรายการเดิม ห้ามสุ่มใหม่ ปัดเศษ หรือใช้ยอดซ้ำ','payments'],
    TRANSFER_TARGET_CHANGED:['บัญชีรับเงินเปลี่ยนหลังจองยอด ให้ผู้ดูแลตรวจคำสั่งโอนเดิมก่อน ไม่ต้องโอนซ้ำ','payments'],
  });
  function explain(error, {role='',page=''}={}) {
    const details=error?.details||{},code=typeof details.code==='string'?details.code:'';
    const admin=page==='admin-console';
    if(code==='ROOM_CODE_EXISTS')return {detail:'เลขห้องนี้ถูกใช้แล้ว ตรวจห้องเดิมหรือแก้เป็นเลขห้องใหม่ที่ไม่ซ้ำ',field:'room_code',action:'ไปแก้เลขห้อง'};
    if(['SELF_DELETE','SELF_OWNER_CHANGE','LAST_OWNER'].includes(code))return {detail:'คงบัญชีเจ้าของที่ใช้งานได้ไว้อย่างน้อยหนึ่งบัญชี ไม่ปิดหรือลดสิทธิ์บัญชีที่กำลังใช้ หากต้องเปลี่ยนผู้ดูแลให้เจ้าของอีกบัญชีดำเนินการ'};
    if(code==='VALIDATION_ERROR'&&Object.hasOwn(fields,details.field))return {detail:`ตรวจช่อง “${fields[details.field]}” ตามรูปแบบที่กำหนด ข้อมูลอื่นที่กรอกยังอยู่`,field:details.field,action:'ไปแก้ช่องนี้'};
    if(code==='MUTATION_OUTCOME_UNKNOWN')return {detail:'ยังไม่ทราบว่าบันทึกสำเร็จหรือไม่ ตรวจรายการล่าสุดก่อนทำซ้ำ เก็บข้อมูลที่กรอกไว้ ระบบจะไม่ส่งคำขอซ้ำให้อัตโนมัติ'};
    if(code==='RATE_LIMITED')return {detail:'รอตามเวลาที่แจ้งก่อนลองใหม่ ไม่ต้องกดซ้ำระหว่างรอ'};
    if(code==='CSRF_INVALID'||code==='UNAUTHENTICATED')return {detail:'จดข้อมูลที่ยังไม่บันทึกไว้ก่อน แล้วรีเฟรชหรือเข้าสู่ระบบใหม่ จากนั้นตรวจรายการเดิมก่อนส่งอีกครั้ง'};
    const route=Object.hasOwn(routes,code)?routes[code]:null;
    if(route){
      const ownerOnly=['settings','line-oas'].includes(route[1]);
      const view=admin&&(!ownerOnly||role==='owner')?route[1]:null;
      return {detail:route[0],view,action:view?'ไปหน้าที่แก้ไขได้':null};
    }
    return {detail:'ข้อมูลที่กรอกยังอยู่ ตรวจช่องที่แจ้งและลองโหลดข้อมูลล่าสุด หากยังแก้ไม่ได้ให้แจ้งผู้ดูแลพร้อมรหัสข้อผิดพลาด ห้ามกดบันทึกซ้ำหากยังไม่ทราบผลครั้งก่อน'};
  }
  window.DormRecoveryGuide=Object.freeze({explain});
})();
