(() => {
  'use strict';
  const statusNames = { pending: 'รอยืนยัน', confirmed: 'ยืนยันแล้ว', checked_in: 'เข้าพักแล้ว', checked_out: 'เช็กเอาต์แล้ว', cancelled: 'ยกเลิก', expired: 'หมดอายุ', no_show: 'ไม่มาเข้าพัก' };
  const accessStorageKey = 'dorm.daily.access.v1';
  const pendingStorageKey = 'dorm.daily.pending.v1';
  const uploadStorageKey = 'dorm.daily.upload.v1';
  const ownerCreateStorageKey = 'dorm.daily.owner-create.v1';
  const ownerFinanceStorageKey = 'dorm.daily.owner-finance.v1';
  const ownerProofStorageKey = 'dorm.daily.owner-proof.v1';
  const uuid = () => globalThis.crypto.randomUUID();
  const object = (value) => value?.booking || value;
  const list = (value) => Array.isArray(value) ? value : value?.items;
  const moneyValue = (value) => typeof value === 'number' || typeof value === 'string' ? /^\d+(?:\.\d{1,2})?$/.test(String(value)) && Number.isFinite(Number(value)) && Number(value) <= 999_999_999_999.99 : false;
  const cents = (value) => Math.round(Number(value) * 100);
  function range(input, maximumNights = 90) {
    const a = String(input.check_in_date || ''), b = String(input.check_out_date || '');
    const date = (raw) => { const parsed = new Date(`${raw}T00:00:00Z`); return /^\d{4}-\d{2}-\d{2}$/.test(raw) && Number.isFinite(parsed.getTime()) && parsed.toISOString().slice(0, 10) === raw; };
    if (!date(a) || !date(b)) throw new Error('กรอกวันเช็กอินและวันเช็กเอาต์ให้ครบ');
    const nights = (Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86400000;
    const guests = Number(input.guests);
    if (nights < 1 || nights > maximumNights || !Number.isInteger(guests) || guests < 1 || guests > 20) throw new Error(maximumNights === 90 ? 'วันเช็กเอาต์ต้องหลังวันเช็กอิน พักได้ 1–90 คืน และจำนวนคนต้องถูกต้อง' : `วันสิ้นสุดต้องหลังวันเริ่มต้น เลือกช่วงได้ 1–${maximumNights} วัน`);
    return { check_in_date: a, check_out_date: b, guests };
  }
  function validQuote(data, submitted, tokenRequired = true) {
    const value = data?.quote || data;
    const nights = (Date.parse(submitted.check_out_date) - Date.parse(submitted.check_in_date)) / 86400000;
    if (!value || Number(value.room_id ?? value.id) !== Number(submitted.room_id) || value.check_in_date !== submitted.check_in_date || value.check_out_date !== submitted.check_out_date || Number(value.guests) !== submitted.guests || Number(value.nights) !== nights
      || !['nightly_rate', 'room_amount', 'deposit_amount', 'total_amount'].every((key) => moneyValue(value[key]))
      || cents(value.room_amount) !== cents(value.nightly_rate) * nights || cents(value.total_amount) !== cents(value.room_amount) + cents(value.deposit_amount)
      || (tokenRequired && (typeof value.quote_token !== 'string' || !value.quote_token || !Number.isFinite(Date.parse(value.quote_expires_at))))) throw new Error('ข้อมูลราคาไม่ครบหรือไม่ตรงช่วงพัก กรุณาค้นหาและตรวจราคาใหม่');
    return value;
  }
  function validBooking(data, submitted = null) {
    const value = object(data);
    if (!value || !Number.isSafeInteger(value.id) || value.id < 1 || !Number.isInteger(Number(value.version)) || Number(value.version) < 1 || !statusNames[value.status] || typeof value.reference_no !== 'string' || !value.reference_no) throw new Error('ยังยืนยันผลการจองไม่ได้ กรุณาตรวจผลคำขอเดิม');
    const dates = range(value); validQuote(value, { ...dates, room_id: value.room_id }, false);
    if (value.status === 'pending' && !Number.isFinite(expiry(value.expires_at))) throw new Error('ข้อมูลเวลารอยืนยันไม่ครบ กรุณาตรวจผลคำขอเดิม');
    if (submitted && ['room_id', 'check_in_date', 'check_out_date', 'guests'].some((key) => String(value[key]) !== String(submitted[key]))) throw new Error('ผลการจองไม่ตรงคำขอ กรุณาตรวจผลคำขอเดิม');
    return value;
  }
  function bookingPage(data, offset = 0) {
    if (!data || !Array.isArray(data.items) || typeof data.has_more !== 'boolean' || !Number.isSafeInteger(data.next_offset) || data.next_offset !== offset + data.items.length || (data.has_more && !data.items.length)) throw new Error('ข้อมูลหน้ารายการจองไม่ครบ กรุณาโหลดรายการล่าสุด');
    const items = data.items.map(value => validBooking(value)), ids = new Set(items.map(value => value.id));
    if (ids.size !== items.length) throw new Error('รายการจองซ้ำในหน้าที่โหลด กรุณารีเฟรชรายการ');
    return { items, hasMore: data.has_more, nextOffset: data.next_offset };
  }
  function validPaymentRecord(value, bookingId) {
    if (!value || !Number.isSafeInteger(value.id) || value.id < 1 || value.booking_id !== bookingId || !['cash', 'slip'].includes(value.method) || !['pending', 'verified', 'rejected', 'closed'].includes(value.status)
      || !moneyValue(value.amount) || !moneyValue(value.transfer_amount)) throw new Error('ข้อมูลหลักฐานการชำระไม่ครบหรือไม่ตรงการจอง กรุณาตรวจผลรายการเดิม');
    return value;
  }
  function paymentGuard(booking, data) {
    if (!data || data.booking_id !== booking.id || typeof data.paid !== 'boolean' || !statusNames[data.booking_status] || !Number.isSafeInteger(data.version) || data.version < 1
      || !['has_transfer_instruction', 'cash_available', 'has_closed_unresolved', 'can_generate_qr', 'can_upload', 'can_owner_upload'].every(key=>typeof data[key]==='boolean') || !Array.isArray(data.closed_payments) || !data.capabilities || typeof data.capabilities.promptpay_ready !== 'boolean' || typeof data.capabilities.slip_verification_ready !== 'boolean') throw new Error('ข้อมูลการชำระไม่ครบ กรุณาตรวจสถานะล่าสุดก่อนโอน');
    if (data.payment) validPaymentRecord(data.payment, booking.id);
    for (const row of data.closed_payments) { validPaymentRecord(row, booking.id); if (row.status !== 'closed') throw new Error('ข้อมูลหลักฐานพักการตรวจไม่ครบ กรุณาตรวจสถานะล่าสุด'); }
    if (data.paid && data.payment?.status !== 'verified') throw new Error('ยังยืนยันผลการรับเงินไม่ได้ กรุณาตรวจสถานะล่าสุด');
    if (!['received_amount', 'refunded_amount', 'refundable_amount', 'deposit_remaining'].every((key) => moneyValue(data[key]))
      || cents(data.refunded_amount) > cents(data.received_amount) || cents(data.refundable_amount) > cents(data.received_amount) - cents(data.refunded_amount) || cents(data.deposit_remaining) > cents(booking.deposit_amount)) throw new Error('ข้อมูลยอดรับและคืนเงินไม่ครบ กรุณาตรวจสถานะล่าสุด');
    const pending = data.payment?.status === 'pending';
    const closed = data.payment?.status === 'closed' || data.has_closed_unresolved === true || data.closed_payments.length > 0;
    const payable = data.booking_status === 'pending' && expiry(booking.expires_at) > Date.now();
    return { pending, closed, paid: data.paid, mayTransfer: payable && data.can_generate_qr === true && !data.paid && !pending && !closed && data.capabilities.promptpay_ready, mayUpload: data.can_upload === true && data.has_transfer_instruction && !data.paid && !pending && !closed && data.capabilities.slip_verification_ready };
  }
  function uploadResolved(marker, payment) {
    if (!marker || !payment || payment.booking_id !== marker.booking_id || payment.method !== 'slip') return false;
    return ['pending', 'verified'].includes(payment.status) && (marker.prior_payment_id === null || payment.id >= marker.prior_payment_id);
  }
  function bookingActions(booking, today) {
    if (booking.status === 'pending') return [['ยกเลิก', 'cancel']];
    if (booking.status === 'checked_in') return [['เช็กเอาต์', 'check-out']];
    if (booking.status !== 'confirmed') return [];
    const actions = [['ยกเลิก', 'cancel']];
    if (today >= booking.check_in_date && today < booking.check_out_date) actions.unshift(['เช็กอิน', 'check-in']);
    if (today > booking.check_in_date) actions.push(['ไม่มาเข้าพัก', 'no-show']);
    return actions;
  }
  function calendarStay(bookings, roomId, day, today) {
    const overdue = bookings.find(b => Number(b.room_id) === roomId && b.status === 'checked_in' && b.check_out_date <= today && day >= today);
    return overdue || bookings.find(b => Number(b.room_id) === roomId && ['pending', 'confirmed', 'checked_in', 'checked_out'].includes(b.status) && b.check_in_date <= day && day < b.check_out_date);
  }
  function validFinanceOutcome(action, value, booking, payload, paymentId = null, historicalResult = false) {
    if (!value || Number(value.booking_id) !== booking.id) throw new Error('ยังยืนยันผลรายการเงินไม่ได้ กรุณาตรวจผลรายการเดิม');
    if (['cash', 'retry', 'close'].includes(action)) { validPaymentRecord(value, booking.id); if (paymentId !== null && value.id !== paymentId) throw new Error('ผลตรวจไม่ตรงหลักฐานเดิม กรุณาตรวจผลรายการเดิม'); }
    if (action === 'close' && (value.method !== 'slip' || (!historicalResult && value.status !== 'closed'))) throw new Error('ยังยืนยันผลพักการตรวจไม่ได้ กรุณาตรวจผลรายการเดิม');
    if (action === 'cash' && (value.method !== 'cash' || value.status !== 'verified' || cents(value.amount) !== cents(booking.total_amount) || value.receipt_reference !== String(payload.reference).trim())) throw new Error('ผลรับเงินสดไม่ตรงใบรับเงิน กรุณาตรวจผลรายการเดิม');
    if (action === 'refund' && (!Number.isSafeInteger(Number(value.id)) || Number(value.id) < 1 || !moneyValue(value.amount) || cents(value.amount) !== cents(payload.amount) || value.reference_no !== String(payload.reference).trim())) throw new Error('ผลคืนเงินไม่ตรงรายการ กรุณาตรวจผลรายการเดิม');
    if (action === 'deposit-settlement' && (!moneyValue(value.retained_amount) || cents(value.retained_amount) !== cents(payload.retained_amount))) throw new Error('ผลหักค่าประกันไม่ตรงรายการ กรุณาตรวจผลรายการเดิม');
    return value;
  }
  async function fileDigest(file) {
    if (!globalThis.crypto?.subtle || typeof file.arrayBuffer !== 'function') throw new Error('เบราว์เซอร์ตรวจไฟล์เดิมไม่ได้ กรุณาใช้ HTTPS หรือเบราว์เซอร์ที่รองรับก่อนส่งสลิป');
    const bytes = await globalThis.crypto.subtle.digest('SHA-256', await file.arrayBuffer());
    return Array.from(new Uint8Array(bytes), value => value.toString(16).padStart(2, '0')).join('');
  }
  function gate() { let revision = 0; return { next: () => ++revision, current: (value) => value === revision, invalidate: () => { revision += 1; } }; }
  function feedback(sharedToast) {
    let latestSuccess = null;
    return (message, type = 'success') => {
      if (type !== 'success' || message instanceof Error) return sharedToast(message, type);
      latestSuccess?.remove();
      latestSuccess = sharedToast(message, type) || null;
      if (latestSuccess) latestSuccess.dataset.dailyFeedback = 'success';
      return latestSuccess;
    };
  }
  function validTransfer(qr, booking) {
    if (!qr || qr.booking_id !== booking.id || typeof qr.payload !== 'string' || qr.payload.length > 512 || !qr.payload.startsWith('000201') || typeof qr.target !== 'string' || !qr.target || qr.amount_locked !== true
      || !['bill_amount', 'adjustment_amount', 'amount'].every((key) => moneyValue(qr[key])) || cents(qr.bill_amount) !== cents(booking.total_amount)
      || cents(qr.adjustment_amount) < 1 || cents(qr.adjustment_amount) > 99 || cents(qr.amount) !== cents(qr.bill_amount) + cents(qr.adjustment_amount)) throw new Error('ยอดหรือข้อมูล QR ไม่ตรงการจอง กรุณาตรวจสถานะก่อนโอน');
    return qr;
  }
  const readStorage = (key) => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { return null; } };
  const writeStorage = (key, value) => { try { value === null ? sessionStorage.removeItem(key) : sessionStorage.setItem(key, JSON.stringify(value)); return true; } catch (_) { return false; } };
  const expiry = (value) => Date.parse(/^\d{4}-\d{2}-\d{2} /.test(String(value || '')) ? String(value).replace(' ', 'T') + 'Z' : value);
  function bookingGuidance(booking, payment, recovering = false, owner = false) {
    if (recovering) return ['ตรวจผลรายการเดิมก่อน', 'ยังไม่ทราบผลล่าสุด กดตรวจผลรายการเดิม โดยไม่เริ่มจอง รับเงิน หรือโอนซ้ำ'];
    if (!booking || !payment) return ['กำลังตรวจสถานะล่าสุด', 'รอข้อมูลการจองและการชำระก่อนทำรายการ'];
    const guard = paymentGuard(booking, payment);
    if (guard.pending) return ['รอผลตรวจสลิป', 'ส่งหลักฐานแล้ว ระบบกำลังตรวจ กรุณารอผลและอย่าโอนหรือรับเงินซ้ำ'];
    if (guard.closed) return [owner ? 'ตรวจหลักฐานเดิมก่อนรับเงินเพิ่ม' : 'ติดต่อหอพักเพื่อตรวจหลักฐานเดิม', owner ? 'ตรวจหรือซ่อมหลักฐานเดิมให้เรียบร้อยก่อนรับเงินเพิ่ม ห้ามให้ผู้พักโอนซ้ำ' : 'เก็บสลิปเดิมและหมายเลขอ้างอิงไว้ติดต่อหอพัก อย่าโอนซ้ำ'];
    if (booking.status === 'confirmed') return ['ยืนยันการจองแล้ว', owner ? 'รับเงินครบแล้ว เมื่อถึงวันเข้าพักกด “เช็กอิน” ในรายการจอง' : 'มาเข้าพักตามวันที่จอง พร้อมแจ้งหมายเลขอ้างอิงให้หอพัก ไม่ต้องชำระซ้ำ'];
    if (booking.status === 'checked_in') return ['เข้าพักแล้ว', owner ? 'ก่อนเช็กเอาต์ ตรวจห้องและบันทึกคืนหรือหักค่าประกันให้ครบ แล้วกด “เช็กเอาต์”' : 'เมื่อถึงวันออก ติดต่อหอพักเพื่อตรวจห้องและรับคืนค่าประกันตามผลตรวจ'];
    if (booking.status === 'checked_out') return ['เช็กเอาต์แล้ว', owner ? 'ทำความสะอาดห้อง แล้วกด “ยืนยันทำความสะอาดเสร็จ” ด้านล่าง' : 'การพักสิ้นสุดแล้ว หากมีข้อสงสัยเรื่องยอดเงิน ให้ติดต่อหอพักด้วยหมายเลขอ้างอิง'];
    if (booking.status !== 'pending' || expiry(booking.expires_at) <= Date.now()) return ['การจองสิ้นสุดหรือหมดเวลากันห้อง', 'ห้ามโอนเพิ่ม หากโอนแล้วให้ใช้สลิปเดิมตรวจผลและติดต่อหอพักเรื่องคืนเงิน'];
    if (payment.payment?.status === 'rejected') return ['ตรวจเหตุผลที่สลิปไม่ผ่าน', 'ตรวจหลักฐานเดิมและติดต่อหอพักก่อนโอนเพิ่ม หากโอนถูกต้องแล้วให้ใช้สลิปที่ตรงยอดเดิม'];
    if (payment.has_transfer_instruction) return ['ตรวจยอดโอนเดิมก่อน', 'ออก QR สำหรับรายการนี้แล้ว หากโอนแล้วให้ส่งสลิปของยอดเดิม ห้ามโอนหรือรับเงินสดซ้ำ'];
    if (!payment.capabilities.slip_verification_ready || !payment.capabilities.promptpay_ready) return [owner ? (payment.cash_available ? 'รับเงินสดเพื่อยืนยันห้อง' : 'ตรวจสถานะก่อนรับเงิน') : 'ติดต่อหอพักเพื่อชำระเงิน', owner ? (payment.cash_available ? 'ยังไม่เปิดรับชำระออนไลน์ เมื่อรับเงินสดครบและออกใบรับเงินแล้ว ให้บันทึกตามปุ่มด้านล่าง หรือตั้งค่าการรับโอนก่อน' : 'ยังรับชำระเพิ่มไม่ได้ ตรวจสถานะและหลักฐานเดิมก่อนทำรายการ') : 'ยังไม่เปิดรับชำระออนไลน์ สอบถามหอพักเรื่องชำระเงินสดก่อนหมดเวลากันห้อง อย่าโอนเอง'];
    return ['ชำระเงินเพื่อยืนยันห้อง', owner ? 'เลือกรับเงินสดพร้อมใบรับเงิน หรือแสดง QR ให้ผู้พัก หลังรับครบระบบจะยืนยันการจอง' : 'กด “สร้าง QR ชำระเงิน” โอนตามยอดที่แสดง แล้วส่งสลิปในแท็บนี้ก่อนหมดเวลากันห้อง'];
  }
  function showGuidance(h, target, booking, payment, recovering = false, owner = false) {
    const [title, message] = bookingGuidance(booking, payment, recovering, owner);
    target.replaceChildren(h.create('strong', '', title), h.create('p', '', message)); target.hidden = false;
  }
  function paymentTotals(h, target, payment) {
    const dl = h.create('dl');
    [['รับเงินแล้ว', 'received_amount'], ['คืนเงินจริงที่บันทึกแล้ว', 'refunded_amount'], ['ยอดที่คืนได้', 'refundable_amount'], ['ค่าประกันรอคืนหรือหัก', 'deposit_remaining']].forEach(([label, name]) => dl.append(h.create('dt', '', label), h.create('dd', '', h.money(payment[name]))));
    target.replaceChildren(dl);
    target.hidden = cents(payment.received_amount) === 0 && cents(payment.refunded_amount) === 0;
  }
  function rejectedCreate(error, recovering) {
    if (!(error.status >= 400 && error.status < 500) || [401, 403, 408, 429].includes(error.status) || ['REQUEST_IN_PROGRESS', 'MUTATION_OUTCOME_UNKNOWN', 'BOOKING_RETRY'].includes(error.details?.code)) return false;
    if (!recovering) return true;
    // These create-service errors occur only after looking up the exact key
    // under the room lock. Generic validation/auth errors cannot prove absence.
    return (error.status === 409 && ['DAILY_QUOTE_INVALID', 'DAILY_QUOTE_EXPIRED', 'DAILY_QUOTE_CHANGED', 'DAILY_ROOM_NOT_AVAILABLE', 'ROOM_NOT_READY', 'ROOM_NOT_DAILY', 'DAILY_STAY_ENDED'].includes(error.details?.code))
      || (error.status === 422 && error.details?.code === 'DAILY_GUEST_CAPACITY');
  }
  function summary(h, target, booking) {
    const dl = h.create('dl');
    const fields = [['ห้อง', booking.room_code || booking.room_id], ['วันเข้าพัก', h.formatDate(booking.check_in_date)], ['วันออก', h.formatDate(booking.check_out_date)], ['ผู้พัก / จำนวนคืน', `${booking.guests} คน / ${booking.nights} คืน`], ['ราคาต่อคืน', h.money(booking.nightly_rate)], ['ค่าห้องรวม', h.money(booking.room_amount)], ['ค่าประกัน', h.money(booking.deposit_amount)], ['ยอดรวมค่าห้องและประกัน', h.money(booking.total_amount)]];
    if (booking.reference_no) fields.unshift(['หมายเลขอ้างอิง', booking.reference_no], ['สถานะ', statusNames[booking.status]]);
    fields.forEach(([name, value]) => dl.append(h.create('dt', '', name), h.create('dd', '', String(value)))); target.replaceChildren(dl);
  }
  const formValues = (form) => Object.fromEntries(new FormData(form).entries());
  function initPublic(h) {
    const { $, $$, create, api, errorMessage, showFormError, money, isoToday, isoDateOffsetDays, setBusy, setFormFieldsBusy, setDialogBusy, openDialog, closeDialog, getQrLibrary, renderQrCanvas } = h;
    if (!$('[data-daily-public]')) return null;
    const search = $('#daily-search-form'), form = $('#daily-booking-form'), dialog = $('#daily-booking-dialog');
    const searchGate = gate(), quoteGate = gate(), detailGate = gate();
    const state = { search: null, quote: null, access: readStorage(accessStorageKey), pending: readStorage(pendingStorageKey), uploadRecovery: readStorage(uploadStorageKey), booking: null, payment: null, pollPayment: false, busy: false, detailReady: false, quoteBusy: false };
    if (!state.access || !Number.isSafeInteger(state.access.id) || typeof state.access.token !== 'string' || state.access.token.length < 32) state.access = null;
    if (!state.pending || typeof state.pending.idempotency_key !== 'string' || typeof state.pending.quote_token !== 'string') state.pending = null;
    if (state.pending) state.pending.outcome_unknown = true;
    if (!state.uploadRecovery || state.uploadRecovery.booking_id !== state.access?.id || !/^[0-9a-f]{64}$/.test(state.uploadRecovery.sha256 || '') || !(state.uploadRecovery.prior_payment_id === null || Number.isSafeInteger(state.uploadRecovery.prior_payment_id))) state.uploadRecovery = null;
    search.elements.check_in_date.min = isoToday(); search.elements.check_in_date.value = isoToday(); search.elements.check_out_date.min = isoDateOffsetDays(1); search.elements.check_out_date.value = isoDateOffsetDays(1);
    function previewStay() {
      try { const dates = range(formValues(search)); const nights = (Date.parse(dates.check_out_date) - Date.parse(dates.check_in_date)) / 86400000; $('#daily-search-summary').textContent = `${h.formatDate(dates.check_in_date)} → ${h.formatDate(dates.check_out_date)} · ${nights} คืน · ${dates.guests} คน`; }
      catch (_) { $('#daily-search-summary').textContent = 'เลือกวันออกหลังวันเข้าพัก และระบุจำนวนผู้พัก เพื่อดูจำนวนคืน'; }
    }
    previewStay();
    function clearQuote() { quoteGate.invalidate(); state.quote = null; $('#daily-quote-summary').replaceChildren(); form.querySelector('[type="submit"]').disabled = true; }
    function resetPayment() { $('#daily-payment-panel').hidden = true; $('#daily-qr-stage').replaceChildren(); $('#daily-load-qr').disabled = true; $('#daily-slip-form').hidden = true; state.payment = null; }
    function recovery() { $('#daily-recovery').hidden = !state.pending; if (state.pending) $('#daily-room-grid').replaceChildren(); }
    function uploadRecoveryNotice() { $('#daily-slip-recovery').hidden = !state.uploadRecovery; }
    function clearUploadRecovery() { state.uploadRecovery = null; writeStorage(uploadStorageKey, null); uploadRecoveryNotice(); }
    function saveRecovery(key, value) { if (!writeStorage(key, value)) { $('#daily-storage-note').hidden = false; $('#daily-storage-note').textContent = 'เบราว์เซอร์เก็บสิทธิ์และคำขอในแท็บนี้ไม่ได้ กรุณาเปิดหน้านี้ไว้และเก็บหมายเลขอ้างอิง การรีเฟรชหรือปิดแท็บอาจทำให้เปิดรายละเอียดต่อไม่ได้'; } }
    function pendingHold() {
      const booking = state.booking, node = $('#daily-hold-countdown');
      if (!booking || booking.status !== 'pending' || !booking.expires_at) { node.textContent = ''; return; }
      const remaining = expiry(booking.expires_at) - Date.now();
      node.textContent = remaining > 0 ? `กันห้องไว้ถึง ${h.formatDateTime(booking.expires_at)} เหลือ ${Math.ceil(remaining / 60000)} นาที การรับเงินครบและตรวจสลิปผ่านจะยืนยันห้องให้อัตโนมัติ` : 'เวลารอยืนยันหมดแล้ว กดตรวจสถานะล่าสุด หากโอนแล้วให้ส่งหลักฐานเดิมเพื่อตรวจรับเงินและติดต่อหอพักเรื่องคืนเงิน';
      if (remaining <= 0) $('#daily-load-qr').disabled = true;
    }
    function renderPayment() {
      const guard = paymentGuard(state.booking, state.payment);
      state.pollPayment = guard.pending || !!state.uploadRecovery;
      $('#daily-payment-panel').hidden = false;
      $('#daily-payment-status').textContent = guard.paid ? (['confirmed', 'checked_in'].includes(state.booking.status) ? 'ได้รับเงินครบและยืนยันการจองแล้ว' : 'ได้รับเงินแล้ว กรุณาตรวจสถานะการจองและติดต่อหอพักก่อนดำเนินการเพิ่ม') : guard.pending ? 'กำลังตรวจสลิปที่ส่งแล้ว กรุณารอผลและอย่าโอนซ้ำ' : guard.closed ? 'เจ้าของพักการตรวจสลิปไว้ ยังไม่ยืนยันยอดเงิน กรุณาติดต่อหอพักด้วยหลักฐานเดิม' : state.payment.payment?.status === 'rejected' ? `สลิปไม่ผ่าน: ${state.payment.payment.reason || 'กรุณาตรวจหลักฐานและติดต่อหอพักก่อนโอนเพิ่ม'}` : 'ยังไม่ได้รับเงินครบ';
      $('#daily-payment-help').textContent = state.uploadRecovery ? 'ยังไม่ทราบผลสลิปเดิม ห้ามโอนซ้ำ กดตรวจผลหรือเลือกไฟล์เดิมแล้วส่งเพื่อตรวจผลคำขอเดิม' : guard.closed ? 'เก็บสลิปเดิมไว้ ห้ามโอนซ้ำ เจ้าของระบบตรวจหลักฐานเดิมต่อได้เมื่อแก้ปัญหาแล้ว' : !['pending', 'confirmed', 'checked_in'].includes(state.booking.status) ? (guard.mayUpload ? 'การจองสิ้นสุดแล้ว หากโอนแล้วให้ส่งสลิปเดิมเพื่อตรวจรับเงินและติดต่อหอพักเรื่องคืนเงิน' : 'การจองสิ้นสุดแล้ว หากมีเงินที่ต้องคืน กรุณาติดต่อหอพัก') : guard.pending || guard.paid ? 'ตรวจสถานะล่าสุดได้จากปุ่มด้านบน ไม่มีความจำเป็นต้องโอนซ้ำ' : state.payment.payment?.status === 'rejected' ? 'ตรวจเหตุผลและหลักฐานเดิม หรือติดต่อหอพักก่อนโอนเพิ่ม หากโอนถูกต้องแล้วให้ส่งหลักฐานที่ตรงยอดเดิม' : !state.payment.capabilities.promptpay_ready ? 'ยังไม่ได้เปิดรับชำระด้วย QR กรุณาติดต่อหอพักก่อนโอน' : 'ตรวจชื่อผู้รับและยอดโอนตาม QR ก่อนยืนยัน ระบบแยกยอดด้วยสตางค์ ห้ามปัดยอด';
      $('#daily-load-qr').disabled = state.busy || !!state.uploadRecovery || !guard.mayTransfer;
      const mayReplayEvidence = state.uploadRecovery && state.payment.has_transfer_instruction && state.payment.capabilities.slip_verification_ready && !guard.pending && !guard.paid;
      $('#daily-slip-form').hidden = !guard.mayUpload && !mayReplayEvidence;
      $('#daily-slip-form').querySelector('[type="submit"]').textContent = state.uploadRecovery ? 'ส่งไฟล์เดิมเพื่อตรวจผล' : 'ส่งสลิปให้ระบบตรวจ';
      if (guard.pending || guard.paid || guard.closed || state.uploadRecovery) $('#daily-qr-stage').replaceChildren();
      uploadRecoveryNotice();
      pendingHold();
      showGuidance(h, $('#daily-next-step'), state.booking, state.payment, !!state.uploadRecovery);
      paymentTotals(h, $('#daily-payment-totals'), state.payment);
    }
    async function loadDetail() {
      if (!state.access || state.busy) return;
      const revision = detailGate.next(), access = { ...state.access };
      state.pollPayment = state.pollPayment || state.payment?.payment?.status === 'pending' || !!state.uploadRecovery;
      state.detailReady = false; state.booking = null; $('#daily-copy-reference').disabled = true; resetPayment(); showGuidance(h, $('#daily-next-step'), null, null); $('#daily-booking-summary').replaceChildren(create('p', '', 'กำลังตรวจการจอง…')); $('#daily-detail').hidden = false; showFormError($('#daily-detail-error'));
      const headers = { 'X-Booking-Access-Token': access.token };
      const results = await Promise.allSettled([api(`/api/public/daily/bookings/${access.id}`, { headers }), api(`/api/public/daily/bookings/${access.id}/payment`, { headers })]);
      if (!detailGate.current(revision)) return;
      try {
        if (results[0].status !== 'fulfilled') throw results[0].reason;
        const booking = validBooking(results[0].value); if (booking.id !== access.id) throw new Error('ข้อมูลการจองไม่ตรงรายการที่เปิด');
        state.booking = booking; state.detailReady = true; $('#daily-copy-reference').disabled = false; summary(h, $('#daily-booking-summary'), booking); pendingHold();
        if (results[1].status !== 'fulfilled') throw results[1].reason;
        const data = results[1].value; paymentGuard(booking, data);
        if (data.version !== booking.version || data.booking_status !== booking.status) throw new Error('สถานะการจองเปลี่ยนระหว่างโหลด กรุณากดตรวจสถานะล่าสุดอีกครั้งก่อนชำระ');
        state.payment = data; if (uploadResolved(state.uploadRecovery, data.payment)) clearUploadRecovery(); renderPayment();
      } catch (error) { resetPayment(); $('#daily-next-step').hidden = true; showFormError($('#daily-detail-error'), error); }
    }
    async function submitPending() {
      if (state.busy || !state.pending) return;
      const wasRecovery = state.pending.outcome_unknown === true;
      const submitted = { ...state.pending }; state.busy = true; setDialogBusy(dialog, true); setFormFieldsBusy(form, true); setBusy(form.querySelector('[type="submit"]'), true); setBusy($('#daily-recover-submit'), true);
      delete submitted.outcome_unknown;
      showFormError($('#daily-booking-error')); showFormError($('#daily-recovery-error'));
      try {
        const booking = validBooking(await api('/api/public/daily/bookings', { method: 'POST', body: submitted }), submitted);
        if (typeof booking.access_token !== 'string' || booking.access_token.length < 32) throw new Error('ยังไม่ได้รับสิทธิ์เปิดการจอง กรุณาตรวจผลคำขอเดิม');
        state.access = { id: booking.id, token: booking.access_token }; saveRecovery(accessStorageKey, state.access);
        state.pending = null; writeStorage(pendingStorageKey, null); clearQuote(); setDialogBusy(dialog, false); closeDialog(dialog); $('#daily-room-grid').replaceChildren(); recovery();
      } catch (error) {
        showFormError($('#daily-booking-error'), error); showFormError($('#daily-recovery-error'), error);
        if (rejectedCreate(error, wasRecovery)) { state.pending = null; writeStorage(pendingStorageKey, null); clearQuote(); }
        else { state.pending.outcome_unknown = true; saveRecovery(pendingStorageKey, state.pending); }
      } finally { state.busy = false; setDialogBusy(dialog, false); setFormFieldsBusy(form, false); setBusy(form.querySelector('[type="submit"]'), false); form.querySelector('[type="submit"]').disabled = !state.quote || !!state.pending; setBusy($('#daily-recover-submit'), false); recovery(); }
      if (state.access && !state.pending) { await loadDetail(); $('#daily-detail').scrollIntoView?.({ block: 'start' }); $('#daily-detail-title').focus?.({ preventScroll: true }); }
    }
    async function chooseRoom(room) {
      if (state.busy || state.pending || state.quoteBusy || !state.search) return;
      if (state.access) { showFormError($('#daily-search-error'), 'มีการจองเปิดอยู่ในแท็บนี้ หากต้องการจองเพิ่ม ให้กด “จองเพิ่มในแท็บใหม่” ในรายละเอียดการจอง เพื่อเก็บรายการเดิมไว้'); return; }
      if (state.uploadRecovery) { showFormError($('#daily-search-error'), 'ยังไม่ทราบผลหลักฐานการจองเดิม กรุณาตรวจผลก่อนเริ่มการจองใหม่'); return; }
      clearQuote(); const revision = quoteGate.next(), submitted = { ...state.search, room_id: room.id };
      state.quoteBusy = true; openDialog(dialog); showFormError($('#daily-booking-error')); $('#daily-quote-summary').textContent = 'กำลังตรวจราคาและห้องว่าง…'; setDialogBusy(dialog, true);
      try { const quote = validQuote(await api('/api/public/daily/quote', { method: 'POST', body: submitted }), submitted); if (!quoteGate.current(revision)) return; state.quote = { ...quote, room_id: submitted.room_id }; summary(h, $('#daily-quote-summary'), { ...quote, room_code: room.room_code }); form.querySelector('[type="submit"]').disabled = false; }
      catch (error) { if (quoteGate.current(revision)) { state.quote = null; $('#daily-quote-summary').replaceChildren(); showFormError($('#daily-booking-error'), error); } }
      finally { state.quoteBusy = false; setDialogBusy(dialog, false); }
    }
    search.addEventListener('input', (event) => { if (state.busy || state.pending) return; searchGate.invalidate(); setBusy(search.querySelector('[type="submit"]'), false); clearQuote(); state.search = null; $('#daily-room-grid').replaceChildren(); $('#daily-results-note').textContent = 'วันที่หรือจำนวนผู้พักเปลี่ยนแล้ว กดค้นหาห้องว่างอีกครั้ง'; const arrival = search.elements.check_in_date.value; if (/^\d{4}-\d{2}-\d{2}$/.test(arrival) && Number.isFinite(Date.parse(`${arrival}T00:00:00Z`))) { const nextDay = new Date(Date.parse(`${arrival}T00:00:00Z`) + 86400000).toISOString().slice(0, 10); search.elements.check_out_date.min = nextDay; if (event?.target === search.elements.check_in_date && search.elements.check_out_date.value <= arrival) search.elements.check_out_date.value = nextDay; } previewStay(); });
    search.addEventListener('submit', async (event) => {
      event.preventDefault(); if (state.busy || state.pending) return; showFormError($('#daily-search-error')); if (!search.reportValidity()) return;
      let submitted; try { submitted = range(formValues(search)); } catch (error) { showFormError($('#daily-search-error'), error.message); return; }
      const revision = searchGate.next(); clearQuote(); state.search = null; $('#daily-room-grid').replaceChildren(); $('#daily-results-note').textContent = 'กำลังตรวจห้องว่าง…'; setBusy(search.querySelector('[type="submit"]'), true);
      try {
        const items = list(await api(`/api/public/daily/availability?${new URLSearchParams(submitted)}`)); if (!searchGate.current(revision)) return;
        if (!Array.isArray(items) || !items.every((room) => Number.isSafeInteger(room.id) && room.id > 0 && moneyValue(room.nightly_rate))) throw new Error('รายการห้องไม่ครบ กรุณาลองค้นหาใหม่');
        state.search = submitted; $('#daily-results-note').textContent = items.length ? `พบ ${items.length} ห้องที่ว่างตลอดช่วงพัก เลือกห้องเพื่อดูยอดรวมก่อนจอง` : 'ไม่พบห้องสำหรับวันที่และจำนวนผู้พักนี้ ลองเปลี่ยนวันที่หรือจำนวนคน หรือสอบถามหอพักผ่าน LINE';
        items.forEach((room) => {
          validQuote(room, { ...submitted, room_id: room.id }, false);
          const card = create('article', 'room-card'), section = create('div', 'room-card-body');
          section.append(create('h3', '', `ห้อง ${room.room_code}`), create('p', '', `${room.room_type} · ชั้น ${room.floor}`), create('strong', 'room-price', `${money(room.nightly_rate)}/คืน`), create('p', 'daily-stay-preview', `ยอดรวม ${money(room.total_amount)} สำหรับ ${room.nights} คืน รวมค่าประกันแล้ว`));
          const button = create('button', 'button button-primary button-full', 'ตรวจราคาและจองห้องนี้'); button.type = 'button'; button.addEventListener('click', () => chooseRoom(room)); section.append(button);
          if (/^\/assets\/images\/rooms\/room-(?:standard|deluxe|suite|studio)\.jpg$/.test(room.image_url || '')) { const media = create('div', 'room-media'), image = create('img'); image.src = room.image_url; image.alt = `ห้อง ${room.room_code}`; image.loading = 'lazy'; media.append(image); card.append(media); }
          section.append(create('p', 'field-hint', `พักได้ไม่เกิน ${room.max_guests || submitted.guests} คน · ค่าประกัน ${money(room.deposit_amount)}`)); if (room.description) section.append(create('p', 'room-description', room.description)); card.append(section); $('#daily-room-grid').append(card);
        });
      } catch (error) { if (searchGate.current(revision)) { state.search = null; $('#daily-room-grid').replaceChildren(); $('#daily-results-note').textContent = 'ยังตรวจห้องว่างไม่ได้'; showFormError($('#daily-search-error'), error); } }
      finally { if (searchGate.current(revision)) setBusy(search.querySelector('[type="submit"]'), false); }
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault(); if (state.busy || state.pending || !state.quote || !form.reportValidity()) return;
      if (expiry(state.quote.quote_expires_at) <= Date.now()) { clearQuote(); showFormError($('#daily-booking-error'), 'ราคาที่ตรวจหมดอายุแล้ว ปิดหน้าต่างและเลือกห้องเพื่อตรวจราคาอีกครั้ง'); return; }
        state.pending = { ...range(state.quote), room_id: state.quote.room_id, ...formValues(form), quote_token: state.quote.quote_token, idempotency_key: uuid() };
      saveRecovery(pendingStorageKey, state.pending); submitPending();
    });
    $('#daily-recover-submit').addEventListener('click', submitPending); $('#daily-detail-refresh').addEventListener('click', loadDetail);
    $('#daily-copy-reference').addEventListener('click', async () => { if (!state.detailReady || !state.booking) return; try { await navigator.clipboard.writeText(state.booking.reference_no); $('#daily-copy-reference').textContent = 'คัดลอกหมายเลขอ้างอิงแล้ว'; } catch (_) { showFormError($('#daily-detail-error'), 'คัดลอกอัตโนมัติไม่ได้ กรุณาแตะค้างที่หมายเลขอ้างอิงแล้วคัดลอก'); } });
    $('#daily-forget-booking').addEventListener('click', () => { if (state.busy) return; if (state.uploadRecovery) { showFormError($('#daily-detail-error'), 'ยังไม่ทราบผลสลิปเดิม กรุณาตรวจผลก่อนล้างสิทธิ์จากแท็บนี้'); return; } state.access = null; state.booking = null; state.payment = null; writeStorage(accessStorageKey, null); detailGate.invalidate(); $('#daily-detail').hidden = true; resetPayment(); });
    $('#daily-slip-recovery-refresh').addEventListener('click', loadDetail);
    $('#daily-load-qr').addEventListener('click', async () => {
      if (state.busy || state.uploadRecovery || !state.detailReady || !state.access || !state.payment || !paymentGuard(state.booking, state.payment).mayTransfer) return;
      state.busy = true; const access = { ...state.access }; setBusy($('#daily-load-qr'), true); showFormError($('#daily-payment-error'));
      try { const qr = await api(`/api/public/daily/bookings/${access.id}/promptpay`, { method: 'POST', body: {}, headers: { 'X-Booking-Access-Token': access.token } });
        validTransfer(qr, state.booking);
        const canvas = create('canvas'); canvas.setAttribute('aria-label', 'QR ชำระการจองรายวัน'); await renderQrCanvas(await getQrLibrary(), canvas, qr.payload, { width: 240, margin: 2 }); $('#daily-qr-stage').replaceChildren(canvas, create('strong', '', `โอน ${money(qr.amount)}`), create('p', '', `ผู้รับ ${qr.name || qr.target}`));
      } catch (error) { $('#daily-qr-stage').replaceChildren(); showFormError($('#daily-payment-error'), error); }
      finally { state.busy = false; setBusy($('#daily-load-qr'), false); if (state.payment) renderPayment(); }
    });
    $('#daily-slip-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const slipForm = event.currentTarget;
      if (state.busy || !state.detailReady || !state.payment || !slipForm.reportValidity()) return;
      const guard = paymentGuard(state.booking, state.payment), mayReplayEvidence = state.uploadRecovery && state.payment.has_transfer_instruction && state.payment.capabilities.slip_verification_ready && !guard.pending && !guard.paid;
      if (!guard.mayUpload && !mayReplayEvidence) return;
      const file = slipForm.elements.slip.files[0]; if (!file || file.size > 4 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { showFormError($('#daily-payment-error'), 'เลือกไฟล์ JPEG, PNG หรือ WebP ไม่เกิน 4 MiB'); return; }
      const upload = new FormData(slipForm), wasRecovery = !!state.uploadRecovery; state.busy = true; setFormFieldsBusy(slipForm, true); setBusy(slipForm.querySelector('[type="submit"]'), true); $('#daily-load-qr').disabled = true; showFormError($('#daily-payment-error'));
      try {
        const digest = await fileDigest(file);
        if (state.uploadRecovery && state.uploadRecovery.sha256 !== digest) throw new Error('กรุณาเลือกไฟล์เดิมที่ส่งค้างไว้ เพื่ออ่านผลคำขอเดิมโดยไม่ส่งหลักฐานใหม่');
        if (!state.uploadRecovery) { state.uploadRecovery = { booking_id: state.access.id, prior_payment_id: state.payment.payment?.id ?? null, sha256: digest }; saveRecovery(uploadStorageKey, state.uploadRecovery); }
        uploadRecoveryNotice();
        validPaymentRecord(await api(`/api/public/daily/bookings/${state.access.id}/slip`, { method: 'POST', body: upload, headers: { 'X-Booking-Access-Token': state.access.token } }), state.access.id);
        clearUploadRecovery(); slipForm.reset();
      }
      catch (error) { if (!wasRecovery && error.status >= 400 && error.status < 500 && !['MUTATION_OUTCOME_UNKNOWN', 'REQUEST_IN_PROGRESS'].includes(error.details?.code)) clearUploadRecovery(); showFormError($('#daily-payment-error'), error); }
      finally { state.busy = false; setFormFieldsBusy(slipForm, false); setBusy(slipForm.querySelector('[type="submit"]'), false); await loadDetail(); }
    });
    window.setInterval(() => { pendingHold(); if (state.quote && expiry(state.quote.quote_expires_at) <= Date.now() && !state.busy) { clearQuote(); showFormError($('#daily-booking-error'), 'ราคาที่ตรวจหมดอายุแล้ว ปิดหน้าต่างและเลือกห้องเพื่อตรวจราคาอีกครั้ง'); } }, 1000);
    window.setInterval(() => { if (document.visibilityState === 'visible' && (state.pollPayment || state.uploadRecovery) && !state.busy) loadDetail(); }, 10000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && state.access) loadDetail(); });
    recovery(); uploadRecoveryNotice(); if (state.access) loadDetail().then(() => $('#daily-detail').scrollIntoView?.({ block: 'start' })); return { loadDetail };
  }
  function initAdmin(h) {
    const { $, create, api, showFormError, errorMessage, money, isoToday, isoDateOffsetDays, formatDate, setBusy, setFormFieldsBusy, setDialogBusy, openDialog, closeDialog, confirmAction } = h;
    if (!$('[data-daily-admin]')) return null;
    const toast = feedback(h.toast);
    const filter = $('#daily-admin-filter'), createForm = $('#daily-owner-create-form'), loadGate = gate(), detailGate = gate(), ownerQuoteGate = gate();
    const state = { bookings: [], bookingOffset: 0, bookingHasMore: false, bookingQuery: null, bookingPageRequest: null, rooms: [], ready: false, busy: false, detail: null, payment: null, detailReady: false, createPending: readStorage(ownerCreateStorageKey), ownerQuote: null, financePending: readStorage(ownerFinanceStorageKey), proofPending: readStorage(ownerProofStorageKey), closePayment: null, restorePayment: null, blockRoom: null, keys: new Map() };
    try { if (state.createPending) { range(state.createPending); if (typeof state.createPending.quote_token !== 'string' || typeof state.createPending.idempotency_key !== 'string') throw new Error('invalid pending create'); } } catch (_) { state.createPending = null; }
    try { if (state.financePending) { validBooking(state.financePending.booking); if (!['cash', 'refund', 'deposit-settlement', 'close'].includes(state.financePending.action) || !Number.isSafeInteger(state.financePending.payload?.expected_version) || typeof state.financePending.payload?.idempotency_key !== 'string') throw new Error('invalid pending finance'); } } catch (_) { state.financePending = null; }
    try { if (state.proofPending) { validBooking(state.proofPending.booking); if (!['upload', 'restore'].includes(state.proofPending.action) || !/^[0-9a-f]{64}$/.test(state.proofPending.sha256 || '')) throw new Error('invalid pending proof'); } } catch (_) { state.proofPending = null; }
    function ownerRecoveryNotice() { $('#daily-owner-recovery').hidden = !state.createPending && !state.financePending && !state.proofPending; }
    function saveOwnerPending() {
      const stored = [writeStorage(ownerCreateStorageKey, state.createPending), writeStorage(ownerFinanceStorageKey, state.financePending ? { booking_id: state.financePending.booking_id, action: state.financePending.action, payload: state.financePending.payload, booking: state.financePending.booking, payment_id: state.financePending.payment_id } : null), writeStorage(ownerProofStorageKey, state.proofPending)]; ownerRecoveryNotice();
      if (stored.includes(false) && !$('#daily-owner-recovery').hidden) $('#daily-owner-recovery-text').textContent = 'เบราว์เซอร์เก็บคำขอเดิมหลังรีเฟรชไม่ได้ กรุณาเปิดแท็บนี้ไว้และตรวจผลรายการเดิมให้เสร็จ ห้ามรับหรือคืนเงินจริงซ้ำ';
    }
    filter.elements.from.value = isoToday(); filter.elements.to.value = isoDateOffsetDays(14);
    function setupState() { $('#daily-setup-guide').hidden = !state.ready || state.rooms.length > 0; $('#daily-owner-create').disabled = !state.ready || (!state.rooms.length && !state.createPending && !state.financePending && !state.proofPending); }
    function key(action, payload) { const hash = `${action}:${JSON.stringify(payload)}`; if (!state.keys.has(hash)) state.keys.set(hash, uuid()); return state.keys.get(hash); }
    function button(label, handler, className = 'button button-secondary button-small') { const node = create('button', className, label); node.type = 'button'; node.addEventListener('click', handler); return node; }
    function resetBookingPaging() {
      state.bookingOffset = 0; state.bookingHasMore = false; state.bookingQuery = null; state.bookingPageRequest = null;
      setBusy($('#daily-booking-load-more'), false); $('#daily-booking-load-more').hidden = true;
    }
    function currentPayment(id) {
      const row = [state.payment?.payment, ...(state.payment?.closed_payments || [])].find(value => value?.id === id);
      return row?.booking_id === state.detail?.id ? row : null;
    }
    function canClose(row) { return row?.method === 'slip' && row.status === 'pending' && row.can_close === true && row.verifying === false; }
    function canRestore(row) { return row?.method === 'slip' && row.can_restore === true && row.verifying === false && row.evidence_available === false; }
    function actionCell(booking) {
      const wrap = create('div', 'table-actions'); wrap.append(button('รายละเอียด / การเงิน', () => openDetail(booking)));
      const actions = bookingActions(booking, isoToday());
      actions.forEach(([label, action]) => wrap.append(button(label, () => transition(booking, action, label)))); return wrap;
    }
    function drawBookings() {
      $('#daily-admin-rows').replaceChildren(); state.bookings.forEach((booking) => { const tr = create('tr'); const person = create('div'); person.append(create('strong', '', booking.reference_no), create('p', '', `${booking.full_name} · ${booking.phone}`)); [person, booking.room_code, `${formatDate(booking.check_in_date)} / ${formatDate(booking.check_out_date)}`, `${booking.guests} คน / ${booking.nights} คืน`, money(booking.total_amount), statusNames[booking.status], actionCell(booking)].forEach((value, index) => { const td = create('td'); td.dataset.label = ['การจอง / ผู้พัก', 'ห้อง', 'เช็กอิน / เช็กเอาต์', 'ผู้พัก / จำนวนคืน', 'ยอดรวม', 'สถานะ', 'จัดการ'][index]; typeof value === 'object' ? td.append(value) : td.textContent = String(value); tr.append(td); }); $('#daily-admin-rows').append(tr); });
      $('#daily-admin-note').textContent = `แสดง ${state.bookings.length} รายการตามช่วงและสถานะที่เลือก${state.bookingHasMore ? ' · ยังมีรายการ กดโหลดเพิ่มเติมด้านล่าง' : ' · ครบตามตัวกรองแล้ว'}`;
      if (!state.bookings.length) { const tr = create('tr'), td = create('td', 'daily-empty-state', state.rooms.length ? 'ไม่มีการจองตามช่วงวันที่และสถานะนี้ เปลี่ยนตัวกรองหรือกด “เพิ่มการจองรายวัน”' : 'ยังไม่มีการจอง เริ่มจากตั้งห้องรายวันตามคำแนะนำด้านบน'); td.colSpan = 7; tr.append(td); $('#daily-admin-rows').append(tr); }
      $('#daily-booking-load-more').hidden = !state.bookingHasMore;
    }
    function drawCalendar(data) {
      const rows = state.rooms, bookings = data?.items || data?.bookings || data?.reservations, blocks = data?.blocks;
      if (!Array.isArray(rows) || !Array.isArray(bookings) || !Array.isArray(blocks)) throw new Error('ข้อมูลปฏิทินไม่ครบ');
      if (!rows.length) { $('#daily-calendar').replaceChildren(create('p', 'daily-empty-state', 'ปฏิทินจะแสดงหลังตั้งห้องรายวันแล้ว')); $('#daily-housekeeping').replaceChildren(create('p', 'field-hint', 'ตั้งห้องรายวันก่อน จึงจัดการทำความสะอาดและช่วงปิดขายได้')); return; }
      const from = filter.elements.from.value, to = filter.elements.to.value, days = [];
      for (let day = from; day < to && days.length < 90; day = new Date(Date.parse(`${day}T00:00:00Z`) + 86400000).toISOString().slice(0, 10)) days.push(day);
      const table = create('table'), head = create('thead'), heading = create('tr'); heading.append(create('th', '', 'ห้อง')); days.forEach((day) => heading.append(create('th', '', day.slice(5)))); head.append(heading); table.append(head); const body = create('tbody');
      rows.forEach((room) => { const tr = create('tr'); tr.append(create('th', '', room.room_code)); days.forEach((day) => { const stay = calendarStay(bookings, room.id, day, isoToday()); const block = blocks.find((b) => Number(b.room_id) === room.id && (b.check_in_date || b.start_date) <= day && day < (b.check_out_date || b.end_date) && !b.released_at); const unavailableToday = day === isoToday() && room.housekeeping_status === 'cleaning'; const overdue = stay?.status === 'checked_in' && stay.check_out_date <= isoToday() && day >= isoToday(); const cell = create('td', 'daily-calendar-cell', stay ? `${overdue ? 'รอเช็กเอาต์เกินกำหนด' : statusNames[stay.status]} ${stay.reference_no}` : block ? 'ปิดขาย' : unavailableToday ? 'รอทำความสะอาด' : 'ว่าง'); cell.dataset.booked = String(!!stay); cell.dataset.blocked = String(!!block || unavailableToday); tr.append(cell); }); body.append(tr); }); table.append(body); $('#daily-calendar').replaceChildren(table);
      const operations = $('#daily-housekeeping'); operations.replaceChildren();
      state.rooms.forEach((room) => { const card = create('article', 'panel stack-form'); card.append(create('strong', '', `ห้อง ${room.room_code}`), create('span', '', room.status === 'occupied' ? 'มีผู้พักอยู่' : room.housekeeping_status === 'cleaning' ? 'รอทำความสะอาด' : 'ทำความสะอาดแล้ว')); if (room.housekeeping_status === 'cleaning') { const payload = { expected_version: Number(room.housekeeping_version) }; payload.idempotency_key = key(`ready:${room.id}`, payload); card.append(button('ยืนยันทำความสะอาดเสร็จ', () => roomAction(`/api/admin/daily/rooms/${room.id}/ready`, payload, 'ยืนยันทำความสะอาดเสร็จ'))); } card.append(button('ปิดขายช่วงวัน', () => blockRoom(room))); blocks.filter((b) => Number(b.room_id) === room.id && !b.released_at).forEach((block) => { const payload = { expected_version: Number(block.version) }; payload.idempotency_key = key(`release:${block.id}`, payload); card.append(create('p', '', `${block.start_date} / ${block.end_date}: ${block.reason || 'ปิดขาย'}`), button('ยกเลิกช่วงปิดขาย', () => roomAction(`/api/admin/daily/blocks/${block.id}/release`, payload, 'ยกเลิกช่วงปิดขาย'))); }); operations.append(card); });
    }
    async function load() {
      if (state.busy) return; const revision = loadGate.next(); resetBookingPaging(); state.ready = false; state.bookings = []; state.rooms = []; setupState(); $('#daily-admin-rows').replaceChildren(); $('#daily-calendar').replaceChildren(); $('#daily-housekeeping').replaceChildren(); $('#daily-admin-note').textContent = 'กำลังโหลดรายการและปฏิทิน…'; showFormError($('#daily-admin-error'));
      for (const name of ['arrivals', 'departures', 'pending', 'cleaning']) $(`[data-daily-count="${name}"]`).textContent = '—';
      const query = formValues(filter), span = (Date.parse(query.to) - Date.parse(query.from)) / 86400000;
      if (!query.from || !query.to || span < 1 || span > 90) { showFormError($('#daily-admin-error'), 'เลือกช่วงวันที่ 1–90 วัน'); return; }
      const results = await Promise.allSettled([api(`/api/admin/daily/bookings?${new URLSearchParams(query)}`), api(`/api/admin/daily/calendar?${new URLSearchParams({ from: query.from, to: query.to })}`), api('/api/admin/rooms'), api(`/api/admin/daily/bookings?${new URLSearchParams({ from: isoDateOffsetDays(-1), to: isoDateOffsetDays(1) })}`)]); if (!loadGate.current(revision)) return;
      try {
        for (const result of results) if (result.status !== 'fulfilled') throw result.reason;
        const page = bookingPage(results[0].value), rooms = list(results[2].value) || results[2].value?.rooms;
        if (!Array.isArray(rooms)) throw new Error('ข้อมูลห้องและการจองไม่ครบ');
        state.bookings = page.items; state.bookingOffset = page.nextOffset; state.bookingHasMore = page.hasMore; state.bookingQuery = { ...query }; state.rooms = rooms.filter((room) => room.rental_mode === 'daily'); state.ready = true; drawBookings(); drawCalendar(results[1].value);
        const all = results[1].value.items, todayItems = list(results[3].value), today = isoToday(); if (!Array.isArray(todayItems)) throw new Error('ข้อมูลผู้เข้าออกวันนี้ไม่ครบ');
        const values = { arrivals: todayItems.filter((b) => b.check_in_date === today && ['pending', 'confirmed'].includes(b.status)).length, departures: all.filter((b) => b.check_out_date <= today && b.status === 'checked_in').length, pending: all.filter((b) => b.status === 'pending').length, cleaning: state.rooms.filter((r) => r.housekeeping_status === 'cleaning').length };
        Object.entries(values).forEach(([name, value]) => { $(`[data-daily-count="${name}"]`).textContent = value; });
        setupState();
      } catch (error) { resetBookingPaging(); state.ready = false; state.bookings = []; state.rooms = []; setupState(); $('#daily-admin-rows').replaceChildren(); $('#daily-calendar').replaceChildren(); $('#daily-housekeeping').replaceChildren(); $('#daily-admin-note').textContent = 'ยังตรวจสถานะล่าสุดไม่ได้ กรุณารีเฟรชก่อนทำรายการ'; showFormError($('#daily-admin-error'), error); }
    }
    async function loadMoreBookings() {
      if (state.busy || !state.ready || !state.bookingHasMore || !state.bookingQuery || state.bookingPageRequest) return;
      const request = { revision: loadGate.next(), offset: state.bookingOffset, query: { ...state.bookingQuery } };
      state.bookingPageRequest = request; setBusy($('#daily-booking-load-more'), true, 'กำลังโหลดเพิ่มเติม…'); showFormError($('#daily-admin-error'));
      try {
        const data = await api(`/api/admin/daily/bookings?${new URLSearchParams({ ...request.query, offset: request.offset, limit: 500 })}`);
        if (state.bookingPageRequest !== request || !loadGate.current(request.revision) || !state.ready || state.busy) return;
        const page = bookingPage(data, request.offset), known = new Map(state.bookings.map(item => [item.id, item]));
        for (const item of page.items) { const old = known.get(item.id); if (!old || Number(item.version) > Number(old.version)) known.set(item.id, item); }
        state.bookings = [...known.values()]; state.bookingOffset = page.nextOffset; state.bookingHasMore = page.hasMore; drawBookings();
      } catch (error) { if (state.bookingPageRequest === request && loadGate.current(request.revision)) showFormError($('#daily-admin-error'), error); }
      finally { if (state.bookingPageRequest === request) { state.bookingPageRequest = null; setBusy($('#daily-booking-load-more'), false); $('#daily-booking-load-more').hidden = !state.bookingHasMore; } }
    }
    $('#daily-booking-load-more').addEventListener('click', loadMoreBookings);
    async function transition(booking, action, label) {
      if (state.busy || !state.ready || !state.bookings.includes(booking)) return; state.busy = true;
      let failure = null;
      try {
        if (action === 'check-out') { const data = await api(`/api/admin/daily/bookings/${booking.id}/payment`); const guard = paymentGuard(booking, data); if (data.version !== Number(booking.version) || data.booking_status !== booking.status) throw new Error('สถานะการจองเปลี่ยนแล้ว กรุณาโหลดรายการล่าสุดก่อนเช็กเอาต์'); if (!guard.paid || Number(data.deposit_remaining) > 0) { state.busy = false; await openDetail(booking); showFormError($('#daily-owner-detail-error'), !guard.paid ? 'ต้องตรวจรับเงินเต็มยอดก่อนเช็กเอาต์ กรุณาดำเนินการกับหลักฐานเดิมโดยไม่รับเงินซ้ำ' : 'ก่อนเช็กเอาต์ กรุณาบันทึกคืนค่าประกันที่คืนจริงหรือยอดหักจากผลตรวจห้องให้ครบ'); return; } }
        if (!await confirmAction(label, `${booking.reference_no} · ห้อง ${booking.room_code} · ${booking.full_name}`, label, ['cancel', 'no-show'].includes(action))) return;
        const payload = { expected_version: Number(booking.version) }; if (['cancel', 'no-show'].includes(action)) { const reason = window.prompt('เหตุผล (อย่างน้อย 3 ตัวอักษร)'); if (reason === null) return; if (reason.trim().length < 3) throw new Error('กรอกเหตุผลอย่างน้อย 3 ตัวอักษร'); payload.reason = reason.trim(); } payload.idempotency_key = key(`${booking.id}:${action}`, payload);
        $('#daily-admin-rows').setAttribute('inert', ''); await api(`/api/admin/daily/bookings/${booking.id}/${action}`, { method: 'POST', body: payload }); toast(`${label}แล้ว`);
      } catch (error) { failure = errorMessage(error); }
      finally { state.busy = false; $('#daily-admin-rows').removeAttribute('inert'); await load(); if (failure) showFormError($('#daily-admin-error'), failure); }
    }
    async function roomAction(url, payload, label) {
      if (state.busy || !state.ready) return; state.busy = true;
      let failure = null;
      try { if (!await confirmAction(label, 'ตรวจห้องและช่วงวันที่ให้ถูกต้องก่อนยืนยัน', label)) return; await api(url, { method: 'POST', body: payload }); toast(`${label}แล้ว`); }
      catch (error) { failure = errorMessage(error); }
      finally { state.busy = false; await load(); if (failure) showFormError($('#daily-admin-error'), failure); }
    }
    function blockRoom(room) {
      if (!state.ready || state.busy) return; state.blockRoom = room; const form = $('#daily-block-form'); form.reset(); form.elements.start_date.value = filter.elements.from.value; form.elements.start_date.min = isoToday(); form.elements.end_date.value = filter.elements.to.value; $('#daily-block-room').textContent = `ห้อง ${room.room_code}`; h.rememberDialogDraft?.(form); showFormError($('#daily-block-error')); openDialog($('#daily-block-dialog'));
    }
    $('#daily-block-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; if (!state.blockRoom || !state.ready || state.busy || !form.reportValidity()) return; let payload;
      try { const fields = formValues(form), dates = range({ check_in_date: fields.start_date, check_out_date: fields.end_date, guests: 1 }, 366); payload = { start_date: dates.check_in_date, end_date: dates.check_out_date, reason: fields.reason }; payload.idempotency_key = key(`block:${state.blockRoom.id}`, payload); } catch (error) { showFormError($('#daily-block-error'), error.message); return; }
      state.busy = true; const dialog = $('#daily-block-dialog'); setDialogBusy(dialog, true); setFormFieldsBusy(form, true); setBusy(form.querySelector('[type="submit"]'), true); showFormError($('#daily-block-error'));
      try { await api(`/api/admin/daily/rooms/${state.blockRoom.id}/blocks`, { method: 'POST', body: payload }); setDialogBusy(dialog, false); closeDialog(dialog); form.reset(); h.rememberDialogDraft?.(form); toast('ปิดขายช่วงที่เลือกแล้ว'); }
      catch (error) { showFormError($('#daily-block-error'), error); }
      finally { state.busy = false; setDialogBusy(dialog, false); setFormFieldsBusy(form, false); setBusy(form.querySelector('[type="submit"]'), false); await load(); }
    });
    async function openDetail(booking) { if (state.busy || !state.ready) return; if (state.financePending || state.proofPending) { restoreOwnerPending(); toast('ยังไม่ทราบผลรายการเดิม กรุณากดตรวจผลรายการเดิมก่อนเปิดการจองอื่น', 'error'); return; } state.detail = booking; state.payment = null; state.detailReady = false; state.closePayment = null; state.restorePayment = null; for (const id of ['daily-cash-form', 'daily-refund-form', 'daily-deposit-form', 'daily-close-form', 'daily-owner-upload-form', 'daily-owner-restore-form']) { $(`#${id}`).reset(); h.rememberDialogDraft?.($(`#${id}`)); } summary(h, $('#daily-owner-detail-summary'), booking); openDialog($('#daily-owner-detail-dialog')); await loadPayment(); }
    async function loadPayment() {
      if (!state.detail || state.busy) return; const revision = detailGate.next(), id = state.detail.id; state.detailReady = false; $('#daily-owner-next-step').hidden = true; $('#daily-owner-payment-totals').replaceChildren(); $('#daily-owner-payment-actions').hidden = true; $('#daily-owner-slip-actions').replaceChildren(); $('#daily-owner-qr-stage').replaceChildren(); $('#daily-owner-load-qr').disabled = true; $('#daily-owner-payment-status').textContent = 'กำลังตรวจยอดและหลักฐาน…'; showFormError($('#daily-owner-detail-error'));
      try { const data = await api(`/api/admin/daily/bookings/${id}/payment`); if (!detailGate.current(revision) || state.detail.id !== id) return; if (statusNames[data.booking_status] && Number.isInteger(data.version)) { state.detail = { ...state.detail, status: data.booking_status, version: data.version }; summary(h, $('#daily-owner-detail-summary'), state.detail); } const guard = paymentGuard(state.detail, data); state.payment = data; state.detailReady = true; $('#daily-owner-payment-status').textContent = guard.paid ? 'ได้รับเงินครบแล้ว' : guard.pending ? 'กำลังตรวจสลิป ห้ามรับยอดซ้ำ' : 'ยังไม่ได้รับเงินครบ'; paymentTotals(h, $('#daily-owner-payment-totals'), data); showGuidance(h, $('#daily-owner-next-step'), state.detail, data, !!state.financePending || !!state.proofPending, true);
        if (data.has_transfer_instruction && !guard.paid && !guard.pending) $('#daily-owner-payment-status').textContent += ' · ออก QR รับโอนแล้ว ห้ามรับเงินสดซ้ำ กรุณาดูรายการโอนเดิมก่อน';
        if (data.has_closed_unresolved) $('#daily-owner-payment-status').textContent += ' · มีหลักฐานพักการตรวจที่ยังต้องทบทวน ห้ามรับเงินหรือให้โอนซ้ำ';
        if (state.proofPending?.action === 'upload' && uploadResolved(state.proofPending, data.payment)) { state.proofPending = null; saveOwnerPending(); $('#daily-owner-detail-dialog').dataset.preserveData = 'false'; }
        $('#daily-owner-payment-actions').hidden = false; $('#daily-cash-form').hidden = state.financePending?.action !== 'cash' && (data.cash_available !== true || guard.paid || guard.pending || state.detail.status !== 'pending' || expiry(state.detail.expires_at) <= Date.now()); $('#daily-refund-form').hidden = state.financePending?.action !== 'refund' && !(Number(data.refundable_amount) > 0); $('#daily-deposit-form').hidden = state.financePending?.action !== 'deposit-settlement' && (state.detail.status !== 'checked_in' || !(Number(data.deposit_remaining) > 0));
        $('#daily-close-form').hidden = state.financePending?.action !== 'close'; $('#daily-owner-restore-form').hidden = state.proofPending?.action !== 'restore'; $('#daily-owner-upload-form').hidden = state.proofPending?.action !== 'upload' && !(data.can_owner_upload === true && data.capabilities.slip_verification_ready);
        $('#daily-owner-load-qr').disabled = !guard.mayTransfer || !!state.financePending || !!state.proofPending;
        $('#daily-owner-bank-help').textContent = guard.paid ? 'รับเงินครบแล้ว ไม่ต้องรับเพิ่มหรือให้ผู้พักโอนซ้ำ ตรวจยอดคืนและค่าประกันตามสถานะล่าสุด' : guard.pending ? 'กำลังตรวจสลิปที่ส่งแล้ว รอผลก่อน ห้ามรับเงินสดหรือให้ผู้พักโอนซ้ำ' : guard.closed ? 'ทบทวนหลักฐานที่พักการตรวจหรือซ่อมไฟล์ต้นฉบับก่อน ห้ามรับเงินหรือให้โอนซ้ำ' : !data.capabilities.slip_verification_ready ? (data.has_transfer_instruction ? 'ระบบตรวจสลิปยังไม่พร้อม กรุณาตั้งค่าให้ครบแล้วตรวจหลักฐานเดิม ห้ามรับเงินสดซ้ำ' : data.cash_available ? 'ยังไม่ได้เปิดรับโอนรายวัน รับเงินสดพร้อมใบรับเงินได้ หรือไปหน้าตั้งค่าเพื่อเปิดระบบตรวจสลิปก่อน' : 'ยังรับเงินเพิ่มไม่ได้ ตรวจสถานะและหลักฐานเดิมก่อน') : data.has_transfer_instruction ? 'มี QR พร้อมยอดเดิมแล้ว หากผู้พักโอนแล้วให้ส่งหลักฐานเดิม ห้ามรับเงินสดซ้ำ' : 'เลือกรับเงินสดพร้อมใบรับเงิน หรือแสดง QR เพื่อให้ผู้พักโอนตามยอดที่ระบบกันไว้';
        $('#daily-refund-form').elements.amount.max = data.refundable_amount; $('#daily-deposit-form').elements.retained_amount.max = data.deposit_remaining;
        const evidenceRows = [data.payment, ...(Array.isArray(data.closed_payments) ? data.closed_payments : [])].filter((row, index, rows) => row?.method === 'slip' && rows.findIndex(other => other?.id === row.id) === index);
        if (state.proofPending?.action === 'restore' && evidenceRows.some(row=>row.id===state.proofPending.payment_id&&row.evidence_available===true)) { state.proofPending=null;saveOwnerPending();$('#daily-owner-detail-dialog').dataset.preserveData='false';$('#daily-owner-restore-form').hidden=true; }
        for (const row of evidenceRows) {
          validPaymentRecord(row, id); const group = create('div', 'stack-form'); group.append(create('strong', '', `หลักฐาน ${row.id} · ${row.status === 'closed' ? 'พักการตรวจ' : row.status === 'pending' ? 'รอตรวจ' : row.status === 'verified' ? 'ตรวจผ่าน' : 'ไม่ผ่าน'}`));
          if (row.evidence_available === true) { const a = create('a', 'button button-secondary', 'ดูหลักฐานสลิป'); a.href = `/api/admin/daily/payments/${row.id}/slip`; a.target = '_blank'; a.rel = 'noopener'; group.append(a); }
          if (row.can_retry === true) { const retry=button(row.status === 'closed' ? 'กลับมาตรวจหลักฐานเดิม' : 'ตรวจสลิปเดิมซ้ำ', () => finance('retry', {}, null, row.id)); retry.disabled=!data.capabilities.slip_verification_ready; group.append(retry); if(retry.disabled)group.append(create('p','field-hint','ตั้งค่าระบบตรวจสลิปให้พร้อมก่อน แล้วกลับมาตรวจหลักฐานเดิม ห้ามให้ผู้พักโอนซ้ำ')); }
          if (row.can_close === true) group.append(button('พักการตรวจหลักฐาน', () => { const selected = currentPayment(row.id); if (!state.detailReady || !canClose(selected)) return; state.closePayment = selected; $('#daily-close-summary').textContent = `พักการตรวจหลักฐาน ${selected.id} ของการจองนี้`; $('#daily-close-form').hidden = false; $('#daily-close-form').scrollIntoView?.({ block: 'center' }); }));
          if (row.can_restore === true) { group.append(create('p', 'field-hint', 'ไฟล์หลักฐานเดิมอ่านไม่ได้ กรุณาซ่อมด้วยไฟล์ต้นฉบับก่อนตรวจซ้ำ'), button('เลือกไฟล์ต้นฉบับเพื่อซ่อม', () => { const selected = currentPayment(row.id); if (!state.detailReady || !canRestore(selected)) return; state.restorePayment = selected; $('#daily-owner-restore-summary').textContent = `ซ่อมหลักฐาน ${selected.id} ของการจองนี้`; $('#daily-owner-restore-form').hidden = false; $('#daily-owner-restore-form').scrollIntoView?.({ block: 'center' }); })); }
          $('#daily-owner-slip-actions').append(group);
        }
      } catch (error) { if (detailGate.current(revision)) { state.detailReady = false; $('#daily-owner-payment-actions').hidden = true; $('#daily-owner-payment-status').textContent = 'ยังตรวจยอดล่าสุดไม่ได้'; showFormError($('#daily-owner-detail-error'), error); } }
    }
    async function finance(action, payload, form = null, paymentId = state.payment?.payment?.id ?? null) {
      if (state.busy || !state.detailReady || !state.detail || !state.payment) return;
      if (state.financePending && (state.financePending.action !== action || state.financePending.booking_id !== state.detail.id)) { showFormError($('#daily-owner-detail-error'), 'ยังไม่ทราบผลรายการเงินเดิม กรุณากดตรวจผลรายการเดิมก่อนทำรายการใหม่'); return; }
      if (state.proofPending) { showFormError($('#daily-owner-detail-error'), 'ยังไม่ทราบผลหลักฐานเดิม กรุณาตรวจผลก่อนทำรายการเงินใหม่'); return; }
      state.busy = true; const booking = { ...state.detail }, dialog = $('#daily-owner-detail-dialog'); if (state.financePending) { payload = { ...state.financePending.payload }; paymentId = state.financePending.payment_id ?? paymentId; } else { payload.expected_version = Number(booking.version); payload.idempotency_key = key(`${booking.id}:${action}:${paymentId ?? ''}`, payload); } setDialogBusy(dialog, true); if (form) setFormFieldsBusy(form, true);
      let failure = null;
      const wasRecovery = !!state.financePending;
      try { if (!await confirmAction(wasRecovery ? 'ตรวจผลรายการเงินเดิม' : 'ยืนยันบันทึกรายการเงิน', `การจอง ${booking.reference_no} · ${wasRecovery ? 'ใช้หมายเลขคำขอเดิมเพื่ออ่านผล ไม่ต้องรับหรือคืนเงินจริงเพิ่ม' : action === 'cash' ? 'ยืนยันว่าได้รับเงินสดเต็มยอดแล้ว' : action === 'refund' ? 'ยืนยันว่าโอนเงินคืนตามยอดนี้แล้ว' : action === 'deposit-settlement' ? 'ยืนยันยอดหักค่าประกันตามผลตรวจห้อง' : 'ตรวจหลักฐานเดิมอีกครั้ง'}`)) return;
        if (form) { state.financePending = { booking_id: booking.id, action, payload: { ...payload }, booking: { ...booking }, form, payment_id: paymentId }; saveOwnerPending(); }
        const url = ['retry', 'close'].includes(action) ? `/api/admin/daily/payments/${paymentId}/${action}` : `/api/admin/daily/bookings/${booking.id}/${action}`;
        let result = null, historicalResult = false;
        if (wasRecovery) { const lookup=await api(`/api/admin/daily/bookings/${booking.id}/requests/${action}?${new URLSearchParams({key:payload.idempotency_key})}`);if(typeof lookup?.found!=='boolean'||lookup.booking_id!==booking.id||lookup.action!==action)throw new Error('ยังตรวจผลคำขอเดิมไม่ได้ กรุณาลองอ่านผลอีกครั้ง');if(lookup.found){result=lookup.result;historicalResult=true;} }
        if (!result) result=await api(url, { method: 'POST', body: action === 'retry' ? {} : payload });
        validFinanceOutcome(action, result, booking, payload, ['retry', 'close'].includes(action) ? paymentId : null, historicalResult); state.financePending = null; saveOwnerPending(); if (form) { form.reset(); h.rememberDialogDraft?.(form); } toast(action === 'close' ? 'พบประวัติพักการตรวจแล้ว กรุณาดูสถานะปัจจุบัน ห้ามให้โอนซ้ำ' : 'บันทึกรายการเงินแล้ว');
      } catch (error) { failure = errorMessage(error); const irreversibleCash = action === 'cash' && ['DAILY_BOOKING_CHANGED', 'DAILY_BOOKING_NOT_PAYABLE'].includes(error.details?.code); if ((irreversibleCash || !wasRecovery) && error.status >= 400 && error.status < 500 && !['MUTATION_OUTCOME_UNKNOWN', 'REQUEST_IN_PROGRESS'].includes(error.details?.code)) { state.financePending = null; saveOwnerPending(); } }
      finally { state.busy = false; setDialogBusy(dialog, false); dialog.dataset.preserveData = String(!!state.financePending); if (form && !state.financePending) setFormFieldsBusy(form, false); if (form) form.querySelector('[type="submit"]').textContent = state.financePending ? 'ตรวจผลรายการเงินเดิม' : action === 'cash' ? 'บันทึกรับเงินสดเต็มยอด' : action === 'refund' ? 'บันทึกเงินคืน' : action === 'close' ? 'ยืนยันพักการตรวจหลักฐานเดิม' : 'บันทึกการหักค่าประกัน'; await loadPayment(); await load(); if (failure) showFormError($('#daily-owner-detail-error'), `${failure}${state.financePending ? ' กรุณากดตรวจผลรายการเงินเดิม ห้ามรับหรือคืนเงินจริงซ้ำ' : ''}`); }
    }
    $('#daily-owner-detail-refresh').addEventListener('click', loadPayment);
    $('#daily-owner-load-qr').addEventListener('click', async () => {
      if(state.busy||state.financePending||state.proofPending||!state.detailReady||!state.payment||!paymentGuard(state.detail,state.payment).mayTransfer)return;
      const booking={...state.detail},dialog=$('#daily-owner-detail-dialog');let qr=null,canvas=null,failure=null;state.busy=true;setDialogBusy(dialog,true);setBusy($('#daily-owner-load-qr'),true);showFormError($('#daily-owner-detail-error'));
      try { qr=validTransfer(await api(`/api/admin/daily/bookings/${booking.id}/promptpay`,{method:'POST',body:{}}),booking);canvas=create('canvas');canvas.setAttribute('aria-label','QR การจองรายวันสำหรับส่งให้ผู้พัก');await h.renderQrCanvas(await h.getQrLibrary(),canvas,qr.payload,{width:240,margin:2}); }
      catch(error){failure=errorMessage(error);}
      finally{state.busy=false;setDialogBusy(dialog,false);setBusy($('#daily-owner-load-qr'),false);await loadPayment();if(canvas&&state.detailReady&&state.detail.id===booking.id&&paymentGuard(state.detail,state.payment).mayTransfer)$('#daily-owner-qr-stage').replaceChildren(canvas,create('strong','',`โอน ${money(qr.amount)}`),create('p','',`ผู้รับ ${qr.name||qr.target} · ตรวจยอดและชื่อผู้รับก่อนโอน`));if(failure)showFormError($('#daily-owner-detail-error'),failure);}
    });
    for (const [id, action] of [['daily-cash-form', 'cash'], ['daily-refund-form', 'refund'], ['daily-deposit-form', 'deposit-settlement']]) $(`#${id}`).addEventListener('submit', (event) => { event.preventDefault(); if (!event.currentTarget.reportValidity()) return; finance(action, formValues(event.currentTarget), event.currentTarget); });
    $('#daily-close-form').addEventListener('submit', event => {
      event.preventDefault(); if (!event.currentTarget.reportValidity()) return;
      const recovering = state.financePending?.action === 'close', selected = currentPayment(state.closePayment?.id);
      if (!recovering && (!state.detailReady || !canClose(selected))) { showFormError($('#daily-owner-detail-error'), 'สถานะหลักฐานที่จะพักการตรวจเปลี่ยนแล้ว กรุณาโหลดล่าสุดและเลือกหลักฐานนั้นอีกครั้ง'); return; }
      finance('close', formValues(event.currentTarget), event.currentTarget, recovering ? state.financePending.payment_id : selected.id);
    });
    async function submitOwnerProof(action, form) {
      if (state.busy || !state.detailReady || !state.detail || !state.payment || !form.reportValidity()) return;
      if (state.financePending || state.createPending) { showFormError($('#daily-owner-detail-error'), 'กรุณาตรวจผลรายการเดิมก่อนส่งหลักฐาน'); return; }
      if (state.proofPending && (state.proofPending.action !== action || state.proofPending.booking_id !== state.detail.id)) { showFormError($('#daily-owner-detail-error'), 'กรุณาตรวจผลหลักฐานเดิมก่อนเริ่มส่งหลักฐานอื่น'); return; }
      const paymentId = state.proofPending?.payment_id ?? (action === 'restore' ? state.restorePayment?.id : null);
      if (action === 'upload' && !state.proofPending && !(state.payment.can_owner_upload && state.payment.capabilities.slip_verification_ready)) return;
      const exactRestoreReplay = state.proofPending?.action === 'restore' && state.proofPending.booking_id === state.detail.id && state.proofPending.booking?.id === state.detail.id && state.proofPending.payment_id === paymentId;
      if (action === 'restore' && (!Number.isSafeInteger(paymentId) || paymentId < 1 || (!exactRestoreReplay && !canRestore(currentPayment(paymentId))))) { showFormError($('#daily-owner-detail-error'), 'สถานะหลักฐานที่จะซ่อมเปลี่ยนแล้ว กรุณาโหลดล่าสุดและเลือกหลักฐานนั้นอีกครั้ง'); return; }
      const file = form.elements.slip.files[0]; if (!file || file.size > 4 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { showFormError($('#daily-owner-detail-error'), 'เลือก JPEG, PNG หรือ WebP ไม่เกิน 4 MiB'); return; }
      const upload = new FormData(form), wasRecovery = !!state.proofPending, booking = { ...state.detail }, dialog = $('#daily-owner-detail-dialog'); state.busy = true; setDialogBusy(dialog, true); setFormFieldsBusy(form, true); setBusy(form.querySelector('[type="submit"]'), true); showFormError($('#daily-owner-detail-error'));
      let failure = null;
      try {
        const digest = await fileDigest(file); if (state.proofPending && digest !== state.proofPending.sha256) throw new Error('กรุณาเลือกไฟล์เดิมที่ส่งค้างไว้ เพื่ออ่านผลคำขอเดิม');
        if (!state.proofPending) { state.proofPending = { booking_id: booking.id, action, booking, prior_payment_id: state.payment.payment?.id ?? null, payment_id: paymentId ?? null, sha256: digest }; saveOwnerPending(); }
        const url = action === 'upload' ? `/api/admin/daily/bookings/${booking.id}/slip` : `/api/admin/daily/payments/${paymentId}/restore`;
        const result = validPaymentRecord(await api(url, { method: 'POST', body: upload }), booking.id); if (action === 'restore' && result.id !== paymentId) throw new Error('ผลซ่อมไม่ตรงหลักฐานเดิม กรุณาตรวจผลคำขอเดิม');
        state.proofPending = null; saveOwnerPending(); form.reset(); h.rememberDialogDraft?.(form); toast(action === 'upload' ? 'รับหลักฐานแล้ว กรุณาตรวจสถานะเงินและการจองล่าสุด' : 'ซ่อมหลักฐานเดิมแล้ว กรุณาตรวจสลิปเดิมซ้ำเพื่ออ่านผลเงิน');
      } catch (error) { failure = errorMessage(error); if (!wasRecovery && error.status >= 400 && error.status < 500 && !['MUTATION_OUTCOME_UNKNOWN', 'REQUEST_IN_PROGRESS'].includes(error.details?.code)) { state.proofPending = null; saveOwnerPending(); } }
      finally { state.busy = false; setDialogBusy(dialog, false); dialog.dataset.preserveData = String(!!state.proofPending); setFormFieldsBusy(form, false); setBusy(form.querySelector('[type="submit"]'), false); await loadPayment(); await load(); if (failure) showFormError($('#daily-owner-detail-error'), `${failure}${state.proofPending ? ' เก็บไฟล์เดิมไว้และกดส่งไฟล์เดิมเพื่อตรวจผล ห้ามรับหรือให้โอนเงินซ้ำ' : ''}`); }
    }
    $('#daily-owner-upload-form').addEventListener('submit', event => { event.preventDefault(); submitOwnerProof('upload', event.currentTarget); });
    $('#daily-owner-restore-form').addEventListener('submit', event => { event.preventDefault(); submitOwnerProof('restore', event.currentTarget); });
    filter.addEventListener('input', () => { if (state.busy) return; loadGate.invalidate(); resetBookingPaging(); state.ready = false; setupState(); $('#daily-admin-rows').replaceChildren(); $('#daily-calendar').replaceChildren(); $('#daily-housekeeping').replaceChildren(); $('#daily-admin-note').textContent = 'ตัวกรองเปลี่ยนแล้ว กดแสดงรายการก่อนทำรายการ'; });
    filter.addEventListener('submit', (event) => { event.preventDefault(); if (filter.reportValidity()) load(); });
    function clearOwnerQuote() { ownerQuoteGate.invalidate(); state.ownerQuote = null; $('#daily-owner-quote-summary').replaceChildren(); createForm.querySelector('[type="submit"]').disabled = true; }
    function ownerRoomHelp() { const room = state.rooms.find(row => String(row.id) === String(createForm.elements.room_id.value)); const capacity = Number(room?.max_guests); createForm.elements.guests.max = Number.isInteger(capacity) && capacity >= 1 && capacity <= 20 ? capacity : 20; $('#daily-owner-room-help').textContent = room ? `ห้อง ${room.room_code} · พักได้ไม่เกิน ${createForm.elements.guests.max} คน · ${money(room.daily_rate)}/คืน` : 'เลือกห้องเพื่อดูจำนวนผู้พักสูงสุด'; }
    createForm.addEventListener('input', (event) => { if (!state.busy && !state.createPending) { clearOwnerQuote(); ownerRoomHelp(); const arrival = createForm.elements.check_in_date.value; if (/^\d{4}-\d{2}-\d{2}$/.test(arrival) && Number.isFinite(Date.parse(`${arrival}T00:00:00Z`))) { const nextDay = new Date(Date.parse(`${arrival}T00:00:00Z`) + 86400000).toISOString().slice(0, 10); createForm.elements.check_out_date.min = nextDay; if (event?.target === createForm.elements.check_in_date && createForm.elements.check_out_date.value <= arrival) createForm.elements.check_out_date.value = nextDay; } } });
    $('#daily-owner-review').addEventListener('click', async () => {
      if (state.busy || state.createPending || !createForm.reportValidity()) return; clearOwnerQuote(); let submitted; try { const fields = formValues(createForm); submitted = { ...range(fields), room_id: Number(fields.room_id) }; } catch (error) { showFormError($('#daily-owner-create-error'), error.message); return; }
      const revision = ownerQuoteGate.next(); state.busy = true; setDialogBusy($('#daily-owner-create-dialog'), true); setFormFieldsBusy(createForm, true); setBusy($('#daily-owner-review'), true); showFormError($('#daily-owner-create-error'));
      try { const quote = validQuote(await api('/api/public/daily/quote', { method: 'POST', body: submitted }), submitted); if (!ownerQuoteGate.current(revision)) return; state.ownerQuote = quote; summary(h, $('#daily-owner-quote-summary'), quote); }
      catch (error) { showFormError($('#daily-owner-create-error'), error); }
      finally { state.busy = false; setDialogBusy($('#daily-owner-create-dialog'), false); setFormFieldsBusy(createForm, false); setBusy($('#daily-owner-review'), false); createForm.querySelector('[type="submit"]').disabled = !state.ownerQuote; }
    });
    function restoreOwnerPending() {
      if (state.busy) return;
      if (state.createPending) { const select = createForm.elements.room_id; select.replaceChildren(); const option = create('option', '', `ห้องตามคำขอเดิม ${state.createPending.room_id}`); option.value = state.createPending.room_id; select.append(option); for (const [name, value] of Object.entries(state.createPending)) if (createForm.elements[name]) createForm.elements[name].value = value; setFormFieldsBusy(createForm, true); createForm.querySelector('[type="submit"]').disabled = false; createForm.querySelector('[type="submit"]').textContent = 'ตรวจผลคำขอเดิม'; $('#daily-owner-review').disabled = true; $('#daily-owner-create-dialog').dataset.preserveData = 'true'; openDialog($('#daily-owner-create-dialog')); }
      else if (state.financePending) { state.detail = { ...state.financePending.booking }; const formId = { cash: 'daily-cash-form', refund: 'daily-refund-form', 'deposit-settlement': 'daily-deposit-form', close: 'daily-close-form' }[state.financePending.action], form = $(`#${formId}`); state.financePending.form = form; for (const [name, value] of Object.entries(state.financePending.payload)) if (form.elements[name]) form.elements[name].value = value; setFormFieldsBusy(form, true); form.querySelector('[type="submit"]').textContent = 'ตรวจผลรายการเงินเดิม'; $('#daily-owner-detail-dialog').dataset.preserveData = 'true'; summary(h, $('#daily-owner-detail-summary'), state.detail); openDialog($('#daily-owner-detail-dialog')); loadPayment(); }
      else if (state.proofPending) { state.detail = { ...state.proofPending.booking }; state.restorePayment = state.proofPending.payment_id ? { id: state.proofPending.payment_id, booking_id: state.detail.id } : null; if(state.proofPending.action==='restore')$('#daily-owner-restore-summary').textContent=`ตรวจผลซ่อมหลักฐาน ${state.proofPending.payment_id} ด้วยไฟล์ต้นฉบับเดิม`; summary(h, $('#daily-owner-detail-summary'), state.detail); $('#daily-owner-detail-dialog').dataset.preserveData = 'true'; openDialog($('#daily-owner-detail-dialog')); loadPayment(); }
    }
    $('#daily-owner-recovery-open').addEventListener('click', restoreOwnerPending);
    $('#daily-owner-create').addEventListener('click', () => { if (!state.ready || state.busy) return; if (state.createPending || state.financePending || state.proofPending) { restoreOwnerPending(); return; } if (!state.rooms.length) return; createForm.reset(); clearOwnerQuote(); const select = createForm.elements.room_id; select.replaceChildren(); const placeholder = create('option', '', 'เลือกห้องรายวัน'); placeholder.value = ''; select.append(placeholder); state.rooms.forEach((room) => { const option = create('option', '', `ห้อง ${room.room_code} · ${money(room.daily_rate)}/คืน`); option.value = room.id; select.append(option); }); select.value = ''; createForm.elements.check_in_date.value = isoToday(); createForm.elements.check_out_date.value = isoDateOffsetDays(1); createForm.elements.check_in_date.min = isoToday(); createForm.elements.check_out_date.min = isoDateOffsetDays(1); ownerRoomHelp(); h.rememberDialogDraft?.(createForm); showFormError($('#daily-owner-create-error')); openDialog($('#daily-owner-create-dialog')); });
    createForm.addEventListener('submit', async (event) => { event.preventDefault(); if (state.busy || (!state.createPending && (!state.ownerQuote || !createForm.reportValidity()))) return; const wasRecovery = !!state.createPending; let createdBooking = null; let payload; try { if (state.createPending) payload = { ...state.createPending }; else { if (expiry(state.ownerQuote.quote_expires_at) <= Date.now()) { clearOwnerQuote(); throw new Error('ราคาที่ตรวจหมดอายุ กรุณาตรวจยอดใหม่'); } const fields = formValues(createForm); payload = { ...fields, ...range(fields), room_id: Number(fields.room_id), quote_token: state.ownerQuote.quote_token }; payload.idempotency_key = key('owner-create', payload); state.createPending = { ...payload }; } } catch (error) { showFormError($('#daily-owner-create-error'), error.message); return; }
      state.busy = true; saveOwnerPending(); const dialog = $('#daily-owner-create-dialog'); setDialogBusy(dialog, true); setFormFieldsBusy(createForm, true); setBusy(createForm.querySelector('[type="submit"]'), true); showFormError($('#daily-owner-create-error'));
      try { createdBooking = validBooking(await api('/api/admin/daily/bookings', { method: 'POST', body: payload }), payload); state.createPending = null; saveOwnerPending(); dialog.dataset.preserveData = 'false'; setDialogBusy(dialog, false); closeDialog(dialog); createForm.reset(); h.rememberDialogDraft?.(createForm); clearOwnerQuote(); toast('สร้างการจองแล้ว เปิดรายละเอียดเพื่อรับเงินและยืนยันห้อง'); }
      catch (error) { showFormError($('#daily-owner-create-error'), error); if (rejectedCreate(error, wasRecovery)) { state.createPending = null; saveOwnerPending(); } }
      finally { state.busy = false; setDialogBusy(dialog, false); dialog.dataset.preserveData = String(!!state.createPending); if (!state.createPending) setFormFieldsBusy(createForm, false); setBusy(createForm.querySelector('[type="submit"]'), false); createForm.querySelector('[type="submit"]').textContent = state.createPending ? 'ตรวจผลคำขอเดิม' : 'สร้างการจองตามยอดนี้'; createForm.querySelector('[type="submit"]').disabled = !state.createPending && !state.ownerQuote; $('#daily-owner-review').disabled = !!state.createPending; await load(); }
      if (createdBooking && state.ready) await openDetail(createdBooking);
    });
    ownerRecoveryNotice();
    return { load, busy: () => state.busy || !!state.createPending || !!state.financePending || !!state.proofPending, inFlight: () => state.busy };
  }
  window.DormDaily = { initPublic, initAdmin, range, validQuote, validBooking, bookingPage, validTransfer, validPaymentRecord, validFinanceOutcome, paymentGuard, uploadResolved, bookingActions, bookingGuidance, calendarStay, gate, feedback };
})();
