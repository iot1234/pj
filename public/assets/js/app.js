(() => {
  'use strict';

  const doc = document;
  const body = doc.body;
  const csrfToken = doc.querySelector('meta[name="csrf-token"]')?.content || '';
  const moneyFormatter = new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    minimumFractionDigits: 2,
  });
  const dateFormatter = new Intl.DateTimeFormat('th-TH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    timeZone: 'Asia/Bangkok',
  });
  const dateTimeFormatter = new Intl.DateTimeFormat('th-TH', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    timeZone: 'Asia/Bangkok',
  });
  const businessDateFormatter = new Intl.DateTimeFormat('en', {
    year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Bangkok',
  });
  const activeMutations = new Set();
  const maxApiResponseBytes = 2 * 1024 * 1024;

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
  const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
  const money = (value) => moneyFormatter.format(number(value));
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
  const isoDateAfterDays = (days) => {
    const { year, month, day } = businessDateParts();
    const date = new Date(Date.UTC(year, month - 1, day));
    date.setUTCDate(date.getUTCDate() + Math.max(0, Number(days) || 0));
    return isoBusinessDate({ year: date.getUTCFullYear(), month: date.getUTCMonth() + 1, day: date.getUTCDate() });
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
    const parsed = new Date(String(value));
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
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
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
    unpaid: 'รอชำระ', due: 'รอชำระ', overdue: 'เลยกำหนด', paid: 'ชำระแล้ว',
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

  function actionButton(label, action, id, style = 'button-ghost') {
    const button = create('button', `button button-small ${style}`, label);
    button.type = 'button';
    button.dataset.action = action;
    button.dataset.id = String(id);
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
    $$('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => closeDialog(button.closest('dialog'))));
    $$('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDialog(dialog);
    }));
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

    function populateSelect(select, values) {
      const existing = select.firstElementChild?.cloneNode(true);
      select.replaceChildren();
      if (existing) select.append(existing);
      [...new Set(values.filter(Boolean).map(String))].sort((a, b) => a.localeCompare(b, 'th', { numeric: true })).forEach((value) => {
        const option = create('option', '', value);
        option.value = value;
        select.append(option);
      });
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
      $('#booking-room-label').textContent = `ห้อง ${text(room.room_code)}`;
      $('#booking-room-meta').textContent = `${text(room.room_type)} · ${money(room.monthly_rent)}/เดือน`;
      const image = $('#booking-room-image');
      image.src = safeRoomImage(room);
      image.alt = `ห้อง ${text(room.room_code)}`;
      openDialog(bookingDialog);
      window.setTimeout(() => bookingForm.elements.full_name?.focus(), 40);
    }

    function render() {
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
    }

    async function load() {
      grid.setAttribute('aria-busy', 'true');
      errorBox.hidden = true;
      try {
        const data = await api('/api/public/rooms');
        rooms = listFrom(data, 'rooms').filter((room) => !room.status || room.status === 'available');
        count.textContent = String(rooms.length);
        populateSelect(typeFilter, rooms.map((room) => room.room_type));
        populateSelect(floorFilter, rooms.map((room) => room.floor));
        render();
      } catch (error) {
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
    bookingForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      showFormError($('#public-booking-error'));
      if (!bookingForm.reportValidity()) return;
      const button = bookingForm.querySelector('[type="submit"]');
      const form = new FormData(bookingForm);
      setBusy(button, true, 'กำลังส่งคำขอ…');
      try {
        const data = await api('/api/public/bookings', {
          method: 'POST',
          body: { room_id: Number(form.get('room_id')), full_name: form.get('full_name'), phone: form.get('phone'), idempotency_key: form.get('idempotency_key') },
        });
        const booking = objectFrom(data, 'booking');
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
        showFormError(error, errorMessage(requestError, fallbackMessage));
      } finally {
        setBusy(button, false);
      }
    });
  }

  function initResidentPortal() {
    const shell = $('.resident-shell');
    if (!shell) return;
    const state = { profile: {}, bills: [], filter: 'all', currentBillId: null, billDetailRequest: 0, qrRequest: 0, slipMaxBytes: 4 * 1024 * 1024, slipReady: false, paymentReady: false };
    const profileForm = $('#resident-profile-form');
    const lineStartForm = $('#resident-line-start-form');
    const lineConfirmForm = $('#resident-line-confirm-form');
    const lineUnlinkButton = $('#resident-line-unlink');
    const billDialog = $('#resident-bill-dialog');

    function billStatus(bill) {
      const raw = String(bill.display_status || bill.status || bill.payment_status || '').toLowerCase();
      if (['paid', 'verified'].includes(raw)) return 'paid';
      if (raw === 'overdue') return 'overdue';
      return 'unpaid';
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
      const filtered = state.bills.filter((bill) => state.filter === 'all'
        || (state.filter === 'unpaid' ? billStatus(bill) !== 'paid' : billStatus(bill) === state.filter));
      list.replaceChildren(...filtered.map(createBillRow));
      recent.replaceChildren(...state.bills.slice(0, 3).map(createBillRow));
      list.setAttribute('aria-busy', 'false');
      recent.setAttribute('aria-busy', 'false');
      $('#resident-bills-empty').hidden = filtered.length > 0;
      const unpaid = state.bills.filter((bill) => billStatus(bill) !== 'paid');
      $('#resident-bill-count').textContent = String(state.bills.length);
      $('#resident-due-total').textContent = money(unpaid.reduce((sum, bill) => sum + number(bill.total_amount ?? bill.total), 0));
      $('#resident-due-caption').textContent = unpaid.length ? `${unpaid.length} บิลที่ยังต้องชำระ` : 'ไม่มียอดค้างชำระ';
      const navCount = $('#resident-unpaid-count');
      navCount.textContent = String(unpaid.length);
      navCount.hidden = unpaid.length === 0;
    }

    function fillProfile() {
      const profile = state.profile;
      ['full_name', 'email', 'phone', 'room_code'].forEach((name) => {
        if (profileForm.elements[name]) profileForm.elements[name].value = profile[name] || (name === 'room_code' ? profile.room?.room_code || '' : '');
      });
      const hasLine = typeof profile.line_user_id === 'string' && profile.line_user_id.length > 0;
      const linked = hasLine && profile.line_verified === true;
      const pending = profile.line_link_pending && typeof profile.line_link_pending === 'object' ? profile.line_link_pending : null;
      $('#resident-line-status').textContent = pending
        ? `รอยืนยัน ${text(pending.line_user_id_hint)} ภายใน ${formatDateTime(pending.expires_at)} น.`
        : (linked ? `ยืนยันแล้ว •••${profile.line_user_id.slice(-6)}`
          : (hasLine ? `ข้อมูลเดิม •••${profile.line_user_id.slice(-6)} ต้องขอรหัสยืนยันใหม่` : 'ยังไม่ได้ผูกบัญชี LINE'));
      lineStartForm.elements.line_user_id.value = hasLine ? profile.line_user_id : '';
      lineUnlinkButton.hidden = !hasLine;
      lineConfirmForm.hidden = !pending;
    }

    async function loadAll() {
      const errorBox = $('#resident-global-error');
      errorBox.hidden = true;
      const [profileResult, billsResult] = await Promise.allSettled([api('/api/resident/profile'), api('/api/resident/bills')]);
      if (profileResult.status === 'fulfilled') { state.profile = objectFrom(profileResult.value, 'profile'); fillProfile(); }
      if (billsResult.status === 'fulfilled') { state.bills = listFrom(billsResult.value, 'bills'); renderBills(); }
      const rejected = [profileResult, billsResult].filter((result) => result.status === 'rejected');
      if (rejected.length) {
        errorBox.hidden = false;
        $('[data-error-message]', errorBox).textContent = (rejected.length === 2 ? 'โหลดข้อมูลผู้พักไม่สำเร็จ: ' : 'โหลดข้อมูลบางส่วนไม่สำเร็จ: ') + errorMessage(rejected[0].reason);
      }
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
      $('#resident-bill-loading').hidden = false;
      $('#resident-bill-loading').textContent = 'กำลังโหลดรายละเอียดบิล…';
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
            ? `สลิปถูกปฏิเสธ: ${text(payment.rejection_reason, 'ข้อมูลในสลิปไม่ตรงกับบิล')}`
            : payment.status === 'verified' ? 'สลิปผ่านการตรวจสอบแล้ว' : 'สลิปอยู่ระหว่างตรวจสอบ');
        }
        if (!paymentConfigurationReady) {
          if (!promptPayReady && !slipReady) notices.push('ยังชำระผ่านระบบไม่ได้: ผู้ดูแลต้องตั้งค่า PromptPay และระบบตรวจสลิปให้พร้อมก่อน ห้ามโอนจนกว่าจะตั้งค่าเสร็จ');
          else if (!promptPayReady) notices.push('ยังชำระผ่านระบบไม่ได้: ยังไม่ได้ตั้งค่า PromptPay กรุณาติดต่อผู้ดูแลก่อนโอน');
          else notices.push('ยังชำระผ่านระบบไม่ได้: ระบบตรวจสลิปยังไม่พร้อม จึงซ่อน QR ไว้เพื่อป้องกันการโอนที่ตรวจสอบไม่ได้');
        }
        paymentNotice.hidden = notices.length === 0;
        paymentNotice.className = `payment-notice${payment ? ` payment-notice-${text(payment.status, 'pending')}` : ''}${!paymentConfigurationReady ? ' payment-notice-blocked' : ''}`;
        paymentNotice.setAttribute('role', !paymentConfigurationReady ? 'alert' : 'status');
        paymentNotice.setAttribute('aria-live', !paymentConfigurationReady ? 'assertive' : 'polite');
        paymentNotice.textContent = notices.join(' · ');
        const breakdown = $('#resident-bill-breakdown');
        breakdown.replaceChildren();
        if (Array.isArray(bill.line_items)) {
          bill.line_items.forEach((item) => appendBreakdown(breakdown, text(item.description || item.label), item.amount, item.detail || ''));
        } else {
          appendBreakdown(breakdown, 'ค่าห้อง', bill.rent_amount ?? bill.monthly_rent);
          appendBreakdown(breakdown, 'ค่าน้ำ', bill.water_amount, bill.water_units !== undefined ? `${number(bill.water_units)} หน่วย × ${money(bill.water_rate)}` : '');
          appendBreakdown(breakdown, 'ค่าไฟ', bill.electric_amount, bill.electric_units !== undefined ? `${number(bill.electric_units)} หน่วย × ${money(bill.electric_rate)}` : '');
          appendBreakdown(breakdown, text(bill.other_description, 'รายการอื่น'), bill.other_amount);
        }
        const totalRow = create('div', 'breakdown-row breakdown-total');
        totalRow.append(create('dt', '', 'ยอดสุทธิ'), create('dd', '', money(bill.total_amount ?? bill.total)));
        breakdown.append(totalRow);
        $('#resident-payment-panel').hidden = status === 'paid';
        $('#resident-payment-complete').hidden = status !== 'paid';
        $('#resident-qr-stage').replaceChildren(create('div', 'qr-placeholder', 'เลือก “แสดง QR” เพื่อสร้าง QR ตามยอดบิล'));
        $('#resident-bill-loading').hidden = true;
        $('#resident-bill-detail').hidden = false;
      } catch (error) {
        if (request !== state.billDetailRequest) return;
        $('#resident-bill-loading').textContent = errorMessage(error, 'โหลดรายละเอียดไม่สำเร็จ');
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
    $$('[data-resident-logout]').forEach((button) => button.addEventListener('click', async () => {
      try { await api('/api/auth/resident/logout', { method: 'POST', body: {} }); } finally { location.assign('/resident/login'); }
    }));
    profileForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const error = $('#resident-profile-error');
      showFormError(error);
      if (!profileForm.reportValidity()) return;
      const button = profileForm.querySelector('[type="submit"]');
      const values = Object.fromEntries(new FormData(profileForm).entries());
      setBusy(button, true, 'กำลังบันทึก…');
      try {
        const data = await api('/api/resident/profile', { method: 'PUT', body: { full_name: values.full_name, email: values.email } });
        state.profile = objectFrom(data, 'profile');
        fillProfile();
        $('#resident-sidebar-name').textContent = text(state.profile.full_name);
        $('#resident-topbar-name').textContent = text(state.profile.full_name);
        $('#resident-dashboard-title').textContent = `สวัสดี ${text(state.profile.full_name)}`;
        toast('บันทึกข้อมูลแล้ว');
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setBusy(button, false); }
    });
    lineStartForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const error = $('#resident-line-error');
      showFormError(error);
      if (!lineStartForm.reportValidity()) return;
      const button = lineStartForm.querySelector('[type="submit"]');
      const lineUserId = String(lineStartForm.elements.line_user_id.value || '').trim();
      setBusy(button, true, 'กำลังส่งรหัส…');
      try {
        const result = await api('/api/resident/profile/line/start', { method: 'POST', body: { line_user_id: lineUserId } });
        lineConfirmForm.hidden = false;
        lineConfirmForm.reset();
        lineConfirmForm.elements.code.focus();
        toast(`ส่งรหัสยืนยันไปยัง ${text(result?.line_user_id_hint, 'LINE แล้ว')}`);
      } catch (errorValue) {
        if (!['LINE_NOT_CONFIGURED', 'LINE_DELIVERY_REJECTED', 'VALIDATION_ERROR'].includes(errorValue?.details?.code)) {
          lineConfirmForm.hidden = false;
        }
        showFormError(error, errorMessage(errorValue));
      }
      finally { setBusy(button, false); }
    });
    lineConfirmForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const error = $('#resident-line-error');
      showFormError(error);
      if (!lineConfirmForm.reportValidity()) return;
      const button = lineConfirmForm.querySelector('[type="submit"]');
      setBusy(button, true, 'กำลังยืนยัน…');
      try {
        const data = await api('/api/resident/profile/line/confirm', { method: 'POST', body: { code: String(lineConfirmForm.elements.code.value || '').trim() } });
        state.profile = objectFrom(data, 'profile');
        fillProfile();
        lineConfirmForm.hidden = true;
        lineConfirmForm.reset();
        toast('ยืนยันบัญชี LINE แล้ว');
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setBusy(button, false); }
    });
    lineUnlinkButton.addEventListener('click', async () => {
      if (!await confirmAction('ยกเลิกการผูก LINE', 'หลังยกเลิก ระบบจะไม่ส่งบิลใหม่ไปยัง LINE จนกว่าจะยืนยันอีกครั้ง')) return;
      const error = $('#resident-line-error');
      showFormError(error);
      setBusy(lineUnlinkButton, true, 'กำลังยกเลิก…');
      try {
        const data = await api('/api/resident/profile/line/unlink', { method: 'POST', body: {} });
        state.profile = objectFrom(data, 'profile');
        fillProfile();
        lineConfirmForm.hidden = true;
        lineConfirmForm.reset();
        toast('ยกเลิกการผูก LINE แล้ว');
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setBusy(lineUnlinkButton, false); }
    });
    $('#resident-load-qr').addEventListener('click', async (event) => {
      const button = event.currentTarget;
      if (!state.paymentReady) { toast('ยังสร้าง QR ไม่ได้ ต้องตั้งค่า PromptPay และระบบตรวจสลิปให้พร้อมทั้งคู่ก่อน', 'error'); return; }
      const billId = state.currentBillId;
      const request = ++state.qrRequest;
      setBusy(button, true, 'กำลังสร้าง QR…');
      try {
        const data = await api(`/api/resident/bills/${encodeURIComponent(billId)}/promptpay`);
        if (request !== state.qrRequest || String(state.currentBillId) !== String(billId)) return;
        const qr = objectFrom(data, 'promptpay');
        const payload = qr.payload || qr.qr_payload || data?.payload;
        const qrLibrary = window.QRCode || window.qrcodelib;
        if (!payload || !qrLibrary?.toCanvas) throw new ApiError('ไม่สามารถสร้าง QR ได้');
        const canvas = create('canvas');
        canvas.setAttribute('aria-label', 'QR PromptPay สำหรับบิลนี้');
        await renderQrCanvas(qrLibrary, canvas, payload, { width: 240, margin: 2, errorCorrectionLevel: 'M' });
        if (request !== state.qrRequest || String(state.currentBillId) !== String(billId)) return;
        const meta = create('div', 'qr-meta');
        meta.append(
          create('strong', '', `ชื่อผู้รับ: ${text(qr.name ?? qr.recipient_name ?? data?.name, 'ไม่ได้ระบุชื่อ')}`),
          create('small', '', `ยอดชำระ ${money(qr.amount ?? data?.amount)}`),
        );
        $('#resident-qr-stage').replaceChildren(canvas, meta);
      } catch (error) { toast(errorMessage(error), 'error'); }
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
      try {
        await api(`/api/resident/bills/${encodeURIComponent(billId)}/slip`, { method: 'POST', body: new FormData(form) });
        form.reset();
        toast('ส่งสลิปแล้ว ระบบกำลังตรวจสอบ');
        if (String(state.currentBillId) === String(billId)) await Promise.all([openBill(billId), loadAll()]);
        else await loadAll();
      } catch (errorValue) { showFormError(error, errorMessage(errorValue)); }
      finally { setBusy(button, false); }
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
    loadAll();
  }

  function initAdminConsole() {
    const app = $('[data-admin-app]');
    if (!app) return;
    const state = {
      rooms: [], bookings: [], residents: [], meters: [], bills: [], payments: [], users: [], settings: {},
      loaded: new Set(), meterController: null, billController: null, bookingController: null, paymentController: null,
      meterPeriod: '', billPeriod: '', bookingOffset: 0, bookingHasMore: false, bookingPendingCount: 0, paymentOffset: 0, paymentHasMore: false,
    };
    const role = body.dataset.userRole || 'admin';
    const titles = { rooms: 'ห้องพัก', bookings: 'การจอง', residents: 'ผู้พักอาศัย', meters: 'จดมิเตอร์', bills: 'ใบแจ้งหนี้', payments: 'การชำระเงิน', users: 'ผู้ดูแลระบบ', settings: 'ตั้งค่า' };
    const loaders = {};
    const menuToggle = $('[data-admin-menu-toggle]');
    const adminSidebar = $('.admin-sidebar', app);
    const mobileMenu = window.matchMedia('(max-width: 860px)');

    function setAdminMenu(open) {
      const active = mobileMenu.matches && open;
      app.classList.toggle('sidebar-open', active);
      menuToggle?.setAttribute('aria-expanded', String(active));
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
      const hash = name === 'rooms' ? '' : `#${name}`;
      window.history.replaceState(null, '', `${location.pathname}${location.search}${hash}`);
    }

    function switchView(name, force = false, updateHash = true) {
      if (!titles[name] || (name === 'users' && role !== 'owner')) return false;
      const activeView = $('[data-admin-view].is-active', app)?.dataset.adminView;
      if (activeView === 'settings' && name !== 'settings' && $('#integration-settings-form')?.dataset.dirty === 'true') {
        if (!window.confirm('มีการตั้งค่าบริการภายนอกที่ยังไม่บันทึก ต้องการออกจากหน้านี้และทิ้งการแก้ไขหรือไม่?')) return false;
        renderIntegrationSettings(objectFrom(state.settings.integrations));
      }
      $$('[data-admin-view]', app).forEach((view) => { const active = view.dataset.adminView === name; view.hidden = !active; view.classList.toggle('is-active', active); });
      $$('[data-admin-nav]', app).forEach((nav) => { const active = nav.dataset.adminNav === name; nav.classList.toggle('is-active', active); if (active) nav.setAttribute('aria-current', 'page'); else nav.removeAttribute('aria-current'); });
      $('#admin-page-title').textContent = titles[name];
      const targetHash = name === 'rooms' ? '' : `#${name}`;
      if (updateHash && location.hash !== targetHash) location.hash = targetHash;
      setAdminMenu(false);
      const volatileViews = ['rooms', 'bookings', 'residents', 'meters', 'bills', 'payments'];
      if (force || volatileViews.includes(name) || !state.loaded.has(name)) loaders[name]?.();
      return true;
    }

    async function confirmAction(title, message, label = 'ยืนยัน', danger = true) {
      const dialog = $('#confirm-dialog');
      $('#confirm-title').textContent = title;
      $('#confirm-message').textContent = message;
      const accept = $('[data-confirm-accept]', dialog);
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
        const cleanup = () => { accept.removeEventListener('click', yes); $('[data-confirm-cancel]', dialog).removeEventListener('click', no); dialog.removeEventListener('cancel', cancel); dialog.removeEventListener('close', closed); };
        const finish = (value) => { if (settled) return; settled = true; cleanup(); if (dialog.open) closeDialog(dialog); resolve(value); };
        accept.addEventListener('click', yes);
        $('[data-confirm-cancel]', dialog).addEventListener('click', no);
        dialog.addEventListener('cancel', cancel, { once: true });
        dialog.addEventListener('close', closed, { once: true });
      });
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
        if (room.status === 'available') actions.push(actionButton('เพิ่มผู้พัก', 'add-resident-to-room', room.id, 'button-primary'));
        actions.push(actionButton('แก้ไข', 'edit-room', room.id), actionButton('ลบ', 'delete-room', room.id, 'button-danger-text'));
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

    function renderBookings() {
      const rows = $('#booking-rows'); rows.replaceChildren();
      const visible = state.bookings;
      visible.forEach((booking) => {
        const tr = create('tr');
        const person = create('span', 'person-cell'); person.append(create('strong', '', text(booking.full_name)), create('small', '', text(booking.phone)));
        const actions = [];
        if (booking.status === 'pending') actions.push(actionButton('ยืนยัน', 'confirm-booking', booking.id, 'button-primary'), actionButton('ยกเลิก', 'cancel-booking', booking.id, 'button-danger-text'));
        if (booking.status === 'confirmed') actions.push(actionButton('รับเข้าพัก', 'move-in', booking.id, 'button-primary'), actionButton('ยกเลิก', 'cancel-booking', booking.id, 'button-danger-text'));
        tr.append(td(text(booking.reference_no || booking.reference || booking.booking_reference || booking.id)), td(person), td(text(booking.room_code || booking.room?.room_code)), td(formatDate(booking.created_at)), td(pill(booking.status)), td(rowActions(...actions), 'align-right'));
        rows.append(tr);
      });
      const pending = state.bookingPendingCount; const count = $('#booking-nav-count'); count.textContent = String(pending); count.hidden = pending === 0;
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
    loaders.bookings = loadBookings;
    $('#booking-status-filter').addEventListener('change', () => loadBookings(false));
    $('#booking-load-more').addEventListener('click', () => loadBookings(true));
    $('#booking-rows').addEventListener('click', async (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return; const booking = state.bookings.find((item) => String(item.id) === button.dataset.id); if (!booking) return;
      if (button.dataset.action === 'move-in') {
        const form = $('#move-in-form'); form.reset(); form.elements.booking_id.value = booking.id; form.elements.move_in_date.value = isoToday();
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
        if (!await confirmAction('ยืนยันการจอง', `ยืนยันห้องให้ ${text(booking.full_name)} หรือไม่?`, 'ยืนยันการจอง', false)) return;
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
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังรับเข้าพัก…');
      try { await api(`/api/admin/bookings/${encodeURIComponent(id)}/move-in`, { method: 'POST', body: values }); closeDialog($('#move-in-dialog')); toast('รับเข้าพักและสร้างบัญชีแล้ว'); state.loaded.delete('residents'); await Promise.all([loadBookings(), loadRooms()]); }
      catch (requestError) {
        if (requestError?.details?.code === 'RESIDENT_REUSE_CONFIRMATION_REQUIRED') {
          const reuseInput = form.elements.reuse_resident_id; reuseInput.value = String(requestError.details.resident_id || ''); reuseInput.disabled = false; reuseInput.required = true;
          $('#move-in-reuse-label').textContent = `ยืนยันว่า ${text(requestError.details.resident_name)} เป็นบุคคลเดิมและอนุญาตให้เชื่อมประวัติบิล`;
          $('#move-in-reuse-field').hidden = false; $('#move-in-reuse-help').hidden = false; reuseInput.focus();
        }
        showFormError(error, errorMessage(requestError));
      } finally { setBusy(button, false); }
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
        const line = resident.line_verified === true ? pill('active', `ยืนยันแล้ว ${text(resident.line_user_id_hint)}`)
          : (resident.line_user_id_hint ? pill('pending', 'รอยืนยันใหม่') : pill('neutral', 'ยังไม่ผูก'));
        const active = !(resident.active === false || resident.active === 0);
        const actions = active ? rowActions(
          actionButton('แก้ข้อมูล', 'edit-resident', resident.id),
          actionButton('ย้ายออก', 'move-out-resident', resident.id, 'button-danger-text'),
        ) : rowActions();
        tr.append(td(person), td(text(resident.room_code || resident.room?.room_code)), td(text(resident.phone)), td(line), td(formatDate(resident.move_in_date)), td(pill(active ? 'active' : 'inactive')), td(actions, 'align-right'));
        rows.append(tr);
      });
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
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังรับเข้าพัก…');
      try {
        const result = await api('/api/admin/residents', { method: 'POST', body: values });
        closeDialog($('#resident-create-dialog')); form.reset();
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
        setBusy(button, false);
        button.disabled = state.rooms.every((room) => room.status !== 'available');
      }
    });
    $('#resident-rows').addEventListener('click', (event) => {
      const button = event.target.closest('[data-action]'); if (!button) return;
      const resident = state.residents.find((item) => String(item.id) === button.dataset.id); if (!resident) return;
      const summary = `${text(resident.full_name)} · ห้อง ${text(resident.room_code || resident.room?.room_code)}`;
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
    $('#resident-edit-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const error = $('#resident-edit-error'); showFormError(error); if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries()); const id = values.resident_id; delete values.resident_id;
      const original = state.residents.find((item) => String(item.id) === String(id));
      if (original && String(values.phone).replace(/[^0-9+]/g, '') !== String(original.phone).replace(/[^0-9+]/g, '')) {
        if (!await confirmAction('เปลี่ยนเบอร์เข้าสู่ระบบ', 'ยืนยันว่าได้ตรวจสอบตัวตนผู้พักแล้วหรือไม่? เซสชันเดิมของผู้พักจะถูกยกเลิกทันที', 'ยืนยันและบันทึก', false)) return;
      }
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังบันทึก…');
      try {
        const updated = await api(`/api/admin/residents/${encodeURIComponent(id)}`, { method: 'PUT', body: values });
        closeDialog($('#resident-edit-dialog')); form.reset(); toast(updated?.sessions_revoked ? 'บันทึกแล้วและยกเลิกเซสชันเดิมของผู้พัก' : 'บันทึกข้อมูลผู้พักแล้ว'); await loadResidents();
      } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); }
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
        const deltaText = (value, previous, hasPrevious) => value === '' || value === null || value === undefined
          ? '—' : (hasPrevious ? Math.max(0, number(value) - previous).toFixed(2) : '0.00');
        const tr = create('tr'); tr.dataset.roomId = String(roomId); tr.dataset.period = state.meterPeriod;
        tr.classList.toggle('meter-row-baseline', !hasWaterPrevious || !hasElectricPrevious);
        const previousCell = (type, raw, hasPrevious) => {
          const value = hasPrevious ? text(raw) : create('span', 'meter-baseline-label', 'เดือนแรก · หน่วย 0');
          const cell = td(value); cell.dataset.meterPrevious = type; return cell;
        };
        const configureInput = (type, value, previous, hasPrevious) => {
          const input = create('input', 'meter-input');
          input.type = 'number'; input.min = hasPrevious ? String(previous) : '0'; input.max = '9999999'; input.step = '0.01'; input.value = value ?? '';
          input.dataset.meterType = type; input.dataset.savedValue = input.value; input.dataset.previous = hasPrevious ? String(previous) : '0'; input.dataset.hasPrevious = String(hasPrevious);
          const label = type === 'water' ? 'น้ำ' : 'ไฟ';
          input.setAttribute('aria-label', `เลขมิเตอร์${label}ปัจจุบัน ห้อง ${roomCode}${hasPrevious ? '' : ' เดือนแรกใช้เป็นค่าตั้งต้นและหน่วยเท่ากับ 0'}`);
          if (!hasPrevious) input.title = 'เดือนแรก: เลขนี้เป็นค่าตั้งต้น (baseline) และหน่วยที่ใช้เท่ากับ 0';
          return input;
        };
        const waterInput = configureInput('water', meter.water_current, waterPrevious, hasWaterPrevious);
        const electricInput = configureInput('electric', meter.electric_current, electricPrevious, hasElectricPrevious);
        const waterDelta = create('strong', 'meter-delta', deltaText(meter.water_current, waterPrevious, hasWaterPrevious)); waterDelta.dataset.meterDelta = 'water';
        const electricDelta = create('strong', 'meter-delta', deltaText(meter.electric_current, electricPrevious, hasElectricPrevious)); electricDelta.dataset.meterDelta = 'electric';
        const updateDirtyState = () => {
          const dirty = $$('input[data-meter-type]', tr).some((input) => input.value !== input.dataset.savedValue);
          tr.classList.toggle('meter-row-dirty', dirty); tr.dataset.dirty = String(dirty);
        };
        waterInput.addEventListener('input', () => { waterDelta.textContent = deltaText(waterInput.value, number(waterInput.dataset.previous), waterInput.dataset.hasPrevious === 'true'); updateDirtyState(); });
        electricInput.addEventListener('input', () => { electricDelta.textContent = deltaText(electricInput.value, number(electricInput.dataset.previous), electricInput.dataset.hasPrevious === 'true'); updateDirtyState(); });
        tr.append(td(roomCode), previousCell('water', waterPreviousRaw, hasWaterPrevious), td(waterInput), td(waterDelta), previousCell('electric', electricPreviousRaw, hasElectricPrevious), td(electricInput), td(electricDelta), td(actionButton('บันทึก', 'save-meter', roomId, 'button-primary'), 'align-right')); rows.append(tr);
      });
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
    async function loadMeters() {
      const period = $('#meter-period').value || todayPeriod(); $('#meter-period').value = period;
      state.meterController?.abort(); const controller = new AbortController(); state.meterController = controller;
      setTableState($('#meter-state'), 'loading');
      try {
        const data = await api(`/api/admin/meters?period=${encodeURIComponent(period)}`, { signal: controller.signal });
        if (state.meterController !== controller || $('#meter-period').value !== period) return;
        state.meterPeriod = period; state.meters = listFrom(data, 'meters'); state.loaded.add('meters'); renderMeters();
      } catch (error) { if (error?.name !== 'AbortError') setTableState($('#meter-state'), 'error', errorMessage(error)); }
      finally { if (state.meterController === controller) state.meterController = null; }
    }
    loaders.meters = loadMeters; $('#meter-period').value = todayPeriod(); $('#meter-period').addEventListener('change', loadMeters);
    async function saveMeterRow(button, confirmLargeUsage = false) {
      const tr = button.closest('tr'); const inputs = $$('input', tr);
      if (tr.dataset.period !== $('#meter-period').value) { toast('รอบเดือนเปลี่ยนแล้ว ระบบจะโหลดข้อมูลใหม่เพื่อป้องกันการบันทึกผิดเดือน', 'error'); await loadMeters(); return; }
      if (!inputs.every((input) => input.reportValidity()) || inputs.some((input) => input.value === '')) { toast('กรุณากรอกค่าน้ำและไฟให้ครบ', 'error'); return; }
      let confirmRetry = false;
      setBusy(button, true, 'กำลังบันทึก…');
      try {
        const payload = { room_id: Number(button.dataset.id), period: tr.dataset.period, water_current: number(inputs[0].value), electric_current: number(inputs[1].value) };
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
    $('#meter-rows').addEventListener('click', async (event) => { const button = event.target.closest('[data-action="save-meter"]'); if (button) await saveMeterRow(button); });

    function selectedBillRooms() { return $$('#bill-room-options input:checked').map((input) => Number(input.value)); }
    function billPayload() { const form = $('#bill-builder-form'); const values = Object.fromEntries(new FormData(form).entries()); return { period: values.period, room_ids: selectedBillRooms(), water_rate: number(values.water_rate), electric_rate: number(values.electric_rate), other_description: values.other_description || '', other_amount: number(values.other_amount), due_date: values.due_date }; }
    function fillBillRooms() {
      const wrap = $('#bill-room-options'); wrap.replaceChildren(); const candidates = state.rooms.filter((room) => room.status === 'occupied');
      candidates.forEach((room) => { const label = create('label', 'check-field room-check'); const input = create('input'); input.type = 'checkbox'; input.value = room.id; label.append(input, create('span', '', `ห้อง ${text(room.room_code)}`)); wrap.append(label); });
      if (!candidates.length) wrap.append(create('span', 'muted', 'ไม่พบห้องที่มีผู้พัก'));
    }
    function renderAdminBills() {
      const rows = $('#bill-admin-rows'); rows.replaceChildren();
      const lineReady = objectFrom(state.settings.integrations).line_ready === true;
      let eligibleForLine = 0;
      state.bills.forEach((bill) => {
        const tr = create('tr');
        const line = create('div', 'line-delivery');
        const lineLabels = { pending: 'รอส่ง', processing: 'กำลังส่ง', sent: 'LINE รับคำขอแล้ว', failed: 'ส่งไม่สำเร็จ' };
        if (bill.line_status) line.append(pill(bill.line_status, lineLabels[bill.line_status] || text(bill.line_status)));
        if (bill.line_status === 'failed' && bill.line_last_error) {
          const failure = create('small', 'line-error', text(bill.line_last_error));
          failure.title = text(bill.line_last_error);
          line.append(failure);
        }
        if (bill.line_status === 'sent' && (bill.line_accepted_request_id || bill.line_request_id)) {
          line.append(create('small', 'muted', `LINE ref ${text(bill.line_accepted_request_id || bill.line_request_id)}`));
        }
        const mayQueue = bill.status === 'pending' && bill.line_linked === true && lineReady && (!bill.line_status || bill.line_status === 'failed');
        if (mayQueue) { line.append(actionButton(bill.line_status === 'failed' ? 'เข้าคิวใหม่' : 'เข้าคิว', 'send-line', bill.id, 'button-secondary')); eligibleForLine++; }
        else if (!bill.line_status && bill.status !== 'pending') line.append(create('small', 'muted', 'ชำระแล้ว ไม่ส่งซ้ำ'));
        else if (!bill.line_status && !bill.line_linked) line.append(create('small', 'muted', 'ผู้พักยังไม่ผูก LINE'));
        else if (!bill.line_status && !lineReady) line.append(create('small', 'muted', 'ตั้งค่า LINE ยังไม่ครบ'));
        const displayStatus = bill.display_status || bill.status;
        const billStatusLabel = displayStatus === 'overdue' ? 'เลยกำหนด' : (bill.status === 'pending' ? 'รอชำระ' : '');
        tr.append(td(text(bill.bill_no || bill.number || bill.id)), td(text(bill.room_code || bill.room?.room_code)), td(text(bill.resident_name || bill.resident?.full_name)), td(money(bill.total_amount ?? bill.total)), td(formatDate(bill.due_date)), td(pill(displayStatus, billStatusLabel)), td(line, 'align-right'));
        rows.append(tr);
      });
      const bulkButton = $('#line-bulk-button');
      bulkButton.disabled = !lineReady || eligibleForLine === 0;
      bulkButton.title = !lineReady ? 'ตั้งค่า LINE Channel access token ในหน้า “ตั้งค่า” ก่อน' : (eligibleForLine === 0 ? 'ไม่มีบิลค้างชำระที่ผูก LINE และพร้อมเข้าคิว' : 'เข้าคิวบิลค้างชำระที่พร้อมส่ง');
      setTableState($('#bill-admin-state'), state.bills.length ? 'ready' : 'empty', 'ยังไม่มีบิลในรอบเดือนนี้');
    }
    async function loadBills() {
      const period = $('#bill-period').value || todayPeriod(); $('#bill-period').value = period;
      state.billController?.abort(); const controller = new AbortController(); state.billController = controller;
      setTableState($('#bill-admin-state'), 'loading');
      try {
        const data = await api(`/api/admin/bills?period=${encodeURIComponent(period)}`, { signal: controller.signal });
        if (state.billController !== controller || $('#bill-period').value !== period) return;
        state.billPeriod = period; state.bills = listFrom(data, 'bills'); state.loaded.add('bills'); renderAdminBills();
      } catch (error) { if (error?.name !== 'AbortError') setTableState($('#bill-admin-state'), 'error', errorMessage(error)); }
      finally { if (state.billController === controller) state.billController = null; }
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
      const qrLibrary = window.QRCode || window.qrcodelib;
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
      const fields = ['promptpay_target', 'promptpay_name', 'payment_receiver_account_tail', 'line_max_attempts', 'notification_batch_size', 'slip_provider', 'slipok_branch_id', 'slip_max_bytes', 'slip_time_tolerance_seconds'];
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
      const readiness = objectFrom(integrations.readiness);
      const webhookUrl = $('[data-line-webhook-url]', form);
      if (webhookUrl) webhookUrl.value = text(integrations.line_webhook_url, '');
      const webhookReadiness = $('[data-line-webhook-readiness]', form);
      if (webhookReadiness) webhookReadiness.textContent = readiness.line_webhook === true
        ? 'Webhook พร้อมใช้งาน — นำ URL นี้ไปใส่ใน LINE Developers Console และเปิด Use webhook'
        : 'Webhook ยังไม่พร้อม: ต้องบันทึก Channel access token และ Channel secret ให้ครบ';
      const statusMap = { promptpay: readiness.promptpay === true, line: readiness.line === true, slip: readiness.slip_verification === true };
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
    async function loadSettings() {
      try {
        const data = await api('/api/admin/settings');
        state.settings = objectFrom(data, 'settings');
        const form = $('#settings-form');
        ['water_rate', 'electric_rate', 'due_days'].forEach((key) => { if (form.elements[key]) form.elements[key].value = state.settings[key] ?? ''; });
        applyBillingReadiness();
        renderIntegrationSettings(objectFrom(state.settings.integrations));
        $('#bill-water-rate').value = state.settings.water_rate ?? '';
        $('#bill-electric-rate').value = state.settings.electric_rate ?? '';
        if (!$('#bill-due-date').value) $('#bill-due-date').value = isoDateAfterDays(state.settings.due_days);
        state.loaded.add('settings');
        if (state.loaded.has('bills')) renderAdminBills();
      } catch (error) {
        clearPromptPayTestQr();
        showFormError($('#settings-error'), errorMessage(error));
        showFormError($('#integration-settings-error'), errorMessage(error));
      }
    }
    function applyBillingReadiness() {
      const ready = state.settings.configured === true;
      const note = $('#billing-readiness-note');
      const status = $('#billing-settings-status');
      if (note) {
        note.classList.toggle('security-note-warning', !ready);
        const copy = $('span', note); if (copy) copy.textContent = ready ? 'ยืนยันค่ารายเดือนแล้ว สามารถตรวจยอดและออกบิลได้' : 'ยังไม่ยืนยันค่าเริ่มต้น ระบบจะยังไม่ออกบิลเพื่อป้องกันยอดผิด';
      }
      if (status) status.textContent = ready ? `ยืนยันแล้ว${state.settings.updated_at ? ` · แก้ไขล่าสุด ${formatDate(state.settings.updated_at)}` : ''}` : 'ยังไม่ยืนยัน — ตรวจสอบตัวเลขแล้วกด “ยืนยันและบันทึกการตั้งค่า”';
      const builder = $('#bill-builder-form');
      if (builder) $$('button', builder).forEach((button) => { button.disabled = !ready; });
    }
    async function initBills() { await Promise.all([loadRooms(), loadSettings()]); fillBillRooms(); await loadBills(); }
    loaders.bills = initBills; loaders.settings = loadSettings;
    $('#bill-period').value = todayPeriod(); $('#bill-period').addEventListener('change', loadBills);
    $('#select-all-bill-rooms').addEventListener('change', (event) => $$('#bill-room-options input').forEach((input) => { input.checked = event.currentTarget.checked; }));
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
        appendPreviewLine(text(item.other_description, 'รายการอื่น'), 'คิดต่อห้องนี้', item.other_amount);
        card.append(heading, breakdown); content.append(card);
      });
      issues.forEach((issue) => { const card = create('article', 'preview-item preview-issue'); const issueDetails = { AMBIGUOUS_OCCUPANCY: 'พบข้อมูลผู้พักซ้อนกันในรอบเดือนนี้ กรุณาตรวจสอบวันเข้า–ออก', NO_OCCUPANCY: 'ไม่มีผู้พักในรอบที่เลือก' }; const detail = issue.code === 'MISSING_METER' ? `ขาดมิเตอร์: ${(issue.meter_types || []).join(', ')}` : (issueDetails[issue.code] || 'ข้อมูลห้องยังไม่พร้อมออกบิล'); card.append(create('strong', '', `ห้อง ${text(issue.room_code || issue.room_id)}`), create('small', '', detail)); content.append(card); });
      if (!previews.length && !issues.length) content.append(create('p', 'empty-inline', 'ไม่มีรายการที่สร้างได้'));
      openDialog($('#preview-dialog')); return { previews, issues };
    }
    $('#preview-bills-button').addEventListener('click', async (event) => {
      const payload = billPayload(); const error = $('#bill-builder-error'); showFormError(error);
      if (!$('#bill-builder-form').reportValidity() || !payload.room_ids.length) { showFormError(error, 'กรุณาเลือกอย่างน้อย 1 ห้อง'); return; }
      const button = event.currentTarget; setBusy(button, true, 'กำลังคำนวณ…');
      try { renderBillPreview(await api('/api/admin/bills/preview', { method: 'POST', body: payload }), payload); }
      catch (requestError) { showFormError(error, errorMessage(requestError)); }
      finally { setBusy(button, false); }
    });
    $('#bill-builder-form').addEventListener('submit', async (event) => {
      event.preventDefault(); const form = event.currentTarget; const payload = billPayload(); const error = $('#bill-builder-error'); showFormError(error);
      if (!form.reportValidity() || !payload.room_ids.length) { showFormError(error, 'กรุณาเลือกห้องที่จะออกบิล'); return; }
      const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังตรวจยอด…');
      try {
        const preview = await api('/api/admin/bills/preview', { method: 'POST', body: payload });
        const previews = listFrom(preview, 'bills'); const issues = listFrom(preview, 'issues');
        if (issues.length || !previews.length) { renderBillPreview(preview, payload); showFormError(error, issues.length ? `มี ${issues.length} ห้องที่ข้อมูลยังไม่พร้อม กรุณาแก้ก่อนออกบิล` : 'ไม่มีรายการที่ออกบิลได้'); return; }
        if (!preview?.preview_token) throw new ApiError('เซิร์ฟเวอร์ไม่ส่งรหัสยืนยันตัวอย่างยอด กรุณาลองใหม่');
        const grandTotal = previews.reduce((sum, item) => sum + number(item.total_amount ?? item.total), 0);
        if (!await confirmAction('ยืนยันยอดก่อนออกบิล', `ตรวจแล้ว ${previews.length} ห้อง ยอดรวม ${money(grandTotal)} สำหรับ${formatPeriod(payload.period)} หากข้อมูลเปลี่ยนหลังจากนี้ระบบจะหยุดให้อัตโนมัติ`, 'ยืนยันและออกบิล', false)) return;
        setBusy(button, true, 'กำลังออกบิล…');
        const result = await api('/api/admin/bills/bulk', { method: 'POST', body: { ...payload, preview_token: preview.preview_token } });
        const created = listFrom(result, 'created'); const skipped = listFrom(result, 'skipped'); toast(`สร้างบิล ${created.length} รายการ${skipped.length ? ` · ข้ามรายการเดิม ${skipped.length}` : ''}`); await loadBills();
      } catch (requestError) { const issues = Array.isArray(requestError.details?.issues) ? ` (${requestError.details.issues.length} ห้องต้องแก้ไข)` : ''; showFormError(error, errorMessage(requestError) + issues); }
      finally { setBusy(button, false); }
    });
    $('#bill-admin-rows').addEventListener('click', async (event) => { const button = event.target.closest('[data-action="send-line"]'); if (!button) return; setBusy(button, true, 'กำลังเข้าคิว…'); try { await api(`/api/admin/bills/${encodeURIComponent(button.dataset.id)}/line`, { method: 'POST', body: {} }); toast('นำบิลเข้าคิว LINE แล้ว'); await loadBills(); } catch (error) { toast(errorMessage(error), 'error'); } finally { setBusy(button, false); } });
    $('#line-bulk-button').addEventListener('click', async (event) => { if (!await confirmAction('เข้าคิว LINE ทั้งหมด', `นำบิลรอบ${formatPeriod($('#bill-period').value)} เข้าคิวสำหรับผู้พักที่ผูก LINE ไว้?`, 'เข้าคิว', false)) return; const button = event.currentTarget; setBusy(button, true, 'กำลังเข้าคิว…'); try { const result = await api('/api/admin/bills/line-bulk', { method: 'POST', body: { period: $('#bill-period').value } }); const queued = listFrom(result, 'queued'); const already = listFrom(result, 'already'); const skipped = listFrom(result, 'skipped'); toast(`เข้าคิวใหม่ ${queued.length} รายการ${already.length ? ` · อยู่ในคิว/ส่งแล้ว ${already.length}` : ''}${skipped.length ? ` · ข้าม ${skipped.length}` : ''}`); await loadBills(); } catch (error) { toast(errorMessage(error), 'error'); } finally { setBusy(button, false); renderAdminBills(); } });

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
            actionButton('ตรวจซ้ำ', 'retry-payment', payment.id, 'button-primary'),
            actionButton('ปิดรายการ', 'close-payment', payment.id, 'button-danger-text'),
          );
        } else if (payment.status === 'pending' && payment.verifying) {
          const waiting = create('small', 'muted', 'รอผลตรวจหรือหมดเวลา 60 วินาที');
          actions.push(waiting);
        }
        tr.append(
          td(formatDate(payment.created_at)),
          td(`${text(payment.bill_no || payment.bill?.bill_no)} / ${text(payment.room_code || payment.bill?.room_code)}`),
          td(text(payment.resident_name || payment.resident?.full_name)),
          td(money(payment.amount || payment.bill?.total_amount)),
          td(result),
          td(rowActions(...actions), 'align-right'),
        );
        rows.append(tr);
      });
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
        closeDialog($('#payment-close-dialog')); form.reset(); toast('ปิดรายการแล้ว ผู้พักสามารถส่งสลิปใหม่ได้'); await Promise.all([loadPayments(), loadBills()]);
      } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); }
    });
    function renderUsers() { const rows = $('#user-rows'); rows.replaceChildren(); state.users.forEach((user) => { const tr = create('tr'); const status = user.is_active === false || user.is_active === 0 ? 'inactive' : 'active'; tr.append(td(text(user.username)), td(text(user.role === 'owner' ? 'เจ้าของ' : 'ผู้ดูแล')), td(pill(status)), td(formatDate(user.updated_at)), td(rowActions(actionButton('แก้ไข', 'edit-user', user.id), actionButton('ลบ', 'delete-user', user.id, 'button-danger-text')), 'align-right')); rows.append(tr); }); setTableState($('#user-state'), state.users.length ? 'ready' : 'empty', 'ยังไม่มีบัญชีผู้ดูแล'); }
    async function loadUsers() { if (role !== 'owner') return; setTableState($('#user-state'), 'loading'); try { const data = await api('/api/admin/users'); state.users = listFrom(data, 'users'); state.loaded.add('users'); renderUsers(); } catch (error) { setTableState($('#user-state'), 'error', errorMessage(error)); } }
    loaders.users = loadUsers;
    function openUserForm(user = null) { const form = $('#user-form'); form.reset(); showFormError($('#user-form-error')); form.elements.id.value = user?.id || ''; form.elements.username.value = user?.username || ''; form.elements.role.value = user?.role || 'admin'; form.elements.is_active.checked = user ? !(user.is_active === false || user.is_active === 0) : true; form.elements.password.required = !user; $('#user-dialog-title').textContent = user ? `แก้ไข ${text(user.username)}` : 'เพิ่มผู้ดูแล'; $('#user-password-help').textContent = user ? 'เว้นว่างหากไม่ต้องการเปลี่ยนรหัสผ่าน' : 'อย่างน้อย 12 ตัวอักษร'; openDialog($('#user-dialog')); }
    $('[data-open-user-dialog]')?.addEventListener('click', () => openUserForm());
    $('#user-rows').addEventListener('click', async (event) => { const button = event.target.closest('[data-action]'); if (!button) return; const user = state.users.find((item) => String(item.id) === button.dataset.id); if (!user) return; if (button.dataset.action === 'edit-user') openUserForm(user); if (button.dataset.action === 'delete-user' && await confirmAction('ลบผู้ดูแล', `ลบบัญชี ${text(user.username)} ออกจากระบบหรือไม่?`)) { try { await api(`/api/admin/users/${encodeURIComponent(user.id)}`, { method: 'DELETE', body: {} }); toast('ลบผู้ดูแลแล้ว'); loadUsers(); } catch (error) { toast(errorMessage(error), 'error'); } } });
    $('#user-form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const error = $('#user-form-error'); showFormError(error); if (!form.reportValidity()) return; const values = Object.fromEntries(new FormData(form).entries()); const id = values.id; delete values.id; values.is_active = form.elements.is_active.checked; if (!values.password) delete values.password; const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังบันทึก…'); try { await api(id ? `/api/admin/users/${encodeURIComponent(id)}` : '/api/admin/users', { method: id ? 'PUT' : 'POST', body: values }); closeDialog($('#user-dialog')); toast('บันทึกผู้ดูแลแล้ว'); loadUsers(); } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); } });

    $('#settings-form').addEventListener('submit', async (event) => { event.preventDefault(); const form = event.currentTarget; const error = $('#settings-error'); showFormError(error); if (!form.reportValidity()) return; const values = Object.fromEntries(new FormData(form).entries()); values.water_rate = number(values.water_rate); values.electric_rate = number(values.electric_rate); values.due_days = Number(values.due_days); const button = form.querySelector('[type="submit"]'); setBusy(button, true, 'กำลังบันทึก…'); try { const data = await api('/api/admin/settings', { method: 'PUT', body: values }); state.settings = { ...state.settings, ...objectFrom(data, 'settings') }; $('#bill-water-rate').value = state.settings.water_rate ?? values.water_rate; $('#bill-electric-rate').value = state.settings.electric_rate ?? values.electric_rate; $('#bill-due-date').value = isoDateAfterDays(state.settings.due_days ?? values.due_days); applyBillingReadiness(); toast('ยืนยันและบันทึกการตั้งค่าแล้ว'); } catch (requestError) { showFormError(error, errorMessage(requestError)); } finally { setBusy(button, false); } });
    const integrationSettingsForm = $('#integration-settings-form');
    integrationSettingsForm?.addEventListener('input', markIntegrationSettingsDirty);
    integrationSettingsForm?.addEventListener('change', (event) => {
      if (event.target?.name === 'slip_provider') applySlipProviderVisibility(integrationSettingsForm);
      markIntegrationSettingsDirty();
    });
    window.addEventListener('beforeunload', (event) => {
      if (integrationSettingsForm?.dataset.dirty !== 'true') return;
      event.preventDefault(); event.returnValue = '';
    });
    integrationSettingsForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      if (role !== 'owner' || form.dataset.ownerOnly !== 'true') return;
      const error = $('#integration-settings-error');
      showFormError(error);
      if (!form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form).entries());
      ['line_max_attempts', 'notification_batch_size', 'slip_max_bytes', 'slip_time_tolerance_seconds'].forEach((key) => { values[key] = Number(values[key]); });
      ['line_channel_access_token_clear', 'line_channel_secret_clear', 'slipok_api_key_clear', 'easyslip_api_key_clear'].forEach((key) => { values[key] = !form.elements[key].disabled && form.elements[key].checked; });
      const button = form.querySelector('[type="submit"]');
      setBusy(button, true, 'กำลังเข้ารหัสและบันทึก…');
      try {
        const integrations = objectFrom(await api('/api/admin/settings/integrations', { method: 'PUT', body: values }), 'integrations');
        state.settings.integrations = integrations;
        renderIntegrationSettings(integrations);
        toast('บันทึกการเชื่อมต่อแล้ว');
      } catch (requestError) {
        clearPromptPayTestQr();
        showFormError(error, errorMessage(requestError));
      } finally { setBusy(button, false); }
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

    $$('[data-admin-nav]').forEach((button) => button.addEventListener('click', () => switchView(button.dataset.adminNav)));
    $$('[data-refresh]').forEach((button) => button.addEventListener('click', () => switchView(button.dataset.refresh, true)));
    menuToggle.addEventListener('click', () => { const open = !app.classList.contains('sidebar-open'); setAdminMenu(open); if (open) $('[data-admin-nav]', adminSidebar)?.focus(); });
    app.addEventListener('click', (event) => { if (mobileMenu.matches && app.classList.contains('sidebar-open') && event.target === app) setAdminMenu(false); });
    doc.addEventListener('keydown', (event) => {
      if (!mobileMenu.matches || !app.classList.contains('sidebar-open')) return;
      if (event.key === 'Escape') { setAdminMenu(false); menuToggle.focus(); return; }
      if (event.key !== 'Tab') return;
      const focusable = $$('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])', adminSidebar).filter((node) => !node.hidden);
      if (!focusable.length) return;
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    if (typeof mobileMenu.addEventListener === 'function') mobileMenu.addEventListener('change', () => setAdminMenu(false));
    setAdminMenu(false);
    $('[data-admin-logout]').addEventListener('click', async () => { try { await api('/api/auth/admin/logout', { method: 'POST', body: {} }); } finally { location.assign('/admin/login'); } });
    const initialHash = location.hash.replace('#', '');
    const initialView = titles[initialHash] && (initialHash !== 'users' || role === 'owner') ? initialHash : 'rooms';
    switchView(initialView, false, false);
    if (initialHash !== (initialView === 'rooms' ? '' : initialView)) replaceAdminHash(initialView);
    window.addEventListener('hashchange', () => {
      const requested = location.hash.replace('#', '');
      const nextView = titles[requested] && (requested !== 'users' || role === 'owner') ? requested : 'rooms';
      if ($('[data-admin-view].is-active', app)?.dataset.adminView === nextView) {
        if (requested !== (nextView === 'rooms' ? '' : nextView)) replaceAdminHash(nextView);
        return;
      }
      if (!switchView(nextView, false, false)) {
        replaceAdminHash($('[data-admin-view].is-active', app)?.dataset.adminView || 'rooms');
        return;
      }
      if (requested !== (nextView === 'rooms' ? '' : nextView)) replaceAdminHash(nextView);
    });
  }

  setupCommonInteractions();
  initPublicRooms();
  initLogin('#admin-login-form', '/api/auth/admin/login', '/admin', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
  initLogin('#resident-login-form', '/api/auth/resident/login', '/resident', 'ไม่สามารถเข้าสู่ระบบด้วยเบอร์นี้ได้');
  initResidentPortal();
  initAdminConsole();
})();
