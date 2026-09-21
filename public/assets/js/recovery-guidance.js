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
    BOOKING_EXPIRED:['ตรวจสถานะการจองล่าสุด การจองที่หมดอายุต้องเริ่มคำขอใหม่','bookings'],
    BILLING_SETTINGS_UNCONFIRMED:['ตรวจราคาน้ำ ไฟ และวันครบกำหนด แล้วบันทึกยืนยันค่าก่อนออกบิล','settings'],
    PROMPTPAY_NOT_CONFIGURED:['ให้เจ้าของตั้งบัญชี PromptPay ก่อนสร้าง QR ไม่ต้องเปิดตรวจสลิปก็สร้าง QR ได้','settings'],
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
