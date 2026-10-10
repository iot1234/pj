(() => {
  'use strict';
  const types = ['monthly', 'daily'];
  function scopedRooms(data, type) {
    const rows = Array.isArray(data) ? data : data?.items || data?.rooms;
    if (!types.includes(type) || !Array.isArray(rows) || rows.some(row => !row || !Number.isSafeInteger(row.id) || row.id < 1 || row.rental_mode !== type) || new Set(rows.map(row => row.id)).size !== rows.length) throw new Error('รายการห้องไม่ตรงกับส่วนงาน กรุณาโหลดข้อมูลล่าสุด');
    return rows;
  }
  function decimalMoney(value) {
    if (typeof value !== 'string' || !/^-?\d{1,30}\.\d{2}$/.test(value)) throw new Error('ข้อมูลยอดเงินไม่ครบ กรุณาโหลดรายงานใหม่');
    const negative = value.startsWith('-'), [whole, fraction] = (negative ? value.slice(1) : value).split('.');
    return `${negative ? '-' : ''}฿${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
  }
  function revenueReport(data, type, period, offset = 0) {
    const utc = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/.test(value) && Number.isFinite(Date.parse(value));
    if (!types.includes(type) || !/^[1-9]\d{3}-(?:0[1-9]|1[0-2])$/.test(period) || !Number.isSafeInteger(offset) || offset < 0 || !data || data.type !== type || data.period !== period || data.currency !== 'THB' || !data.summary || !data.events || !Array.isArray(data.events.items)
      || !utc(data.generated_at_utc) || !data.range || !utc(data.range.start_utc) || !utc(data.range.end_utc) || Date.parse(data.range.start_utc) >= Date.parse(data.range.end_utc) || typeof data.timezone !== 'string'
      || !Number.isSafeInteger(data.summary.verified_payment_count) || data.summary.verified_payment_count < 0 || !Number.isSafeInteger(data.summary.refund_count) || data.summary.refund_count < 0
      || !Number.isSafeInteger(data.events.offset) || !Number.isSafeInteger(data.events.next_offset) || data.events.limit !== 200 || data.events.items.length > 200
      || typeof data.events.has_more !== 'boolean' || data.events.offset !== offset || data.events.next_offset !== offset + data.events.items.length || (data.events.has_more && !data.events.items.length)) throw new Error('รายงานไม่ตรงประเภทหรือเดือนที่เลือก กรุณาโหลดใหม่');
    try { if (!data.timezone) throw new Error('timezone'); new Intl.DateTimeFormat('en', { timeZone: data.timezone }); } catch (_) { throw new Error('เขตเวลารายงานไม่ถูกต้อง'); }
    if (!data.basis || data.basis.collections !== 'verified_at' || data.basis.earned_revenue_calculated !== false || data.basis.refunds !== (type === 'daily' ? 'created_at' : null) || data.basis.deposit_retention !== (type === 'daily' ? 'created_at' : null) || data.basis.balances_scope !== (type === 'daily' ? 'current_all_periods' : null)) throw new Error('เกณฑ์รายงานไม่ตรงกับเงินรับจริง');
    const positive = value => { decimalMoney(value); if (value.startsWith('-')) throw new Error('ยอดเงินรับหรือคืนต้องไม่ติดลบ'); };
    ['gross_receipts', 'refunds_total', 'bank_adjustment', ...(type === 'daily' ? ['room_collections', 'security_deposit_collections', 'deposit_refunds', 'cancellation_refunds', 'damage_retention', 'future_stay_room_collections'] : ['collected_charges', 'rent_collections', 'water_collections', 'electric_collections', 'other_collections', 'later_bill_period_collections'])].forEach(key => positive(data.summary[key])); decimalMoney(data.summary.net_cash_flow);
    if (type === 'daily') { if (!data.balances || !utc(data.balances.as_of_utc)) throw new Error('ข้อมูลค่าประกันไม่ครบ'); ['active_stay_security_deposits', 'closed_booking_cash_refundable', 'unconfirmed_verified_cash'].forEach(key => positive(data.balances[key])); }
    const seen = new Set();
    for (const row of data.events.items) {
      const key = `${row.event_type}:${row.event_id}`;
      if (!['collection', 'refund', 'deposit_retention'].includes(row.event_type) || (type === 'monthly' && row.event_type !== 'collection') || !Number.isSafeInteger(row.event_id) || row.event_id < 1 || seen.has(key) || typeof row.reference_no !== 'string' || !utc(row.occurred_at)) throw new Error('ข้อมูลรายการเงินไม่ครบหรือซ้ำ กรุณาโหลดใหม่');
      positive(row.amount); seen.add(key);
    }
    return data;
  }
  function init(h) {
    const { $, create, api, money, formatDateTime, showFormError, errorMessage, setBusy, confirmAction } = h;
    if (!$('[data-admin-app]')) return null;
    const state = { rooms: [], ready: false, controller: null, mutation: false, overviewController: null, revenue: new Map() };
    function reportError(selector, error = '') { const panel = $(selector); const target = panel.querySelector('[data-error-message]') || panel; showFormError(target, error); panel.hidden = !error; }
    const node = (selector, value) => { const item = $(selector); if (item) item.textContent = value; };
    const button = (label, action, room, style = 'button-secondary') => { const item = create('button', `button button-small ${style}`, label); item.type = 'button'; item.dataset.dailyRoomAction = action; item.dataset.id = String(room.id); return item; };
    function drawRooms() {
      const rows = $('#daily-room-rows'); rows.replaceChildren();
      const query = $('#daily-room-search').value.trim().toLocaleLowerCase('th'), status = $('#daily-room-status').value;
      const visible = state.rooms.filter(room => (!status || (status === 'cleaning' ? room.housekeeping_status === 'cleaning' : room.status === status)) && (!query || `${room.room_code} ${room.floor} ${room.room_type}`.toLocaleLowerCase('th').includes(query)));
      for (const room of visible) {
        const tr = create('tr'), actions = create('div', 'table-actions'); actions.append(button('แก้ไข', 'edit', room));
        if (room.can_delete === true) actions.append(button('ลบ', 'delete', room, 'button-danger-text'));
        [room.room_code, room.floor, room.room_type, `${money(room.daily_rate)}/คืน · ประกัน ${money(room.daily_deposit)}`, `${room.max_guests} คน`, `${room.status === 'occupied' ? 'มีผู้พัก' : room.status === 'reserved' ? 'มีการจอง' : 'ว่าง'} · ${room.housekeeping_status === 'cleaning' ? 'รอทำความสะอาด' : 'พร้อมใช้งาน'}`, actions].forEach(value => { const td = create('td'); typeof value === 'object' ? td.append(value) : td.textContent = String(value); tr.append(td); }); rows.append(tr);
      }
      const counts = { all: state.rooms.length, available: state.rooms.filter(r => r.status === 'available' && r.housekeeping_status === 'ready').length, reserved: state.rooms.filter(r => r.status === 'reserved').length, occupied: state.rooms.filter(r => r.status === 'occupied').length, cleaning: state.rooms.filter(r => r.housekeeping_status === 'cleaning').length };
      Object.entries(counts).forEach(([key, value]) => node(`#daily-room-stat-${key}`, String(value)));
      $('#daily-room-state').hidden = visible.length > 0; node('#daily-room-state', state.rooms.length ? 'ไม่พบห้องรายวันที่ตรงกับตัวกรอง' : 'ยังไม่มีห้องรายวัน กด “เพิ่มห้องรายวัน” เพื่อตั้งราคาและจำนวนผู้พัก');
    }
    async function loadDailyRooms() {
      state.controller?.abort(); const controller = new AbortController(); state.controller = controller; state.ready = false; state.rooms = [];
      $('#daily-room-rows').replaceChildren(); $('#daily-room-state').hidden = false; node('#daily-room-state', 'กำลังโหลดห้องรายวัน…');
      ['all', 'available', 'reserved', 'occupied', 'cleaning'].forEach(key => node(`#daily-room-stat-${key}`, '—'));
      try { const data = await api('/api/admin/daily/rooms', { signal: controller.signal }); if (state.controller !== controller) return; state.rooms = scopedRooms(data, 'daily'); state.ready = true; drawRooms(); }
      catch (error) { if (state.controller === controller) { state.rooms = []; state.ready = false; $('#daily-room-rows').replaceChildren(); node('#daily-room-state', errorMessage(error)); } }
      finally { if (state.controller === controller) state.controller = null; }
    }
    $('#daily-room-search').addEventListener('input', () => { if (state.ready) drawRooms(); }); $('#daily-room-status').addEventListener('change', () => { if (state.ready) drawRooms(); });
    $('[data-open-daily-room-dialog]').addEventListener('click', () => { if (!state.mutation) h.openRoomForm(null, 'daily'); });
    $('#daily-room-rows').addEventListener('click', async event => {
      const control = event.target.closest('[data-daily-room-action]'); if (!control || control.disabled || !state.ready || state.controller || state.mutation) return;
      const room = state.rooms.find(row => String(row.id) === control.dataset.id); if (!room) return;
      if (control.dataset.dailyRoomAction === 'edit') { h.openRoomForm(room, 'daily'); return; }
      if (control.dataset.dailyRoomAction !== 'delete' || room.can_delete !== true) return;
      state.mutation = true;
      try { if (!await confirmAction('ลบห้องรายวัน', `ลบห้อง ${room.room_code} หรือไม่? ระบบตรวจผู้พักและการจองก่อนลบ`)) return; if (!state.ready || !state.rooms.includes(room)) return; setBusy(control, true); await api(`/api/admin/daily/rooms/${room.id}`, { method: 'DELETE', body: {} }); h.toast('ลบห้องรายวันแล้ว'); }
      catch (error) { h.toast(error, 'error'); }
      finally { state.mutation = false; setBusy(control, false); await loadDailyRooms(); }
    });
    async function loadDailyOverview() {
      state.overviewController?.abort(); const controller = new AbortController(); state.overviewController = controller;
      reportError('#daily-overview-error'); node('#daily-overview-state', 'กำลังโหลดภาพรวมรายวัน…');
      ['arrivals', 'departures', 'pending', 'cleaning', 'occupied', 'guests'].forEach(key => node(`#daily-overview-${key}`, '—'));
      try { const data = await api('/api/admin/daily/overview', { signal: controller.signal }); if (state.overviewController !== controller) return;
        if (data.type !== 'daily' || !data.counts || !['arrivals', 'departures', 'pending', 'cleaning', 'occupied', 'guests', 'rooms'].every(key => Number.isSafeInteger(data.counts[key]) && data.counts[key] >= 0)) throw new Error('ข้อมูลภาพรวมรายวันไม่ครบ');
        ['arrivals', 'departures', 'pending', 'cleaning', 'occupied', 'guests'].forEach(key => node(`#daily-overview-${key}`, String(data.counts[key])));
        node('#daily-overview-state', data.counts.rooms ? `ห้องรายวัน ${data.counts.rooms} ห้อง · นับจากรายการรายวันทั้งหมด · อัปเดต ${formatDateTime(data.generated_at_utc)}` : 'ยังไม่มีห้องรายวัน เริ่มจากเพิ่มห้องในเมนู “ห้องรายวัน”');
      } catch (error) { if (state.overviewController === controller) { node('#daily-overview-state', 'ยังโหลดภาพรวมรายวันไม่ได้'); reportError('#daily-overview-error', error); } }
      finally { if (state.overviewController === controller) state.overviewController = null; }
    }
    function revenueState(type) { if (!state.revenue.has(type)) state.revenue.set(type, { controller: null, report: null, items: [], offset: 0, period: null }); return state.revenue.get(type); }
    function clearRevenue(type, message) {
      ['received', 'refunded', 'net', 'deposit'].forEach(key => node(`#${type}-revenue-${key}`, '—'));
      $(`#${type}-revenue-rows`).replaceChildren(); $(`#${type}-revenue-details`).replaceChildren(); node(`#${type}-revenue-state`, message);
      const more = $(`#${type}-revenue-more`); if (more) more.hidden = true;
    }
    function renderRevenue(type, report, items) {
      const prefix = `#${type}-revenue-`, sum = report.summary;
      node(`${prefix}received`, decimalMoney(type === 'daily' ? sum.room_collections : sum.collected_charges)); node(`${prefix}refunded`, decimalMoney(sum.refunds_total)); node(`${prefix}net`, decimalMoney(sum.net_cash_flow));
      const deposit = $(`${prefix}deposit`); if (deposit) { deposit.closest('.stat-card').hidden = type !== 'daily'; if (type === 'daily') deposit.textContent = decimalMoney(report.balances.active_stay_security_deposits); }
      const fields = type === 'daily' ? [['ค่าห้องที่รับแล้ว (ยังไม่หักเงินคืน)', sum.room_collections], ['เงินประกันที่ยืนยันรับในเดือนนี้', sum.security_deposit_collections], ['เศษยอดโอนที่รับ', sum.bank_adjustment], ['เงินคืนประกันที่บันทึกในเดือนนี้', sum.deposit_refunds], ['เงินคืนรายการยกเลิก / สิ้นสุดที่บันทึก', sum.cancellation_refunds], ['ยอดหักประกันที่บันทึกในเดือนนี้', sum.damage_retention], ['ค่าห้องรับล่วงหน้า (ยังไม่ถึงวันเข้าพัก ณ วันที่อัปเดต)', sum.future_stay_room_collections], ['ยอดเงินคืนของการจองที่สิ้นสุดแล้ว (ปัจจุบัน)', report.balances.closed_booking_cash_refundable], ['เงินยืนยันรับแล้ว แต่การจองยังรอยืนยัน (ปัจจุบัน)', report.balances.unconfirmed_verified_cash]] : [['ค่าเช่ารายเดือนที่รับแล้ว', sum.rent_collections], ['ค่าน้ำที่รับแล้ว', sum.water_collections], ['ค่าไฟที่รับแล้ว', sum.electric_collections], ['รายการอื่นในบิลที่รับแล้ว', sum.other_collections], ['เศษยอดโอนที่รับ', sum.bank_adjustment], ['เงินรับล่วงหน้าสำหรับบิลหลังเดือนที่เลือก', sum.later_bill_period_collections]];
      const dl = create('dl'); fields.forEach(([label, value]) => dl.append(create('dt', '', label), create('dd', '', decimalMoney(value)))); $(`${prefix}details`).replaceChildren(dl);
      const rows = $(`${prefix}rows`); rows.replaceChildren();
      for (const event of items) { const tr = create('tr'), label = { collection: 'ยืนยันรับเงิน', refund: 'บันทึกคืนเงิน', deposit_retention: 'หักค่าประกัน (ไม่ใช่รับเงินเพิ่ม)' }[event.event_type]; [formatDateTime(event.occurred_at), event.reference_no, event.room_code || '—', label, event.event_type === 'collection' ? decimalMoney(event.amount) : '—', type === 'monthly' ? (event.bill_period || '—') : event.event_type === 'refund' ? decimalMoney(event.amount) : event.event_type === 'deposit_retention' ? `หัก ${decimalMoney(event.amount)}` : '—'].forEach(value => tr.append(create('td', '', value))); rows.append(tr); }
      if (!items.length) { const tr = create('tr'), td = create('td', '', 'ไม่มีรายการเงินของส่วนงานนี้ในเดือนที่เลือก'); td.colSpan = 6; tr.append(td); rows.append(tr); }
      node(`${prefix}state`, `เดือน ${report.period} · ${sum.verified_payment_count} รายการรับเงิน · รายการในตาราง ${items.length}${report.events.has_more ? ' · ยังมีรายการเพิ่มเติม' : ''}`);
      node(`${prefix}heading-month`, report.period);
      node(`${prefix}ledger-note`, type === 'daily' ? `นับตามวันที่ระบบยืนยันรับเงิน และวันที่บันทึกคืนเงินหรือหักประกัน ไม่ใช่วันที่โอน เงินเข้าออกสุทธิรวมเงินประกัน จึงไม่ใช่รายได้ค่าห้อง ยอดประกันและยอดเงินคืนรายการสิ้นสุดเป็นยอดปัจจุบัน ณ ${formatDateTime(report.balances.as_of_utc)} ไม่ใช่ยอดสิ้นเดือน` : 'นับตามวันที่ระบบยืนยันรับเงินของบิลรายเดือน ไม่ใช่วันที่โอนหรือเดือนในบิล แยกค่าเช่า ค่าน้ำ ค่าไฟ และเศษยอดโอน');
      const more = $(`${prefix}more`); if (more) more.hidden = !report.events.has_more;
    }
    async function loadRevenue(type, append = false) {
      const current = revenueState(type), period = $(`#${type}-revenue-period`).value;
      if (append && (current.controller || !current.report?.events.has_more || current.period !== period)) return;
      current.controller?.abort(); const controller = new AbortController(); current.controller = controller;
      const offset = append ? current.offset : 0; if (!append) { current.report = null; current.items = []; current.offset = 0; clearRevenue(type, 'กำลังโหลดรายรับของเดือนที่เลือก…'); } reportError(`#${type}-revenue-error`);
      try { const data = await api(`/api/admin/${type}/revenue?${new URLSearchParams({ period, offset })}`, { signal: controller.signal }); if (current.controller !== controller || $(`#${type}-revenue-period`).value !== period) return;
        const report = revenueReport(data, type, period, offset); const known = new Set(current.items.map(row => `${row.event_type}:${row.event_id}`)); if (append && report.events.items.some(row => known.has(`${row.event_type}:${row.event_id}`))) throw new Error('รายการเงินเปลี่ยนระหว่างโหลด กรุณาโหลดรายงานใหม่');
        current.items = append ? [...current.items, ...report.events.items] : report.events.items; current.offset = report.events.next_offset; current.period = period; current.report = report; renderRevenue(type, report, current.items);
      } catch (error) { if (current.controller === controller) { if (!append) clearRevenue(type, 'ยังโหลดรายรับไม่ได้'); reportError(`#${type}-revenue-error`, error); } }
      finally { if (current.controller === controller) current.controller = null; }
    }
    for (const type of types) {
      const period = $(`#${type}-revenue-period`); period.value = h.todayPeriod();
      period.addEventListener('change', () => loadRevenue(type));
      const more = create('button', 'button button-secondary', 'โหลดรายการเงินเพิ่มเติม'); more.type = 'button'; more.id = `${type}-revenue-more`; more.hidden = true; more.addEventListener('click', () => loadRevenue(type, true)); $(`#${type}-revenue-rows`).closest('.panel').append(more);
    }
    return { loadDailyRooms, loadDailyOverview, loadRevenue, busy: () => state.mutation };
  }
  window.DormRentalWorkspaces = { init, scopedRooms, decimalMoney, revenueReport };
})();
