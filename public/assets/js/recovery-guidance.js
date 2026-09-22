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
    METER_ALREADY_BILLED:['งวดนี้ใช้ในบิลแล้ว ตรวจบิลและเลขจริงกับผู้ดูแล ไม่แก้ทับมิเตอร์ที่ออกบิลแล้ว','bills'],
    METER_HISTORY_LOCKED:['มีงวดถัดไปอ้างอิงเลขนี้แล้ว ตรวจประวัติตามลำดับกับผู้ดูแล ไม่ลบงวดหรือเปลี่ยนเลขเพื่อข้ามเงื่อนไข','meters'],
    METER_ROLLBACK:['ตรวจเลขจริงกับเลขก่อนหน้า เลขล่าสุดต้องไม่น้อยกว่าเดิม หากเปลี่ยนมิเตอร์ให้ผู้ดูแลตรวจประวัติก่อน ไม่เดาตัวเลข','meters'],
    METER_USAGE_ANOMALY:['ตรวจเลขน้ำ/ไฟและจุดทศนิยมกับมิเตอร์จริงอีกครั้ง ไม่ยืนยันตัวเลขที่ยังไม่ตรวจสอบ','meters'],
    RESIDENT_REUSE_CONFIRMATION_REQUIRED:['ตรวจชื่อและเบอร์กับผู้พักตัวจริง ถ้าเป็นคนเดิมจึงยืนยันใช้ประวัติเดิมในฟอร์ม หากเป็นคนละคนให้ใช้เบอร์ที่ถูกต้อง','residents'],
    OCCUPANCY_CONFLICT:['ตรวจวันเข้า–ออกและประวัติผู้พักของห้องนี้ ไม่เปลี่ยนวันจริงเพื่อให้ระบบยอมรับ','residents'],
    RESIDENT_CHANGED:['เก็บร่างที่แก้ไว้ก่อน แล้วอ่านข้อมูลผู้พักล่าสุดและเปรียบเทียบก่อนบันทึกใหม่','residents'],
    BILL_ALREADY_PAID:['เปิดบิลและหลักฐานที่ชำระแล้ว ไม่โอนหรือแนบสลิปซ้ำ หากข้อมูลไม่ตรงให้ติดต่อผู้ดูแลพร้อมเลขบิล','payments'],
    PAYMENT_CHANGED:['สถานะรายการชำระเปลี่ยนแล้ว โหลดรายการเดิมและตรวจผลก่อน ไม่โอนหรือส่งหลักฐานซ้ำ','payments'],
    DUPLICATE_SLIP:['สลิปนี้ถูกใช้แล้ว ตรวจรายการชำระเดิมและเลขบิล หากโอนแล้วไม่ต้องโอนซ้ำ ให้ผู้ดูแลตรวจรายการที่เกี่ยวข้อง','payments'],
    PAYMENT_RETRY_LIMIT:['ถึงขีดจำกัดการตรวจซ้ำแล้ว ให้ผู้ดูแลตรวจผลและหลักฐานเดิม ไม่สร้างรายการใหม่เพื่อข้ามขีดจำกัด','payments'],
    SLIP_TYPE_INVALID:['เลือกภาพสลิปต้นฉบับ JPEG, PNG หรือ WebP ที่อ่านได้ชัดเจน หากแนบไม่ได้ส่งสลิปเข้า LINE Bot ของหอพักพร้อมเลขห้องและเลขบิล ไม่ต้องโอนซ้ำ',null],
    SLIP_TOO_LARGE:['ขนาดภาพเกินที่ระบบรองรับ ลดขนาดไฟล์โดยยังอ่านยอดและ QR บนสลิปได้ชัด แล้วเลือกไฟล์ใหม่ หรือส่งสลิปเข้า LINE Bot ของหอพัก ไม่ต้องโอนซ้ำ',null],
    SLIP_UPLOAD_ERROR:['ยังส่งหรืออ่านไฟล์สลิปไม่สำเร็จ ตรวจสถานะรายการเดิมก่อนลองใหม่ หากแนบไม่ได้ส่งสลิปเข้า LINE Bot ของหอพักพร้อมเลขห้องและเลขบิล ไม่ต้องโอนซ้ำ',null],
    LINE_BINDING_BLOCKED:['ติดต่อผู้ดูแลให้ตรวจเหตุผลการระงับและปลดระงับเมื่อถูกต้อง จากนั้นโหลดสถานะใหม่ ไม่สร้างบัญชีอื่นเพื่อข้ามการระงับ','line-bindings'],
    LINE_ALREADY_LINKED:['ตรวจบัญชีที่ผูกไว้ ใช้บัญชีเดิมได้ หากเปลี่ยนบัญชีให้ตรวจตัวตนและยกเลิกการผูกเดิมก่อน','line-bindings'],
    LINE_ID_IN_USE:['บัญชี LINE นี้ผูกกับผู้พักอื่นแล้ว ให้ผู้ดูแลตรวจตัวตนและการผูกเดิม ห้ามเชื่อมประวัติของคนละคน','line-bindings'],
    LINE_LINK_CODE_EXPIRED:['รหัสหมดอายุหรือใช้ไม่ได้แล้ว กลับไปสร้างรหัสใหม่และส่งรหัสล่าสุดให้บอทของหอพักด้วย LINE ของตนเอง','line-bindings'],
    LINE_NOT_LINKED:['สร้างรหัสผูกให้ผู้พัก แล้วให้ผู้พักส่งรหัสใน LINE Bot ของหอพักด้วยบัญชีของตน เมื่อยืนยันสำเร็จให้โหลดบิลใหม่','line-bindings'],
    LINE_SINGLE_BOT_ONLY:['หอพักใช้ LINE Bot เดียว แก้ไขบัญชีเดิมในหน้าบัญชี LINE OA ไม่ต้องสร้างบัญชีเพิ่ม','line-oas'],
    LINE_TOKEN_REJECTED:['ให้เจ้าของตรวจ Channel access token และ Channel secret ของบอทเดียวกัน บันทึกให้สำเร็จแล้วกดทดสอบอีกครั้ง ไม่ส่ง Token ให้ผู้อื่น','line-oas'],
    LINE_WEBHOOK_NOT_SET:['ให้เจ้าของตรวจ URL Webhook ของบอทเดิม เปิดใช้งานและ Verify ใน LINE Developers แล้วกลับมาตรวจสถานะ ไม่หมุน URL โดยไม่จำเป็น','line-oas'],
    LINE_API_UNAVAILABLE:['ยังติดต่อ LINE ไม่สำเร็จ ตรวจสถานะการเชื่อมต่อและเครือข่ายของเซิร์ฟเวอร์ หากเป็นการส่งบิลให้ตรวจประวัติเดิมก่อนเข้าคิวใหม่','line-oas'],
    LINE_CONFIGURATION_CHANGED:['การตั้งค่า LINE เปลี่ยนระหว่างทำรายการ เปิดข้อมูลล่าสุด ตรวจว่าเป็นบอทและผู้พักที่ต้องการก่อนทำต่อ','line-oas'],
    LINE_QR_UNAVAILABLE:['ระบบไม่อนุญาตใช้ QR เดิมแล้ว ให้เปิดบิลล่าสุดหรือพิมพ์ “บิล” ใน LINE เพื่อตรวจสถานะ QR เดิมอาจหมดอายุหรือข้อมูลชำระ/การผูกเปลี่ยน หากโอนแล้วไม่ต้องโอนซ้ำ','bills'],
    BILL_PREVIEW_INVALID:['อ่านปัญหารายห้องในผลตรวจ แก้ตามจุดที่ระบุและตรวจยอดใหม่ก่อนออกบิล ห้ามข้ามห้องที่มีข้อมูลผิดโดยไม่ตรวจสอบ','bills'],
    CURRENT_BILLING_PERIOD_NOT_FINALIZED:['จดมิเตอร์ให้ครบ ตรวจรอบเดือน และอ่านคำยืนยันปิดยอดเดือนนี้ก่อนเลือกยืนยันด้วยตนเอง','bills'],
  });
  const aliases=Object.freeze({
    BILLING_SETTINGS_NOT_CONFIRMED:'BILLING_SETTINGS_UNCONFIRMED', BILL_PREVIEW_TOKEN_INVALID:'BILL_PREVIEW_EXPIRED',
    MISSING_METER:'METER_HISTORY_GAP', METER_CHAIN_INVALID:'METER_HISTORY_GAP', METER_OPENING_LOCKED:'METER_HISTORY_LOCKED', METER_OPENING_HISTORY_CONFLICT:'METER_HISTORY_LOCKED',
    METER_BASELINE_MISMATCH:'METER_HISTORY_LOCKED', METER_OCCUPANCY_MISMATCH:'OCCUPANCY_CONFLICT', AMBIGUOUS_OCCUPANCY:'OCCUPANCY_CONFLICT',
    MOVE_IN_PERIOD_CONFLICT:'OCCUPANCY_CONFLICT', MOVE_IN_METER_PERIOD_CONFLICT:'OCCUPANCY_CONFLICT', RESIDENT_MOVE_IN_PERIOD_CONFLICT:'OCCUPANCY_CONFLICT',
    RESIDENT_ALREADY_OCCUPIED:'ROOM_OCCUPIED', RESIDENT_IDENTITY_CONFLICT:'RESIDENT_IDENTITY_IN_USE', OCCUPANCY_CHANGED:'RESIDENT_CHANGED',
    BILL_PAYMENT_PENDING:'PAYMENT_ALREADY_PENDING', BILL_PAYMENT_VERIFIED:'BILL_ALREADY_PAID', BILL_NOT_PENDING:'PAYMENT_CHANGED', PAYMENT_NOT_PENDING:'PAYMENT_CHANGED',
    DUPLICATE_PAYMENT:'DUPLICATE_SLIP', BILL_CHANGED:'PAYMENT_CHANGED', TRANSFER_BILL_CHANGED:'PAYMENT_CHANGED',
    SLIP_FILE_INVALID:'SLIP_TYPE_INVALID', SLIP_IMAGE_INVALID:'SLIP_TYPE_INVALID', SLIP_IMAGE_MEMORY_LIMIT:'SLIP_TOO_LARGE',
    SLIP_FILE_MISSING:'SLIP_UPLOAD_ERROR', SLIP_FILE_UNREADABLE:'SLIP_UPLOAD_ERROR', SLIP_FILE_INTEGRITY_FAILED:'SLIP_UPLOAD_ERROR', SLIP_STORAGE_PERMISSION_FAILED:'SLIP_UPLOAD_ERROR',
    LINE_LINK_CODE_INVALID:'LINE_LINK_CODE_EXPIRED', LINE_ADMIN_CODE_EXPIRED:'LINE_LINK_CODE_EXPIRED', LINE_ADMIN_CODE_INVALID:'LINE_LINK_CODE_EXPIRED', LINE_ADMIN_CODE_USED:'LINE_LINK_CODE_EXPIRED',
    LINE_NOT_VERIFIED:'LINE_NOT_LINKED', LINE_BINDING_STALE:'LINE_NOT_LINKED', LINE_BINDING_WRONG_OA:'LINE_CONFIGURATION_CHANGED',
    LINE_OA_NOT_AVAILABLE:'LINE_NOT_CONFIGURED', LINE_OA_DISABLED:'LINE_NOT_CONFIGURED', LINE_DEFAULT_NOT_CONFIGURED:'LINE_NOT_CONFIGURED',
    LINE_OA_DUPLICATE:'LINE_SINGLE_BOT_ONLY', LINE_OA_IDENTITY_MISMATCH:'LINE_TOKEN_REJECTED',
    LINE_CONNECT_FAILED:'LINE_API_UNAVAILABLE', LINE_CONNECT_TIMEOUT:'LINE_API_UNAVAILABLE', LINE_DNS_ERROR:'LINE_API_UNAVAILABLE', LINE_TLS_ERROR:'LINE_API_UNAVAILABLE', LINE_CURL_MISSING:'LINE_API_UNAVAILABLE', LINE_RESPONSE_INVALID:'LINE_API_UNAVAILABLE', LINE_TEST_FAILED:'LINE_API_UNAVAILABLE',
    LINE_WEBHOOK_ERROR:'LINE_WEBHOOK_NOT_SET', LINE_DELIVERY_IDENTITY_CHANGED:'LINE_CONFIGURATION_CHANGED',
  });
  function explain(error, {role='',page=''}={}) {
    const details=error?.details||{},code=typeof details.code==='string'?details.code:'';
    const admin=page==='admin-console';
    if(code==='ROOM_CODE_EXISTS')return {detail:'เลขห้องนี้ถูกใช้แล้ว ตรวจห้องเดิมหรือแก้เป็นเลขห้องใหม่ที่ไม่ซ้ำ',field:'room_code',action:'ไปแก้เลขห้อง'};
    if(code==='USERNAME_EXISTS')return {detail:'ชื่อผู้ใช้นี้มีอยู่แล้ว ตรวจบัญชีเดิมหรือเลือกชื่อใหม่ที่ไม่ซ้ำ',field:'username',action:'ไปแก้ชื่อผู้ใช้'};
    if(code==='INVALID_CREDENTIALS')return {detail:page==='admin-login'?'ตรวจชื่อผู้ใช้ รหัสผ่าน และภาษาบนแป้นพิมพ์ หากยังเข้าไม่ได้ให้เจ้าของระบบตรวจสถานะบัญชี':'ใช้เบอร์โทรที่ลงทะเบียนเข้าพักจริง หากเข้าไม่ได้ให้ผู้ดูแลตรวจเบอร์และสถานะผู้พัก ไม่ใช้เบอร์ของผู้อื่น'};
    if(['FORBIDDEN','UNAUTHORIZED','ADMIN_INACTIVE','RESIDENT_INACTIVE','RESIDENT_SESSION_STALE'].includes(code))return {detail:'บัญชีหรือสิทธิ์นี้ยังทำรายการไม่ได้ เก็บข้อมูลที่ยังไม่บันทึกไว้ ให้เจ้าของตรวจสถานะบัญชีและสิทธิ์ แล้วเข้าสู่ระบบใหม่ด้วยบัญชีของตนเอง'};
    if(['REQUEST_IN_PROGRESS','LINE_REGISTRY_BUSY','LINE_API_RATE_LIMITED'].includes(code))return {detail:'มีคำขอเดิมกำลังทำงานหรือบริการจำกัดจำนวนคำขอ รอผลก่อนโหลดสถานะใหม่ ไม่กดส่งคำขอซ้ำระหว่างรอ'};
    if(['ROOM_NOT_FOUND','RESIDENT_NOT_FOUND','BOOKING_NOT_FOUND','BILL_NOT_FOUND','PAYMENT_NOT_FOUND','ADMIN_NOT_FOUND','OCCUPANCY_NOT_FOUND','LINE_OA_NOT_FOUND','LINE_RECIPIENT_NOT_FOUND','NOT_FOUND'].includes(code))return {detail:'ไม่พบรายการนี้หรือบัญชีนี้เข้าถึงไม่ได้ กลับไปโหลดรายการล่าสุดและเลือกใหม่ ตรวจว่ากำลังใช้บัญชีที่ถูกต้อง หากยังหาไม่พบให้ติดต่อผู้ดูแล ไม่สร้างรายการซ้ำทันที'};
    if(['SELF_DELETE','SELF_OWNER_CHANGE','LAST_OWNER'].includes(code))return {detail:'คงบัญชีเจ้าของที่ใช้งานได้ไว้อย่างน้อยหนึ่งบัญชี ไม่ปิดหรือลดสิทธิ์บัญชีที่กำลังใช้ หากต้องเปลี่ยนผู้ดูแลให้เจ้าของอีกบัญชีดำเนินการ'};
    if(code==='VALIDATION_ERROR'&&Object.hasOwn(fields,details.field))return {detail:`ตรวจช่อง “${fields[details.field]}” ตามรูปแบบที่กำหนด ข้อมูลอื่นที่กรอกยังอยู่`,field:details.field,action:'ไปแก้ช่องนี้'};
    if(code==='MUTATION_OUTCOME_UNKNOWN')return {detail:'ยังไม่ทราบว่าบันทึกสำเร็จหรือไม่ ตรวจรายการล่าสุดก่อนทำซ้ำ เก็บข้อมูลที่กรอกไว้ ระบบจะไม่ส่งคำขอซ้ำให้อัตโนมัติ'};
    if(code==='IDEMPOTENCY_KEY_REUSED')return {detail:'คำขอเดิมถูกใช้กับข้อมูลอีกชุดแล้ว เก็บร่างและตรวจผลรายการเดิมก่อน เมื่อยืนยันว่าไม่มีรายการซ้ำจึงปิดฟอร์มและเริ่มใหม่ ไม่เปลี่ยนรหัสคำขอเพื่อบังคับส่งซ้ำ'};
    if(code==='RATE_LIMITED')return {detail:'รอตามเวลาที่แจ้งก่อนลองใหม่ ไม่ต้องกดซ้ำระหว่างรอ'};
    if(code==='CSRF_INVALID'||code==='UNAUTHENTICATED')return {detail:'จดข้อมูลที่ยังไม่บันทึกไว้ก่อน แล้วรีเฟรชหรือเข้าสู่ระบบใหม่ จากนั้นตรวจรายการเดิมก่อนส่งอีกครั้ง'};
    const routeCode=Object.hasOwn(aliases,code)?aliases[code]:code;
    const route=Object.hasOwn(routes,routeCode)?routes[routeCode]:null;
    if(route){
      const ownerOnly=['settings','line-oas'].includes(route[1]);
      const view=admin&&(!ownerOnly||role==='owner')?route[1]:null;
      return {detail:route[0],view,action:view?'ไปหน้าที่แก้ไขได้':null};
    }
    return {detail:'ข้อมูลที่กรอกยังอยู่ ตรวจช่องที่แจ้งและลองโหลดข้อมูลล่าสุด หากยังแก้ไม่ได้ให้แจ้งผู้ดูแลพร้อมรหัสข้อผิดพลาด ห้ามกดบันทึกซ้ำหากยังไม่ทราบผลครั้งก่อน'};
  }
  window.DormRecoveryGuide=Object.freeze({explain});
})();
