(() => {
  'use strict';

  const doc = document;
  const body = doc.body;
  const csrfToken = doc.querySelector('meta[name="csrf-token"]')?.content || '';
  const configuredTimeZone = doc.querySelector('meta[name="app-timezone"]')?.content || 'Asia/Bangkok';
  let businessTimeZone = 'Asia/Bangkok';
  try {
    new Intl.DateTimeFormat('en', { timeZone: configuredTimeZone }).format();
    businessTimeZone = configuredTimeZone;
  } catch (_) { /* The server validates this value; keep a safe display fallback. */ }
  const moneyFormatter = new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    minimumFractionDigits: 2,
  });
  const dateFormatter = new Intl.DateTimeFormat('th-TH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: businessTimeZone,
  });
  const dateTimeFormatter = new Intl.DateTimeFormat('th-TH', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    timeZone: businessTimeZone,
  });
  const businessDateFormatter = new Intl.DateTimeFormat('en', {
    year: 'numeric', month: '2-digit', day: '2-digit', timeZone: businessTimeZone,
  });
  const activeMutations = new Set();
  const maxApiResponseBytes = 2 * 1024 * 1024;
  let confirmPending = false;

  class ApiError extends Error {
    constructor(message, status = 0, details = null) {
      super(message);
      this.name = 'ApiError';
      this.status = status;
      this.details = details;
    }
  }

  const $ = (selector, root = doc) => root.querySelector(selector);
  const $$ = (selector, root = doc) => Array.from(root.querySelectorAll(selector));
  const text = (value, fallback = '—') => value === null || value === undefined || value === '' ? fallback : String(value);
  const finiteNumber = (value) => {
    if (value === null || value === undefined || (typeof value === 'string' && value.trim() === '')) return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  };
  const number = (value) => finiteNumber(value) ?? 0;
  const money = (value) => {
    const parsed = finiteNumber(value);
    return parsed === null ? '—' : moneyFormatter.format(parsed);
  };
  const businessDateParts = (date = new Date()) => Object.fromEntries(
    businessDateFormatter.formatToParts(date)
      .filter((part) => ['year', 'month', 'day'].includes(part.type))
      .map((part) => [part.type, Number(part.value)]),
  );
  const isoBusinessDate = ({ year, month, day }) => `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  const todayPeriod = () => {
    const { year, month } = businessDateParts();
    return `${year}-${String(month).padStart(2, '0')}`;
  };
  const isoToday = () => isoBusinessDate(businessDateParts());
  const isoDateOffsetDays = (days) => {
    const { year, month, day } = businessDateParts();
    const date = new Date(Date.UTC(year, month - 1, day));
    date.setUTCDate(date.getUTCDate() + (Number(days) || 0));
    return isoBusinessDate({ year: date.getUTCFullYear(), month: date.getUTCMonth() + 1, day: date.getUTCDate() });
  };
  const isoDateAfterDays = (days) => isoDateOffsetDays(Math.max(0, Number(days) || 0));
  const businessIsoDateFromValue = (value) => {
    if (!value) return '';
    const raw = String(value);
    const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(raw)
      ? `${raw.replace(' ', 'T')}Z`
      : raw;
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? '' : isoBusinessDate(businessDateParts(parsed));
  };
  const formatDate = (value) => {
    if (!value) return '—';
    const raw = String(value);
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(raw) ? `${raw}T00:00:00+07:00`
      : /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(raw) ? `${raw.replace(' ', 'T')}Z` : raw;
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? text(value) : dateFormatter.format(parsed);
  };
  const formatDateTime = (value) => {
    if (!value) return '—';
    const raw = String(value);
    const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(raw)
      ? `${raw.replace(' ', 'T')}Z`
      : raw;
    const parsed = new Date(normalized);
    return Number.isNaN(parsed.getTime()) ? text(value) : dateTimeFormatter.format(parsed);
  };
  const formatPeriod = (value) => {
    const match = String(value || '').match(/^(\d{4})-(\d{2})/);
    if (!match) return text(value);
    return new Intl.DateTimeFormat('th-TH', { year: 'numeric', month: 'long' })
      .format(new Date(Number(match[1]), Number(match[2]) - 1, 1));
  };
  const create = (tag, className = '', content = '') => {
    const node = doc.createElement(tag);
    if (className) node.className = className;
    if (content !== '') node.textContent = content;
    return node;
  };
  const listFrom = (payload, key) => {
    if (Array.isArray(payload)) return payload;
    if (!payload || typeof payload !== 'object') return [];
    if (key && Array.isArray(payload[key])) return payload[key];
    if (Array.isArray(payload.items)) return payload.items;
    return [];
  };
  const objectFrom = (payload, key) => {
    if (!payload || typeof payload !== 'object') return {};
    if (key && payload[key] && typeof payload[key] === 'object') return payload[key];
    return payload;
  };
  const errorMessagesByCode = Object.freeze({
    CSRF_INVALID: 'หน้าที่เปิดอยู่อาจหมดอายุ กรุณารีเฟรชแล้วลองใหม่',
    ORIGIN_REQUIRED: 'ตรวจสอบแหล่งที่มาของคำขอไม่ได้ กรุณารีเฟรชหน้าแล้วลองใหม่',
    ORIGIN_INVALID: 'คำขอมาจากที่อยู่เว็บที่ไม่ถูกต้อง กรุณาเปิดระบบจาก URL หลัก',
    ORIGIN_MISMATCH: 'ระบบปฏิเสธคำขอจากคนละเว็บไซต์ กรุณาเปิดระบบจาก URL หลัก',
    HOST_REQUIRED: 'ตั้งค่าที่อยู่เว็บไซต์ไม่ครบ กรุณาติดต่อผู้ดูแลระบบ',
    REQUEST_TOO_LARGE: 'ข้อมูลหรือไฟล์มีขนาดใหญ่เกินที่ระบบรองรับ',
    INVALID_JSON: 'รูปแบบข้อมูลที่ส่งไม่ถูกต้อง กรุณารีเฟรชแล้วลองใหม่',
    INVALID_JSON_SHAPE: 'รูปแบบข้อมูลที่ส่งไม่ถูกต้อง กรุณารีเฟรชแล้วลองใหม่',
    UNKNOWN_FIELDS: 'มีข้อมูลที่ระบบไม่รองรับ กรุณารีเฟรชแล้วลองใหม่',
    INVALID_CREDENTIALS: 'เบอร์โทรหรือรหัสลับไม่ถูกต้อง หรือบัญชียังไม่มีห้องที่ใช้งานอยู่',
    IDEMPOTENCY_KEY_REUSED: 'คำขอนี้ถูกใช้กับการจองอื่นแล้ว กรุณาเริ่มรายการใหม่',
    ADMIN_CHECK_IN_RETRY: 'มีการรับเข้าพักพร้อมกัน กรุณากดส่งอีกครั้งโดยไม่เปลี่ยนข้อมูล',
    ADMIN_CHECK_IN_INACTIVE: 'รายการรับเข้าพักนี้สิ้นสุดแล้ว กรุณาปิดฟอร์มและเริ่มรายการใหม่',
    BOOKING_EXPIRED: 'เวลายืนยันการจองเดิมหมดแล้ว กรุณาส่งคำขอใหม่',
    BOOKING_INACTIVE: 'การจองเดิมสิ้นสุดแล้ว กรุณาส่งคำขอใหม่',
    BOOKING_RETRY: 'มีคำขอจองพร้อมกัน กรุณากดส่งอีกครั้งโดยไม่เปลี่ยนข้อมูล',
    BOOKING_PHONE_ACTIVE: 'เบอร์นี้มีคำขอจองที่ยังดำเนินการอยู่ กรุณาติดต่อผู้ดูแลหากต้องการเปลี่ยนห้อง',
    ROOM_NOT_AVAILABLE: 'ห้องนี้มีผู้จองหรือกำลังถูกดำเนินการแล้ว กรุณาเลือกห้องว่างอื่น',
    ROOM_OCCUPIED: 'ห้องนี้มีผู้พักอยู่แล้ว กรุณารีเฟรชรายการห้อง',
    RESIDENT_ALREADY_OCCUPIED: 'เบอร์นี้เป็นผู้พักที่มีห้องอยู่แล้ว ไม่สามารถผูกซ้ำกับอีกห้องได้',
    RESIDENT_REUSE_CONFIRMATION_REQUIRED: 'เบอร์นี้มีประวัติผู้พักเดิม กรุณาตรวจตัวตนและยืนยันการเชื่อมประวัติก่อนบันทึก',
    MOVE_IN_METER_PERIOD_CONFLICT: 'ห้องนี้มีเลขมิเตอร์ของเดือนที่เลือกอยู่แล้ว กรุณาเลือกย้ายเข้าเดือนถัดไป หรือติดต่อผู้ดูแลฐานข้อมูลเพื่อกระทบยอดก่อน',
    MOVE_IN_PERIOD_CONFLICT: 'ห้องนี้มีประวัติผู้พักเดิมทับเดือนที่เลือก กรุณารับเข้าพักตั้งแต่เดือนถัดไป',
    RESIDENT_MOVE_IN_PERIOD_CONFLICT: 'ผู้พักรายนี้ย้ายออกในเดือนเดียวกัน ระบบยังไม่รองรับการคิดค่าเช่าแบบแบ่งเดือน กรุณารับเข้าพักในเดือนถัดไป',
    MOVE_OUT_MISSING_BILLS: 'ยังออกบิลไม่ครบทุกรอบเดือนของการเข้าพัก กรุณาออกและชำระบิลที่ขาดก่อนย้ายออก',
    PROMPTPAY_NOT_CONFIGURED: 'ยังไม่ได้ตั้งค่า PromptPay กรุณาติดต่อผู้ดูแลก่อนโอน',
    PROMPTPAY_TARGET_INVALID: 'เบอร์ PromptPay หรือเลขผู้เสียภาษีไม่ถูกต้อง',
    AMOUNT_INVALID: 'ยอดชำระไม่ถูกต้อง กรุณารีเฟรชรายละเอียดบิล',
    SLIP_NOT_CONFIGURED: 'ระบบตรวจสลิปยังตั้งค่าไม่ครบ กรุณาติดต่อผู้ดูแล',
    LINE_NOT_CONFIGURED: 'ยังไม่ได้ตั้งค่า LINE Messaging',
    LINE_NOT_VERIFIED: 'บัญชี LINE ยังไม่ผ่านรหัสยืนยัน',
    LINE_LINK_STALE: 'ข้อมูลบัญชีเปลี่ยนหลังขอรหัส LINE กรุณาขอรหัสใหม่',
    METER_HISTORY_LOCKED: 'แก้เลขมิเตอร์นี้ไม่ได้ เพราะมีรอบเดือนถัดไปอ้างอิงแล้ว',
    METER_ALREADY_BILLED: 'แก้เลขมิเตอร์ไม่ได้หลังออกบิลแล้ว',
    METER_OCCUPANCY_MISMATCH: 'เลขมิเตอร์นี้ผูกกับผู้พักคนละรอบและย้ายมาใช้ซ้ำไม่ได้ กรุณาตรวจประวัติห้อง',
    METER_OPENING_REQUIRED: 'ยังขาดเลขมิเตอร์น้ำและไฟ ณ วันเข้าพัก กรุณาไปหน้า “ผู้พักอาศัย” แล้วกด “เติมเลขเริ่มต้น” ของห้องนี้ก่อนจดมิเตอร์หรือออกบิล',
    METER_TOO_HIGH: 'เลขมิเตอร์เริ่มต้นต้องไม่เกิน 9,999,999.00',
    CURRENT_BILLING_PERIOD_NOT_FINALIZED: 'การออกบิลเดือนปัจจุบันต้องยืนยันว่าจดมิเตอร์ครบและต้องการปิดยอดเดือนนี้แล้ว',
    RESIDENT_CHANGED: 'ข้อมูลผู้พักถูกแก้ไขพร้อมกัน กรุณารีเฟรชแล้วลองใหม่',
    ADMIN_NOT_FOUND: 'ไม่พบบัญชีผู้ดูแล',
    USERNAME_EXISTS: 'ชื่อผู้ใช้นี้มีอยู่แล้ว',
    LAST_OWNER: 'ระบบต้องมีเจ้าของที่ใช้งานได้อย่างน้อย 1 บัญชี',
    SELF_OWNER_CHANGE: 'ไม่สามารถลดสิทธิ์หรือปิดบัญชีเจ้าของของตนเองได้',
    SELF_DELETE: 'ไม่สามารถปิดบัญชีของตนเองได้',
    BILL_NOT_FOUND: 'ไม่พบบิล',
    BILL_ALREADY_PAID: 'บิลนี้ชำระแล้ว',
    PAYMENT_ALREADY_PENDING: 'บิลนี้มีรายการชำระที่กำลังดำเนินการอยู่แล้ว',
    BILL_PREVIEW_INVALID: 'บางห้องยังมีข้อมูลไม่พร้อมออกบิล กรุณาตรวจรายการที่แจ้ง',
  });
  const errorMessage = (error, fallback = 'เกิดข้อผิดพลาด กรุณาลองใหม่') => {
    const code = error?.details?.code;
    if (code === 'RATE_LIMITED') {
      const retryAfter = Math.max(0, Math.ceil(Number(error?.details?.retry_after) || 0));
      return retryAfter > 0
        ? `มีการทำรายการถี่เกินไป กรุณารอ ${retryAfter} วินาทีแล้วลองใหม่`
        : 'มีการทำรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
    }
    if (code && errorMessagesByCode[code]) return errorMessagesByCode[code];
    return error instanceof Error && error.message ? error.message : fallback;
  };

  async function api(url, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const mutation = !['GET', 'HEAD', 'OPTIONS'].includes(method);
    const mutationKey = `${method}:${url}`;
    if (mutation && activeMutations.has(mutationKey)) {
      throw new ApiError('คำขอนี้กำลังดำเนินการอยู่ กรุณารอผลก่อนกดซ้ำ', 0, { code: 'REQUEST_IN_PROGRESS' });
    }
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    let requestBody = options.body;
    if (requestBody !== undefined && !(requestBody instanceof FormData) && typeof requestBody !== 'string') {
      headers.set('Content-Type', 'application/json');
      requestBody = JSON.stringify(requestBody);
    }
    if (mutation && csrfToken) {
      headers.set('X-CSRF-Token', csrfToken);
    }

    const controller = new AbortController();
    const externalSignal = options.signal;
    let timedOut = false;
    const timeoutMs = Math.max(1000, Number(options.timeoutMs) || (mutation ? 35000 : 20000));
    const abortFromExternal = () => controller.abort(externalSignal?.reason);
    if (externalSignal?.aborted) abortFromExternal();
    else externalSignal?.addEventListener('abort', abortFromExternal, { once: true });
    const timeout = window.setTimeout(() => { timedOut = true; controller.abort(); }, timeoutMs);
    if (mutation) activeMutations.add(mutationKey);
    let cleaned = false;
    const cleanupRequest = () => {
      if (cleaned) return;
      cleaned = true;
      window.clearTimeout(timeout);
      externalSignal?.removeEventListener('abort', abortFromExternal);
      if (mutation) activeMutations.delete(mutationKey);
    };

    let response;
    try {
      response = await fetch(url, {
        method,
        headers,
        body: requestBody,
        credentials: 'same-origin',
        signal: controller.signal,
      });
    } catch (error) {
      cleanupRequest();
      if (externalSignal?.aborted && !timedOut) throw error;
      if (mutation) {
        throw new ApiError(
          timedOut
            ? 'เซิร์ฟเวอร์ตอบช้าและไม่ทราบผลการบันทึก กรุณารีเฟรชเพื่อตรวจสอบก่อนทำซ้ำ'
            : 'การเชื่อมต่อขาดระหว่างบันทึกและไม่ทราบผล กรุณารีเฟรชเพื่อตรวจสอบก่อนทำซ้ำ',
          0,
          { code: 'MUTATION_OUTCOME_UNKNOWN' },
        );
      }
      throw new ApiError(timedOut ? 'เซิร์ฟเวอร์ตอบช้าเกินกำหนด กรุณาลองใหม่' : 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้ กรุณาตรวจสอบเครือข่าย');
    }

    try {
    const declaredLength = Number(response.headers.get('Content-Length') || 0);
    if (declaredLength > maxApiResponseBytes) {
      throw new ApiError('ข้อมูลตอบกลับมีขนาดเกินขอบเขตที่ปลอดภัย', response.status, { code: 'RESPONSE_TOO_LARGE' });
    }
    let raw;
    try {
      raw = await response.text();
    } catch (error) {
      if (externalSignal?.aborted && !timedOut) throw error;
      if (mutation) throw new ApiError('การตอบกลับขาดระหว่างบันทึกและไม่ทราบผล กรุณารีเฟรชเพื่อตรวจสอบก่อนทำซ้ำ', response.status, { code: 'MUTATION_OUTCOME_UNKNOWN' });
      throw new ApiError(timedOut ? 'เซิร์ฟเวอร์ตอบช้าเกินกำหนด กรุณาลองใหม่' : 'รับข้อมูลจากเซิร์ฟเวอร์ไม่สำเร็จ กรุณาลองใหม่', response.status);
    }
    if (raw.length > maxApiResponseBytes) {
      throw new ApiError('ข้อมูลตอบกลับมีขนาดเกินขอบเขตที่ปลอดภัย', response.status, { code: 'RESPONSE_TOO_LARGE' });
    }
    let envelope = null;
    try {
      envelope = JSON.parse(raw);
    } catch (_) {
      const message = mutation && response.ok
        ? 'เซิร์ฟเวอร์บันทึกข้อมูลแล้วอาจตอบกลับไม่สมบูรณ์ กรุณารีเฟรชเพื่อตรวจสอบก่อนทำซ้ำ'
        : 'เซิร์ฟเวอร์ส่งข้อมูลตอบกลับไม่ถูกต้อง';
      throw new ApiError(message, response.status, { code: mutation && response.ok ? 'MUTATION_OUTCOME_UNKNOWN' : 'INVALID_RESPONSE' });
    }
    if (!envelope || typeof envelope !== 'object' || Array.isArray(envelope) || typeof envelope.ok !== 'boolean') {
      const message = mutation && response.ok
        ? 'ไม่สามารถยืนยันผลการบันทึกได้ กรุณารีเฟรชเพื่อตรวจสอบก่อนทำซ้ำ'
        : 'รูปแบบข้อมูลตอบกลับไม่ถูกต้อง';
      throw new ApiError(message, response.status, { code: mutation && response.ok ? 'MUTATION_OUTCOME_UNKNOWN' : 'INVALID_RESPONSE' });
    }

    if (!response.ok || envelope?.ok === false) {
      if (response.status === 401 && !$('#admin-login-form') && !$('#resident-login-form')) {
        const destination = location.pathname.startsWith('/admin') ? '/admin/login' : '/resident/login';
        location.assign(destination);
      }
      throw new ApiError(envelope?.message || envelope?.error || 'ไม่สามารถทำรายการได้', response.status, envelope?.data || envelope?.errors || null);
    }
    if (!Object.prototype.hasOwnProperty.call(envelope, 'data')) {
      throw new ApiError(
        mutation ? 'ไม่สามารถยืนยันผลการบันทึกได้ กรุณารีเฟรชเพื่อตรวจสอบก่อนทำซ้ำ' : 'ข้อมูลตอบกลับไม่ครบถ้วน',
        response.status,
        { code: mutation ? 'MUTATION_OUTCOME_UNKNOWN' : 'INVALID_RESPONSE' },
      );
    }
    return envelope.data;
    } finally {
      cleanupRequest();
    }
  }

  function setBusy(button, busy, label = 'กำลังดำเนินการ…') {
    if (!button) return;
    if (busy) {
      if (button.getAttribute('aria-busy') !== 'true') button.dataset.originalLabel = button.textContent;
      button.textContent = label;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
    } else {
      button.textContent = button.dataset.originalLabel || button.dataset.submitLabel || button.textContent;
      button.disabled = false;
      button.removeAttribute('aria-busy');
      delete button.dataset.originalLabel;
    }
  }

  function setFormFieldsBusy(form, busy) {
    if (!form) return;
    $$('input, select, textarea', form).forEach((control) => {
      if (busy) {
        if (control.dataset.formBusyWasDisabled === undefined) control.dataset.formBusyWasDisabled = String(control.disabled);
        control.disabled = true;
      } else if (control.dataset.formBusyWasDisabled !== undefined) {
        control.disabled = control.dataset.formBusyWasDisabled === 'true';
        delete control.dataset.formBusyWasDisabled;
      }
    });
    if (busy) form.setAttribute('aria-busy', 'true');
    else form.removeAttribute('aria-busy');
  }

  function showFormError(element, message = '') {
    if (!element) return;
    element.textContent = message;
    element.hidden = !message;
  }

  function toast(message, type = 'success') {
    const region = $('#toast-region');
    if (!region) return;
    const item = create('div', `toast toast-${type}`, message);
    item.setAttribute('role', type === 'error' ? 'alert' : 'status');
    region.append(item);
    requestAnimationFrame(() => item.classList.add('is-visible'));
    window.setTimeout(() => {
      item.classList.remove('is-visible');
      window.setTimeout(() => item.remove(), 250);
    }, 4200);
  }

  function openDialog(dialog) {
    if (!dialog) return;
    if (dialog.open || dialog.hasAttribute('open')) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function dialogCloseBlocked(dialog, trigger = null) {
    return Boolean(dialog && (
      dialog.dataset.dialogBusy === 'true'
      || dialog.dataset.preserveData === 'true'
      || (dialog.dataset.requireExplicitClose === 'true' && !trigger?.hasAttribute('data-explicit-close'))
    ));
  }

  function setDialogBusy(dialog, busy) {
    if (!dialog) return;
    if (busy) {
      dialog.dataset.dialogBusy = 'true';
      dialog.setAttribute('aria-busy', 'true');
    } else {
      delete dialog.dataset.dialogBusy;
      dialog.removeAttribute('aria-busy');
    }
    $$('[data-close-dialog]', dialog).forEach((button) => {
      if (busy) {
        if (button.dataset.dialogCloseWasDisabled === undefined) button.dataset.dialogCloseWasDisabled = String(button.disabled);
        button.disabled = true;
      } else if (button.dataset.dialogCloseWasDisabled !== undefined) {
        button.disabled = button.dataset.dialogCloseWasDisabled === 'true';
        delete button.dataset.dialogCloseWasDisabled;
      }
    });
  }

  function closeDialog(dialog, trigger = null) {
    if (!dialog || dialogCloseBlocked(dialog, trigger)) return false;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
    return true;
  }

  async function confirmAction(title, message, label = 'ยืนยัน', danger = true) {
    const dialog = $('#confirm-dialog');
    const accept = dialog ? $('[data-confirm-accept]', dialog) : null;
    const reject = dialog ? $('[data-confirm-cancel]', dialog) : null;
    if (!dialog || !accept || !reject || confirmPending) return false;
    confirmPending = true;
    $('#confirm-title', dialog).textContent = title;
    $('#confirm-message', dialog).textContent = message;
    accept.textContent = label;
    accept.classList.toggle('button-danger', danger);
    accept.classList.toggle('button-primary', !danger);
    openDialog(dialog);
    return new Promise((resolve) => {
      let settled = false;
      const yes = () => finish(true);
      const no = () => finish(false);
      const cancel = () => finish(false);
      const closed = () => finish(false);
      const cleanup = () => {
        accept.removeEventListener('click', yes);
        reject.removeEventListener('click', no);
        dialog.removeEventListener('cancel', cancel);
        dialog.removeEventListener('close', closed);
      };
      const finish = (value) => {
        if (settled) return;
        settled = true;
        confirmPending = false;
        cleanup();
        if (dialog.open) closeDialog(dialog);
        resolve(value);
      };
      accept.addEventListener('click', yes);
      reject.addEventListener('click', no);
      dialog.addEventListener('cancel', cancel, { once: true });
      dialog.addEventListener('close', closed, { once: true });
    });
  }

  function renderQrCanvas(qrLibrary, canvas, payload, options) {
    return new Promise((resolve, reject) => {
      let settled = false;
      const finish = (error) => {
        if (settled) return;
        settled = true;
        if (error) reject(error); else resolve();
      };
      try {
        const pending = qrLibrary.toCanvas(canvas, payload, options, finish);
        if (pending?.then) pending.then(() => finish(), finish);
      } catch (error) { finish(error); }
    });
  }

  async function getQrLibrary() {
    const existing = window.QRCode || window.qrcodelib;
    if (existing?.toCanvas) return existing;
    if (!window.qrLibraryReady) {
      window.qrLibraryReady = (async () => {
        const source = document.querySelector('script[src*="/qrcode.min.js"]')?.src || '/assets/js/vendor/qrcode.min.js';
        const moduleUrl = `${source}${source.includes('?') ? '&' : '?'}module=1`;
        try { await import(moduleUrl); } catch (_) { return null; }
        const library = window.QRCode || window.qrcodelib;
        if (!library?.toCanvas) return null;
        window.QRCode = library;
        return library;
      })();
    }
    try {
      const loaded = await Promise.resolve(window.qrLibraryReady);
      return loaded?.toCanvas ? loaded : null;
    } catch (_) {
      return null;
    }
  }

  function safeLineMessageUrl(value, code) {
    if (typeof value !== 'string' || !/^BIND-[A-F0-9]{32}$/.test(String(code || ''))) return '';
    const match = value.match(/^https:\/\/line\.me\/R\/oaMessage\/%40[A-Za-z0-9._-]{1,32}\/\?(BIND-[A-F0-9]{32})$/);
    return match && match[1] === code ? value : '';
  }

  function safeRoomImage(room) {
    const allowed = new Set(['room-standard.jpg', 'room-deluxe.jpg', 'room-suite.jpg', 'room-studio.jpg']);
    const key = allowed.has(String(room?.image_key)) ? room.image_key : 'room-standard.jpg';
    const candidate = String(room?.image_url || '');
    if (candidate.startsWith('/assets/images/rooms/') && !candidate.includes('..')) return candidate;
    return `/assets/images/rooms/${key}`;
  }

  const statusLabels = {
    available: 'ว่าง', reserved: 'รอเข้าพัก', occupied: 'มีผู้พัก',
    pending: 'รอตรวจสอบ', confirmed: 'ยืนยันแล้ว', cancelled: 'ยกเลิก', moved_in: 'เข้าพักแล้ว',
    unpaid: 'รอชำระ', due: 'รอชำระ', overdue: 'เลยกำหนด', paid: 'ชำระแล้ว', verifying: 'กำลังตรวจสลิป',
    verified: 'ยืนยันแล้ว', rejected: 'ปฏิเสธสลิป', failed: 'ตรวจไม่สำเร็จ', active: 'ใช้งาน', inactive: 'ปิดใช้งาน',
  };

  function pill(status, override = '') {
    const value = String(status || 'neutral').toLowerCase();
    const span = create('span', `status-pill status-${value}`, override || statusLabels[value] || text(status));
    return span;
  }

  function td(content = '', className = '') {
    const cell = create('td', className);
    if (content instanceof Node) cell.append(content);
    else cell.textContent = content;
    return cell;
  }

  function actionButton(label, action, id, style = 'button-ghost', accessibleLabel = '') {
    const button = create('button', `button button-small ${style}`, label);
    button.type = 'button';
    button.dataset.action = action;
    button.dataset.id = String(id);
    if (accessibleLabel) button.setAttribute('aria-label', accessibleLabel);
    return button;
  }

  function actionLink(label, href, style = 'button-ghost') {
    const link = create('a', `button button-small ${style}`, label);
    link.href = href;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    return link;
  }

  function setTableState(element, state, message = '') {
    if (!element) return;
    element.dataset.state = state;
    element.setAttribute('aria-live', 'polite');
    element.setAttribute('role', state === 'error' ? 'alert' : 'status');
    element.hidden = state === 'ready';
    if (state !== 'ready') {
      element.replaceChildren();
      if (state === 'loading') element.append(create('span', 'spinner'));
      element.append(create('p', '', message || (state === 'loading' ? 'กำลังโหลด…' : 'ไม่พบข้อมูล')));
    }
  }

  function setupCommonInteractions() {
    $$('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => closeDialog(button.closest('dialog'), button)));
    $$('dialog').forEach((dialog) => {
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog && !dialog.querySelector('form')) closeDialog(dialog);
      });
      dialog.addEventListener('cancel', (event) => {
        if (dialogCloseBlocked(dialog)) event.preventDefault();
      });
    });
    $$('[data-password-toggle]').forEach((button) => button.addEventListener('click', () => {
      const input = button.closest('.password-wrap')?.querySelector('input') || button.parentElement?.querySelector('input');
      if (!input) return;
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      button.textContent = reveal ? 'ซ่อน' : 'แสดง';
      button.setAttribute('aria-pressed', String(reveal));
      button.setAttribute('aria-label', reveal ? 'ซ่อนรหัส' : 'แสดงรหัส');
    }));
  }

  async function loadPublicSupport() {
    const links = $$('[data-public-support-line]');
    if (!links.length) return;
    try {
      const contact = objectFrom(await api('/api/public/contact'));
      const url = typeof contact.line_add_friend_url === 'string'
        && /^https:\/\/line\.me\/R\/ti\/p\/(?:@|%40)[A-Za-z0-9._-]{1,32}$/.test(contact.line_add_friend_url)
        ? contact.line_add_friend_url
        : '';
      if (!url) return;
      links.forEach((link) => {
        link.href = url;
        link.hidden = false;
        link.setAttribute('aria-label', `ติดต่อผู้ดูแลผ่าน LINE ${text(contact.line_basic_id, '')}`.trim());
      });
    } catch (_) { /* Keep the optional contact action hidden if settings are unavailable. */ }
  }

  function initPublicRooms() {
    const grid = $('#public-room-grid');
    if (!grid) return;
    const template = $('#public-room-card-template');
    const count = $('#public-room-count');
    const empty = $('#public-rooms-empty');
    const errorBox = $('#public-rooms-error');
    const search = $('#public-room-search');
    const typeFilter = $('#public-room-type');
    const floorFilter = $('#public-room-floor');
    const bookingDialog = $('#booking-dialog');
    const bookingForm = $('#public-booking-form');
    let rooms = [];
    let roomLoadRequest = 0;
    let roomsReady = false;

    function maskBookingPhone(value) {
      const digits = String(value || '').replace(/\D/g, '');
      if (digits.length < 7) return '•••';
      return `${digits.slice(0, 3)}-•••-${digits.slice(-4)}`;
    }

    function populateSelect(select, values) {
      const selectedValue = select.value;
      const existing = select.firstElementChild?.cloneNode(true);
      select.replaceChildren();
      if (existing) select.append(existing);
      [...new Set(values.filter(Boolean).map(String))].sort((a, b) => a.localeCompare(b, 'th', { numeric: true })).forEach((value) => {
        const option = create('option', '', value);
        option.value = value;
        select.append(option);
      });
      select.value = Array.from(select.children).some((option) => option.value === selectedValue) ? selectedValue : '';
    }

    function filteredRooms() {
      const query = search.value.trim().toLocaleLowerCase('th');
      return rooms.filter((room) => {
        const haystack = `${room.room_code || ''} ${room.room_type || ''} ${room.description || ''}`.toLocaleLowerCase('th');
        return (!query || haystack.includes(query)) && (!typeFilter.value || String(room.room_type) === typeFilter.value) && (!floorFilter.value || String(room.floor) === floorFilter.value);
      });
    }

    function openBooking(room) {
      bookingForm.reset();
      bookingForm.hidden = false;
      $('#public-booking-success').hidden = true;
      showFormError($('#public-booking-error'));
      $('#booking-room-id').value = room.id;
      $('#booking-idempotency').value = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
      bookingForm.dataset.roomCode = text(room.room_code);
      $('#booking-room-label').textContent = `ห้อง ${text(room.room_code)}`;
      $('#booking-room-meta').textContent = `${text(room.room_type)} · ${money(room.monthly_rent)}/เดือน`;
      const image = $('#booking-room-image');
      image.src = safeRoomImage(room);
      image.alt = `ห้อง ${text(room.room_code)}`;
      openDialog(bookingDialog);
      window.setTimeout(() => bookingForm.elements.full_name?.focus(), 40);
    }

    function render() {
      if (!roomsReady) return;
      const filtered = filteredRooms();
      grid.replaceChildren();
      filtered.forEach((room) => {
        const card = template.content.firstElementChild.cloneNode(true);
        const image = $('[data-room-image]', card);
        image.src = safeRoomImage(room);
        image.alt = `ห้อง ${text(room.room_code)} ประเภท ${text(room.room_type)}`;
        $('[data-room-code]', card).textContent = text(room.room_code);
        $('[data-room-floor]', card).textContent = `ชั้น ${text(room.floor)}`;
        $('[data-room-type]', card).textContent = text(room.room_type);
        $('[data-room-rent]', card).textContent = `${money(room.monthly_rent)}/เดือน`;
        $('[data-room-description]', card).textContent = text(room.description, 'ห้องพักพร้อมเข้าอยู่');
        const amenities = $('[data-room-amenities]', card);
        (Array.isArray(room.amenities) ? room.amenities : []).slice(0, 6).forEach((item) => amenities.append(create('li', '', text(item))));
        $('[data-book-room]', card).addEventListener('click', () => openBooking(room));
        grid.append(card);
      });
      grid.setAttribute('aria-busy', 'false');
      empty.hidden = filtered.length !== 0;
      if (!empty.hidden) {
        const noAvailableRooms = rooms.length === 0;
        $('#public-rooms-empty-title').textContent = noAvailableRooms ? 'ขณะนี้ยังไม่มีห้องว่าง' : 'ไม่พบห้องว่างที่ตรงกับตัวกรอง';
        $('#public-rooms-empty-copy').textContent = noAvailableRooms ? 'กรุณากลับมาตรวจสอบอีกครั้ง หรือติดต่อหอพักโดยตรง' : 'ลองเปลี่ยนประเภท ชั้น หรือคำค้นหา';
      }
    }

    async function load() {
      const request = ++roomLoadRequest;
      roomsReady = false;
      empty.hidden = true;
      grid.replaceChildren();
      grid.append(create('p', 'field-hint', 'กำลังโหลดห้องว่าง…'));
      count.textContent = '…';
      grid.setAttribute('aria-busy', 'true');
      errorBox.hidden = true;
      try {
        const data = await api('/api/public/rooms');
        if (request !== roomLoadRequest) return;
        rooms = listFrom(data, 'rooms').filter((room) => !room.status || room.status === 'available');
        count.textContent = String(rooms.length);
        populateSelect(typeFilter, rooms.map((room) => room.room_type));
        populateSelect(floorFilter, rooms.map((room) => room.floor));
        roomsReady = true;
        render();
      } catch (error) {
        if (request !== roomLoadRequest) return;
        rooms = [];
        empty.hidden = true;
        grid.replaceChildren();
        grid.setAttribute('aria-busy', 'false');
        count.textContent = '—';
        errorBox.hidden = false;
        $('[data-error-message]', errorBox).textContent = errorMessage(error);
      }
    }

    [search, typeFilter, floorFilter].forEach((field) => field.addEventListener(field === search ? 'input' : 'change', render));
    $('#public-filter-reset').addEventListener('click', () => { search.value = ''; typeFilter.value = ''; floorFilter.value = ''; render(); });
    $('#public-rooms-retry').addEventListener('click', load);
    $('#booking-reference-copy').addEventListener('click', async () => {
      const reference = $('#booking-reference').textContent.trim();
      if (!reference || reference === '—') return;
      try {
        await navigator.clipboard.writeText(reference);
        toast('คัดลอกหมายเลขอ้างอิงแล้ว');
      } catch (_) { toast('คัดลอกอัตโนมัติไม่ได้ กรุณาแตะค้างที่หมายเลขอ้างอิง', 'error'); }
    });
    bookingForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (bookingDialog.dataset.dialogBusy === 'true') return;
      showFormError($('#public-booking-error'));
      if (!bookingForm.reportValidity()) return;
      const button = bookingForm.querySelector('[type="submit"]');
      const form = new FormData(bookingForm);
      setDialogBusy(bookingDialog, true);
      setFormFieldsBusy(bookingForm, true);
      setBusy(button, true, 'กำลังส่งคำขอ…');
      try {
        const data = await api('/api/public/bookings', {
          method: 'POST',
          body: { room_id: Number(form.get('room_id')), full_name: form.get('full_name'), phone: form.get('phone'), idempotency_key: form.get('idempotency_key') },
        });
        const booking = objectFrom(data, 'booking');
        $('#booking-success-room').textContent = `ห้อง ${text(bookingForm.dataset.roomCode)}`;
        $('#booking-success-phone').textContent = `ติดต่อที่เบอร์ ${maskBookingPhone(form.get('phone'))}`;
        $('#booking-reference').textContent = text(booking.reference_no || booking.reference || booking.booking_reference || booking.id || data?.reference_no || data?.reference);
        $('#booking-expiry').textContent = booking.status === 'confirmed'
          ? 'คำขอนี้ได้รับการยืนยันแล้ว กรุณารอผู้ดูแลติดต่อเรื่องวันเข้าพัก'
          : (booking.expires_at
            ? `ระบบกันห้องไว้ถึง ${formatDateTime(booking.expires_at)} น. หากเลยเวลานี้กรุณาส่งคำขอใหม่`
            : 'กรุณารอผู้ดูแลติดต่อกลับเพื่อยืนยันการจอง');
        bookingForm.hidden = true;
        const success = $('#public-booking-success');
        success.hidden = false;
        success.focus();
        load();
      } catch (error) {
        showFormError($('#public-booking-error'), errorMessage(error, 'ส่งคำขอไม่สำเร็จ'));
        if (['BOOKING_EXPIRED', 'BOOKING_INACTIVE', 'IDEMPOTENCY_KEY_REUSED'].includes(error?.details?.code)) {
          $('#booking-idempotency').value = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        }
        if (['ROOM_NOT_AVAILABLE', 'ROOM_NOT_FOUND', 'BOOKING_EXPIRED', 'BOOKING_INACTIVE'].includes(error?.details?.code)) await load();
      } finally {
        setFormFieldsBusy(bookingForm, false);
        setDialogBusy(bookingDialog, false);
        setBusy(button, false);
      }
    });
    load();
  }

  function initLogin(formId, endpoint, destination, fallbackMessage = 'เข้าสู่ระบบไม่สำเร็จ') {
    const form = $(formId);
    if (!form) return;
    const error = $(`${formId.replace('-form', '-error')}`);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      showFormError(error);
      if (!form.reportValidity()) return;
      const button = form.querySelector('[type="submit"]');
      const values = Object.fromEntries(new FormData(form).entries());
      setBusy(button, true, 'กำลังตรวจสอบ…');
      try {
        await api(endpoint, { method: 'POST', body: values });
        location.assign(destination);
      } catch (requestError) {
        const message = requestError?.details?.code === 'INVALID_CREDENTIALS'
          ? fallbackMessage
          : errorMessage(requestError, fallbackMessage);
        showFormError(error, message);
      } finally {
        setBusy(button, false);
      }
    });
  }

  function initResidentLogin() {
    const form = $('#resident-login-form');
    if (!form) return;
    const firstActivation = $('#resident-first-activation');
    const newPasswordFields = $('#resident-new-password-fields');
    const credentialLabel = $('#resident-credential-label');
    const credentialHelp = $('#resident-credential-help');
    const error = $('#resident-login-error');

    const resetSecretVisibility = () => {
      $$('[data-password-toggle]', form).forEach((button) => {
        const input = button.closest('.password-field')?.querySelector('input');
        if (input) input.type = 'password';
        button.textContent = 'แสดง';
        button.setAttribute('aria-pressed', 'false');
      });
    };
    const syncMode = () => {
      const activating = firstActivation.checked;
      newPasswordFields.hidden = !activating;
      ['new_password', 'new_password_confirm'].forEach((name) => {
        form.elements[name].disabled = !activating;
        form.elements[name].required = activating;
        if (!activating) form.elements[name].value = '';
      });
      form.elements.credential.autocomplete = activating ? 'one-time-code' : 'current-password';
      credentialLabel.textContent = activating ? 'รหัสเปิดใช้งานครั้งเดียว' : 'รหัสผ่าน';
      credentialHelp.textContent = activating
        ? 'กรอกรหัสรูปแบบ XXXXX-XXXXX-XXXXX-XXXXX ที่ผู้ดูแลส่งมอบให้'
        : 'ใช้รหัสผ่านที่ตั้งไว้ตอนเปิดใช้งานครั้งแรก';
      form.elements.credential.value = '';
      resetSecretVisibility();
      showFormError(error);
    };
    firstActivation.addEventListener('change', syncMode);
    syncMode();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      showFormError(error);
      if (!form.reportValidity()) return;
      const activating = firstActivation.checked;
      if (activating && form.elements.new_password.value !== form.elements.new_password_confirm.value) {
        showFormError(error, 'รหัสผ่านใหม่และช่องยืนยันไม่ตรงกัน');
        form.elements.new_password_confirm.focus();
        return;
      }
      const payload = {
        phone: form.elements.phone.value,
        credential: form.elements.credential.value,
      };
      if (activating) payload.new_password = form.elements.new_password.value;
      const button = form.querySelector('[type="submit"]');
      setBusy(button, true, activating ? 'กำลังเปิดใช้งาน…' : 'กำลังตรวจสอบ…');
      let loginSucceeded = false;
      try {
        await api('/api/auth/resident/login', { method: 'POST', body: payload });
        loginSucceeded = true;
        location.assign('/resident');
      } catch (requestError) {
        showFormError(error, errorMessage(requestError, 'เบอร์โทรหรือรหัสลับไม่ถูกต้อง'));
        form.elements.credential.focus();
      } finally {
        form.elements.credential.value = '';
        if (!activating || loginSucceeded) {
          form.elements.new_password.value = '';
          form.elements.new_password_confirm.value = '';
        }
        resetSecretVisibility();
        setBusy(button, false);
      }
    });
  }

  function initResidentPortal() {
    const shell = $('.resident-shell');
    if (!shell) return;
    const state = {
      profile: {}, bills: [], filter: 'all', currentBillId: null, billDetailRequest: 0, qrRequest: 0,
      loadRequest: 0, lineCodeExpiresAt: 0, lineStatusRequest: false, billRefreshRequest: false,
      profileRevision: 0, lineStateRevision: 0, lineIssueRequest: false,
      slipMaxBytes: 4 * 1024 * 1024, slipReady: false, paymentReady: false,
    };
    const profileForm = $('#resident-profile-form');
    const lineStartForm = $('#resident-line-start-form');
    const lineCodePanel = $('#resident-line-code-panel');
    const lineCodeInput = $('#resident-line-code');
    const lineCodeCopyButton = $('#resident-line-code-copy');
    const lineCodeRenewButton = $('#resident-line-code-renew');
    const lineOpenMessage = $('#resident-line-open-message');
    const lineCodeQr = $('#resident-line-code-qr');
    const lineCodeQrFallback = $('#resident-line-code-qr-fallback');
    const lineAddFriendLink = $('#resident-line-add-friend');
    const lineStatusRefreshButton = $('#resident-line-status-refresh');
    const lineUnlinkButton = $('#resident-line-unlink');
    const billDialog = $('#resident-bill-dialog');
    const billDetailLoading = $('#resident-bill-loading');
    const billDetailLoadingMessage = $('#resident-bill-loading-message');
    const billDetailRetryButton = $('#resident-bill-detail-retry');
    let lineExpiryTimer = null;
    let linePollTimer = null;
    let residentLogoutInProgress = false;
    let lastLineReturnRefresh = 0;

    function billStatus(bill) {
      const payment = String(bill.payment_status || bill.payment?.status || '').toLowerCase();
      if (payment === 'pending') return 'verifying';
      if (payment === 'verified') return 'paid';
      const raw = String(bill.display_status || bill.status || '').toLowerCase();
      if (['paid', 'verified'].includes(raw)) return 'paid';
      if (raw === 'overdue') return 'overdue';
      return 'unpaid';
    }

    function stopLineCodeTracking(clearCode = false) {
      if (lineExpiryTimer !== null) window.clearInterval(lineExpiryTimer);
      if (linePollTimer !== null) window.clearInterval(linePollTimer);
      lineExpiryTimer = null;
      linePollTimer = null;
      state.lineCodeExpiresAt = 0;
      if (clearCode) {
        state.lineStateRevision += 1;
        lineCodeInput.value = '';
        lineOpenMessage.hidden = true;
        lineOpenMessage.removeAttribute('href');
        lineCodePanel.hidden = true;
        lineCodeQr.hidden = true;
        lineCodeQrFallback.hidden = true;
        const context = lineCodeQr.getContext?.('2d');
        context?.clearRect(0, 0, lineCodeQr.width, lineCodeQr.height);
      }
    }

    function updateLineCodeCountdown() {
      if (!state.lineCodeExpiresAt) return;
      const seconds = Math.max(0, Math.ceil((state.lineCodeExpiresAt - Date.now()) / 1000));
      if (seconds <= 0) {
        stopLineCodeTracking(true);
        $('#resident-line-status').textContent = 'รหัสหมดอายุ กรุณาสร้างรหัสใหม่';
        lineStartForm.hidden = false;
        toast('รหัสผูก LINE หมดอายุแล้ว กรุณาสร้างรหัสใหม่', 'error');
        return;
      }
      const minutes = Math.floor(seconds / 60);
      const remainder = String(seconds % 60).padStart(2, '0');
      $('#resident-line-code-expiry').textContent = `ส่งรหัสในแชตส่วนตัวกับ LINE Bot ภายใน ${formatDateTime(new Date(state.lineCodeExpiresAt).toISOString())} น. · เหลือ ${minutes}:${remainder}`;
    }

    function startLineCodeTracking(expiresAt) {
      stopLineCodeTracking(false);
      const raw = String(expiresAt || '');
      const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(raw)
        ? `${raw.replace(' ', 'T')}Z`
        : raw;
      const parsed = new Date(normalized).getTime();
      if (!Number.isFinite(parsed) || parsed <= Date.now()) {
        stopLineCodeTracking(true);
        return false;
      }
      state.lineCodeExpiresAt = parsed;
      updateLineCodeCountdown();
      lineExpiryTimer = window.setInterval(updateLineCodeCountdown, 1000);
      linePollTimer = window.setInterval(() => { if (doc.visibilityState === 'visible') refreshLineStatus(true); }, 5000);
      return true;
    }

    async function refreshLineStatus(silent = false) {
      if (state.lineStatusRequest) return false;
      state.lineStatusRequest = true;
      const profileRevision = state.profileRevision;
      const lineRevision = state.lineStateRevision;
      if (!silent) setBusy(lineStatusRefreshButton, true, 'กำลังตรวจสอบ…');
      try {
        const data = await api('/api/resident/profile');
        if (profileRevision !== state.profileRevision) return false;
        if (lineRevision !== state.lineStateRevision) return false;
        const latestProfile = objectFrom(data, 'profile');
        const wasLinked = state.profile.line_verified === true && state.profile.line_blocked !== true;
        state.profile = {
          ...state.profile,
          line_verified: latestProfile.line_verified,
          line_user_id_hint: latestProfile.line_user_id_hint,
          line_add_friend_url: latestProfile.line_add_friend_url,
          line_binding_ready: latestProfile.line_binding_ready,
          line_bound_count: latestProfile.line_bound_count,
          line_blocked: latestProfile.line_blocked,
        };
        const linked = state.profile.line_verified === true && state.profile.line_blocked !== true;
        renderLineStatus();
        if (linked && !wasLinked) toast('ผูกบัญชี LINE สำเร็จแล้ว');
        else if (!linked && !silent) toast(state.profile.line_blocked === true ? 'บัญชีนี้ถูกระงับการผูก LINE กรุณาติดต่อผู้ดูแล' : 'ยังไม่พบการยืนยัน กรุณาส่งรหัสให้ LINE Bot แล้วลองอีกครั้ง', 'error');
        return linked;
      } catch (errorValue) {
        if (!silent) showFormError($('#resident-line-error'), errorMessage(errorValue));
        return false;
      } finally {
        state.lineStatusRequest = false;
        if (!silent) setBusy(lineStatusRefreshButton, false);
      }
    }

    function replaceResidentHash(name) {
      const hash = name === 'dashboard' ? '' : `#${name}`;
      window.history.replaceState(null, '', `${location.pathname}${location.search}${hash}`);
    }

    function switchView(name, updateHash = true) {
      const labels = { dashboard: 'ภาพรวม', bills: 'บิลของฉัน', profile: 'ข้อมูลส่วนตัว' };
      if (!Object.prototype.hasOwnProperty.call(labels, name)) return false;
      $$('[data-view-panel]', shell).forEach((panel) => { const active = panel.dataset.viewPanel === name; panel.hidden = !active; panel.classList.toggle('is-active', active); });
      $$('[data-resident-view]', shell).forEach((button) => { const active = button.dataset.residentView === name; button.classList.toggle('is-active', active); if (active) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current'); });
      $('#resident-page-title').textContent = labels[name] || labels.dashboard;
      const targetHash = name === 'dashboard' ? '' : `#${name}`;
      if (updateHash && location.hash !== targetHash) location.hash = targetHash;
      $('#main-content').focus({ preventScroll: true });
      return true;
    }

    function createBillRow(bill) {
      const template = $('#resident-bill-row-template');
      const row = template.content.firstElementChild.cloneNode(true);
      $('[data-bill-no]', row).textContent = text(bill.bill_no || bill.number || `#${bill.id}`);
      $('[data-bill-period]', row).textContent = `${formatPeriod(bill.period)} · ครบกำหนด ${formatDate(bill.due_date)}`;
      const status = billStatus(bill);
      const badge = $('[data-bill-status]', row);
      badge.textContent = statusLabels[status];
      badge.className = `status-badge status-${status}`;
      $('[data-bill-total]', row).textContent = money(bill.total_amount ?? bill.total);
      row.dataset.id = String(bill.id);
      row.addEventListener('click', () => openBill(bill.id));
      return row;
    }

    function renderBills() {
      const list = $('#resident-bill-list');
      const recent = $('#resident-recent-bills');
      const payableStatuses = new Set(['unpaid', 'overdue']);
      const filtered = state.bills.filter((bill) => state.filter === 'all'
        || (state.filter === 'unpaid' ? payableStatuses.has(billStatus(bill)) : billStatus(bill) === state.filter));
      list.replaceChildren(...filtered.map(createBillRow));
      recent.replaceChildren(...state.bills.slice(0, 3).map(createBillRow));
      if (state.bills.length === 0) recent.append(create('p', 'empty-inline', 'ยังไม่มีบิล เมื่อผู้ดูแลออกบิลแล้วจะแสดงที่นี่'));
      list.setAttribute('aria-busy', 'false');
      recent.setAttribute('aria-busy', 'false');
      const emptyState = $('#resident-bills-empty');
      emptyState.hidden = filtered.length > 0;
      if (!emptyState.hidden) {
        const messages = {
          all: ['ยังไม่มีบิล', 'เมื่อผู้ดูแลออกบิล รายการจะแสดงที่นี่'],
          unpaid: ['ไม่มีบิลรอชำระ', 'ขณะนี้ไม่มีบิลที่ต้องชำระในรายการนี้'],
          paid: ['ยังไม่มีบิลที่ชำระแล้ว', 'เมื่อการชำระได้รับการยืนยัน รายการจะแสดงที่นี่'],
        };
        const [title, copy] = messages[state.filter] || messages.all;
        $('#resident-bills-empty-title').textContent = title;
        $('#resident-bills-empty-copy').textContent = copy;
      }
      const unpaid = state.bills.filter((bill) => payableStatuses.has(billStatus(bill)));
      const verifying = state.bills.filter((bill) => billStatus(bill) === 'verifying');
      $('#resident-bill-count').textContent = String(state.bills.length);
      $('#resident-due-total').textContent = money(unpaid.reduce((sum, bill) => sum + number(bill.total_amount ?? bill.total), 0));
      $('#resident-due-caption').textContent = unpaid.length
        ? `${unpaid.length} บิลที่ยังต้องชำระ${verifying.length ? ` · กำลังตรวจสลิป ${verifying.length} บิล ห้ามโอนซ้ำ` : ''}`
        : (verifying.length ? `กำลังตรวจสลิป ${verifying.length} บิล · ยังไม่ต้องโอนซ้ำ` : 'ไม่มียอดค้างชำระ');
      const navCount = $('#resident-unpaid-count');
      navCount.textContent = String(unpaid.length);
      navCount.hidden = unpaid.length === 0;
    }

    function renderLineStatus() {
      const profile = state.profile;
      const lineHint = typeof profile.line_user_id_hint === 'string' ? profile.line_user_id_hint : '';
      const blocked = profile.line_blocked === true;
      const linked = profile.line_verified === true && !blocked;
      const hasLine = !blocked && (linked || lineHint.length > 0 || Number(profile.line_bound_count) > 0);
      const lineLabel = Number(profile.line_bound_count) > 1 ? `${Number(profile.line_bound_count)} บัญชี` : lineHint;
      const addFriendUrl = typeof profile.line_add_friend_url === 'string' && /^https:\/\/line\.me\/R\/ti\/p\/(?:@|%40)[A-Za-z0-9._-]{1,32}$/.test(profile.line_add_friend_url)
        ? profile.line_add_friend_url
        : '';
      lineAddFriendLink.hidden = addFriendUrl === '' || hasLine || blocked;
      if (addFriendUrl) lineAddFriendLink.href = addFriendUrl;
      else lineAddFriendLink.removeAttribute('href');
      $('#resident-line-status').textContent = blocked ? 'ผู้ดูแลระงับการผูก LINE กรุณาติดต่อผู้ดูแล' : linked ? `ยืนยันแล้ว ${lineLabel}`
        : (hasLine ? `บัญชี ${lineHint} ยังไม่ผ่านการยืนยัน กรุณายกเลิกแล้วผูกใหม่` : (state.lineCodeExpiresAt ? 'สร้างรหัสแล้ว รอส่งรหัสให้ LINE Bot' : 'ยังไม่ได้ผูกบัญชี LINE'));
      const hasActiveCode = state.lineCodeExpiresAt > Date.now() && /^BIND-[A-F0-9]{32}$/.test(lineCodeInput.value);
      lineStartForm.hidden = hasLine || hasActiveCode || blocked;
      const ready = profile.line_binding_ready === true && !blocked;
      lineStartForm.querySelector('[type="submit"]').disabled = !ready || state.lineIssueRequest;
      lineCodeRenewButton.disabled = !ready || state.lineIssueRequest;
      $('#resident-line-readiness').hidden = ready || hasLine;
      $('#resident-line-readiness').textContent = blocked ? 'บัญชีนี้ถูกระงับการผูก LINE กรุณาติดต่อผู้ดูแลเพื่อปลดระงับ' : 'ระบบยังตั้งค่า LINE ไม่ครบ กรุณาติดต่อผู้ดูแลก่อนสร้างรหัส';
      lineUnlinkButton.hidden = !hasLine;
      if (hasLine || blocked) {
        stopLineCodeTracking(true);
      }
    }

    function fillProfile() {
      const profile = state.profile;
      ['full_name', 'email', 'phone', 'room_code'].forEach((name) => {
        if (profileForm.elements[name]) profileForm.elements[name].value = profile[name] || (name === 'room_code' ? profile.room?.room_code || '' : '');
      });
      renderLineStatus();
      $('#resident-profile-fields').disabled = false;
      $('#resident-profile-load-state').hidden = true;
    }

    async function loadAll() {
      const request = ++state.loadRequest;
      const profileRevision = state.profileRevision;
      const errorBox = $('#resident-global-error');
      errorBox.hidden = true;
      const [profileResult, billsResult] = await Promise.allSettled([api('/api/resident/profile'), api('/api/resident/bills')]);
      if (request !== state.loadRequest) return;
      if (profileResult.status === 'fulfilled' && profileRevision === state.profileRevision) {
        const draft = profileForm.dataset.dirty === 'true' ? { full_name: profileForm.elements.full_name.value, email: profileForm.elements.email.value } : null;
        state.profile = objectFrom(profileResult.value, 'profile'); fillProfile();
        if (draft) Object.entries(draft).forEach(([name, value]) => { profileForm.elements[name].value = value; });
      }
      if (billsResult.status === 'fulfilled') { state.bills = listFrom(billsResult.value, 'bills'); renderBills(); }
      else {
        $('#resident-bill-list').setAttribute('aria-busy', 'false');
        $('#resident-recent-bills').setAttribute('aria-busy', 'false');
      }
      const rejected = [profileResult, billsResult].filter((result) => result.status === 'rejected');
      if (rejected.length) {
        errorBox.hidden = false;
        $('[data-error-message]', errorBox).textContent = (rejected.length === 2 ? 'โหลดข้อมูลผู้พักไม่สำเร็จ: ' : 'โหลดข้อมูลบางส่วนไม่สำเร็จ: ') + errorMessage(rejected[0].reason);
        if ($('#resident-profile-fields').disabled) $('#resident-profile-load-state').textContent = 'ยังโหลดข้อมูลส่วนตัวไม่ได้ กรุณากดลองอีกครั้งด้านบนก่อนแก้ไข';
      }
    }

    async function refreshVerifyingBills(force = false) {
      if (state.billRefreshRequest || (!force && !state.bills.some((bill) => billStatus(bill) === 'verifying'))) return;
      state.billRefreshRequest = true;
      const currentId = state.currentBillId;
      const loadGeneration = state.loadRequest;
      const refreshOpenBill = billDialog.open && state.bills.some((bill) => String(bill.id) === String(currentId) && billStatus(bill) === 'verifying');
      try {
        const result = await api('/api/resident/bills');
        if (loadGeneration !== state.loadRequest) return;
        const nextBills = listFrom(result, 'bills');
        if (JSON.stringify(nextBills) === JSON.stringify(state.bills)) return;
        state.bills = nextBills;
        renderBills();
        if (refreshOpenBill && currentId !== null && billDialog.open && String(state.currentBillId) === String(currentId)) await openBill(currentId);
      } catch (_) { /* Manual refresh remains available; do not interrupt the resident for a background poll. */ }
      finally { state.billRefreshRequest = false; }
    }

    function appendBreakdown(list, label, amount, detail = '') {
      if (amount === null || amount === undefined) return;
      const wrapper = create('div', 'breakdown-row');
      const term = create('dt');
      term.append(create('span', '', label));
      if (detail) term.append(create('small', '', detail));
      wrapper.append(term, create('dd', '', money(amount)));
      list.append(wrapper);
    }

    async function openBill(id) {
      state.currentBillId = id;
      state.slipReady = false;
      state.paymentReady = false;
      state.qrRequest += 1;
      const request = ++state.billDetailRequest;
      const slipForm = $('#resident-slip-form');
      slipForm.reset();
      slipForm.hidden = true;
      $('[data-file-name]', slipForm).textContent = 'ยังไม่ได้เลือกไฟล์';
      billDetailLoading.hidden = false;
      billDetailLoading.setAttribute('role', 'status');
      billDetailLoadingMessage.textContent = 'กำลังโหลดรายละเอียดบิล…';
      billDetailRetryButton.hidden = true;
      $('#resident-bill-detail').hidden = true;
      showFormError($('#resident-slip-error'));
      openDialog(billDialog);
      try {
        const data = await api(`/api/resident/bills/${encodeURIComponent(id)}`);
        if (request !== state.billDetailRequest || String(state.currentBillId) !== String(id)) return;
        const bill = objectFrom(data, 'bill');
        $('#resident-bill-number').textContent = text(bill.bill_no || bill.number || `#${bill.id}`);
        $('#resident-bill-due').textContent = `ครบกำหนด ${formatDate(bill.due_date)}`;
        $('#resident-bill-total').textContent = money(bill.total_amount ?? bill.total);
        const status = billStatus(bill);
        const statusNode = $('#resident-bill-status');
        statusNode.textContent = statusLabels[status];
        statusNode.className = `status-badge status-${status}`;
        const paymentNotice = $('#resident-payment-notice');
        const payment = bill.payment && typeof bill.payment === 'object' ? bill.payment : null;
        const capabilities = objectFrom(bill.payment_capabilities);
        const promptPayReady = capabilities.promptpay_ready === true;
        const slipReady = capabilities.slip_verification_ready === true;
        const paymentConfigurationReady = promptPayReady && slipReady;
        const paymentInProgress = payment?.status === 'pending' || payment?.status === 'verified';
        const paymentBlocked = !paymentConfigurationReady && !paymentInProgress;
        state.slipMaxBytes = Math.max(1024, Math.min(4 * 1024 * 1024, Number(capabilities.slip_max_bytes) || 4 * 1024 * 1024));
        state.paymentReady = paymentConfigurationReady && !paymentInProgress;
        state.slipReady = state.paymentReady;
        $('#resident-load-qr').hidden = !state.paymentReady;
        $('#resident-qr-stage').hidden = !state.paymentReady;
        $('#resident-slip-form').hidden = !state.slipReady;
        const limitMiB = state.slipMaxBytes / 1024 / 1024;
        $('[data-file-name]', $('#resident-slip-form')).textContent = `JPG, PNG หรือ WebP ขนาดไม่เกิน ${limitMiB < 1 ? `${Math.round(state.slipMaxBytes / 1024)} KiB` : `${limitMiB.toFixed(limitMiB % 1 ? 2 : 0)} MiB`}`;
        const notices = [];
        if (payment) {
          notices.push(payment.status === 'rejected'
            ? `สลิปถูกปฏิเสธ: ${text(payment.rejection_reason, 'ข้อมูลในสลิปไม่ตรงกับบิล')} หากโอนเงินจริงแล้ว กรุณาติดต่อผู้ดูแลเพื่อตรวจยอดก่อนโอนซ้ำ การส่งไฟล์เดิมจะได้ผลเดิม`
            : payment.status === 'verified' ? 'สลิปผ่านการตรวจสอบแล้ว ไม่ต้องชำระซ้ำ' : 'สลิปอยู่ระหว่างตรวจสอบ กรุณารอผลและอย่าโอนซ้ำ');
        }
        if (paymentBlocked) {
          if (!promptPayReady && !slipReady) notices.push('ยังชำระผ่านระบบไม่ได้: ผู้ดูแลต้องตั้งค่า PromptPay และระบบตรวจสลิปให้พร้อมก่อน ห้ามโอนจนกว่าจะตั้งค่าเสร็จ');
          else if (!promptPayReady) notices.push('ยังชำระผ่านระบบไม่ได้: ยังไม่ได้ตั้งค่า PromptPay กรุณาติดต่อผู้ดูแลก่อนโอน');
          else notices.push('ยังชำระผ่านระบบไม่ได้: ระบบตรวจสลิปยังไม่พร้อม จึงซ่อน QR ไว้เพื่อป้องกันการโอนที่ตรวจสอบไม่ได้');
        }
        paymentNotice.hidden = notices.length === 0;
        paymentNotice.className = `payment-notice${payment ? ` payment-notice-${text(payment.status, 'pending')}` : ''}${paymentBlocked ? ' payment-notice-blocked' : ''}`;
        paymentNotice.setAttribute('role', paymentBlocked ? 'alert' : 'status');
        paymentNotice.setAttribute('aria-live', paymentBlocked ? 'assertive' : 'polite');
        paymentNotice.textContent = notices.join(' · ');
        const breakdown = $('#resident-bill-breakdown');
        breakdown.replaceChildren();
        if (Array.isArray(bill.items) && bill.items.length > 0) {
          const itemLabels = { rent: 'ค่าห้อง', water: 'ค่าน้ำ', electric: 'ค่าไฟ' };
          bill.items.forEach((item) => {
            const type = String(item.item_type || '');
            const detail = ['water', 'electric'].includes(type) && finiteNumber(item.quantity) !== null && finiteNumber(item.unit_price) !== null
              ? `${number(item.quantity)} หน่วย × ${money(item.unit_price)}`
              : '';
            appendBreakdown(breakdown, itemLabels[type] || text(item.description, 'รายการอื่น'), item.amount, detail);
          });
        } else {
          appendBreakdown(breakdown, 'ค่าห้อง', bill.rent_amount ?? bill.monthly_rent);
          appendBreakdown(breakdown, 'ค่าน้ำ', bill.water_amount, bill.water_units !== undefined ? `${number(bill.water_units)} หน่วย × ${money(bill.water_rate)}` : '');
          appendBreakdown(breakdown, 'ค่าไฟ', bill.electric_amount, bill.electric_units !== undefined ? `${number(bill.electric_units)} หน่วย × ${money(bill.electric_rate)}` : '');
          if (finiteNumber(bill.other_amount) !== null && number(bill.other_amount) !== 0) appendBreakdown(breakdown, text(bill.other_description, 'รายการอื่น'), bill.other_amount);
        }
        const totalRow = create('div', 'breakdown-row breakdown-total');
        totalRow.append(create('dt', '', 'ยอดสุทธิ'), create('dd', '', money(bill.total_amount ?? bill.total)));
        breakdown.append(totalRow);
        $('#resident-payment-panel').hidden = status === 'paid';
        $('#resident-payment-complete').hidden = status !== 'paid';
        $('#resident-qr-stage').replaceChildren(create('div', 'qr-placeholder', 'เลือก “แสดง QR” เพื่อสร้าง QR ตามยอดบิล'));
        billDetailLoading.hidden = true;
        $('#resident-bill-detail').hidden = false;
      } catch (error) {
        if (request !== state.billDetailRequest || String(state.currentBillId) !== String(id)) return;
        billDetailLoading.setAttribute('role', 'alert');
        billDetailLoadingMessage.textContent = errorMessage(error, 'โหลดรายละเอียดไม่สำเร็จ');
        billDetailRetryButton.hidden = false;
      }
    }

    $$('[data-resident-view]', shell).forEach((button) => button.addEventListener('click', () => switchView(button.dataset.residentView)));
    $$('[data-bill-filter]').forEach((button) => button.addEventListener('click', () => {
      state.filter = button.dataset.billFilter;
      $$('[data-bill-filter]').forEach((item) => { const active = item === button; item.classList.toggle('is-active', active); item.setAttribute('aria-pressed', String(active)); });
      renderBills();
    }));
    $('#resident-bills-refresh').addEventListener('click', loadAll);
    $('#resident-retry').addEventListener('click', loadAll);
    billDetailRetryButton.addEventListener('click', () => {
      if (state.currentBillId !== null) openBill(state.currentBillId);
    });
    const residentLogoutButtons = $$('[data-resident-logout]');
    residentLogoutButtons.forEach((button) => button.addEventListener('click', async () => {
      if (residentLogoutInProgress) return;
      residentLogoutInProgress = true;
      residentLogoutButtons.forEach((item) => { item.disabled = true; item.setAttribute('aria-busy', 'true'); });
      try {
        await api('/api/auth/resident/logout', { method: 'POST', body: {} });
        location.assign('/resident/login');
      } catch (errorValue) {
        residentLogoutInProgress = false;
        residentLogoutButtons.forEach((item) => { item.disabled = false; item.removeAttribute('aria-busy'); });
        toast(errorMessage(errorValue, 'ออกจากระบบไม่สำเร็จ กรุณาลองอีกครั้ง'), 'error');
      }
    }));
    profileForm.addEventListener('input', () => { profileForm.dataset.dirty = 'true'; });
    profileForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const error = $('#resident-profile-error');
      showFormError(error);
      if ($('#resident-profile-fields').disabled || !profileForm.reportValidity()) return;
      const button = profileForm.querySelector('[type="submit"]');
      const values = Object.fromEntries(new FormData(profileForm).entries());
      const profileRevision = ++state.profileRevision;
      setFormFieldsBusy(profileForm, true);
      setBusy(button, true, 'กำลังบันทึก…');
      try {
        const data = await api('/api/resident/profile', { method: 'PUT', body: { full_name: values.full_name, email: values.email } });
        if (profileRevision !== state.profileRevision) return;
        state.profile = objectFrom(data, 'profile');
        profileForm.dataset.dirty = 'false';
        fillProfile();
        $('#resident-sidebar-name').textContent = text(state.profile.full_name);
        $('#resident-topbar-name').textContent = text(state.profile.full_name);
        $('#resident-dashboard-title').textContent = `สวัสดี ${text(state.profile.full_name)}`;
        toast('บันทึกข้อมูลแล้ว');
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setFormFieldsBusy(profileForm, false); setBusy(button, false); }
    });
    lineStartForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      await issueResidentLineCode();
    });
    lineCodeRenewButton.addEventListener('click', () => issueResidentLineCode(true));

    async function issueResidentLineCode(rotate = false) {
      if (state.lineIssueRequest || state.profile.line_binding_ready !== true || state.profile.line_blocked === true || state.profile.line_verified === true) return;
      state.lineIssueRequest = true;
      const error = $('#resident-line-error');
      showFormError(error);
      const button = lineStartForm.querySelector('[type="submit"]');
      try {
        if (rotate && !await confirmAction('สร้างรหัสผูก LINE ใหม่', 'รหัสและ QR เดิมจะใช้ไม่ได้หลังสร้างรหัสใหม่ ต้องการยกเลิกรหัสเดิมหรือไม่?', 'ยกเลิกรหัสเดิมและสร้างใหม่', true)) return;
        setBusy(button, true, 'กำลังสร้างรหัส…');
        setBusy(lineCodeRenewButton, true, 'กำลังสร้างรหัส…');
        stopLineCodeTracking(true);
        renderLineStatus();
        const revision = state.lineStateRevision;
        const result = objectFrom(await api('/api/resident/profile/line/code', { method: 'POST', body: {} }), 'LINE link code');
        if (revision !== state.lineStateRevision) return;
        if (!/^BIND-[A-F0-9]{32}$/.test(String(result.code || ''))) throw new ApiError('ระบบสร้างรหัสผูก LINE ไม่ถูกต้อง');
        lineCodeInput.value = result.code;
        lineStartForm.hidden = true;
        lineCodePanel.hidden = false;
        lineCodeQr.hidden = true;
        lineCodeQrFallback.hidden = true;
        const messageUrl = safeLineMessageUrl(result.line_message_url, result.code);
        lineOpenMessage.hidden = !messageUrl;
        if (messageUrl) lineOpenMessage.href = messageUrl;
        else lineOpenMessage.removeAttribute('href');
        if (!startLineCodeTracking(result.expires_at)) throw new ApiError('รหัสผูก LINE หมดอายุหรือไม่มีวันหมดอายุที่ถูกต้อง กรุณาสร้างใหม่');
        const qrLibrary = messageUrl ? await getQrLibrary() : null;
        if (revision !== state.lineStateRevision || lineCodeInput.value !== result.code) return;
        if (messageUrl && qrLibrary?.toCanvas) {
          try {
            await renderQrCanvas(qrLibrary, lineCodeQr, messageUrl, { width: 220, margin: 2, errorCorrectionLevel: 'M' });
            if (revision !== state.lineStateRevision || lineCodeInput.value !== result.code) return;
            lineCodeQr.hidden = false;
          } catch (_) { lineCodeQr.hidden = true; lineCodeQrFallback.hidden = false; }
        } else lineCodeQrFallback.hidden = false;
        if (revision !== state.lineStateRevision || lineCodeInput.value !== result.code) return;
        $('#resident-line-status').textContent = 'รอคุณกดส่งรหัสใน LINE แล้วระบบจะยืนยันให้อัตโนมัติ';
        lineCodeInput.focus();
        lineCodeInput.select();
        toast('สร้างรหัสแล้ว เปิด LINE พร้อมรหัสแล้วกดส่งในแชตของหอพัก');
      } catch (errorValue) {
        showFormError(error, errorMessage(errorValue));
      }
      finally {
        state.lineIssueRequest = false;
        setBusy(button, false); setBusy(lineCodeRenewButton, false);
        renderLineStatus();
      }
    }

    lineCodeCopyButton.addEventListener('click', async () => {
      const code = String(lineCodeInput.value || '');
      if (!/^BIND-[A-F0-9]{32}$/.test(code)) return;
      let copied = false;
      try {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
          await navigator.clipboard.writeText(code);
          copied = true;
        } else {
          lineCodeInput.focus();
          lineCodeInput.select();
          copied = document.execCommand('copy');
        }
      } catch (_) { copied = false; }
      toast(copied ? 'คัดลอกรหัสแล้ว นำไปส่งในแชต LINE ได้เลย' : 'คัดลอกอัตโนมัติไม่ได้ กรุณาเลือกรหัสแล้วคัดลอกด้วยตนเอง', copied ? 'success' : 'error');
    });

    lineStatusRefreshButton.addEventListener('click', () => refreshLineStatus(false));

    lineUnlinkButton.addEventListener('click', async () => {
      if (!await confirmAction('ยกเลิกการผูก LINE', 'หลังยกเลิก ระบบจะไม่ส่งบิลใหม่ไปยัง LINE จนกว่าจะยืนยันอีกครั้ง')) return;
      const error = $('#resident-line-error');
      showFormError(error);
      const profileRevision = ++state.profileRevision;
      state.lineStateRevision += 1;
      setBusy(lineUnlinkButton, true, 'กำลังยกเลิก…');
      try {
        const data = await api('/api/resident/profile/line/unlink', { method: 'POST', body: {} });
        if (profileRevision !== state.profileRevision) return;
        const latestProfile = objectFrom(data, 'profile');
        state.profile = {
          ...state.profile,
          line_verified: latestProfile.line_verified,
          line_user_id_hint: latestProfile.line_user_id_hint,
          line_add_friend_url: latestProfile.line_add_friend_url,
          line_binding_ready: latestProfile.line_binding_ready,
          line_bound_count: latestProfile.line_bound_count,
          line_blocked: latestProfile.line_blocked,
        };
        stopLineCodeTracking(true);
        renderLineStatus();
        toast('ยกเลิกการผูก LINE แล้ว');
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setBusy(lineUnlinkButton, false); }
    });
    $('#resident-load-qr').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      if (!state.paymentReady) { toast('ยังสร้าง QR ไม่ได้ ต้องตั้งค่า PromptPay และระบบตรวจสลิปให้พร้อมทั้งคู่ก่อน', 'error'); return; }
      const billId = state.currentBillId;
      const request = ++state.qrRequest;
      const qrStage = $('#resident-qr-stage');
      qrStage.replaceChildren(create('div', 'qr-placeholder', 'กำลังสร้าง QR พร้อมเพย์…'));
      setBusy(button, true, 'กำลังสร้าง QR…');
      try {
        const data = await api(`/api/resident/bills/${encodeURIComponent(billId)}/promptpay`);
        if (request !== state.qrRequest || String(state.currentBillId) !== String(billId)) return;
        const qr = objectFrom(data, 'promptpay');
        const payload = qr.payload || qr.qr_payload || data?.payload;
        const qrLibrary = await getQrLibrary();
        if (!payload || !qrLibrary?.toCanvas) throw new ApiError('ไม่สามารถสร้าง QR ได้');
        const canvas = create('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', 'QR PromptPay สำหรับบิลนี้');
        await renderQrCanvas(qrLibrary, canvas, payload, { width: 240, margin: 2, errorCorrectionLevel: 'M' });
        if (request !== state.qrRequest || String(state.currentBillId) !== String(billId)) return;
        const meta = create('div', 'qr-meta');
        meta.append(
          create('strong', '', `ชื่อผู้รับ: ${text(qr.name ?? qr.recipient_name ?? data?.name, 'ไม่ได้ระบุชื่อ')}`),
          create('small', '', `ยอดชำระ ${money(qr.amount ?? data?.amount)}`),
        );
        qrStage.replaceChildren(canvas, meta);
      } catch (error) {
        if (request !== state.qrRequest || String(state.currentBillId) !== String(billId)) return;
        const message = create('div', 'form-error', errorMessage(error, 'สร้าง QR พร้อมเพย์ไม่สำเร็จ กรุณาลองใหม่'));
        message.setAttribute('role', 'alert');
        qrStage.replaceChildren(message);
      }
      finally { setBusy(button, false); }
    });
    const slipInput = $('#resident-slip-form input[name="slip"]');
    slipInput.addEventListener('change', () => {
      const file = slipInput.files?.[0];
      const limitMiB = state.slipMaxBytes / 1024 / 1024;
      $('[data-file-name]', $('#resident-slip-form')).textContent = file ? `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MiB` : `JPG, PNG หรือ WebP ขนาดไม่เกิน ${limitMiB < 1 ? `${Math.round(state.slipMaxBytes / 1024)} KiB` : `${limitMiB.toFixed(limitMiB % 1 ? 2 : 0)} MiB`}`;
    });
    $('#resident-slip-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const error = $('#resident-slip-error');
      showFormError(error);
      if (!state.slipReady) { showFormError(error, 'ระบบตรวจสลิปยังไม่พร้อม กรุณาติดต่อผู้ดูแล'); return; }
      const file = slipInput.files?.[0];
      if (!file) { showFormError(error, 'กรุณาเลือกไฟล์สลิป'); return; }
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > state.slipMaxBytes) { showFormError(error, `รองรับเฉพาะไฟล์ JPG, PNG หรือ WebP ตามขนาดสูงสุดที่ผู้ดูแลตั้งไว้`); return; }
      const button = form.querySelector('[type="submit"]');
      const billId = state.currentBillId;
      setBusy(button, true, 'กำลังส่งสลิป…');
      setDialogBusy(billDialog, true);
      try {
        await api(`/api/resident/bills/${encodeURIComponent(billId)}/slip`, { method: 'POST', body: new FormData(form) });
        form.reset();
        toast('ส่งสลิปแล้ว ระบบกำลังตรวจสอบ');
        if (String(state.currentBillId) === String(billId)) await Promise.all([openBill(billId), loadAll()]);
        else await loadAll();
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setBusy(button, false); setDialogBusy(billDialog, false); }
    });
    const initialHash = location.hash.replace('#', '');
    const initialView = ['bills', 'profile'].includes(initialHash) ? initialHash : 'dashboard';
    switchView(initialView, false);
    if (initialHash !== (initialView === 'dashboard' ? '' : initialView)) replaceResidentHash(initialView);
    window.addEventListener('hashchange', () => {
      const requested = location.hash.replace('#', '');
      const nextView = ['bills', 'profile'].includes(requested) ? requested : 'dashboard';
      if ($('[data-view-panel].is-active', shell)?.dataset.viewPanel === nextView) {
        if (requested !== (nextView === 'dashboard' ? '' : nextView)) replaceResidentHash(nextView);
        return;
      }
      switchView(nextView, false);
      if (requested !== (nextView === 'dashboard' ? '' : nextView)) replaceResidentHash(nextView);
    });
    window.setInterval(() => {
      if (doc.visibilityState === 'visible') refreshVerifyingBills();
    }, 30_000);
    doc.addEventListener('visibilitychange', () => {
      if (doc.visibilityState === 'visible') refreshVerifyingBills(true);
      refreshResidentLineOnReturn();
    });
    function refreshResidentLineOnReturn() {
      if (doc.visibilityState !== 'visible' || Date.now() - lastLineReturnRefresh < 1000) return;
      lastLineReturnRefresh = Date.now();
      refreshLineStatus(true);
    }
    window.addEventListener('focus', refreshResidentLineOnReturn);
    loadAll();
  }

  function initAdminLineBinding(onStatus) {
    const dialog = $('#admin-line-dialog');
    if (!dialog) return { open() {} };
    const codeInput = $('#admin-line-code');
    const codePanel = $('#admin-line-code-panel');
    const openMessage = $('#admin-line-open-message');
    const qrCanvas = $('#admin-line-qr');
    const qrFallback = $('#admin-line-qr-fallback');
    const issueButton = $('#admin-line-issue');
    const refreshButton = $('#admin-line-refresh');
    const unlinkButton = $('#admin-line-unlink');
    const errorNode = $('#admin-line-error');
    let residentId = null;
    let currentStatus = null;
    let epoch = 0;
    let codeRevision = 0;
    let expiresAt = 0;
    let mutation = false;
    let statusRequest = null;
    let pollTimer = null;
    let expiryTimer = null;
    let lastReturnRefresh = 0;

    function clearCode() {
      codeRevision += 1;
      expiresAt = 0;
      if (expiryTimer !== null) window.clearInterval(expiryTimer);
      expiryTimer = null;
      codeInput.value = '';
      codePanel.hidden = true;
      openMessage.hidden = true; openMessage.removeAttribute('href');
      qrCanvas.hidden = true; qrFallback.hidden = true;
      qrCanvas.getContext?.('2d')?.clearRect(0, 0, qrCanvas.width, qrCanvas.height);
      $('#admin-line-expiry').textContent = '';
    }

    function renderStatus() {
      const blocked = currentStatus?.line_blocked === true;
      const linked = currentStatus?.line_verified === true && !blocked;
      const hasLine = !blocked && (linked || Boolean(currentStatus?.line_user_id_hint) || Number(currentStatus?.line_bound_count) > 0);
      const ready = currentStatus?.line_binding_ready === true && !blocked;
      const pending = currentStatus?.pending_expires_at;
      $('#admin-line-status').textContent = !currentStatus ? 'กำลังตรวจสอบสถานะ…'
        : blocked ? 'ผู้พักถูกระงับการผูก LINE ปลดระงับได้ในหน้า “การผูก LINE ผู้พัก”' : linked ? `ผูก LINE แล้ว ${Number(currentStatus.line_bound_count) > 1 ? `${Number(currentStatus.line_bound_count)} บัญชี` : currentStatus.line_user_id_hint || ''}`
          : hasLine ? 'บัญชี LINE เดิมยังไม่ผ่านการยืนยัน กรุณายกเลิกแล้วผูกใหม่'
            : pending ? `รอผู้พักส่งรหัสใน LINE · หมดอายุ ${formatDateTime(pending)} น.` : 'ยังไม่ผูก LINE';
      $('#admin-line-readiness').hidden = !currentStatus || ready || hasLine;
      $('#admin-line-readiness').textContent = blocked ? 'ปลดระงับในหน้า “การผูก LINE ผู้พัก” ก่อนสร้างรหัสใหม่' : 'ระบบยังตั้งค่า LINE ไม่ครบ กรุณาตรวจบัญชี LINE OA';
      issueButton.disabled = mutation || !ready || hasLine;
      if (!mutation) issueButton.textContent = pending || codeInput.value ? 'สร้างรหัสใหม่' : 'สร้างรหัสผูก LINE';
      unlinkButton.hidden = !hasLine;
      unlinkButton.disabled = mutation;
      refreshButton.disabled = mutation || Boolean(statusRequest);
      const friendLink = $('#admin-line-add-friend');
      const friendUrl = currentStatus?.line_add_friend_url;
      const validFriend = typeof friendUrl === 'string' && /^https:\/\/line\.me\/R\/ti\/p\/(?:@|%40)[A-Za-z0-9._-]{1,32}$/.test(friendUrl);
      friendLink.hidden = !validFriend || hasLine || blocked;
      if (validFriend) friendLink.href = friendUrl;
      else friendLink.removeAttribute('href');
    }

    function applyStatus(status) {
      if (String(status?.resident_id) !== String(residentId)) throw new ApiError('สถานะ LINE ไม่ตรงกับผู้พักที่เลือก กรุณาเปิดรายการใหม่');
      const wasLinked = currentStatus?.line_verified === true && currentStatus?.line_blocked !== true;
      const knownStatus = currentStatus !== null;
      currentStatus = status;
      const linked = status.line_verified === true && status.line_blocked !== true;
      if (status.line_user_id_hint || linked || status.line_blocked) clearCode();
      renderStatus();
      onStatus?.(status);
      if (knownStatus && linked && !wasLinked) toast('ผู้พักผูก LINE สำเร็จแล้ว');
    }

    async function refreshStatus(silent = false) {
      if (!dialog.open || residentId === null || mutation || statusRequest) return false;
      const request = { epoch, residentId };
      statusRequest = request;
      if (!silent) { showFormError(errorNode); setBusy(refreshButton, true, 'กำลังตรวจสอบ…'); }
      try {
        const result = await api(`/api/admin/residents/${encodeURIComponent(request.residentId)}/line`);
        if (statusRequest !== request || epoch !== request.epoch || !dialog.open) return false;
        applyStatus(objectFrom(result));
        return true;
      } catch (error) {
        if (statusRequest === request && epoch === request.epoch && !silent) showFormError(errorNode, errorMessage(error));
        return false;
      } finally {
        if (statusRequest === request) {
          statusRequest = null;
          if (!silent) setBusy(refreshButton, false);
          renderStatus();
        }
      }
    }

    function updateCountdown() {
      if (!expiresAt) return;
      const seconds = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
      if (seconds === 0) {
        clearCode();
        if (currentStatus) currentStatus.pending_expires_at = null;
        renderStatus();
        toast('รหัสผูก LINE หมดอายุแล้ว สามารถสร้างรหัสใหม่ได้', 'error');
        return;
      }
      $('#admin-line-expiry').textContent = `เหลือ ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} · ใช้ครั้งเดียว ผู้พักต้องกดส่งรหัสใน LINE`;
    }

    async function issueCode() {
      if (mutation || !currentStatus || currentStatus.line_binding_ready !== true || currentStatus.line_user_id_hint || currentStatus.line_verified === true || currentStatus.line_blocked === true) return;
      const request = { epoch, residentId };
      mutation = true;
      try {
        if ((codeInput.value || currentStatus.pending_expires_at)
          && !await confirmAction('สร้างรหัสผูก LINE ใหม่', 'รหัสและ QR เดิมของผู้พักรายนี้จะใช้ไม่ได้หลังสร้างรหัสใหม่ ต้องการดำเนินการหรือไม่?', 'ยกเลิกรหัสเดิมและสร้างใหม่', true)) return;
        if (epoch !== request.epoch || !dialog.open) return;
        statusRequest = null;
        setBusy(refreshButton, false); showFormError(errorNode);
        clearCode();
        const revision = codeRevision;
        setDialogBusy(dialog, true); setBusy(issueButton, true, 'กำลังสร้างรหัส…'); renderStatus();
        const result = objectFrom(await api(`/api/admin/residents/${encodeURIComponent(request.residentId)}/line/code`, { method: 'POST', body: {} }));
        if (epoch !== request.epoch || !dialog.open) return;
        if (!/^BIND-[A-F0-9]{32}$/.test(String(result.code || ''))) throw new ApiError('ระบบส่งรหัสผูก LINE ไม่ถูกต้อง กรุณาสร้างใหม่');
        const rawExpiry = String(result.expires_at || '');
        expiresAt = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(rawExpiry) ? `${rawExpiry.replace(' ', 'T')}Z` : rawExpiry).getTime();
        if (!Number.isFinite(expiresAt) || expiresAt <= Date.now()) { clearCode(); throw new ApiError('รหัสผูก LINE หมดอายุหรือไม่มีวันหมดอายุที่ถูกต้อง กรุณาสร้างใหม่'); }
        codeInput.value = result.code;
        codePanel.hidden = false;
        currentStatus.pending_expires_at = result.expires_at;
        const messageUrl = safeLineMessageUrl(result.line_message_url, result.code);
        openMessage.hidden = !messageUrl;
        if (messageUrl) openMessage.href = messageUrl;
        updateCountdown(); expiryTimer = window.setInterval(updateCountdown, 1000);
        const qrLibrary = messageUrl ? await getQrLibrary() : null;
        if (epoch !== request.epoch || revision !== codeRevision || codeInput.value !== result.code) return;
        if (messageUrl && qrLibrary?.toCanvas) {
          try {
            await renderQrCanvas(qrLibrary, qrCanvas, messageUrl, { width: 220, margin: 2, errorCorrectionLevel: 'M' });
            if (epoch !== request.epoch || revision !== codeRevision || codeInput.value !== result.code) return;
            qrCanvas.hidden = false;
          } catch (_) { qrFallback.hidden = false; }
        } else qrFallback.hidden = false;
        if (epoch !== request.epoch || revision !== codeRevision || codeInput.value !== result.code) return;
        codeInput.focus(); codeInput.select();
        toast('สร้างรหัสแล้ว ให้ผู้พักเปิด LINE ของตนเองและกดส่งรหัส');
      } catch (error) {
        if (epoch === request.epoch) showFormError(errorNode, errorMessage(error));
      } finally {
        if (epoch === request.epoch) {
          mutation = false; setDialogBusy(dialog, false); setBusy(issueButton, false); renderStatus();
          refreshStatus(true);
        }
      }
    }

    issueButton.addEventListener('click', issueCode);
    refreshButton.addEventListener('click', () => refreshStatus(false));
    $('#admin-line-copy').addEventListener('click', async () => {
      const code = codeInput.value;
      if (!/^BIND-[A-F0-9]{32}$/.test(code) || expiresAt <= Date.now()) return;
      try {
        let copied = false;
        if (navigator.clipboard?.writeText && window.isSecureContext) { await navigator.clipboard.writeText(code); copied = true; }
        else { codeInput.focus(); codeInput.select(); copied = doc.execCommand('copy'); }
        if (!copied) throw new Error('Copy unavailable');
        toast('คัดลอกรหัสแล้ว ส่งให้ผู้พักรายนี้โดยตรง');
      } catch (_) { showFormError(errorNode, 'คัดลอกอัตโนมัติไม่ได้ กรุณาเลือกรหัสแล้วคัดลอกด้วยตนเอง'); }
    });
    unlinkButton.addEventListener('click', async () => {
      if (mutation || !currentStatus?.line_user_id_hint) return;
      const request = { epoch, residentId };
      mutation = true;
      try {
        if (!await confirmAction('ยกเลิก LINE ของผู้พัก', 'บัญชี LINE นี้จะดูห้องและบิลของผู้พักไม่ได้ และจะไม่ได้รับบิลใหม่จนกว่าจะผูกอีกครั้ง ต้องการยกเลิกหรือไม่?', 'ยกเลิกการผูก', true)) return;
        if (epoch !== request.epoch || !dialog.open) return;
        statusRequest = null;
        setBusy(refreshButton, false); showFormError(errorNode);
        setDialogBusy(dialog, true); setBusy(unlinkButton, true, 'กำลังยกเลิก…'); renderStatus();
        const result = await api(`/api/admin/residents/${encodeURIComponent(request.residentId)}/line/unlink`, { method: 'POST', body: {} });
        if (epoch !== request.epoch || !dialog.open) return;
        clearCode(); applyStatus(objectFrom(result));
        toast('ยกเลิก LINE ของผู้พักรายนี้แล้ว');
      } catch (error) { if (epoch === request.epoch) showFormError(errorNode, errorMessage(error)); }
      finally {
        if (epoch === request.epoch) { mutation = false; setDialogBusy(dialog, false); setBusy(unlinkButton, false); renderStatus(); }
      }
    });
    dialog.addEventListener('close', () => {
      epoch += 1; residentId = null; currentStatus = null; statusRequest = null; mutation = false;
      clearCode();
      if (pollTimer !== null) window.clearInterval(pollTimer);
      pollTimer = null;
    });
    const onReturn = () => {
      if (!dialog.open || doc.visibilityState !== 'visible' || Date.now() - lastReturnRefresh < 1000) return;
      lastReturnRefresh = Date.now(); refreshStatus(true);
    };
    doc.addEventListener('visibilitychange', onReturn);
    window.addEventListener('focus', onReturn);
    return {
      open(resident) {
        if (mutation) return;
        epoch += 1; residentId = resident.id; currentStatus = null; statusRequest = null;
        clearCode(); showFormError(errorNode);
        setBusy(refreshButton, false); setBusy(issueButton, false); setBusy(unlinkButton, false);
        $('#admin-line-summary').textContent = `${text(resident.full_name)} · ห้อง ${text(resident.room_code || resident.room?.room_code)}`;
        renderStatus(); openDialog(dialog);
        if (pollTimer !== null) window.clearInterval(pollTimer);
        pollTimer = window.setInterval(() => { if (dialog.open && doc.visibilityState === 'visible') refreshStatus(true); }, 5000);
        refreshStatus(false);
      },
    };
  }

  function initAdminConsole() {
    const app = $('[data-admin-app]');
    if (!app) return;
    const state = {
      rooms: [], bookings: [], residents: [], meters: [], bills: [], payments: [], users: [], settings: {},
      loaded: new Set(), meterController: null, billController: null, bookingController: null, paymentController: null,
      meterPeriod: '', billPeriod: '', billingSettingsAvailable: false, billPreview: null,
      bookingOffset: 0, bookingHasMore: false, bookingPendingCount: 0, paymentOffset: 0, paymentHasMore: false,
      paymentPendingCount: 0, overviewController: null,
    };
    const role = body.dataset.userRole || 'admin';
    const titles = { overview: 'ภาพรวม', rooms: 'ห้องพัก', bookings: 'การจอง', residents: 'ผู้พักอาศัย', meters: 'จดมิเตอร์', bills: 'ใบแจ้งหนี้', payments: 'การชำระเงิน', users: 'ผู้ดูแลระบบ', settings: 'ตั้งค่า', 'line-oas': 'บัญชี LINE OA', 'line-bindings': 'การผูก LINE ผู้พัก' };
    const homeView = 'overview';
    const loaders = {};
    const menuToggles = $$('[data-admin-menu-toggle]');
    const menuToggle = menuToggles[0] || null;
    let lastMenuOpener = null;
    const adminSidebar = $('.admin-sidebar', app);
    const mobileMenu = window.matchMedia('(max-width: 860px)');
    const residentAccessDialog = $('#resident-access-dialog');
    const billingSettingsForm = $('#settings-form');
    const integrationSettingsForm = $('#integration-settings-form');
    let residentActivationSecret = '';
    let adminLogoutInProgress = false;
    let settingsSaveInProgress = false;
    let settingsLoadGeneration = 0;
    let lastVisibilityRefreshAt = 0;
    const adminLineBinding = initAdminLineBinding((status) => {
      const resident = state.residents.find((item) => String(item.id) === String(status.resident_id));
      if (resident) {
        resident.line_verified = status.line_verified;
        resident.line_user_id_hint = status.line_user_id_hint;
        resident.line_bound_count = status.line_bound_count;
        resident.line_blocked = status.line_blocked;
        renderResidents();
      }
    });
    const linePlatform = window.DormLinePlatform?.init({ $, $$, create, api, toast, errorMessage, showFormError, formatDateTime, openDialog, closeDialog, setDialogBusy, setFormFieldsBusy, confirmAction, getQrLibrary, renderQrCanvas });
    loaders['line-oas'] = () => linePlatform?.loadOas();
    loaders['line-bindings'] = () => linePlatform?.loadBindings();

    function clearResidentAccess() {
      residentActivationSecret = '';
      const code = $('#resident-access-code');
      if (code) code.textContent = '•••••-•••••-•••••-•••••';
      const expiry = $('#resident-access-expiry');
      if (expiry) expiry.textContent = '';
      const summary = $('#resident-access-summary');
      if (summary) summary.textContent = '';
      showFormError($('#resident-access-error'));
    }

    function showResidentAccess(data, summary) {
      const access = data?.resident_access && typeof data.resident_access === 'object'
        ? data.resident_access
        : null;
      if (!access || access.activation_required !== true) {
        clearResidentAccess();
        toast('บัญชีนี้เปิดใช้งานแล้ว จึงไม่มี activation code ที่แสดงซ้ำ');
        return false;
      }
      const code = String(access.activation_code || '').toUpperCase();
      if (!/^[0-9A-HJKMNP-TV-Z]{5}(?:-[0-9A-HJKMNP-TV-Z]{5}){3}$/.test(code)) {
        clearResidentAccess();
        toast('เซิร์ฟเวอร์ไม่ส่ง activation code ในรูปแบบที่ปลอดภัย กรุณาออกคีย์ใหม่', 'error');
        return false;
      }
      residentActivationSecret = code;
      $('#resident-access-code').textContent = code;
      $('#resident-access-summary').textContent = summary || 'ส่งมอบรหัสให้ผู้พักที่ยืนยันตัวตนแล้ว';
      const rawExpiry = String(access.expires_at || '');
      const normalizedExpiry = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(rawExpiry)
        ? `${rawExpiry.replace(' ', 'T')}Z`
        : rawExpiry;
      $('#resident-access-expiry').textContent = rawExpiry
        ? `หมดอายุ ${formatDateTime(normalizedExpiry)} น. หรือทันทีหลังใช้สำเร็จ`
        : 'ใช้ได้ครั้งเดียว และจะหมดอายุตามนโยบายระบบ';
      showFormError($('#resident-access-error'));
      openDialog(residentAccessDialog);
      window.setTimeout(() => $('#resident-access-copy')?.focus(), 40);
      return true;
    }

    async function copyResidentAccess() {
      if (!residentActivationSecret) {
        showFormError($('#resident-access-error'), 'รหัสถูกล้างแล้ว กรุณาออกคีย์ใหม่หากยังไม่ได้ส่งมอบ');
        return;
      }
      try {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
          await navigator.clipboard.writeText(residentActivationSecret);
        } else {
          const temporary = create('textarea');
          temporary.value = residentActivationSecret;
          temporary.setAttribute('readonly', '');
          temporary.setAttribute('aria-hidden', 'true');
          temporary.style.position = 'fixed';
          temporary.style.opacity = '0';
          body.append(temporary);
          temporary.select();
          let copied = false;
          try {
            copied = doc.execCommand('copy');
          } finally {
            temporary.value = '';
            temporary.remove();
          }
          if (!copied) throw new Error('Clipboard copy failed');
        }
        showFormError($('#resident-access-error'));
        toast('คัดลอก activation code แล้ว กรุณาส่งให้ผู้พักโดยตรง');
      } catch (_) {
        showFormError($('#resident-access-error'), 'คัดลอกอัตโนมัติไม่ได้ กรุณาจดและส่งมอบรหัสโดยไม่บันทึกในพื้นที่สาธารณะ');
      }
    }

    residentAccessDialog?.addEventListener('close', clearResidentAccess);
    $('#resident-access-copy')?.addEventListener('click', copyResidentAccess);

    function setAdminMenu(open) {
      const active = mobileMenu.matches && open;
      app.classList.toggle('sidebar-open', active);
      menuToggles.forEach((toggle) => toggle.setAttribute('aria-expanded', String(active)));
      if (mobileMenu.matches) {
        adminSidebar?.toggleAttribute('inert', !active);
        adminSidebar?.setAttribute('aria-hidden', String(!active));
      } else {
        adminSidebar?.removeAttribute('inert');
        adminSidebar?.removeAttribute('aria-hidden');
      }
    }

    function rowActions(...buttons) {
      const wrap = create('div', 'table-actions');
      wrap.append(...buttons.filter(Boolean));
      return wrap;
    }

    function replaceAdminHash(name) {
      const hash = name === homeView ? '' : `#${name}`;
      window.history.replaceState(null, '', `${location.pathname}${location.search}${hash}`);
    }

    function hasDirtyMeterRows() {
      return $$('#meter-rows tr[data-dirty="true"]').length > 0;
    }

    function hasDirtySettings() {
      return billingSettingsForm?.dataset.dirty === 'true'
        || integrationSettingsForm?.dataset.dirty === 'true';
    }

    function switchView(name, force = false, updateHash = true) {
      if (!titles[name] || (name === 'users' && role !== 'owner')) return false;
      const activeView = $('[data-admin-view].is-active', app)?.dataset.adminView;
      if (activeView === 'settings' && name !== 'settings' && settingsSaveInProgress) {
        toast('กำลังบันทึกการตั้งค่า กรุณารอให้เสร็จก่อนเปลี่ยนหน้า', 'error');
        return false;
      }
      if (activeView === 'settings' && name !== 'settings' && hasDirtySettings()) {
        if (!window.confirm('มีการตั้งค่าที่ยังไม่บันทึก ต้องการออกจากหน้านี้และทิ้งการแก้ไขหรือไม่?')) return false;
        if (billingSettingsForm?.dataset.dirty === 'true') renderBillingSettings(state.settings);
        if (integrationSettingsForm?.dataset.dirty === 'true') renderIntegrationSettings(objectFrom(state.settings.integrations));
      }
      if (activeView === 'meters' && hasDirtyMeterRows()) {
        const message = name === 'meters'
          ? 'มีเลขมิเตอร์ที่แก้ไขแต่ยังไม่ได้บันทึก ต้องการโหลดข้อมูลใหม่และทิ้งค่าที่กรอกหรือไม่?'
          : 'มีเลขมิเตอร์ที่แก้ไขแต่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้และทิ้งค่าที่กรอกหรือไม่?';
        if (!window.confirm(message)) return false;
        renderMeters();
      }
      const menuWasOpen = mobileMenu.matches && app.classList.contains('sidebar-open');
      $$('[data-admin-view]', app).forEach((view) => { const active = view.dataset.adminView === name; view.hidden = !active; view.classList.toggle('is-active', active); });
      $$('[data-admin-nav]', app).forEach((nav) => { const active = nav.dataset.adminNav === name; nav.classList.toggle('is-active', active); if (active) nav.setAttribute('aria-current', 'page'); else nav.removeAttribute('aria-current'); });
      $('#admin-page-title').textContent = titles[name];
      const targetHash = name === homeView ? '' : `#${name}`;
      if (updateHash && location.hash !== targetHash) location.hash = targetHash;
      setAdminMenu(false);
      if (menuWasOpen) $('#main-content')?.focus({ preventScroll: true });
      const volatileViews = ['overview', 'rooms', 'bookings', 'residents', 'meters', 'bills', 'payments'];
      if (force || volatileViews.includes(name) || !state.loaded.has(name)) loaders[name]?.();
      return true;
    }

    function roomMatches(room) {
      const query = $('#admin-room-search').value.trim().toLocaleLowerCase('th');
      const status = $('#admin-room-status').value;
      return (!status || room.status === status) && (!query || `${room.room_code} ${room.floor} ${room.room_type}`.toLocaleLowerCase('th').includes(query));
    }

    function renderRooms() {
      const rows = $('#admin-room-rows');
      rows.replaceChildren();
      const visible = state.rooms.filter(roomMatches);
      visible.forEach((room) => {
        const tr = create('tr');
        const roomCell = create('div', 'room-cell');
        const image = create('img'); image.src = safeRoomImage(room); image.alt = '';
        const label = create('span'); label.append(create('strong', '', text(room.room_code)), create('small', '', text(room.description, 'ไม่มีคำอธิบาย')));
        roomCell.append(image, label);
        const actions = [];
        const roomCode = text(room.room_code);
        if (room.status === 'available') actions.push(actionButton('เพิ่มผู้พัก', 'add-resident-to-room', room.id, 'button-primary', `เพิ่มผู้พักเข้าห้อง ${roomCode}`));
        actions.push(actionButton('แก้ไข', 'edit-room', room.id, 'button-ghost', `แก้ไขห้อง ${roomCode}`));
        if (room.status === 'available') actions.push(actionButton('ลบ', 'delete-room', room.id, 'button-danger-text', `ลบห้อง ${roomCode}`));
        tr.append(td(roomCell), td(text(room.floor)), td(text(room.room_type)), td(money(room.monthly_rent)), td(pill(room.status)), td(rowActions(...actions), 'align-right'));
        rows.append(tr);
      });
      ['all', 'available', 'reserved', 'occupied'].forEach((key) => { const node = $(`[data-room-stat="${key}"]`); node.textContent = String(key === 'all' ? state.rooms.length : state.rooms.filter((room) => room.status === key).length); });
      const emptyMessage = state.rooms.length === 0
        ? 'ยังไม่มีห้อง เริ่มใช้งานตามลำดับ: ตั้งค่า → เพิ่มห้อง → รับจอง → รับเข้าพัก → จดเลขตั้งต้น'
        : 'ไม่พบห้องที่ตรงกับตัวกรอง';
      setTableState($('#admin-room-state'), visible.length ? 'ready' : 'empty', emptyMessage);
    }

    async function loadRooms() {
      setTableState($('#admin-room-state'), 'loading', 'กำลังโหลดห้องพัก…');
      try {
        const data = await api('/api/admin/rooms');
        state.rooms = listFrom(data, 'rooms'); state.loaded.add('rooms'); state.loaded.delete('meters'); renderRooms();
        if (state.loaded.has('bills')) fillBillRooms();
      } catch (error) { setTableState($('#admin-room-state'), 'error', errorMessage(error)); }
    }

    function openRoomForm(room = null) {
      const form = $('#room-form'); form.reset(); showFormError($('#room-form-error'));
      form.elements.id.value = room?.id || '';
      $('#room-dialog-title').textContent = room ? `แก้ไขห้อง ${text(room.room_code)}` : 'เพิ่มห้องพัก';
      if (room) ['room_code', 'floor', 'room_type', 'monthly_rent', 'description', 'image_key'].forEach((key) => { form.elements[key].value = room[key] ?? ''; });
      form.elements.amenities.value = Array.isArray(room?.amenities) ? room.amenities.join(', ') : '';
      openDialog($('#room-dialog'));
    }

    loaders.rooms = loadRooms;
    $('#admin-room-search').addEventListener('input', renderRooms);
    $('#admin-room-status').addEventListener('change', renderRooms);
    $('[data-open-room-dialog]').addEventListener('click', () => openRoomForm());
    $('#admin-room-rows').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return;
      const room = state.rooms.find((item) => String(item.id) === button.dataset.id); if (!room) return;
      if (button.dataset.action === 'add-resident-to-room') { await openResidentCreateForm(room); return; }
      if (button.dataset.action === 'edit-room') openRoomForm(room);
      if (button.dataset.action === 'delete-room' && await confirmAction('ลบห้องพัก', `ต้องการลบห้อง ${text(room.room_code)} หรือไม่? ระบบจะปฏิเสธหากมีข้อมูลผูกพัน`)) {
        try { await api(`/api/admin/rooms/${encodeURIComponent(room.id)}`, { method: 'DELETE', body: {} }); toast('ลบห้องแล้ว'); loadRooms(); } catch (error) { toast(errorMessage(error), 'error'); }
      }
    });
    $('#room-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const error = $('#room-form-error'); showFormError(error); if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries()); const id = values.id; delete values.id;
      values.monthly_rent = number(values.monthly_rent); values.amenities = String(values.amenities || '').split(',').map((item) => item.trim()).filter(Boolean);
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังบันทึก…');
      try { await api(id ? `/api/admin/rooms/${encodeURIComponent(id)}` : '/api/admin/rooms', { method: id ? 'PUT' : 'POST', body: values }); closeDialog($('#room-dialog')); toast('บันทึกห้องแล้ว'); loadRooms(); }
      catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); }
    });

    const setStat = (selector, value) => { const node = $(selector); if (node) node.textContent = String(value); };
    function renderCountBadge(selectors, value) {
      selectors.forEach((selector) => {
        const node = $(selector);
        if (!node) return;
        node.textContent = String(value);
        node.hidden = value === 0;
      });
    }

    function renderBookingPendingBadge() {
      renderCountBadge(['#booking-nav-count', '#booking-bottom-count'], state.bookingPendingCount);
    }

    function renderPaymentPendingBadge() {
      renderCountBadge(['#payment-nav-count', '#payment-bottom-count'], state.paymentPendingCount);
    }

    // The badge must stay truthful even while an admin works in another view, so
    // it is refreshed from the authoritative server count instead of the rows
    // that happen to be loaded in the payments table.
    async function refreshPaymentPendingCount() {
      try {
        const data = await api('/api/admin/payments?status=pending&offset=0&limit=1');
        state.paymentPendingCount = Math.max(0, Number(data?.pending_count) || 0);
        renderPaymentPendingBadge();
      } catch (_) {
        // The payments view keeps its own visible retry state. A badge refresh
        // failure must not interrupt unrelated admin work.
      }
    }

    function renderBookings() {
      const rows = $('#booking-rows'); rows.replaceChildren();
      const visible = state.bookings;
      visible.forEach((booking) => {
        const tr = create('tr');
        const person = create('span', 'person-cell'); person.append(create('strong', '', text(booking.full_name)), create('small', '', text(booking.phone)));
        const actions = [];
        const bookingLabel = `${text(booking.reference_no || booking.reference || booking.booking_reference || booking.id)} ของ ${text(booking.full_name)}`;
        if (booking.status === 'pending') actions.push(actionButton('ยืนยัน', 'confirm-booking', booking.id, 'button-primary', `ยืนยันการจอง ${bookingLabel}`), actionButton('ยกเลิก', 'cancel-booking', booking.id, 'button-danger-text', `ยกเลิกการจอง ${bookingLabel}`));
        if (booking.status === 'confirmed') actions.push(actionButton('รับเข้าพัก', 'move-in', booking.id, 'button-primary', `รับเข้าพักจากการจอง ${bookingLabel}`), actionButton('ยกเลิก', 'cancel-booking', booking.id, 'button-danger-text', `ยกเลิกการจอง ${bookingLabel}`));
        tr.append(td(text(booking.reference_no || booking.reference || booking.booking_reference || booking.id)), td(person), td(text(booking.room_code || booking.room?.room_code)), td(formatDate(booking.created_at)), td(pill(booking.status)), td(rowActions(...actions), 'align-right'));
        rows.append(tr);
      });
      setStat('[data-booking-stat="pending"]', state.bookingPendingCount);
      setStat('[data-booking-stat="confirmed"]', state.bookings.filter((booking) => booking.status === 'confirmed').length);
      setStat('[data-booking-stat="loaded"]', state.bookings.length);
      setStat('[data-booking-stat-note="loaded"]', state.bookingHasMore ? 'ยังมีรายการเก่ากว่านี้ กดโหลดเพิ่มเติม' : 'ครบทุกรายการตามตัวกรองแล้ว');
      renderBookingPendingBadge();
      setTableState($('#booking-state'), visible.length ? 'ready' : 'empty', 'ไม่พบการจองในสถานะนี้');
      $('#booking-load-more').hidden = !state.bookingHasMore;
    }
    async function loadBookings(append = false) {
      if (!append) { state.bookingOffset = 0; state.bookingHasMore = false; setTableState($('#booking-state'), 'loading'); }
      state.bookingController?.abort(); const controller = new AbortController(); state.bookingController = controller;
      const filter = $('#booking-status-filter').value;
      const query = new URLSearchParams({ offset: String(append ? state.bookingOffset : 0), limit: '100' });
      if (filter) query.set('status', filter);
      const more = $('#booking-load-more'); if (append) setBusy(more, true, 'กำลังโหลด…');
      try {
        const data = await api(`/api/admin/bookings?${query}`, { signal: controller.signal });
        if (state.bookingController !== controller || $('#booking-status-filter').value !== filter) return;
        const items = listFrom(data, 'bookings');
        state.bookings = append ? [...state.bookings, ...items.filter((item) => !state.bookings.some((current) => String(current.id) === String(item.id)))] : items;
        state.bookingOffset = Number(data?.next_offset) || state.bookings.length;
        state.bookingHasMore = data?.has_more === true;
        state.bookingPendingCount = Math.max(0, Number(data?.pending_count) || 0);
        state.loaded.add('bookings'); renderBookings();
      } catch (error) { if (error?.name !== 'AbortError') { if (!append) setTableState($('#booking-state'), 'error', errorMessage(error)); else toast(errorMessage(error), 'error'); } }
      finally { if (state.bookingController === controller) state.bookingController = null; if (append) setBusy(more, false); }
    }
    async function refreshBookingPendingCount() {
      try {
        const data = await api('/api/admin/bookings?status=pending&offset=0&limit=1');
        state.bookingPendingCount = Math.max(0, Number(data?.pending_count) || 0);
        renderBookingPendingBadge();
      } catch (_) {
        // The full bookings view keeps its own visible retry state. A badge
        // refresh failure must not interrupt unrelated admin work.
      }
    }
    loaders.bookings = loadBookings;
    $('#booking-status-filter').addEventListener('change', () => loadBookings(false));
    $('#booking-load-more').addEventListener('click', () => loadBookings(true));
    $('#booking-rows').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return; const booking = state.bookings.find((item) => String(item.id) === button.dataset.id); if (!booking) return;
      if (button.dataset.action === 'move-in') {
        const form = $('#move-in-form'); form.reset(); form.elements.booking_id.value = booking.id;
        form.elements.move_in_date.min = businessIsoDateFromValue(booking.created_at) || isoDateOffsetDays(-31);
        form.elements.move_in_date.max = isoToday();
        form.elements.move_in_date.value = isoToday();
        const candidate = booking.existing_resident;
        const reuseField = $('#move-in-reuse-field'); const reuseHelp = $('#move-in-reuse-help'); const reuseInput = form.elements.reuse_resident_id;
        if (candidate?.id) {
          reuseInput.value = String(candidate.id); reuseInput.disabled = false; reuseInput.required = true;
          form.elements.email.value = candidate.email || '';
          $('#move-in-reuse-label').textContent = `ยืนยันว่า ${text(candidate.full_name)} เป็นบุคคลเดิมและอนุญาตให้เชื่อมประวัติบิล`;
          reuseField.hidden = false; reuseHelp.hidden = false;
        } else {
          reuseInput.value = ''; reuseInput.disabled = true; reuseInput.required = false; reuseField.hidden = true; reuseHelp.hidden = true;
        }
        $('#move-in-summary').textContent = `${text(booking.full_name)} · ห้อง ${text(booking.room_code || booking.room?.room_code)}`; showFormError($('#move-in-error')); openDialog($('#move-in-dialog')); return;
      }
      if (button.dataset.action === 'confirm-booking') {
        const reference = text(booking.reference_no || booking.reference || booking.booking_reference || booking.id);
        const roomCode = text(booking.room_code || booking.room?.room_code);
        if (!await confirmAction('ยืนยันการจอง', `ยืนยันเลขที่ ${reference} ของ ${text(booking.full_name)} สำหรับห้อง ${roomCode} หรือไม่?`, 'ยืนยันการจอง', false)) return;
        try { await api(`/api/admin/bookings/${encodeURIComponent(booking.id)}/confirm`, { method: 'POST', body: {} }); toast('ยืนยันการจองแล้ว'); await Promise.all([loadBookings(), loadRooms()]); } catch (error) { toast(errorMessage(error), 'error'); if (error?.details?.code === 'BOOKING_EXPIRED') await Promise.all([loadBookings(), loadRooms()]); }
      }
      if (button.dataset.action === 'cancel-booking') {
        const form = $('#booking-cancel-form'); form.reset(); form.elements.booking_id.value = booking.id;
        $('#booking-cancel-summary').textContent = `${text(booking.full_name)} · ห้อง ${text(booking.room_code || booking.room?.room_code)}`;
        showFormError($('#booking-cancel-error')); openDialog($('#booking-cancel-dialog'));
      }
    });
    $('#booking-cancel-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget; const error = $('#booking-cancel-error'); showFormError(error);
      if (!form.reportValidity()) return;
      const id = form.elements.booking_id.value; const reason = String(form.elements.reason.value || '').trim();
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังยกเลิก…');
      try {
        await api(`/api/admin/bookings/${encodeURIComponent(id)}/cancel`, { method: 'POST', body: reason ? { reason } : {} });
        closeDialog($('#booking-cancel-dialog')); form.reset(); toast('ยกเลิกการจองแล้ว');
        await Promise.all([loadBookings(), loadRooms()]);
      } catch (requestError) { showFormError(error, errorMessage(requestError)); }
      finally { setBusy(button, false); }
    });
    $('#move-in-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const error = $('#move-in-error'); showFormError(error); if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries());
      const id = values.booking_id; delete values.booking_id;
      const summary = $('#move-in-summary').textContent;
      const dialog = $('#move-in-dialog');
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังรับเข้าพัก…');
      setDialogBusy(dialog, true);
      let busyReleased = false;
      const releaseBusy = () => {
        if (busyReleased) return;
        busyReleased = true;
        setDialogBusy(dialog, false); setBusy(button, false);
      };
      try {
        const result = await api(`/api/admin/bookings/${encodeURIComponent(id)}/move-in`, { method: 'POST', body: values });
        releaseBusy();
        closeDialog(dialog); form.reset();
        showResidentAccess(result, summary);
        toast(result?.idempotent_replay ? 'พบรายการรับเข้าพักเดิมและคืนผลเดิมแล้ว' : 'รับเข้าพักและสร้างบัญชีแล้ว');
        state.loaded.delete('residents');
        await Promise.all([loadBookings(), loadRooms()]);
      }
      catch (requestError) {
        if (requestError?.details?.code === 'RESIDENT_REUSE_CONFIRMATION_REQUIRED') {
          const reuseInput = form.elements.reuse_resident_id; reuseInput.value = String(requestError.details.resident_id || ''); reuseInput.disabled = false; reuseInput.required = true;
          $('#move-in-reuse-label').textContent = `ยืนยันว่า ${text(requestError.details.resident_name)} เป็นบุคคลเดิมและอนุญาตให้เชื่อมประวัติบิล`;
          $('#move-in-reuse-field').hidden = false; $('#move-in-reuse-help').hidden = false; reuseInput.focus();
        }
        showFormError(error, errorMessage(requestError));
      } finally { releaseBusy(); }
    });

    function resetResidentCreateReuse(form) {
      const input = form.elements.reuse_resident_id;
      input.checked = false; input.value = ''; input.disabled = true; input.required = false;
      $('#resident-create-reuse-field').hidden = true;
      $('#resident-create-reuse-help').hidden = true;
    }
    function populateResidentCreateRooms(selectedRoomId = '') {
      const form = $('#resident-create-form');
      const select = form.elements.room_id;
      const rooms = state.rooms.filter((room) => room.status === 'available');
      const prompt = create('option', '', rooms.length ? 'เลือกห้องว่าง' : 'ไม่มีห้องว่างในขณะนี้');
      prompt.value = '';
      select.replaceChildren(prompt);
      rooms.forEach((room) => {
        const option = create('option', '', `ห้อง ${text(room.room_code)} · ชั้น ${text(room.floor)} · ${money(room.monthly_rent)}/เดือน`);
        option.value = String(room.id);
        select.append(option);
      });
      const selected = rooms.some((room) => String(room.id) === String(selectedRoomId)) ? String(selectedRoomId) : '';
      select.value = selected;
      select.disabled = rooms.length === 0;
      $('#resident-create-room-help').textContent = rooms.length
        ? `พบห้องว่าง ${rooms.length} ห้อง ระบบจะตรวจสถานะซ้ำอีกครั้งตอนบันทึก`
        : 'ยังไม่มีห้องว่าง กรุณาตรวจการจอง/ผู้พัก หรือเพิ่มห้องก่อน';
      form.querySelector('[type="submit"]').disabled = rooms.length === 0;
    }
    async function openResidentCreateForm(room = null) {
      if (!state.loaded.has('rooms')) await loadRooms();
      const form = $('#resident-create-form');
      form.reset(); showFormError($('#resident-create-error')); resetResidentCreateReuse(form);
      form.elements.idempotency_key.value = window.crypto?.randomUUID?.()
        || `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`;
      form.elements.move_in_date.value = isoToday();
      form.elements.move_in_date.min = isoDateOffsetDays(-31);
      form.elements.move_in_date.max = isoToday();
      populateResidentCreateRooms(room?.status === 'available' ? room.id : '');
      openDialog($('#resident-create-dialog'));
      window.setTimeout(() => (form.elements.room_id.value ? form.elements.full_name : form.elements.room_id).focus(), 0);
    }

    function renderResidents() {
      const rows = $('#resident-rows'); rows.replaceChildren(); const query = $('#resident-search').value.trim().toLocaleLowerCase('th');
      const visible = state.residents.filter((resident) => !query || `${resident.full_name} ${resident.phone} ${resident.room_code || resident.room?.room_code} ${resident.line_user_id_hint || ''}`.toLocaleLowerCase('th').includes(query));
      visible.forEach((resident) => {
        const tr = create('tr'); const person = create('span', 'person-cell'); person.append(create('strong', '', text(resident.full_name)), create('small', '', text(resident.email, 'ไม่ระบุอีเมล')));
        const line = resident.line_blocked === true ? pill('inactive', 'ระงับการผูก') : resident.line_verified === true ? pill('active', `ยืนยันแล้ว ${Number(resident.line_bound_count) > 1 ? `${Number(resident.line_bound_count)} บัญชี` : text(resident.line_user_id_hint)}`)
          : (resident.line_user_id_hint ? pill('pending', 'รอยืนยันใหม่') : pill('neutral', 'ยังไม่ผูก'));
        const active = !(resident.active === false || resident.active === 0);
        const openingPending = resident.opening_readings_pending === true;
        const access = !active
          ? pill('inactive', 'สิ้นสุดแล้ว')
          : (resident.access_active === true
            ? pill('active', 'เข้าสู่ระบบได้')
            : (resident.activation_pending === true
              ? pill('pending', 'รอเปิดใช้งาน')
              : pill('danger', 'ต้องออกคีย์')));
        const status = create('span', 'person-cell'); status.append(access);
        if (openingPending) status.append(pill('pending', 'รอเลขมิเตอร์เริ่มต้น'));
        const actions = active ? rowActions(
          openingPending && resident.occupancy_id
            ? actionButton('เติมเลขเริ่มต้น', 'opening-readings', resident.id, 'button-primary', `เติมเลขมิเตอร์เริ่มต้นห้อง ${text(resident.room_code || resident.room?.room_code)}`)
            : null,
          actionButton(resident.line_verified || resident.line_user_id_hint || resident.line_blocked ? 'สถานะ LINE' : 'ผูก LINE', 'resident-line', resident.id, 'button-secondary', `จัดการ LINE ของ ${text(resident.full_name)}`),
          actionButton('แก้ข้อมูล', 'edit-resident', resident.id, 'button-ghost', `แก้ข้อมูลผู้พัก ${text(resident.full_name)}`),
          actionButton('ออกคีย์ใหม่', 'reissue-resident-access', resident.id, 'button-secondary', `ออกคีย์ใหม่ให้ ${text(resident.full_name)}`),
          actionButton('ย้ายออก', 'move-out-resident', resident.id, 'button-danger-text', `ย้าย ${text(resident.full_name)} ออกจากห้อง`),
        ) : rowActions();
        tr.append(td(person), td(text(resident.room_code || resident.room?.room_code)), td(text(resident.phone)), td(line), td(formatDate(resident.move_in_date)), td(status), td(actions, 'align-right'));
        rows.append(tr);
      });
      const linked = state.residents.filter((resident) => resident.line_verified === true).length;
      setStat('[data-resident-stat="all"]', state.residents.length);
      setStat('[data-resident-stat="line"]', linked);
      setStat('[data-resident-stat="activation"]', state.residents.filter((resident) => resident.activation_pending === true).length);
      setStat('[data-resident-stat="noline"]', state.residents.length - linked);
      const pendingOpenings = state.residents.filter((resident) => resident.opening_readings_pending === true).length;
      const pendingNote = $('#resident-opening-note');
      pendingNote.hidden = pendingOpenings === 0;
      $('#resident-opening-count').textContent = `${pendingOpenings} ห้องรอเลขมิเตอร์เริ่มต้น`;
      setTableState($('#resident-state'), visible.length ? 'ready' : 'empty', 'ไม่พบผู้พัก');
    }
    async function loadResidents() { setTableState($('#resident-state'), 'loading'); try { const data = await api('/api/admin/residents'); state.residents = listFrom(data, 'residents'); state.loaded.add('residents'); renderResidents(); } catch (error) { setTableState($('#resident-state'), 'error', errorMessage(error)); } }
    loaders.residents = loadResidents; $('#resident-search').addEventListener('input', renderResidents);
    $('[data-open-resident-create]')?.addEventListener('click', () => openResidentCreateForm());
    $('#resident-create-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget; const error = $('#resident-create-error'); showFormError(error);
      if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries());
      values.room_id = Number(values.room_id);
      const room = state.rooms.find((item) => String(item.id) === String(values.room_id));
      const summary = `${text(values.full_name)} · ห้อง ${text(room?.room_code)}`;
      const dialog = $('#resident-create-dialog');
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังรับเข้าพัก…');
      setDialogBusy(dialog, true);
      let busyReleased = false;
      const releaseBusy = () => {
        if (busyReleased) return;
        busyReleased = true;
        setDialogBusy(dialog, false); setBusy(button, false);
        button.disabled = state.rooms.every((room) => room.status !== 'available');
      };
      try {
        const result = await api('/api/admin/residents', { method: 'POST', body: values });
        releaseBusy();
        closeDialog(dialog); form.reset();
        showResidentAccess(result, summary);
        toast(result?.idempotent_replay ? 'พบรายการรับเข้าพักเดิมและแสดงผลเดิมแล้ว' : 'เพิ่มผู้พักหลักและเปิดบัญชีห้องแล้ว');
        await Promise.all([loadResidents(), loadRooms(), loadBookings()]);
      } catch (requestError) {
        if (requestError?.details?.code === 'RESIDENT_REUSE_CONFIRMATION_REQUIRED') {
          const reuseInput = form.elements.reuse_resident_id;
          reuseInput.value = String(requestError.details.resident_id || ''); reuseInput.checked = false;
          reuseInput.disabled = false; reuseInput.required = true;
          $('#resident-create-reuse-label').textContent = `ยืนยันว่า ${text(requestError.details.resident_name)} เป็นบุคคลเดิมและอนุญาตให้เชื่อมประวัติบิล`;
          $('#resident-create-reuse-field').hidden = false; $('#resident-create-reuse-help').hidden = false;
          reuseInput.focus();
        }
        if (['ROOM_NOT_AVAILABLE', 'ROOM_OCCUPIED', 'ROOM_DELETED'].includes(requestError?.details?.code)) {
          await loadRooms(); populateResidentCreateRooms(values.room_id);
        }
        showFormError(error, errorMessage(requestError));
      } finally {
        releaseBusy();
      }
    });
    $('#resident-rows').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return;
      const resident = state.residents.find((item) => String(item.id) === button.dataset.id); if (!resident) return;
      const summary = `${text(resident.full_name)} · ห้อง ${text(resident.room_code || resident.room?.room_code)}`;
      if (button.dataset.action === 'opening-readings') {
        if (resident.opening_readings_pending !== true || !resident.occupancy_id) return;
        const dialog = $('#opening-readings-dialog');
        if (dialogCloseBlocked(dialog)) return;
        const form = $('#opening-readings-form'); form.reset();
        form.elements.occupancy_id.value = resident.occupancy_id;
        $('#opening-readings-summary').textContent = `${summary} · เข้าพัก ${formatDate(resident.move_in_date)}`;
        showFormError($('#opening-readings-error')); openDialog(dialog);
        form.elements.opening_water_reading.focus();
        return;
      }
      if (button.dataset.action === 'resident-line') { adminLineBinding.open(resident); return; }
      if (button.dataset.action === 'reissue-resident-access') {
        if (!await confirmAction(
          'ออก activation code ใหม่',
          `ออกคีย์ใหม่ให้ ${summary} หรือไม่? รหัสผ่านและเซสชันเดิมจะถูกยกเลิกทันที`,
          'ยกเลิกของเดิมและออกคีย์ใหม่',
          true,
        )) return;
        setBusy(button, true, 'กำลังออกคีย์…');
        try {
          const result = await api(`/api/admin/residents/${encodeURIComponent(resident.id)}/access/reissue`, { method: 'POST', body: {} });
          showResidentAccess(result, summary);
          toast('ออก activation code ใหม่และยกเลิกสิทธิ์เดิมแล้ว');
          await loadResidents();
        } catch (requestError) {
          toast(errorMessage(requestError), 'error');
        } finally {
          setBusy(button, false);
        }
        return;
      }
      if (button.dataset.action === 'edit-resident') {
        const form = $('#resident-edit-form'); form.reset(); form.elements.resident_id.value = resident.id;
        ['full_name', 'phone', 'email'].forEach((name) => { form.elements[name].value = resident[name] || ''; });
        $('#resident-edit-summary').textContent = summary; showFormError($('#resident-edit-error')); openDialog($('#resident-edit-dialog'));
      }
      if (button.dataset.action === 'move-out-resident') {
        const form = $('#resident-move-out-form'); form.reset(); form.elements.resident_id.value = resident.id;
        form.elements.move_out_date.value = isoToday(); form.elements.move_out_date.max = isoToday(); form.elements.move_out_date.min = String(resident.move_in_date || '');
        $('#resident-move-out-summary').textContent = summary; showFormError($('#resident-move-out-error')); openDialog($('#resident-move-out-dialog'));
      }
    });
    $('#opening-readings-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget; const dialog = $('#opening-readings-dialog');
      if (dialog.dataset.dialogBusy === 'true') return;
      const error = $('#opening-readings-error'); showFormError(error);
      if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries());
      const id = values.occupancy_id; delete values.occupancy_id;
      const button = form.querySelector('[type="submit"]');
      setBusy(button, true, 'กำลังบันทึก…'); setDialogBusy(dialog, true); setFormFieldsBusy(form, true);
      let busyReleased = false;
      const releaseBusy = () => {
        if (busyReleased) return;
        busyReleased = true;
        setFormFieldsBusy(form, false); setDialogBusy(dialog, false); setBusy(button, false);
      };
      try {
        await api(`/api/admin/occupancies/${encodeURIComponent(id)}/opening-readings`, { method: 'POST', body: values });
        state.billPreview = null;
        ['meters', 'bills', 'overview'].forEach((name) => state.loaded.delete(name));
        releaseBusy(); closeDialog(dialog); form.reset();
        toast('บันทึกเลขมิเตอร์เริ่มต้นแล้ว สามารถจดมิเตอร์และตรวจบิลได้');
        await loadResidents();
      } catch (requestError) {
        showFormError(error, errorMessage(requestError));
      } finally { releaseBusy(); }
    });
    $('#resident-edit-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const error = $('#resident-edit-error'); showFormError(error); if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries()); const id = values.resident_id; delete values.resident_id;
      const original = state.residents.find((item) => String(item.id) === String(id));
      if (original && String(values.phone).replace(/[^0-9+]/g, '') !== String(original.phone).replace(/[^0-9+]/g, '')) {
        if (!await confirmAction('เปลี่ยนเบอร์เข้าสู่ระบบ', 'ยืนยันว่าได้ตรวจสอบตัวตนผู้พักแล้วหรือไม่? เซสชันและรหัสผ่านเดิมจะถูกยกเลิกทันที แล้วระบบจะออก activation code ใหม่', 'ยืนยันและบันทึก', false)) return;
      }
      const dialog = $('#resident-edit-dialog');
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังบันทึก…');
      setDialogBusy(dialog, true);
      let busyReleased = false;
      const releaseBusy = () => {
        if (busyReleased) return;
        busyReleased = true;
        setDialogBusy(dialog, false); setBusy(button, false);
      };
      try {
        const updated = await api(`/api/admin/residents/${encodeURIComponent(id)}`, { method: 'PUT', body: values });
        const summary = `${text(updated?.full_name || values.full_name)} · ห้อง ${text(updated?.room_code || original?.room_code || original?.room?.room_code)}`;
        releaseBusy();
        closeDialog(dialog); form.reset();
        if (updated?.resident_access) showResidentAccess(updated, summary);
        toast(updated?.sessions_revoked ? 'บันทึกแล้ว ยกเลิกสิทธิ์เดิม และออก activation code ใหม่' : 'บันทึกข้อมูลผู้พักแล้ว');
        await loadResidents();
      } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { releaseBusy(); }
    });
    $('#resident-move-out-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const error = $('#resident-move-out-error'); showFormError(error); if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries());
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังย้ายออก…');
      try {
        await api(`/api/admin/residents/${encodeURIComponent(values.resident_id)}/move-out`, { method: 'POST', body: { move_out_date: values.move_out_date } });
        closeDialog($('#resident-move-out-dialog')); form.reset(); toast('ย้ายผู้พักออกและเปิดห้องว่างแล้ว');
        await Promise.all([loadResidents(), loadRooms(), loadBills(), loadPayments()]);
      } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); }
    });
    // A room counts as read for the period only when both meters carry a saved
    // current reading; a half-entered row still needs the admin's attention.
    const meterHasPendingOpening = (meter) => meter.opening_readings_pending === true
      || meter.water_lock_reason === 'opening_readings_pending' || meter.electric_lock_reason === 'opening_readings_pending';
    const meterIsComplete = (meter) => !meterHasPendingOpening(meter) && ['water', 'electric'].every((type) => {
      const value = meter[`${type}_current`];
      return value !== null && value !== undefined && value !== '';
    });
    function renderMeters() {
      const rows = $('#meter-rows'); rows.replaceChildren();
      state.meters.forEach((meter) => {
        const roomId = meter.room_id || meter.id;
        const roomCode = text(meter.room_code || meter.room?.room_code);
        const waterPreviousRaw = meter.water_previous ?? meter.previous_water ?? null;
        const electricPreviousRaw = meter.electric_previous ?? meter.previous_electric ?? null;
        const hasWaterPrevious = waterPreviousRaw !== null && waterPreviousRaw !== '';
        const hasElectricPrevious = electricPreviousRaw !== null && electricPreviousRaw !== '';
        const waterPrevious = hasWaterPrevious ? number(waterPreviousRaw) : null;
        const electricPrevious = hasElectricPrevious ? number(electricPreviousRaw) : null;
        const openingPending = meterHasPendingOpening(meter);
        const waterLocked = openingPending || meter.water_locked === true || meter.water_locked === 1;
        const electricLocked = openingPending || meter.electric_locked === true || meter.electric_locked === 1;
        const lockMessage = (reason) => openingPending ? 'ยังขาดเลขมิเตอร์ ณ วันเข้าพัก'
          : (reason === 'billed' ? 'ออกบิลรอบนี้แล้ว' : 'มีเลขมิเตอร์รอบถัดไปแล้ว');
        const deltaText = (value, previous, hasPrevious) => openingPending || value === '' || value === null || value === undefined
          ? '—' : (hasPrevious ? Math.max(0, number(value) - previous).toFixed(2) : '0.00');
        const tr = create('tr'); tr.dataset.roomId = String(roomId); tr.dataset.period = state.meterPeriod;
        tr.classList.toggle('meter-row-baseline', !hasWaterPrevious || !hasElectricPrevious);
        const previousCell = (type, raw, hasPrevious) => {
          const value = openingPending ? create('span', 'meter-baseline-label', 'รอเลข ณ วันเข้าพัก')
            : (hasPrevious ? text(raw) : create('span', 'meter-baseline-label', 'เดือนแรก · หน่วย 0'));
          const cell = td(value); cell.dataset.meterPrevious = type; return cell;
        };
        const configureInput = (type, value, previous, hasPrevious, locked, reason) => {
          const input = create('input', 'meter-input');
          input.type = 'number'; input.min = hasPrevious ? String(previous) : '0'; input.max = '9999999'; input.step = '0.01'; input.value = value ?? '';
          input.dataset.meterType = type; input.dataset.savedValue = input.value; input.dataset.previous = hasPrevious ? String(previous) : '0'; input.dataset.hasPrevious = String(hasPrevious);
          const label = type === 'water' ? 'น้ำ' : 'ไฟ';
          const lockedText = locked ? ` ล็อกเพราะ${lockMessage(reason)}` : '';
          input.setAttribute('aria-label', `เลขมิเตอร์${label}ปัจจุบัน ห้อง ${roomCode}${hasPrevious || openingPending ? '' : ' เดือนแรกใช้เป็นค่าตั้งต้นและหน่วยเท่ากับ 0'}${lockedText}`);
          input.disabled = locked;
          if (locked) input.title = lockMessage(reason);
          else if (!hasPrevious) input.title = 'เดือนแรก: เลขนี้เป็นค่าตั้งต้น (baseline) และหน่วยที่ใช้เท่ากับ 0';
          return input;
        };
        const waterInput = configureInput('water', meter.water_current, waterPrevious, hasWaterPrevious, waterLocked, meter.water_lock_reason);
        const electricInput = configureInput('electric', meter.electric_current, electricPrevious, hasElectricPrevious, electricLocked, meter.electric_lock_reason);
        const waterDelta = create('strong', 'meter-delta', deltaText(meter.water_current, waterPrevious, hasWaterPrevious)); waterDelta.dataset.meterDelta = 'water';
        const electricDelta = create('strong', 'meter-delta', deltaText(meter.electric_current, electricPrevious, hasElectricPrevious)); electricDelta.dataset.meterDelta = 'electric';
        const updateDirtyState = () => {
          const dirty = $$('input[data-meter-type]', tr).some((input) => input.value !== input.dataset.savedValue);
          tr.classList.toggle('meter-row-dirty', dirty); tr.dataset.dirty = String(dirty);
        };
        waterInput.addEventListener('input', () => { waterDelta.textContent = deltaText(waterInput.value, number(waterInput.dataset.previous), waterInput.dataset.hasPrevious === 'true'); updateDirtyState(); });
        electricInput.addEventListener('input', () => { electricDelta.textContent = deltaText(electricInput.value, number(electricInput.dataset.previous), electricInput.dataset.hasPrevious === 'true'); updateDirtyState(); });
        const allLocked = waterLocked && electricLocked;
        const action = openingPending
          ? actionButton('เติมเลขเริ่มต้น', 'meter-opening-readings', roomId, 'button-secondary', `ไปเติมเลขมิเตอร์ ณ วันเข้าพัก ห้อง ${roomCode}`)
          : (allLocked
            ? create('span', 'status-badge status-neutral', meter.is_billed ? 'ออกบิลแล้ว' : 'ล็อกแล้ว')
            : actionButton('บันทึก', 'save-meter', roomId, 'button-primary', `บันทึกเลขมิเตอร์ห้อง ${roomCode}`));
        tr.append(td(roomCode), previousCell('water', waterPreviousRaw, hasWaterPrevious), td(waterInput), td(waterDelta), previousCell('electric', electricPreviousRaw, hasElectricPrevious), td(electricInput), td(electricDelta), td(action, 'align-right')); rows.append(tr);
      });
      const complete = state.meters.filter(meterIsComplete).length;
      const remaining = state.meters.length - complete;
      setStat('#meter-progress', state.meters.length === 0
        ? 'ยังไม่มีห้องให้จด'
        : (remaining === 0 ? `จดครบแล้วทั้ง ${state.meters.length} ห้อง` : `จดแล้ว ${complete}/${state.meters.length} ห้อง · เหลืออีก ${remaining} ห้อง`));
      setTableState($('#meter-state'), state.meters.length ? 'ready' : 'empty', 'ไม่พบห้องที่ต้องจดมิเตอร์');
    }

    function applySavedMeterResult(tr, result, submittedValues) {
      const meter = state.meters.find((item) => String(item.room_id || item.id) === String(result.room_id));
      let hasUnsavedEdit = false;
      ['water', 'electric'].forEach((type) => {
        const reading = result[type];
        if (!reading || typeof reading !== 'object') return;
        if (meter) {
          meter[type] = { previous: reading.previous, current: reading.current, units: reading.units };
          meter[`${type}_previous`] = reading.previous;
          meter[`${type}_current`] = reading.current;
          meter[`${type}_units`] = reading.units;
        }
        const previousCell = $(`[data-meter-previous="${type}"]`, tr);
        const input = $(`input[data-meter-type="${type}"]`, tr);
        const delta = $(`[data-meter-delta="${type}"]`, tr);
        if (previousCell) previousCell.textContent = text(reading.previous);
        if (input) {
          const submitted = submittedValues[`${type}_current`];
          const changedDuringSave = input.value === '' || !Number.isFinite(Number(input.value)) || Number(input.value) !== Number(submitted);
          input.min = text(reading.previous, '0'); input.dataset.savedValue = text(reading.current, ''); input.dataset.previous = input.min; input.dataset.hasPrevious = 'true';
          if (changedDuringSave) hasUnsavedEdit = true;
          else input.value = input.dataset.savedValue;
          input.title = ''; input.setAttribute('aria-label', `เลขมิเตอร์${type === 'water' ? 'น้ำ' : 'ไฟ'}ปัจจุบัน ห้อง ${text(meter?.room_code || meter?.room?.room_code || tr.dataset.roomId)}`);
          if (delta) delta.textContent = input.value === '' ? '—' : Math.max(0, number(input.value) - number(input.dataset.previous)).toFixed(2);
        }
        else if (delta) delta.textContent = text(reading.units, '0.00');
      });
      tr.classList.remove('meter-row-baseline'); tr.classList.toggle('meter-row-dirty', hasUnsavedEdit); tr.dataset.dirty = String(hasUnsavedEdit);
    }
    function setMeterTableLoading(loading) {
      const rows = $('#meter-rows');
      rows.toggleAttribute('inert', loading);
      rows.setAttribute('aria-busy', String(loading));
      $$('input, button[data-action="save-meter"]', rows).forEach((control) => {
        if (control.tagName === 'BUTTON') {
          // A save may finish while the read is pending. Its own busy flag,
          // rather than the state at read start, decides when it can be reused.
          control.disabled = loading || control.getAttribute('aria-busy') === 'true';
        } else if (loading) {
          if (control.dataset.meterLoadWasDisabled === undefined) control.dataset.meterLoadWasDisabled = String(control.disabled);
          control.disabled = true;
        } else if (control.dataset.meterLoadWasDisabled !== undefined) {
          control.disabled = control.dataset.meterLoadWasDisabled === 'true';
          delete control.dataset.meterLoadWasDisabled;
        }
      });
    }
    async function loadMeters() {
      const period = $('#meter-period').value || todayPeriod(); $('#meter-period').value = period;
      state.meterController?.abort(); const controller = new AbortController(); state.meterController = controller;
      if (state.meterPeriod !== period) $('#meter-rows').replaceChildren();
      setMeterTableLoading(true);
      setTableState($('#meter-state'), 'loading');
      try {
        const data = await api(`/api/admin/meters?period=${encodeURIComponent(period)}`, { signal: controller.signal });
        if (state.meterController !== controller || $('#meter-period').value !== period) return;
        state.meterPeriod = period; state.meters = listFrom(data, 'meters'); state.loaded.add('meters'); renderMeters();
      } catch (error) {
        if (state.meterController === controller && $('#meter-period').value === period && error?.name !== 'AbortError') {
          setTableState($('#meter-state'), 'error', errorMessage(error));
        }
      } finally {
        if (state.meterController === controller) {
          state.meterController = null;
          if (state.meterPeriod !== $('#meter-period').value) $('#meter-rows').replaceChildren();
          setMeterTableLoading(false);
        }
      }
    }
    loaders.meters = loadMeters;
    $('#meter-period').value = todayPeriod();
    $('#meter-period').addEventListener('change', async (event) => {
      const field = event.currentTarget;
      if (hasDirtyMeterRows() && field.value !== state.meterPeriod
        && !window.confirm('มีเลขมิเตอร์ที่ยังไม่ได้บันทึก ต้องการเปลี่ยนเดือนและทิ้งค่าที่กรอกหรือไม่?')) {
        field.value = state.meterPeriod || todayPeriod();
        return;
      }
      await loadMeters();
    });
    async function saveMeterRow(button, confirmLargeUsage = false) {
      if (state.meterController) { toast('กำลังโหลดเลขมิเตอร์ กรุณารอข้อมูลล่าสุดก่อนบันทึก', 'error'); return; }
      const tr = button.closest('tr'); const inputs = $$('input', tr);
      const editableInputs = inputs.filter((input) => !input.disabled);
      if (tr.dataset.period !== $('#meter-period').value) { toast('รอบเดือนเปลี่ยนแล้ว ระบบจะโหลดข้อมูลใหม่เพื่อป้องกันการบันทึกผิดเดือน', 'error'); await loadMeters(); return; }
      if (!editableInputs.length) { toast('เลขมิเตอร์รอบนี้ถูกล็อกแล้ว', 'error'); return; }
      if (!editableInputs.every((input) => input.reportValidity()) || editableInputs.some((input) => input.value === '')) { toast('กรุณากรอกค่ามิเตอร์ที่แก้ไขได้ให้ครบ', 'error'); return; }
      let confirmRetry = false;
      setBusy(button, true, 'กำลังบันทึก…');
      try {
        const payload = { room_id: Number(button.dataset.id), period: tr.dataset.period };
        editableInputs.forEach((input) => { payload[`${input.dataset.meterType}_current`] = number(input.value); });
        if (confirmLargeUsage) payload.confirm_large_usage = true;
        const result = objectFrom(await api('/api/admin/meters', { method: 'POST', body: payload }));
        if ($('#meter-period').value === tr.dataset.period && state.meterPeriod === tr.dataset.period) {
          applySavedMeterResult(tr, result, payload);
          toast('บันทึกห้องนี้แล้ว ค่าแถวอื่นที่ยังไม่ได้บันทึกยังอยู่ครบ');
        } else {
          toast(`บันทึกมิเตอร์รอบ ${tr.dataset.period} แล้ว`);
        }
      } catch (error) {
        if (!confirmLargeUsage && error?.details?.code === 'METER_USAGE_ANOMALY') {
          const anomalies = Array.isArray(error.details.anomalies) && error.details.anomalies.length ? error.details.anomalies : [error.details];
          const summary = anomalies.map((item) => {
            const kind = item.meter_type === 'water' ? 'น้ำ' : 'ไฟ';
            return `มิเตอร์${kind} ${text(item.units)} หน่วย (จาก ${text(item.previous)} เป็น ${text(item.current)})`;
          }).join(' และ ');
          confirmRetry = await confirmAction('หน่วยมิเตอร์สูงผิดปกติ', `${summary} ตรวจเลขทุกค่าจากหน้ามิเตอร์แล้วและยืนยันว่าถูกต้องหรือไม่?`, 'ยืนยันค่าทั้งหมด', true);
        } else toast(errorMessage(error), 'error');
      } finally { setBusy(button, false); }
      if (confirmRetry) await saveMeterRow(button, true);
    }
    $('#meter-rows').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return;
      if (button.dataset.action === 'meter-opening-readings') {
        const meter = state.meters.find((item) => String(item.room_id || item.id) === button.dataset.id);
        if (!meter) return;
        const search = $('#resident-search'); const previousSearch = search.value;
        search.value = text(meter.room_code || meter.room?.room_code, '');
        if (!switchView('residents')) search.value = previousSearch;
        return;
      }
      if (button.dataset.action === 'save-meter') await saveMeterRow(button);
    });

    function billDataReady() { return state.billListAvailable === true && !state.billController && state.billPeriod === $('#bill-period').value; }
    function selectedBillRooms() { return $$('#bill-room-options input:checked').map((input) => Number(input.value)); }
    function billPayloadSignature(payload = billPayload()) { return JSON.stringify(payload); }
    function invalidateBillPreview() { state.billPreview = null; syncBillActionState(); }
    function syncBillActionState() {
      const inputs = $$('#bill-room-options input[type="checkbox"]').filter((input) => !input.disabled);
      const selected = inputs.filter((input) => input.checked).length;
      const master = $('#select-all-bill-rooms');
      master.checked = inputs.length > 0 && selected === inputs.length;
      master.indeterminate = selected > 0 && selected < inputs.length;
      master.disabled = inputs.length === 0;
      const form = $('#bill-builder-form');
      const currentPeriodConfirmed = form.elements.period.value !== todayPeriod() || form.elements.confirm_current_period.checked;
      const ready = billDataReady() && state.billingSettingsAvailable && state.settings.configured === true && selected > 0 && currentPeriodConfirmed;
      const previewButton = $('#preview-bills-button');
      const createButton = $('#create-bills-button');
      const previewFresh = ready && state.billPreview?.signature === billPayloadSignature();
      previewButton.disabled = previewButton.getAttribute('aria-busy') === 'true' || !ready;
      createButton.disabled = createButton.getAttribute('aria-busy') === 'true' || !previewFresh;
      createButton.title = previewFresh ? 'ออกบิลจากยอดที่ตรวจล่าสุด' : 'กด “ตรวจยอดก่อน” และตรวจรายละเอียดให้ครบก่อนออกบิล';
    }
    function syncCurrentPeriodConfirmation() {
      const form = $('#bill-builder-form');
      const current = form.elements.period.value === todayPeriod();
      const confirmation = form.elements.confirm_current_period;
      $('#bill-current-period-confirmation').hidden = !current;
      $('#bill-current-period-help').hidden = !current;
      confirmation.required = current;
      if (!current) confirmation.checked = false;
      syncBillActionState();
    }
    function billPayload() {
      const form = $('#bill-builder-form');
      const values = Object.fromEntries(new FormData(form).entries());
      const payload = {
        period: values.period,
        room_ids: selectedBillRooms(),
        other_description: values.other_description || '',
        other_amount: number(values.other_amount),
      };
      if (values.period === todayPeriod()) payload.confirm_current_period = form.elements.confirm_current_period.checked;
      return payload;
    }
    function fillBillRooms(preserveSelection = true) {
      const selectedRoomIds = preserveSelection ? new Set(selectedBillRooms()) : new Set();
      const wrap = $('#bill-room-options'); wrap.replaceChildren(); const candidates = state.rooms.filter((room) => room.status === 'occupied');
      const billedRoomIds = new Set(state.bills.map((bill) => Number(bill.room_id)));
      candidates.forEach((room) => {
        const billed = billedRoomIds.has(Number(room.id));
        const label = create('label', `check-field room-check${billed ? ' is-disabled' : ''}`);
        const input = create('input'); input.type = 'checkbox'; input.value = room.id; input.disabled = billed; input.checked = !billed && (preserveSelection ? selectedRoomIds.has(Number(room.id)) : true);
        label.append(input, create('span', '', `ห้อง ${text(room.room_code)}${billed ? ' — ออกบิลแล้ว' : ''}`)); wrap.append(label);
      });
      if (!candidates.length) wrap.append(create('span', 'muted', 'ไม่พบห้องที่มีผู้พัก'));
      $('#select-all-bill-rooms').checked = false;
      $('#select-all-bill-rooms').indeterminate = false;
      invalidateBillPreview();
    }
    // A latest payment that is still being verified outranks the stored bill
    // status, so summaries and rows never disagree about the same bill.
    function effectiveBillStatus(bill) {
      const paymentStatus = String(bill.payment_status || '').toLowerCase();
      if (paymentStatus === 'pending') return 'verifying';
      if (paymentStatus === 'verified') return 'paid';
      return String(bill.display_status || bill.status || '');
    }
    function billOutstandingTotal(bills) {
      return bills.reduce((sum, bill) => effectiveBillStatus(bill) === 'paid' ? sum : sum + number(bill.total_amount ?? bill.total), 0);
    }
    function renderAdminBills() {
      const rows = $('#bill-admin-rows'); rows.replaceChildren();
      const lineReady = objectFrom(state.settings.integrations).line_ready === true;
      let eligibleForLine = 0;
      state.bills.forEach((bill) => {
        const tr = create('tr');
        const line = create('div', 'line-delivery');
        const paymentStatus = String(bill.payment_status || '').toLowerCase();
        const paymentLocksLine = paymentStatus === 'pending' || paymentStatus === 'verified';
        const billLineReady = typeof bill.line_ready === 'boolean' ? bill.line_ready : lineReady;
        const deliveryCounts = objectFrom(bill.line_delivery_counts);
        const lineLabels = { pending: 'รอส่ง', processing: 'กำลังส่ง', sent: 'LINE รับคำขอแล้ว', failed: 'ส่งไม่สำเร็จ' };
        if (bill.line_status) line.append(pill(bill.line_status, lineLabels[bill.line_status] || text(bill.line_status)));
        if (Number(deliveryCounts.total) > 1) {
          const parts = [['sent', 'LINE รับแล้ว'], ['pending', 'รอส่ง'], ['processing', 'กำลังส่ง'], ['failed', 'ไม่สำเร็จ']].filter(([key]) => Number(deliveryCounts[key]) > 0).map(([key, label]) => `${label} ${Number(deliveryCounts[key])}`);
          line.append(create('small', 'muted', `${Number(deliveryCounts.total)} ผู้รับ · ${parts.join(' · ')}`));
        }
        if (bill.line_status === 'failed' && bill.line_last_error) {
          const failure = create('small', 'line-error', text(bill.line_last_error));
          failure.title = text(bill.line_last_error);
          line.append(failure);
        }
        if (bill.line_status === 'sent' && (bill.line_accepted_request_id || bill.line_request_id)) {
          line.append(create('small', 'muted', `LINE ref ${text(bill.line_accepted_request_id || bill.line_request_id)}`));
        }
        const mayQueue = typeof bill.line_can_queue === 'boolean' ? bill.line_can_queue && bill.status === 'pending' && !paymentLocksLine : bill.status === 'pending' && !paymentLocksLine && bill.line_linked === true && billLineReady && (!bill.line_status || bill.line_status === 'failed');
        if (mayQueue) { line.append(actionButton(bill.line_status === 'failed' ? 'เข้าคิวใหม่' : 'เข้าคิว', 'send-line', bill.id, 'button-secondary', `ส่งบิล ${text(bill.bill_no || bill.id)} เข้า LINE`)); eligibleForLine++; }
        else if (!bill.line_status && paymentStatus === 'pending') line.append(create('small', 'muted', 'กำลังตรวจสลิป ไม่ส่งแจ้งชำระซ้ำ'));
        else if (!bill.line_status && paymentStatus === 'verified') line.append(create('small', 'muted', 'ชำระแล้ว ไม่ส่งซ้ำ'));
        else if (!bill.line_status && bill.status !== 'pending') line.append(create('small', 'muted', 'ชำระแล้ว ไม่ส่งซ้ำ'));
        else if (!bill.line_status && !bill.line_linked) line.append(create('small', 'muted', 'ผู้พักยังไม่ผูก LINE'));
        else if (!bill.line_status && !billLineReady) line.append(create('small', 'muted', 'OA ที่ผูกยังไม่พร้อมรับบิล'));
        const displayStatus = paymentStatus === 'pending' ? 'verifying' : (paymentStatus === 'verified' ? 'paid' : (bill.display_status || bill.status));
        const billStatusLabel = displayStatus === 'overdue' ? 'เลยกำหนด'
          : (displayStatus === 'verifying' ? 'กำลังตรวจสลิป' : (displayStatus === 'paid' ? 'ชำระแล้ว' : (displayStatus === 'pending' ? 'รอชำระ' : '')));
        tr.append(td(text(bill.bill_no || bill.number || bill.id)), td(text(bill.room_code || bill.room?.room_code)), td(text(bill.resident_name || bill.resident?.full_name)), td(money(bill.total_amount ?? bill.total)), td(formatDate(bill.due_date)), td(pill(displayStatus, billStatusLabel)), td(line, 'align-right'));
        rows.append(tr);
      });
      const bulkButton = $('#line-bulk-button');
      bulkButton.disabled = !billDataReady() || eligibleForLine === 0;
      bulkButton.title = eligibleForLine === 0 ? 'ไม่มีบิลค้างชำระที่ผูก LINE กับ OA ที่พร้อมเข้าคิว' : 'เข้าคิวบิลค้างชำระที่พร้อมส่ง';
      const statuses = state.bills.map(effectiveBillStatus);
      setStat('[data-bill-stat="all"]', state.bills.length);
      setStat('[data-bill-stat="pending"]', statuses.filter((value) => value === 'pending' || value === 'overdue').length);
      setStat('[data-bill-stat="verifying"]', statuses.filter((value) => value === 'verifying').length);
      setStat('[data-bill-stat="outstanding"]', money(billOutstandingTotal(state.bills)));
      setTableState($('#bill-admin-state'), state.bills.length ? 'ready' : 'empty', 'ยังไม่มีบิลในรอบเดือนนี้');
    }
    async function loadBills() {
      const period = $('#bill-period').value || todayPeriod(); $('#bill-period').value = period;
      state.billController?.abort(); const controller = new AbortController(); state.billController = controller;
      state.billListAvailable = false;
      const rows = $('#bill-admin-rows'); rows.setAttribute('inert', '');
      if (state.billPeriod !== period) { state.bills = []; rows.replaceChildren(); fillBillRooms(false); }
      $('#line-bulk-button').disabled = true; syncBillActionState();
      setTableState($('#bill-admin-state'), 'loading');
      try {
        const data = await api(`/api/admin/bills?period=${encodeURIComponent(period)}`, { signal: controller.signal });
        if (state.billController !== controller || $('#bill-period').value !== period) return;
        const preserveSelection = state.billPeriod === period;
        state.billPeriod = period; state.bills = listFrom(data, 'bills'); state.billListAvailable = true;
        state.loaded.add('bills'); renderAdminBills(); fillBillRooms(preserveSelection);
      } catch (error) {
        if (state.billController !== controller || $('#bill-period').value !== period) return;
        state.bills = []; rows.replaceChildren();
        for (const key of ['all', 'pending', 'verifying', 'outstanding']) setStat(`[data-bill-stat="${key}"]`, '—');
        setTableState($('#bill-admin-state'), 'error', errorMessage(error));
      } finally {
        if (state.billController === controller) {
          state.billController = null; rows.removeAttribute('inert');
          if (state.billListAvailable) renderAdminBills();
          syncBillActionState();
        }
      }
    }
    function clearPromptPayTestQr() {
      const dialog = $('#promptpay-test-dialog');
      if (dialog?.open) closeDialog(dialog);
      const stage = $('#promptpay-test-qr-stage');
      if (stage) stage.replaceChildren(create('div', 'qr-placeholder', 'กดสุ่มยอดจากหน้าตั้งค่าเพื่อสร้าง QR ทดสอบ'));
    }
    async function renderPromptPayTestQr(result, form, revision) {
      const qr = objectFrom(result.test_qr);
      const amount = String(qr.amount || '');
      const payload = String(qr.payload || '');
      const generatedAt = String(qr.generated_at || '');
      const qrLibrary = await getQrLibrary();
      const stage = $('#promptpay-test-qr-stage');
      const dialog = $('#promptpay-test-dialog');
      if (!/^1\.\d{2}$/.test(amount) || number(amount) < 1.01 || number(amount) > 1.99) {
        throw new ApiError('เซิร์ฟเวอร์ส่งยอดทดสอบ PromptPay ที่ไม่ถูกต้อง');
      }
      if (!/^000201/.test(payload) || payload.length > 512 || !/[0-9A-Z]{4}$/.test(payload)) {
        throw new ApiError('เซิร์ฟเวอร์ส่งข้อมูล QR PromptPay ที่ไม่ถูกต้อง');
      }
      if (!generatedAt || Number.isNaN(new Date(generatedAt).getTime())) {
        throw new ApiError('เซิร์ฟเวอร์ส่งเวลาสร้าง QR PromptPay ที่ไม่ถูกต้อง');
      }
      if (!qrLibrary?.toCanvas) throw new ApiError('ไม่พบไลบรารีสร้าง QR กรุณารีเฟรชหน้าแล้วลองใหม่');
      if (!stage || !dialog) throw new ApiError('หน้าแสดง QR PromptPay โหลดไม่สมบูรณ์ กรุณารีเฟรชแล้วลองใหม่');

      const canvas = create('canvas');
      canvas.setAttribute('aria-label', `QR PromptPay ทดสอบยอด ${money(amount)}`);
      await renderQrCanvas(qrLibrary, canvas, payload, { width: 240, margin: 2, errorCorrectionLevel: 'M' });
      if (form.dataset.revision !== revision || form.dataset.dirty === 'true') return false;

      const meta = create('div', 'qr-meta');
      meta.append(
        create('strong', '', `ยอดทดสอบ ${money(amount)}`),
        create('small', '', `ชื่อที่บันทึก: ${text(result.recipient_name, 'ไม่ได้ระบุ — ให้ตรวจชื่อจริงในแอปธนาคาร')}`),
        create('small', '', `บัญชีปลายทาง: ${text(result.target_hint)}`),
        create('small', '', `สร้างเมื่อ ${formatDateTime(generatedAt)}`),
      );
      stage.replaceChildren(canvas, meta);
      openDialog(dialog);
      return true;
    }
    function advanceIntegrationRevision(form) {
      form.dataset.revision = String(Number(form.dataset.revision || 0) + 1);
      return form.dataset.revision;
    }
    function markIntegrationSettingsDirty() {
      const form = $('#integration-settings-form');
      if (!form || role !== 'owner' || form.dataset.ownerOnly !== 'true') return;
      clearPromptPayTestQr();
      form.dataset.dirty = 'true';
      advanceIntegrationRevision(form);
      $$('[data-test-integration]', form).forEach((button) => {
        button.disabled = true;
        button.title = 'บันทึกการแก้ไขก่อนทดสอบ';
      });
      $$('[data-integration-test-result]', form).forEach((node) => {
        node.className = 'integration-test-result is-dirty';
        node.textContent = 'มีการแก้ไขที่ยังไม่บันทึก — บันทึกก่อนทดสอบ';
      });
    }
    function applySlipProviderVisibility(form) {
      if (!form) return;
      const provider = String(form.elements.slip_provider?.value || 'none');
      const editable = role === 'owner' && form.dataset.ownerOnly === 'true';
      $$('[data-slip-provider-field]', form).forEach((wrapper) => {
        const active = wrapper.dataset.slipProviderField === provider;
        wrapper.hidden = !active;
        $$('input, select, textarea', wrapper).forEach((control) => { control.disabled = !active || !editable; });
      });
      const help = $('#slip-provider-help', form);
      if (help) {
        help.textContent = provider === 'slipok'
          ? 'แสดงเฉพาะ Branch ID และ API Key ของ SlipOK; ค่า EasySlip เดิมยังถูกเก็บไว้'
          : provider === 'easyslip'
            ? 'แสดงเฉพาะ API Key ของ EasySlip; ค่า SlipOK เดิมยังถูกเก็บไว้'
            : 'ปิดการตรวจสลิปอยู่ โดย API Key เดิมยังถูกเก็บไว้และจะไม่ถูกล้างอัตโนมัติ';
      }
    }
    function renderIntegrationSettings(integrations = {}) {
      const form = $('#integration-settings-form');
      if (!form) return;
      clearPromptPayTestQr();
      const fields = ['promptpay_target', 'promptpay_name', 'payment_receiver_account_tail', 'line_basic_id', 'line_max_attempts', 'notification_batch_size', 'slip_provider', 'slipok_branch_id', 'slip_max_bytes', 'slip_time_tolerance_seconds'];
      fields.forEach((key) => { if (form.elements[key]) form.elements[key].value = integrations[key] ?? (key === 'slip_provider' ? 'none' : ''); });
      if (role === 'owner' && form.dataset.ownerOnly === 'true') {
        $$('input, select', form).forEach((control) => { control.disabled = false; });
        const save = $('[data-integration-save]', form); if (save) save.disabled = false;
      }
      ['line_channel_access_token', 'line_channel_secret', 'slipok_api_key', 'easyslip_api_key'].forEach((key) => {
        if (form.elements[key]) form.elements[key].value = '';
        if (form.elements[`${key}_clear`]) form.elements[`${key}_clear`].checked = false;
        const status = $(`[data-secret-status="${key}"]`, form);
        const configured = integrations[`${key}_configured`] === true;
        if (status) status.textContent = configured ? `ตั้งค่าแล้ว ${text(integrations[`${key}_hint`], '')}`.trim() : 'ยังไม่ได้ตั้งค่า';
      });
      applySlipProviderVisibility(form);
      const automatic = $('#integration-managed-defaults');
      if (automatic) automatic.textContent = `สลิปสูงสุด ${(number(integrations.slip_max_bytes) / 1048576).toLocaleString('th-TH')} MiB · เผื่อเวลา ${number(integrations.slip_time_tolerance_seconds)} วินาที · ลองส่งสูงสุด ${number(integrations.line_max_attempts)} ครั้ง · คิวครั้งละ ${number(integrations.notification_batch_size)} งาน`;
      const readiness = objectFrom(integrations.readiness);
      const webhookUrl = $('[data-line-webhook-url]', form);
      if (webhookUrl) webhookUrl.value = text(integrations.line_webhook_url, '');
      const webhookReadiness = $('[data-line-webhook-readiness]', form);
      if (webhookReadiness) webhookReadiness.textContent = readiness.line_webhook === true
        ? (readiness.line_add_friend === true ? 'บันทึก Token/Secret/Basic ID ครบแล้ว — ยังต้องเปิด Use webhook และ Webhook redelivery แล้วกด Verify ใน LINE Developers Console' : 'Token/Secret ครบแล้ว แต่ยังต้องบันทึก LINE Basic ID เพื่อให้ผู้พักเปิดหน้าเพิ่มเพื่อนได้')
        : 'Webhook ยังไม่พร้อม: ต้องบันทึก Channel access token และ Channel secret ให้ครบ';
      const statusMap = { promptpay: readiness.promptpay === true, line: readiness.line_binding === true, slip: readiness.slip_verification === true };
      Object.entries(statusMap).forEach(([key, ready]) => {
        const node = $(`[data-integration-status="${key}"]`, form);
        if (!node) return;
        node.className = `status-pill status-${ready ? 'active' : 'neutral'}`;
        node.textContent = ready ? 'ตั้งค่าครบ' : 'ยังตั้งค่าไม่ครบ';
      });
      form.dataset.dirty = 'false';
      advanceIntegrationRevision(form);
      $$('[data-test-integration]', form).forEach((button) => {
        button.disabled = role !== 'owner' || form.dataset.ownerOnly !== 'true';
        button.title = button.disabled ? 'เฉพาะ Owner เท่านั้น' : 'ทดสอบเฉพาะค่าที่บันทึกอยู่ในระบบ';
      });
      $$('[data-integration-test-result]', form).forEach((node) => {
        node.className = 'integration-test-result';
        node.textContent = 'ยังไม่ได้ทดสอบค่าที่บันทึกนี้';
      });
    }
    function renderBillDefaults() {
      $('#bill-water-rate').value = money(state.settings.water_rate);
      $('#bill-electric-rate').value = money(state.settings.electric_rate);
      $('#bill-due-date').value = state.settings.default_due_date ? formatDate(state.settings.default_due_date) : 'กำหนดให้เมื่อคำนวณยอด';
    }
    function renderBillingSettings(settings = {}) {
      if (!billingSettingsForm) return;
      ['water_rate', 'electric_rate', 'due_days'].forEach((key) => {
        if (billingSettingsForm.elements[key]) billingSettingsForm.elements[key].value = settings[key] ?? '';
      });
      billingSettingsForm.dataset.dirty = 'false';
    }
    async function loadSettings() {
      const generation = ++settingsLoadGeneration;
      try {
        const data = await api('/api/admin/settings');
        if (generation !== settingsLoadGeneration) return;
        state.settings = objectFrom(data, 'settings');
        state.billingSettingsAvailable = true;
        if (billingSettingsForm?.dataset.dirty !== 'true') renderBillingSettings(state.settings);
        applyBillingReadiness();
        if (integrationSettingsForm?.dataset.dirty !== 'true') renderIntegrationSettings(objectFrom(state.settings.integrations));
        renderBillDefaults();
        invalidateBillPreview();
        state.loaded.add('settings');
        if (state.loaded.has('bills')) renderAdminBills();
      } catch (error) {
        if (generation !== settingsLoadGeneration) return;
        state.billingSettingsAvailable = false;
        applyBillingReadiness();
        clearPromptPayTestQr();
        showFormError($('#settings-error'), errorMessage(error));
        showFormError($('#integration-settings-error'), errorMessage(error));
      }
    }
    function applyBillingReadiness() {
      const ready = state.billingSettingsAvailable && state.settings.configured === true;
      const note = $('#billing-readiness-note');
      const status = $('#billing-settings-status');
      if (note) {
        note.classList.toggle('security-note-warning', !ready);
        const copy = $('span', note); if (copy) copy.textContent = ready ? 'ยืนยันค่ารายเดือนแล้ว สามารถตรวจยอดและออกบิลได้' : 'ยังไม่ยืนยันค่าเริ่มต้น ระบบจะยังไม่ออกบิลเพื่อป้องกันยอดผิด';
      }
      if (status) status.textContent = ready ? `ยืนยันแล้ว${state.settings.updated_at ? ` · แก้ไขล่าสุด ${formatDate(state.settings.updated_at)}` : ''}` : 'ยังไม่ยืนยัน — ตรวจสอบตัวเลขแล้วกด “ยืนยันและบันทึกการตั้งค่า”';
      syncBillActionState();
    }
    async function initBills() { await Promise.all([loadRooms(), loadSettings()]); fillBillRooms(); await loadBills(); }
    loaders.bills = initBills; loaders.settings = loadSettings;
    $('#bill-period').value = todayPeriod();
    syncCurrentPeriodConfirmation();
    $('#bill-period').addEventListener('change', () => {
      invalidateBillPreview();
      syncCurrentPeriodConfirmation();
      loadBills();
    });
    $('#select-all-bill-rooms').addEventListener('change', (event) => {
      $$('#bill-room-options input:not([disabled])').forEach((input) => { input.checked = event.currentTarget.checked; });
      invalidateBillPreview();
    });
    $('#bill-room-options').addEventListener('change', (event) => {
      if (event.target.matches('input[type="checkbox"]')) invalidateBillPreview();
    });
    const billBuilderForm = $('#bill-builder-form');
    billBuilderForm.addEventListener('input', (event) => {
      if (!event.target.matches('#bill-room-options input')) invalidateBillPreview();
    });
    billBuilderForm.elements.confirm_current_period.addEventListener('change', invalidateBillPreview);
    function renderBillPreview(data, previewPayload = billPayload()) {
      const previews = listFrom(data, 'bills'); const issues = listFrom(data, 'issues'); const content = $('#bill-preview-content'); content.replaceChildren();
      const otherContext = create('div', `preview-context${number(previewPayload.other_amount) > 0 ? ' preview-context-warning' : ''}`);
      otherContext.append(
        create('strong', '', number(previewPayload.other_amount) > 0 ? 'รายการอื่นคิดแยกต่อห้อง' : 'ไม่มีค่าใช้จ่ายรายการอื่น'),
        create('span', '', number(previewPayload.other_amount) > 0
          ? `${text(previewPayload.other_description, 'รายการอื่น')} ${money(previewPayload.other_amount)} จะเพิ่มให้ทุกห้องที่เลือก (${previewPayload.room_ids.length} ห้อง)`
          : 'จำนวนเงินอื่นเป็น 0 จึงไม่เพิ่มยอดให้ห้องที่เลือก'),
      );
      content.append(otherContext);
      const automatic = create('div', 'preview-context');
      automatic.append(create('strong', '', 'อัตราและกำหนดชำระจากการตั้งค่าบนเซิร์ฟเวอร์'), create('span', '', `กำหนดชำระ ${formatDate(data.due_date)} · ค่าน้ำและค่าไฟจริงแสดงแยกในแต่ละห้องด้านล่าง`));
      content.append(automatic);
      if (previewPayload.confirm_current_period === true) {
        const currentWarning = create('div', 'preview-context preview-context-warning');
        currentWarning.append(
          create('strong', '', 'กำลังปิดยอดเดือนปัจจุบัน'),
          create('span', '', 'ตรวจเลขมิเตอร์ทุกห้องแล้ว บิลนี้เป็นยอดเต็มเดือน และเลขมิเตอร์จะแก้ไม่ได้หลังออกบิล'),
        );
        content.append(currentWarning);
      }
      previews.forEach((item) => {
        const card = create('article', 'preview-item preview-item-detailed');
        const heading = create('div', 'preview-heading');
        const identity = create('div'); identity.append(create('strong', '', `ห้อง ${text(item.room_code || item.room?.room_code)}`), create('small', '', text(item.resident_name || item.resident?.full_name)));
        heading.append(identity, create('strong', 'preview-total', money(item.total_amount ?? item.total)));
        const breakdown = create('dl', 'preview-breakdown');
        const appendPreviewLine = (label, detail, amount) => {
          const row = create('div');
          const term = create('dt'); term.append(create('span', '', label)); if (detail) term.append(create('small', '', detail));
          row.append(term, create('dd', '', money(amount))); breakdown.append(row);
        };
        appendPreviewLine('ค่าห้อง', '', item.rent_amount);
        appendPreviewLine('ค่าน้ำ', `${text(item.water_units, '0')} หน่วย × ${money(item.water_rate)}`, item.water_amount);
        appendPreviewLine('ค่าไฟ', `${text(item.electric_units, '0')} หน่วย × ${money(item.electric_rate)}`, item.electric_amount);
        if (finiteNumber(item.other_amount) !== null && number(item.other_amount) !== 0) appendPreviewLine(text(item.other_description, 'รายการอื่น'), 'คิดต่อห้องนี้', item.other_amount);
        card.append(heading, breakdown); content.append(card);
      });
      issues.forEach((issue) => { const card = create('article', 'preview-item preview-issue'); const issueDetails = { AMBIGUOUS_OCCUPANCY: 'พบข้อมูลผู้พักซ้อนกันในรอบเดือนนี้ กรุณาตรวจสอบวันเข้า–ออก', NO_OCCUPANCY: 'ไม่มีผู้พักในรอบที่เลือก', METER_OPENING_REQUIRED: 'ยังขาดเลขมิเตอร์ ณ วันเข้าพัก กรุณาไปหน้า “ผู้พักอาศัย” แล้วกด “เติมเลขเริ่มต้น” ของห้องนี้ก่อน' }; const detail = issue.code === 'MISSING_METER' ? `ขาดมิเตอร์: ${(issue.meter_types || []).map((type) => ({ water: 'น้ำ', electric: 'ไฟฟ้า' }[type] || type)).join(', ')}` : (issueDetails[issue.code] || 'ข้อมูลห้องยังไม่พร้อมออกบิล'); card.append(create('strong', '', `ห้อง ${text(issue.room_code || issue.room_id)}`), create('small', '', detail)); content.append(card); });
      if (!previews.length && !issues.length) content.append(create('p', 'empty-inline', 'ไม่มีรายการที่สร้างได้'));
      openDialog($('#preview-dialog')); return { previews, issues };
    }
    $('#preview-bills-button').addEventListener('click', async (event) => {
      if (!billDataReady()) { showFormError($('#bill-builder-error'), 'กรุณารอโหลดบิลของรอบเดือนนี้ให้สำเร็จก่อน'); return; }
      const payload = billPayload(); const requestSignature = billPayloadSignature(payload); const error = $('#bill-builder-error'); showFormError(error);
      if (!$('#bill-builder-form').reportValidity() || !payload.room_ids.length) { showFormError(error, 'กรุณาเลือกอย่างน้อย 1 ห้อง'); return; }
      invalidateBillPreview();
      const button = event.currentTarget; setBusy(button, true, 'กำลังคำนวณ…');
      try {
        const preview = await api('/api/admin/bills/preview', { method: 'POST', body: payload });
        if (billPayloadSignature() !== requestSignature) {
          state.billPreview = null;
          toast('ข้อมูลออกบิลเปลี่ยนระหว่างคำนวณ กรุณากดตรวจยอดอีกครั้ง', 'error');
          return;
        }
        const rendered = renderBillPreview(preview, payload);
        if (rendered.issues.length || !rendered.previews.length || !preview?.preview_token) {
          state.billPreview = null;
          showFormError(error, rendered.issues.length ? `มี ${rendered.issues.length} ห้องที่ข้อมูลยังไม่พร้อม กรุณาแก้ก่อนออกบิล` : 'ไม่มีรายการที่ออกบิลได้');
        } else {
          state.billPreview = {
            signature: billPayloadSignature(payload),
            token: preview.preview_token,
            previews: rendered.previews,
          };
          toast('ตรวจยอดแล้ว ปุ่ม “ออกบิลที่เลือก” พร้อมใช้งานจนกว่าข้อมูลจะเปลี่ยน');
        }
      }
      catch (requestError) { state.billPreview = null; showFormError(error, errorMessage(requestError)); }
      finally { setBusy(button, false); syncBillActionState(); }
    });
    $('#bill-builder-form').addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!billDataReady()) { showFormError($('#bill-builder-error'), 'กรุณาโหลดบิลของรอบเดือนนี้ให้สำเร็จก่อน'); return; }
      const form = event.currentTarget; const payload = billPayload(); const error = $('#bill-builder-error'); showFormError(error);
      if (!form.reportValidity() || !payload.room_ids.length) { showFormError(error, 'กรุณาเลือกห้องที่จะออกบิล'); return; }
      const preview = state.billPreview;
      if (!preview || preview.signature !== billPayloadSignature(payload) || !preview.token) {
        invalidateBillPreview();
        showFormError(error, 'กรุณากด “ตรวจยอดก่อน” และตรวจรายละเอียดล่าสุดให้ครบก่อนออกบิล');
        return;
      }
      const button = form.querySelector('[type="submit"]');
      try {
        const grandTotal = preview.previews.reduce((sum, item) => sum + number(item.total_amount ?? item.total), 0);
        const currentPeriodWarning = payload.confirm_current_period === true
          ? ' นี่คือเดือนปัจจุบัน: ยืนยันว่าจดมิเตอร์ครบ ต้องการคิดยอดเต็มเดือน และยอมรับว่าเลขมิเตอร์จะแก้ไม่ได้หลังออกบิล'
          : '';
        if (!await confirmAction('ยืนยันยอดก่อนออกบิล', `ตรวจรายละเอียดแล้ว ${preview.previews.length} ห้อง ยอดรวม ${money(grandTotal)} สำหรับ${formatPeriod(payload.period)}${currentPeriodWarning} หากข้อมูลเปลี่ยนหลังจากนี้ระบบจะหยุดให้อัตโนมัติ`, 'ยืนยันและออกบิล', false)) return;
        setBusy(button, true, 'กำลังออกบิล…');
        const result = await api('/api/admin/bills/bulk', { method: 'POST', body: { ...payload, preview_token: preview.token } });
        state.billPreview = null;
        const created = listFrom(result, 'created'); const skipped = listFrom(result, 'skipped'); toast(`สร้างบิล ${created.length} รายการ${skipped.length ? ` · ข้ามรายการเดิม ${skipped.length}` : ''}`); await loadBills();
      } catch (requestError) {
        const issues = Array.isArray(requestError.details?.issues) ? ` (${requestError.details.issues.length} ห้องต้องแก้ไข)` : '';
        if (['BILL_PREVIEW_CHANGED', 'BILL_PREVIEW_EXPIRED', 'BILL_PREVIEW_TOKEN_INVALID'].includes(requestError?.details?.code)) state.billPreview = null;
        showFormError(error, errorMessage(requestError) + issues);
      }
      finally { setBusy(button, false); syncBillActionState(); }
    });
    $('#bill-admin-rows').addEventListener('click', async (event) => { const button = event.target.closest('[data-action="send-line"]'); if (!button || !billDataReady()) return; setBusy(button, true, 'กำลังเข้าคิว…'); try { await api(`/api/admin/bills/${encodeURIComponent(button.dataset.id)}/line`, { method: 'POST', body: {} }); toast('นำบิลเข้าคิว LINE แล้ว'); await loadBills(); } catch (error) { toast(errorMessage(error), 'error'); } finally { setBusy(button, false); } });
    $('#line-bulk-button').addEventListener('click', async (event) => { if (!billDataReady()) return; if (!await confirmAction('เข้าคิว LINE ทั้งหมด', `นำบิลรอบ${formatPeriod($('#bill-period').value)} เข้าคิวสำหรับผู้พักที่ผูก LINE ไว้?`, 'เข้าคิว', false)) return; const button = event.currentTarget; setBusy(button, true, 'กำลังเข้าคิว…'); try { const result = await api('/api/admin/bills/line-bulk', { method: 'POST', body: { period: $('#bill-period').value } }); const queued = listFrom(result, 'queued'); const already = listFrom(result, 'already'); const skipped = listFrom(result, 'skipped'); toast(`เข้าคิวใหม่ ${queued.length} รายการ${already.length ? ` · อยู่ในคิว/ส่งแล้ว ${already.length}` : ''}${skipped.length ? ` · ข้าม ${skipped.length}` : ''}`); await loadBills(); } catch (error) { toast(errorMessage(error), 'error'); } finally { setBusy(button, false); renderAdminBills(); } });

    function renderPayments() {
      const rows = $('#payment-rows'); rows.replaceChildren();
      const visible = state.payments;
      visible.forEach((payment) => {
        const tr = create('tr');
        const result = create('div', 'payment-result');
        result.append(pill(payment.status, payment.verifying ? 'กำลังตรวจ' : ''));
        if (payment.rejection_reason) {
          const reason = create('small', 'payment-reason', text(payment.rejection_reason));
          reason.title = text(payment.rejection_reason);
          result.append(reason);
        }
        const actions = [actionLink('ดูสลิป', `/api/admin/payments/${encodeURIComponent(payment.id)}/slip`, 'button-secondary')];
        if (payment.status === 'pending' && !payment.verifying) {
          actions.push(
            actionButton('ตรวจซ้ำ', 'retry-payment', payment.id, 'button-primary', `ตรวจการชำระบิล ${text(payment.bill_no || payment.bill?.bill_no)}`),
            actionButton('ปิดรายการ', 'close-payment', payment.id, 'button-danger-text', `ปิดรายการชำระบิล ${text(payment.bill_no || payment.bill?.bill_no)}`),
          );
        } else if (payment.status === 'pending' && payment.verifying) {
          const waiting = create('small', 'muted', 'รอผลตรวจหรือหมดเวลา 60 วินาที');
          actions.push(waiting);
        }
        tr.append(
          td(formatDateTime(payment.created_at)),
          td(`${text(payment.bill_no || payment.bill?.bill_no)} / ${text(payment.room_code || payment.bill?.room_code)}`),
          td(text(payment.resident_name || payment.resident?.full_name)),
          td(money(payment.amount || payment.bill?.total_amount)),
          td(result),
          td(rowActions(...actions), 'align-right'),
        );
        rows.append(tr);
      });
      setStat('[data-payment-stat="pending"]', state.paymentPendingCount);
      setStat('[data-payment-stat="loaded"]', state.payments.length);
      setStat('[data-payment-stat-note="loaded"]', state.paymentHasMore ? 'ยังมีรายการเก่ากว่านี้ กดโหลดเพิ่มเติม' : 'ครบทุกรายการตามตัวกรองแล้ว');
      setStat('[data-payment-stat="amount"]', money(state.payments.reduce((sum, payment) => sum + number(payment.amount ?? payment.bill?.total_amount), 0)));
      renderPaymentPendingBadge();
      setTableState($('#payment-state'), visible.length ? 'ready' : 'empty', 'ไม่พบรายการชำระ');
      const more = $('#payment-load-more'); more.hidden = !state.paymentHasMore; more.disabled = false;
    }
    async function loadPayments(append = false) {
      if (!append) { state.paymentOffset = 0; state.paymentHasMore = false; setTableState($('#payment-state'), 'loading'); }
      state.paymentController?.abort(); const controller = new AbortController(); state.paymentController = controller;
      const filter = $('#payment-status-filter').value;
      const query = new URLSearchParams({ offset: String(append ? state.paymentOffset : 0), limit: '100' });
      if (filter) query.set('status', filter);
      const more = $('#payment-load-more'); if (append) setBusy(more, true, 'กำลังโหลด…');
      try {
        const data = await api(`/api/admin/payments?${query}`, { signal: controller.signal });
        if (state.paymentController !== controller || $('#payment-status-filter').value !== filter) return;
        const items = listFrom(data, 'payments');
        state.payments = append ? [...state.payments, ...items.filter((item) => !state.payments.some((current) => String(current.id) === String(item.id)))] : items;
        state.paymentOffset = Number(data?.next_offset) || state.payments.length;
        state.paymentHasMore = data?.has_more === true;
        state.paymentPendingCount = Math.max(0, Number(data?.pending_count) || 0);
        state.loaded.add('payments'); renderPayments();
      } catch (error) { if (error?.name !== 'AbortError') { if (!append) setTableState($('#payment-state'), 'error', errorMessage(error)); else toast(errorMessage(error), 'error'); } }
      finally { if (state.paymentController === controller) state.paymentController = null; if (append) setBusy(more, false); }
    }
    loaders.payments = loadPayments; $('#payment-status-filter').addEventListener('change', () => loadPayments(false)); $('#payment-load-more').addEventListener('click', () => loadPayments(true));
    $('#payment-rows').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return;
      const payment = state.payments.find((item) => String(item.id) === button.dataset.id); if (!payment) return;
      const summary = `${text(payment.bill_no || payment.bill?.bill_no)} · ห้อง ${text(payment.room_code || payment.bill?.room_code)} · ${money(payment.amount)}`;
      if (button.dataset.action === 'close-payment') {
        const form = $('#payment-close-form'); form.reset(); form.elements.payment_id.value = payment.id;
        $('#payment-close-summary').textContent = summary; showFormError($('#payment-close-error')); openDialog($('#payment-close-dialog')); return;
      }
      if (button.dataset.action === 'retry-payment') {
        if (!await confirmAction('ตรวจสลิปซ้ำ', `ส่งหลักฐานของ ${summary} ไปตรวจซ้ำกับผู้ให้บริการหรือไม่? อาจใช้โควตา API`, 'ตรวจซ้ำ', false)) return;
        setBusy(button, true, 'กำลังตรวจ…');
        try {
          const result = await api(`/api/admin/payments/${encodeURIComponent(payment.id)}/retry`, { method: 'POST', body: {} });
          const status = result?.status;
          toast(status === 'verified' ? 'ตรวจผ่านและอัปเดตบิลเป็นชำระแล้ว' : (status === 'rejected' ? 'ตรวจแล้วไม่ผ่าน ดูเหตุผลในรายการ' : 'ผู้ให้บริการยังไม่ให้ผลสุดท้าย ระบบคงรายการไว้ให้ตรวจซ้ำ'));
          await Promise.all([loadPayments(), loadBills()]);
        } catch (requestError) { toast(errorMessage(requestError), 'error'); } finally { setBusy(button, false); }
      }
    });
    $('#payment-close-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const error = $('#payment-close-error'); showFormError(error); if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries()); const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังปิด…');
      try {
        await api(`/api/admin/payments/${encodeURIComponent(values.payment_id)}/close`, { method: 'POST', body: { reason: values.reason } });
        closeDialog($('#payment-close-dialog')); form.reset(); toast('ปิดรายการถาวรแล้ว หากผู้พักโอนเงินจริงแล้ว ให้ตรวจยอดก่อนโอนซ้ำ'); await Promise.all([loadPayments(), loadBills()]);
      } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); }
    });
    function renderUsers() { const rows = $('#user-rows'); rows.replaceChildren(); state.users.forEach((user) => { const tr = create('tr'); const status = user.is_active === false || user.is_active === 0 ? 'inactive' : 'active'; const actions = [actionButton('แก้ไข', 'edit-user', user.id, 'button-ghost', `แก้ไขผู้ดูแล ${text(user.username)}`)]; if (status === 'active') actions.push(actionButton('ปิดใช้งาน', 'delete-user', user.id, 'button-danger-text', `ปิดใช้งานผู้ดูแล ${text(user.username)}`)); tr.append(td(text(user.username)), td(text(user.role === 'owner' ? 'เจ้าของ' : 'ผู้ดูแล')), td(pill(status)), td(formatDate(user.updated_at)), td(rowActions(...actions), 'align-right')); rows.append(tr); }); setTableState($('#user-state'), state.users.length ? 'ready' : 'empty', 'ยังไม่มีบัญชีผู้ดูแล'); }
    async function loadUsers() { if (role !== 'owner') return; setTableState($('#user-state'), 'loading'); try { const data = await api('/api/admin/users'); state.users = listFrom(data, 'users'); state.loaded.add('users'); renderUsers(); } catch (error) { setTableState($('#user-state'), 'error', errorMessage(error)); } }
    loaders.users = loadUsers;
    function openUserForm(user = null) { const form = $('#user-form'); form.reset(); showFormError($('#user-form-error')); form.elements.id.value = user?.id || ''; form.elements.username.value = user?.username || ''; form.elements.role.value = user?.role || 'admin'; form.elements.is_active.checked = user ? !(user.is_active === false || user.is_active === 0) : true; form.elements.password.required = !user; $('#user-dialog-title').textContent = user ? `แก้ไข ${text(user.username)}` : 'เพิ่มผู้ดูแล'; $('#user-password-help').textContent = user ? 'เว้นว่างหากไม่ต้องการเปลี่ยนรหัสผ่าน' : 'อย่างน้อย 12 ตัวอักษร'; openDialog($('#user-dialog')); }
    $('[data-open-user-dialog]')?.addEventListener('click', () => openUserForm());
    $('#user-rows').addEventListener('click', async (event) => { const button = event.target.closest('[data-action]'); if (!button) return; const user = state.users.find((item) => String(item.id) === button.dataset.id); if (!user) return; if (button.dataset.action === 'edit-user') openUserForm(user); if (button.dataset.action === 'delete-user' && await confirmAction('ปิดใช้งานผู้ดูแล', `ปิดบัญชี ${text(user.username)} ไม่ให้เข้าสู่ระบบอีกหรือไม่?`)) { try { await api(`/api/admin/users/${encodeURIComponent(user.id)}`, { method: 'DELETE', body: {} }); toast('ปิดใช้งานผู้ดูแลแล้ว'); loadUsers(); } catch (error) { toast(errorMessage(error), 'error'); } } });
    $('#user-form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const error = $('#user-form-error'); showFormError(error); if (!form.reportValidity()) return; const values = Object.fromEntries(new FormData(form).entries()); const id = values.id; delete values.id; values.is_active = form.elements.is_active.checked; if (!values.password) delete values.password; const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังบันทึก…'); try { await api(id ? `/api/admin/users/${encodeURIComponent(id)}` : '/api/admin/users', { method: id ? 'PUT' : 'POST', body: values }); closeDialog($('#user-dialog')); toast('บันทึกผู้ดูแลแล้ว'); loadUsers(); } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); } });

    billingSettingsForm?.addEventListener('input', () => { billingSettingsForm.dataset.dirty = 'true'; });
    billingSettingsForm?.addEventListener('change', () => { billingSettingsForm.dataset.dirty = 'true'; });
    billingSettingsForm?.addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const error = $('#settings-error'); showFormError(error); if (settingsSaveInProgress || !form.reportValidity()) return; const values = Object.fromEntries(new FormData(form).entries()); values.water_rate = number(values.water_rate); values.electric_rate = number(values.electric_rate); values.due_days = Number(values.due_days); const button = form.querySelector('[type="submit"]'); settingsSaveInProgress = true; setFormFieldsBusy(form, true); setBusy(button, true, 'กำลังบันทึก…'); try { const data = await api('/api/admin/settings', { method: 'PUT', body: values }); ++settingsLoadGeneration; state.settings = { ...state.settings, ...objectFrom(data, 'settings') }; state.billingSettingsAvailable = true; renderBillingSettings(state.settings); renderBillDefaults(); invalidateBillPreview(); applyBillingReadiness(); toast('ยืนยันและบันทึกการตั้งค่าแล้ว'); } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { settingsSaveInProgress = false; setFormFieldsBusy(form, false); setBusy(button, false); } });
    integrationSettingsForm?.addEventListener('input', (event) => {
      const target = event.target;
      if (target instanceof HTMLInputElement && ['line_channel_access_token', 'line_channel_secret', 'slipok_api_key', 'easyslip_api_key'].includes(target.name) && target.value !== '') {
        const clear = integrationSettingsForm.elements[`${target.name}_clear`];
        if (clear) clear.checked = false;
      }
      markIntegrationSettingsDirty();
    });
    integrationSettingsForm?.addEventListener('change', (event) => {
      if (event.target?.name === 'slip_provider') applySlipProviderVisibility(integrationSettingsForm);
      if (event.target instanceof HTMLInputElement && event.target.name.endsWith('_clear') && event.target.checked) {
        const secret = integrationSettingsForm.elements[event.target.name.replace(/_clear$/, '')];
        if (secret) secret.value = '';
      }
      markIntegrationSettingsDirty();
    });
    window.addEventListener('beforeunload', (event) => {
      if (adminLogoutInProgress || (!residentActivationSecret && !hasDirtySettings() && !hasDirtyMeterRows())) return;
      event.preventDefault(); event.returnValue = '';
    });
    integrationSettingsForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (role !== 'owner' || form.dataset.ownerOnly !== 'true') return;
      const error = $('#integration-settings-error');
      showFormError(error);
      if (settingsSaveInProgress || !form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries());
      // Technical limits are retained server-side; the form sends only editable business settings.
      ['slipok_api_key_clear', 'easyslip_api_key_clear'].forEach((key) => { if (form.elements[key]) values[key] = !form.elements[key].disabled && form.elements[key].checked; });
      const button = form.querySelector('[type="submit"]');
      settingsSaveInProgress = true;
      setFormFieldsBusy(form, true);
      setBusy(button, true, 'กำลังเข้ารหัสและบันทึก…');
      try {
        const integrations = objectFrom(await api('/api/admin/settings/integrations', { method: 'PUT', body: values }), 'integrations');
        ++settingsLoadGeneration;
        state.settings.integrations = integrations;
        renderIntegrationSettings(integrations);
        toast('บันทึกการเชื่อมต่อแล้ว');
      } catch (requestError) {
        clearPromptPayTestQr();
        showFormError(error, errorMessage(requestError));
      } finally { settingsSaveInProgress = false; setFormFieldsBusy(form, false); setBusy(button, false); }
    });
    $$('[data-test-integration]').forEach((button) => button.addEventListener('click', async () => {
      const form = $('#integration-settings-form');
      if (!form || form.dataset.dirty === 'true' || button.disabled) return;
      const integration = button.dataset.testIntegration;
      const resultNode = $(`[data-integration-test-result="${integration}"]`, form);
      const revision = form.dataset.revision;
      if (integration === 'promptpay') clearPromptPayTestQr();
      if (resultNode) { resultNode.className = 'integration-test-result'; resultNode.textContent = 'กำลังทดสอบค่าที่บันทึก…'; }
      setBusy(button, true, 'กำลังทดสอบ…');
      try {
        const result = await api('/api/admin/settings/integrations/test', { method: 'POST', body: { integration } });
        if (form.dataset.revision !== revision || form.dataset.dirty === 'true') return;
        let detail = result.target_hint || '';
        if (integration === 'promptpay') {
          if (result.ready !== true || !await renderPromptPayTestQr(result, form, revision)) return;
          detail = `${result.target_hint || ''} · ยอด ${money(objectFrom(result.test_qr).amount)}`;
        } else if (integration === 'line') detail = result.display_name || result.basic_id || '';
        else if (result.provider) {
          const quota = result.quota_remaining;
          const quotaDetail = result.provider === 'easyslip' && (quota === null || quota === undefined)
            ? 'โควตาไม่จำกัด'
            : (Number.isFinite(Number(quota)) ? `โควตาคงเหลือ ${Number(quota)}` : '');
          detail = `${result.provider}${quotaDetail ? ` · ${quotaDetail}` : ''}`;
        }
        if (resultNode) { resultNode.className = 'integration-test-result is-success'; resultNode.textContent = `ทดสอบค่าที่บันทึกแล้ว: ผ่าน${detail ? ` · ${text(detail)}` : ''}`; }
        toast(`ทดสอบ ${integration === 'promptpay' ? 'PromptPay' : integration === 'line' ? 'LINE Bot' : 'ระบบตรวจสลิป'} สำเร็จ${detail ? ` · ${detail}` : ''}`);
      } catch (error) {
        if (form.dataset.revision !== revision || form.dataset.dirty === 'true') return;
        if (integration === 'promptpay') clearPromptPayTestQr();
        if (resultNode) { resultNode.className = 'integration-test-result is-error'; resultNode.textContent = `ทดสอบค่าที่บันทึกแล้ว: ไม่ผ่าน · ${errorMessage(error)}`; }
        toast(errorMessage(error), 'error');
      }
      finally { setBusy(button, false); button.disabled = role !== 'owner' || form.dataset.dirty === 'true'; }
    }));

    function setOverviewError(message) {
      const alert = $('#overview-error');
      if (!alert) return;
      const copy = $('[data-error-message]', alert);
      if (copy) copy.textContent = message || '';
      alert.hidden = !message;
    }

    function renderOverviewTask(key, value, done, total, note) {
      setStat(`[data-overview-task="${key}"]`, value);
      const bar = $(`[data-overview-bar="${key}"]`);
      if (bar) bar.style.width = `${total > 0 ? Math.max(0, Math.min(100, Math.round((done / total) * 100))) : 0}%`;
      setStat(`[data-overview-task-note="${key}"]`, note);
    }

    function clearOverviewTask(key) {
      renderOverviewTask(key, '—', 0, 0, 'โหลดข้อมูลไม่สำเร็จ กรุณากดรีเฟรช');
    }

    function renderWorkerHealth(health) {
      const card = $('#overview-health-card');
      if (!card) return;
      card.hidden = false;
      const status = String(health?.status || '');
      const badge = $('#overview-health-status');
      if (badge) {
        const tone = status === 'ok' ? 'active' : (status === 'degraded' ? 'pending' : 'danger');
        badge.className = `status-pill status-${tone}`;
        badge.textContent = { ok: 'ทำงานปกติ', degraded: 'ต้องตรวจสอบ', unhealthy: 'ไม่ทำงาน' }[status] || 'ไม่ทราบสถานะ';
      }
      const detail = $('#overview-health-detail');
      if (detail) {
        const queue = objectFrom(health?.queue);
        const heartbeat = finiteNumber(health?.heartbeat_age_seconds);
        detail.replaceChildren();
        [
          ['ตัวส่งข้อความ', health?.worker_live === true
            ? `กำลังทำงาน · สัญญาณล่าสุด ${heartbeat === null ? '—' : `${heartbeat} วินาทีที่แล้ว`}`
            : 'ไม่พบสัญญาณ กรุณาตรวจ worker หรือ scheduler'],
          ['รอส่งในคิว', `${number(queue.pending)} รายการ`],
          ['ส่งไม่สำเร็จ', `${number(queue.failed)} รายการ`],
          ['งานค้างเกินกำหนด', `${number(queue.stale_claims)} รายการ`],
          ['ล่าช้าสูงสุด', `${number(queue.oldest_due_lag_seconds)} วินาที`],
        ].forEach(([label, value]) => detail.append(create('dt', '', label), create('dd', '', value)));
      }
      const note = $('#overview-health-note');
      if (note) {
        note.textContent = health?.worker_live === true
          ? 'ถ้าคิวค้างนานหรือมีรายการส่งไม่สำเร็จ ให้เข้าไปกดเข้าคิวใหม่ในหน้าใบแจ้งหนี้'
          : 'worker หยุดทำงาน บิลจะค้างอยู่ในคิวและผู้พักจะยังไม่ได้รับข้อความ';
      }
    }

    async function loadOverview() {
      state.overviewController?.abort();
      const controller = new AbortController();
      state.overviewController = controller;
      const period = todayPeriod();
      setStat('#overview-period', formatPeriod(period));
      const options = { signal: controller.signal };
      const requests = [
        api('/api/admin/rooms', options),
        api('/api/admin/bookings?status=pending&offset=0&limit=1', options),
        api('/api/admin/payments?status=pending&offset=0&limit=1', options),
        api(`/api/admin/bills?period=${encodeURIComponent(period)}`, options),
        api(`/api/admin/meters?period=${encodeURIComponent(period)}`, options),
      ];
      if (role === 'owner') requests.push(api('/api/admin/operations/health', options));
      let results;
      try {
        results = await Promise.allSettled(requests);
      } finally {
        if (state.overviewController === controller) state.overviewController = null;
      }
      // A newer refresh already aborted this one; only its results may paint.
      if (controller.signal.aborted) return;
      const [roomsResult, bookingsResult, paymentsResult, billsResult, metersResult, healthResult] = results;
      const failures = [];

      let occupiedRooms = 0;
      if (roomsResult.status === 'fulfilled') {
        const rooms = listFrom(roomsResult.value, 'rooms');
        occupiedRooms = rooms.filter((room) => room.status === 'occupied').length;
        const available = rooms.filter((room) => room.status === 'available').length;
        setStat('[data-overview-stat="rooms"]', available);
        setStat('[data-overview-note="rooms"]', `จาก ${rooms.length} ห้อง · มีผู้พัก ${occupiedRooms} ห้อง`);
      } else {
        failures.push('ห้องพัก');
        setStat('[data-overview-stat="rooms"]', '—');
        setStat('[data-overview-note="rooms"]', 'โหลดไม่สำเร็จ');
      }

      if (bookingsResult.status === 'fulfilled') {
        state.bookingPendingCount = Math.max(0, Number(bookingsResult.value?.pending_count) || 0);
        renderBookingPendingBadge();
        setStat('[data-overview-stat="bookings"]', state.bookingPendingCount);
        setStat('[data-overview-note="bookings"]', state.bookingPendingCount === 0 ? 'ไม่มีคำขอค้าง' : 'รอผู้ดูแลกดยืนยันหรือยกเลิก');
      } else {
        failures.push('การจอง');
        setStat('[data-overview-stat="bookings"]', '—');
        setStat('[data-overview-note="bookings"]', 'โหลดไม่สำเร็จ');
      }

      if (paymentsResult.status === 'fulfilled') {
        state.paymentPendingCount = Math.max(0, Number(paymentsResult.value?.pending_count) || 0);
        renderPaymentPendingBadge();
        setStat('[data-overview-stat="payments"]', state.paymentPendingCount);
        setStat('[data-overview-note="payments"]', state.paymentPendingCount === 0 ? 'ไม่มีสลิปค้าง' : 'ดูหลักฐานแล้วตรวจซ้ำหรือปิดรายการ');
      } else {
        failures.push('การชำระเงิน');
        setStat('[data-overview-stat="payments"]', '—');
        setStat('[data-overview-note="payments"]', 'โหลดไม่สำเร็จ');
      }

      if (billsResult.status === 'fulfilled') {
        const bills = listFrom(billsResult.value, 'bills');
        const statuses = bills.map(effectiveBillStatus);
        const paid = statuses.filter((value) => value === 'paid').length;
        const unpaid = bills.length - paid;
        const billedRooms = new Set(bills.map((bill) => Number(bill.room_id))).size;
        setStat('[data-overview-stat="outstanding"]', money(billOutstandingTotal(bills)));
        setStat('[data-overview-note="outstanding"]', bills.length === 0 ? 'ยังไม่ออกบิลรอบนี้' : `ค้าง ${unpaid} ใบ จาก ${bills.length} ใบ`);
        renderOverviewTask('bills', `${billedRooms}/${occupiedRooms}`, billedRooms, occupiedRooms,
          occupiedRooms === 0
            ? 'ยังไม่มีห้องที่มีผู้พัก'
            : (billedRooms >= occupiedRooms ? 'ออกบิลครบทุกห้องแล้ว' : `เหลืออีก ${occupiedRooms - billedRooms} ห้องที่ยังไม่ออกบิล`));
        renderOverviewTask('collection', `${paid}/${bills.length}`, paid, bills.length,
          bills.length === 0
            ? 'ยังไม่มีบิลให้ติดตาม'
            : (unpaid === 0 ? 'เก็บครบทุกใบแล้ว' : `ค้าง ${unpaid} ใบ รวม ${money(billOutstandingTotal(bills))}`));
      } else {
        failures.push('ใบแจ้งหนี้');
        setStat('[data-overview-stat="outstanding"]', '—');
        setStat('[data-overview-note="outstanding"]', 'โหลดไม่สำเร็จ');
        clearOverviewTask('bills');
        clearOverviewTask('collection');
      }

      if (metersResult.status === 'fulfilled') {
        const meters = listFrom(metersResult.value, 'meters');
        const done = meters.filter(meterIsComplete).length;
        renderOverviewTask('meters', `${done}/${meters.length}`, done, meters.length,
          meters.length === 0
            ? 'ยังไม่มีห้องให้จด'
            : (done >= meters.length ? 'จดครบทุกห้องแล้ว' : `เหลืออีก ${meters.length - done} ห้อง`));
      } else {
        failures.push('มิเตอร์');
        clearOverviewTask('meters');
      }

      if (role === 'owner') {
        if (healthResult?.status === 'fulfilled') renderWorkerHealth(objectFrom(healthResult.value?.notifications));
        else {
          failures.push('สถานะการส่ง LINE');
          const card = $('#overview-health-card');
          if (card) card.hidden = false;
          const badge = $('#overview-health-status');
          if (badge) { badge.className = 'status-pill status-neutral'; badge.textContent = 'อ่านสถานะไม่ได้'; }
          $('#overview-health-detail')?.replaceChildren();
        }
      }

      setOverviewError(failures.length ? `โหลดข้อมูลบางส่วนไม่สำเร็จ: ${failures.join(' · ')} กรุณากดรีเฟรช` : '');
      state.loaded.add('overview');
    }
    loaders.overview = loadOverview;
    $$('[data-overview-jump]').forEach((button) => button.addEventListener('click', () => switchView(button.dataset.overviewJump)));

    $$('[data-admin-nav]').forEach((button) => button.addEventListener('click', () => switchView(button.dataset.adminNav)));
    $$('[data-refresh]').forEach((button) => button.addEventListener('click', () => switchView(button.dataset.refresh, true)));
    menuToggles.forEach((toggle) => toggle.addEventListener('click', () => {
      const open = !app.classList.contains('sidebar-open');
      lastMenuOpener = toggle;
      setAdminMenu(open);
      if (open) $('[data-admin-nav]', adminSidebar)?.focus();
    }));
    app.addEventListener('click', (event) => { if (mobileMenu.matches && app.classList.contains('sidebar-open') && event.target === app) setAdminMenu(false); });
    doc.addEventListener('keydown', (event) => {
      if (!mobileMenu.matches || !app.classList.contains('sidebar-open')) return;
      if (event.key === 'Escape') { setAdminMenu(false); (lastMenuOpener || menuToggle)?.focus(); return; }
      if (event.key !== 'Tab') return;
      const focusable = $$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])', adminSidebar).filter((node) => !node.hidden);
      if (!focusable.length) return;
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    if (typeof mobileMenu.addEventListener === 'function') mobileMenu.addEventListener('change', () => setAdminMenu(false));
    setAdminMenu(false);
    const adminLogoutButtons = $$('[data-admin-logout]');
    adminLogoutButtons.forEach((button) => button.addEventListener('click', async () => {
      if (adminLogoutInProgress) return;
      adminLogoutInProgress = true;
      const hasUnsavedChanges = hasDirtySettings() || hasDirtyMeterRows();
      const hasUncopiedAccess = Boolean(residentActivationSecret);
      if ((hasUnsavedChanges || hasUncopiedAccess)
        && !await confirmAction(
          hasUncopiedAccess ? 'ยังมี activation code แสดงอยู่' : 'ออกจากระบบทั้งที่ยังไม่บันทึก',
          hasUncopiedAccess
            ? 'รหัสเปิดใช้งานจะแสดงได้ครั้งเดียวและจะถูกล้างเมื่อออกจากระบบ ยืนยันว่าได้ส่งมอบให้ผู้พักแล้วและต้องการออกหรือไม่?'
            : 'มีการตั้งค่าหรือเลขมิเตอร์ที่ยังไม่บันทึก ต้องการทิ้งข้อมูลเหล่านี้และออกจากระบบหรือไม่?',
          hasUncopiedAccess ? 'ยืนยันว่าได้ส่งมอบแล้ว' : 'ทิ้งข้อมูลและออก',
          true,
        )) {
        adminLogoutInProgress = false;
        return;
      }
      adminLogoutButtons.forEach((item) => { item.disabled = true; item.setAttribute('aria-busy', 'true'); });
      try {
        await api('/api/auth/admin/logout', { method: 'POST', body: {} });
        location.assign('/admin/login');
      } catch (errorValue) {
        adminLogoutInProgress = false;
        adminLogoutButtons.forEach((item) => { item.disabled = false; item.removeAttribute('aria-busy'); });
        toast(errorMessage(errorValue, 'ออกจากระบบไม่สำเร็จ กรุณาลองอีกครั้ง'), 'error');
      }
    }));
    const initialHash = location.hash.replace('#', '');
    const initialView = titles[initialHash] && (initialHash !== 'users' || role === 'owner') ? initialHash : homeView;
    switchView(initialView, false, false);
    if (initialView !== 'bookings') refreshBookingPendingCount();
    if (initialView !== 'payments') refreshPaymentPendingCount();
    doc.addEventListener('visibilitychange', () => {
      if (doc.visibilityState !== 'visible' || Date.now() - lastVisibilityRefreshAt < 30_000) return;
      lastVisibilityRefreshAt = Date.now();
      refreshBookingPendingCount();
      refreshPaymentPendingCount();
      const active = $('[data-admin-view].is-active', app)?.dataset.adminView;
      if (active === 'rooms') loadRooms();
      if (active === 'overview') loadOverview();
    });
    if (initialHash !== (initialView === homeView ? '' : initialView)) replaceAdminHash(initialView);
    window.addEventListener('hashchange', () => {
      const requested = location.hash.replace('#', '');
      const nextView = titles[requested] && (requested !== 'users' || role === 'owner') ? requested : homeView;
      if ($('[data-admin-view].is-active', app)?.dataset.adminView === nextView) {
        if (requested !== (nextView === homeView ? '' : nextView)) replaceAdminHash(nextView);
        return;
      }
      if (!switchView(nextView, false, false)) {
        replaceAdminHash($('[data-admin-view].is-active', app)?.dataset.adminView || homeView);
        return;
      }
      if (requested !== (nextView === homeView ? '' : nextView)) replaceAdminHash(nextView);
    });
  }

  setupCommonInteractions();
  loadPublicSupport();
  initPublicRooms();
  initLogin('#admin-login-form', '/api/auth/admin/login', '/admin', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
  initResidentLogin();
  initResidentPortal();
  initAdminConsole();
})();
