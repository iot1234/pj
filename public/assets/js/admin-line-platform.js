(() => {
  'use strict';

  const statuses = { bound: 'ผูกแล้ว', pending: 'รอส่งรหัส', unbound: 'ยังไม่ผูก', blocked: 'ระงับการผูก', claimed: 'ยืนยันผู้รับแล้ว', expired: 'หมดอายุ', revoked: 'ยกเลิกแล้ว' };
  const list = (value) => Array.isArray(value) ? value : Array.isArray(value?.rows) ? value.rows : [];
  const messageUrl = (value, code) => {
    const match = /^https:\/\/line\.me\/R\/oaMessage\/%40[A-Za-z0-9._-]{1,32}\/\?((?:BIND|OWNER|ADMIN)-[A-F0-9]{32})$/.exec(String(value || ''));
    return match && match[1] === code ? match[0] : '';
  };
  const expiryTime = (value) => {
    const raw = String(value || '');
    return Date.parse(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(raw) ? raw.replace(' ', 'T') + 'Z' : raw);
  };
  const matchesBinding = (row, query, status) => {
    const haystack = `${row.full_name || ''} ${row.room_code || ''} ${row.phone || ''}`.toLocaleLowerCase('th');
    return (!status || row.line_binding_status === status) && (!query.trim() || haystack.includes(query.trim().toLocaleLowerCase('th')));
  };

  function init(h) {
    const { $, $$, create, api, toast, errorMessage, showFormError, formatDateTime, openDialog, closeDialog, setDialogBusy, setFormFieldsBusy, confirmAction, getQrLibrary, renderQrCanvas } = h;
    const dialog = $('#line-platform-dialog');
    if (!dialog) return null;
    const oaForm = $('#line-oa-form'), codeForm = $('#line-binding-code-form'), recipientForm = $('#line-recipient-form');
    const errorNode = $('#line-platform-error');
    const state = { oas: [], recipients: [], rows: [], counts: {}, mode: '', id: null, epoch: 0, detailRevision: 0, codeRevision: 0, detail: null, busy: false, read: null, oaLoad: 0, bindingLoad: 0, defaultId: null };
    const codeSurfaces = new Set();
    let pollTimer = null, expiryTimer = null, lastReturnAt = 0;
    const validId = (value) => Number.isSafeInteger(Number(value)) && Number(value) >= 0;
    const pathId = (value) => { if (!validId(value)) throw new Error('รหัสรายการไม่ถูกต้อง กรุณาโหลดรายการใหม่'); return String(Number(value)); };
    const field = (form, name) => form.elements.namedItem(name);
    const txt = (value, fallback = '—') => value === undefined || value === null || value === '' ? fallback : String(value);
    const btn = (label, action, danger = false) => { const node = create('button', `button button-small ${danger ? 'button-danger' : 'button-secondary'}`, label); node.type = 'button'; node.addEventListener('click', action); return node; };
    const note = (value) => create('p', 'muted', value);
    const card = (title) => { const node = create('article', 'line-platform-card'); node.append(create('h3', '', title)); return node; };
    const isCurrent = (epoch, mode, id) => dialog.open && epoch === state.epoch && state.mode === mode && String(state.id) === String(id);
    const error = (cause) => showFormError(errorNode, errorMessage(cause));

    function clearCodes() {
      state.codeRevision++;
      for (const surface of codeSurfaces) surface.clear();
      codeSurfaces.clear();
    }

    async function copy(value, input) {
      try {
        if (navigator.clipboard?.writeText && window.isSecureContext) await navigator.clipboard.writeText(value);
        else { input.focus(); input.select(); if (!document.execCommand('copy')) throw new Error('copy'); }
        toast('คัดลอกแล้ว');
      } catch (_) { input.focus(); input.select(); toast('คัดลอกอัตโนมัติไม่ได้ กรุณาคัดลอกข้อความที่เลือกด้วยตนเอง', 'error'); }
    }

    function codeSurface(row) {
      const wrap = create('div');
      const code = String(row.code || ''), expires = expiryTime(row.expires_at);
      if (!/^(?:BIND|OWNER|ADMIN)-[A-F0-9]{32}$/.test(code) || !Number.isFinite(expires) || expires <= Date.now()) {
        wrap.append(note('ไม่มีรหัสที่ยังใช้ได้ กรุณาสร้างรหัสใหม่')); return wrap;
      }
      const input = create('input', 'line-platform-code'); input.value = code; input.readOnly = true; input.setAttribute('aria-label', 'รหัสใช้ครั้งเดียว');
      const actions = create('div', 'form-actions');
      const copyButton = btn('คัดลอกรหัส', () => { if (alive()) copy(code, input); else expire(); });
      actions.append(copyButton);
      const url = messageUrl(row.line_message_url, code), link = create('a', 'button button-primary', 'เปิด LINE พร้อมรหัส');
      link.target = '_blank'; link.rel = 'noopener noreferrer';
      if (url) { link.href = url; actions.append(link); }
      const canvas = create('canvas', 'line-code-qr'); canvas.hidden = true; canvas.setAttribute('aria-label', 'QR เปิดแชต LINE พร้อมรหัส');
      const fallback = note(url ? 'กำลังสร้าง QR…' : 'คัดลอกรหัสแล้วนำไปส่งในแชต OA ที่ระบุ');
      const expiry = note(`ใช้ได้ถึง ${formatDateTime(row.expires_at)} · ใช้ครั้งเดียว`);
      wrap.append(input, actions, canvas, fallback, expiry);
      const epoch = state.epoch, revision = state.codeRevision;
      let cleared = false;
      const alive = () => !cleared && dialog.open && state.epoch === epoch && state.codeRevision === revision && Date.now() < expires;
      const clear = () => { cleared = true; input.value = ''; link.removeAttribute('href'); canvas.hidden = true; canvas.getContext?.('2d')?.clearRect(0, 0, canvas.width, canvas.height); wrap.replaceChildren(); };
      const expire = () => { clear(); wrap.append(note('รหัสหมดอายุแล้ว กรุณาสร้างใหม่')); };
      const surface = { clear, tick: () => { if (!cleared && Date.now() >= expires) { expire(); codeSurfaces.delete(surface); } } };
      codeSurfaces.add(surface);
      if (url) {
        link.addEventListener('click', (event) => { if (!alive()) { event.preventDefault(); expire(); } });
        Promise.resolve().then(getQrLibrary).then(async (library) => {
          if (!alive()) return;
          await renderQrCanvas(library, canvas, url);
          if (!alive()) return;
          canvas.hidden = false; fallback.hidden = true;
        }).catch(() => { if (alive()) fallback.textContent = 'สร้าง QR ไม่สำเร็จ ใช้ปุ่มเปิด LINE หรือคัดลอกรหัสได้'; });
      }
      return wrap;
    }

    function resetDialog() {
      state.epoch++; state.detailRevision++; state.read = null; state.detail = null;
      clearCodes();
      window.clearInterval(pollTimer); window.clearInterval(expiryTimer); pollTimer = null; expiryTimer = null;
      oaForm.reset(); recipientForm.reset(); codeForm.reset();
      ['#line-pending-list', '#line-account-list', '#line-binding-history', '#line-recipient-code', '#line-oa-diagnostics', '#line-webhook-detail'].forEach((id) => $(id).replaceChildren());
      $('#line-block-reason').value = '';
      [oaForm, recipientForm, $('#line-binding-detail'), $('#line-webhook-detail')].forEach((node) => { node.hidden = true; });
      showFormError(errorNode);
    }

    function begin(mode, id, title) {
      if (state.busy) return null;
      resetDialog(); state.mode = mode; state.id = id;
      $('#line-platform-title').textContent = title;
      $('#line-platform-summary').textContent = 'กำลังโหลด…';
      openDialog(dialog);
      expiryTimer = window.setInterval(() => { for (const surface of codeSurfaces) surface.tick(); }, 1000);
      pollTimer = window.setInterval(() => { if (document.visibilityState === 'visible') refreshDialog(true); }, 5000);
      return state.epoch;
    }

    function fillOas(select, selected = state.defaultId) {
      select.replaceChildren();
      const available = state.oas.filter((oa) => oa.enabled === true && oa.line_binding_ready === true && !oa.deleted_at);
      for (const oa of available) { const option = create('option', '', `${oa.name}${oa.is_default ? ' · เริ่มต้น' : ''}`); option.value = String(oa.id); select.append(option); }
      if (available.some((oa) => String(oa.id) === String(selected))) select.value = String(selected);
      select.disabled = available.length === 0;
      if (!available.length) { const option = create('option', '', 'ไม่มี OA ที่พร้อมใช้งาน'); option.value = ''; select.append(option); }
      return available.length > 0;
    }

    async function mutate(button, action, after, confirmation) {
      if (state.busy) return false;
      state.busy = true; state.detailRevision++; state.read = null;
      const epoch = state.epoch;
      const locks = $$('button', dialog).map((node) => [node, node.disabled]);
      setDialogBusy(dialog, true); setFormFieldsBusy(dialog, true);
      locks.forEach(([node]) => { node.disabled = true; });
      if (button && !dialog.contains(button)) { button.disabled = true; }
      showFormError(errorNode);
      let released = false;
      const release = () => {
        if (released) return; released = true;
        state.busy = false; setFormFieldsBusy(dialog, false); setDialogBusy(dialog, false);
        locks.forEach(([node, disabled]) => { node.disabled = disabled; });
        if (button && !dialog.contains(button)) button.disabled = false;
      };
      try {
        if (confirmation && !await confirmAction(...confirmation)) return false;
        const result = await action();
        release();
        if (epoch === state.epoch) after?.(result);
        return true;
      } catch (cause) {
        if (dialog.open && epoch === state.epoch) error(cause); else toast(errorMessage(cause), 'error');
        return false;
      } finally { release(); }
    }

    function diagnostics(container, row) {
      container.replaceChildren();
      const readiness = row.operational_ready === true
        ? 'พร้อมผูกบัญชี · เคยรับ Webhook ที่ตรวจลายเซ็นผ่านแล้ว'
        : row.credentials_ready !== true ? 'ยังตั้งค่า Token หรือ Secret ไม่ครบ'
          : row.identity_verified !== true ? 'ยังไม่ได้ตรวจตัวตน OA'
            : 'รอ Verify Webhook จาก LINE Developers';
      for (const [label, value] of [['ความพร้อม', readiness], ['ยืนยันตัวตน OA ล่าสุด', row.identity_verified_at], ['รับ Webhook ที่ลายเซ็นถูกล่าสุด', row.last_seen_at], ['ข้อผิดพลาดล่าสุด', row.last_error]]) {
        container.append(note(`${label}: ${txt(value)}`));
      }
      if (row.webhook_url) {
        const label = create('label', 'field'); label.append(create('span', '', 'Webhook URL'));
        const input = create('input'); input.readOnly = true; input.value = String(row.webhook_url); label.append(input);
        container.append(label, btn('คัดลอก Webhook URL', () => copy(input.value, input)), note('นำ URL นี้ไปตั้งค่าใน LINE Developers แล้วเปิด Use webhook และ Webhook redelivery'));
      }
    }

    function renderOas() {
      const container = $('#line-oa-list'); container.replaceChildren();
      state.oas.filter((oa) => !oa.deleted_at).forEach((oa) => {
        const item = card(oa.name || `OA ${oa.id}`);
        const setup = oa.operational_ready ? 'พร้อมสร้างรหัส' : oa.credentials_ready !== true ? 'ยังตั้งค่า Token หรือ Secret ไม่ครบ' : oa.identity_verified !== true ? 'ยังไม่ได้ตรวจตัวตน OA' : 'รอ Verify Webhook';
        item.append(note(`${txt(oa.basic_id)} · ${oa.enabled ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}${oa.is_default ? ' · บัญชีเริ่มต้น' : ''}`), note(setup), note(`บัญชีที่ผูก ${Number(oa.bound_count || 0)} · รหัสรอใช้ ${Number(oa.pending_count || 0)}`));
        if (oa.last_error) item.append(note(`ข้อผิดพลาดล่าสุด: ${oa.last_error}`));
        const actions = create('div', 'form-actions');
        actions.append(btn('แก้ไข', () => openOa(oa.id)), btn('Webhook / สถานะ', () => openWebhook(oa.id)), btn('ทดสอบการเชื่อมต่อ', (event) => oaAction(oa, 'test', event.currentTarget)));
        if (!oa.is_default) actions.append(btn('ตั้งเป็นเริ่มต้น', (event) => oaAction(oa, 'default', event.currentTarget)));
        actions.append(btn(oa.enabled ? 'ปิดใช้งาน' : 'เปิดใช้งาน', (event) => oaAction(oa, 'toggle', event.currentTarget), !!oa.enabled), btn('เปลี่ยน Webhook URL', (event) => oaAction(oa, 'rotate-route', event.currentTarget), true), btn('ลบ OA', (event) => oaAction(oa, 'delete', event.currentTarget), true));
        item.append(actions); container.append(item);
      });
      if (!container.children.length) container.append(note('ยังไม่มี OA กรุณาเพิ่มบัญชี'));
      const setupButton = $('#line-oa-create');
      if (setupButton) setupButton.textContent = state.oas.some((oa) => oa.credentials_ready === true && !oa.deleted_at) ? 'เพิ่ม OA' : 'เชื่อมต่อ LINE OA';
      const recipients = $('#line-recipient-list'); recipients.replaceChildren();
      state.recipients.forEach((row) => {
        const item = card(`${row.label} · ${row.is_owner ? 'OWNER' : 'ADMIN'}`);
        item.append(note(`${txt(row.oa_name)} · ${statuses[row.status] || txt(row.status)} · ${row.enabled ? 'เปิดแจ้งเตือน' : 'ปิดแจ้งเตือน'}`), note(txt(row.line_user_id_hint, 'ยังไม่ยืนยันบัญชี LINE')), btn('รหัส / จัดการผู้รับ', () => openRecipient(row.id)));
        recipients.append(item);
      });
      if (!recipients.children.length) recipients.append(note('ยังไม่มีผู้รับแจ้งเตือนฝ่ายจัดการ'));
    }

    async function loadOas() {
      const generation = ++state.oaLoad;
      showFormError($('#line-oas-error'));
      try {
        const [oas, recipients] = await Promise.all([api('/api/admin/line/oas'), api('/api/admin/line/recipients')]);
        if (generation !== state.oaLoad) return;
        state.oas = list(oas); state.defaultId = oas?.default_oa_id ?? state.oas.find((oa) => oa.is_default)?.id ?? null;
        state.recipients = list(recipients); renderOas();
      } catch (cause) { if (generation === state.oaLoad) showFormError($('#line-oas-error'), errorMessage(cause)); }
    }

    async function oaAction(oa, action, button) {
      const endpoint = `/api/admin/line/oas/${pathId(oa.id)}`;
      const confirmation = action === 'test' ? null : [action === 'delete' ? 'ลบ OA' : action === 'rotate-route' ? 'เปลี่ยน Webhook URL' : action === 'default' ? 'เปลี่ยน OA เริ่มต้น' : (oa.enabled ? 'ปิด OA' : 'เปิด OA'), action === 'delete' ? `ลบ ${oa.name} ออกจากการใช้งาน ระบบจะหยุดรับส่งผ่าน OA นี้ และจะไม่ย้ายบัญชีผู้พักไป OA อื่น` : action === 'rotate-route' ? 'URL เดิมจะใช้ไม่ได้ ต้องนำ URL ใหม่ไปบันทึกใน LINE Developers ทันที รวมถึง OA เดิมที่เคยใช้ Webhook กลาง' : action === 'default' ? 'รหัสที่สร้างใหม่จะเลือก OA นี้เป็นค่าเริ่มต้น บัญชีที่ผูกอยู่จะใช้ OA เดิมต่อไป' : (oa.enabled ? 'หยุดการรับส่งผ่าน OA นี้ บัญชีผู้พักจะไม่ถูกย้ายไป OA อื่น' : 'เปิดรับส่งผ่าน OA นี้อีกครั้ง'), 'ยืนยัน', action !== 'default'];
      const succeeded = await mutate(button, () => api(endpoint + (['test', 'default', 'rotate-route'].includes(action) ? `/${action}` : ''), { method: action === 'delete' ? 'DELETE' : action === 'toggle' ? 'PUT' : 'POST', body: action === 'toggle' ? { enabled: !oa.enabled } : {} }), () => toast(action === 'test' ? 'ตรวจการเชื่อมต่อแล้ว' : 'บันทึกการเปลี่ยนแปลง OA แล้ว'), confirmation);
      if (succeeded) { await loadOas(); if (action === 'rotate-route') openWebhook(oa.id); }
    }

    async function openOa(id = null) {
      const epoch = begin('oa', id, id === null ? 'เพิ่มบัญชี LINE OA' : 'แก้ไขบัญชี LINE OA'); if (epoch === null) return;
      try {
        const row = id === null ? { enabled: true } : await api(`/api/admin/line/oas/${pathId(id)}`);
        if (!isCurrent(epoch, 'oa', id)) return;
        ['name', 'slug', 'basic_id', 'channel_id', 'description', 'add_friend_url'].forEach((name) => { field(oaForm, name).value = row[name] || ''; });
        field(oaForm, 'enabled').checked = row.enabled === true;
        field(oaForm, 'channel_access_token').placeholder = row.channel_access_token_configured ? 'เว้นว่างเพื่อเก็บค่าเดิม' : 'วาง Token จาก Messaging API';
        field(oaForm, 'channel_secret').placeholder = row.channel_secret_configured ? 'เว้นว่างเพื่อเก็บค่าเดิม' : 'วาง Secret จาก Basic settings';
        $('#line-oa-token-hint').textContent = row.channel_access_token_configured || row.access_token_configured ? `ตั้งค่าแล้ว ${txt(row.channel_access_token_hint || row.access_token_hint, '')}` : 'ยังไม่ได้ตั้งค่า';
        $('#line-oa-secret-hint').textContent = row.channel_secret_configured ? `ตั้งค่าแล้ว ${txt(row.channel_secret_hint, '')}` : 'ยังไม่ได้ตั้งค่า';
        diagnostics($('#line-oa-diagnostics'), row); oaForm.hidden = false;
        $('#line-platform-summary').textContent = id === null ? 'กรอก Token และ Secret เท่านั้น ระบบจะดึงข้อมูลบัญชีจาก LINE ให้เอง' : id === 0 ? 'OA หลักของระบบและบัญชีผู้พักเดิม · ช่องค่าลับที่เว้นว่างจะเก็บค่าเดิม' : 'ช่องค่าลับที่เว้นว่างจะเก็บค่าเดิม';
      } catch (cause) { if (isCurrent(epoch, 'oa', id)) error(cause); }
    }

    async function openWebhook(id) {
      const epoch = begin('webhook', id, 'Webhook และสถานะ OA'); if (epoch === null) return;
      try {
        const row = await api(`/api/admin/line/oas/${pathId(id)}/webhook-status`);
        if (!isCurrent(epoch, 'webhook', id)) return;
        state.detail = row;
        $('#line-platform-summary').textContent = row.webhook_verified === true ? `${txt(row.name, `OA ${id}`)} · เคย Verify Webhook สำเร็จแล้ว` : `${txt(row.name, `OA ${id}`)} · คัดลอก URL ไปตั้งใน LINE Developers แล้วกด Verify`;
        diagnostics($('#line-webhook-detail'), row); $('#line-webhook-detail').hidden = false;
      } catch (cause) { if (isCurrent(epoch, 'webhook', id)) error(cause); }
    }

    function renderBindings() {
      const summary = $('#line-binding-summary'); summary.replaceChildren();
      for (const [key, label] of [['total', 'ผู้พักทั้งหมด'], ['bound', 'ผูกแล้ว'], ['pending', 'รอส่งรหัส'], ['unbound', 'ยังไม่ผูก'], ['blocked', 'ระงับ'], ['bound_accounts', 'บัญชีที่ผูก']]) summary.append(create('span', '', `${label} ${Number(state.counts[key] || 0)}`));
      const container = $('#line-binding-rows'); container.replaceChildren();
      state.rows.filter((row) => matchesBinding(row, $('#line-binding-search').value, $('#line-binding-filter').value)).forEach((row) => {
        const tr = create('tr'), person = create('td'); person.append(create('strong', '', row.full_name), note(`ห้อง ${txt(row.room_code)} · ${txt(row.phone)}`));
        const counts = create('td', '', `${Number(row.bound_count || 0)} บัญชี / ${Number(row.pending_count || 0)} รหัส`), actions = create('td'); actions.append(btn('จัดการการผูก', () => openBinding(row.resident_id)));
        tr.append(person, create('td', '', statuses[row.line_binding_status] || '—'), counts, actions); container.append(tr);
      });
      if (!container.children.length) { const tr = create('tr'), td = create('td', 'muted', 'ไม่พบผู้พักตามตัวกรอง'); td.colSpan = 4; tr.append(td); container.append(tr); }
    }

    async function loadBindings() {
      const generation = ++state.bindingLoad; showFormError($('#line-bindings-error'));
      try { const data = await api('/api/admin/line/bindings'); if (generation !== state.bindingLoad) return; state.rows = list(data); state.counts = data.counts || {}; renderBindings(); }
      catch (cause) { if (generation === state.bindingLoad) showFormError($('#line-bindings-error'), errorMessage(cause)); }
    }

    function renderDetail(row) {
      if (String(row.resident_id) !== String(state.id)) throw new Error('ข้อมูลการผูกไม่ตรงกับผู้พักที่เลือก');
      const previous = state.detail;
      state.detail = row; state.detailRevision++; clearCodes();
      $('#line-platform-summary').textContent = `${row.full_name} · ห้อง ${txt(row.room_code)}`;
      $('#line-binding-policy').textContent = row.blocked ? `ระงับการผูก: ${txt(row.reason)}` : `ผูกแล้ว ${Number(row.bound_count || 0)} บัญชี`;
      codeForm.hidden = row.blocked === true;
      $('#line-binding-block').hidden = row.blocked === true; $('#line-block-reason').hidden = row.blocked === true;
      $('#line-binding-unblock').hidden = row.blocked !== true;
      $('#line-binding-revoke-all').disabled = !(list(row.pending_codes).length || list(row.bound_accounts).length);
      const pending = $('#line-pending-list'); pending.replaceChildren();
      list(row.pending_codes).forEach((code) => {
        const item = card(`รหัสสำหรับ ${txt(code.oa_name)}`); item.append(codeSurface(code), btn('ยกเลิกรหัสนี้', (event) => bindingAction('code', code.id, event.currentTarget), true)); pending.append(item);
      });
      if (!pending.children.length) pending.append(note('ไม่มีรหัสรอใช้'));
      const accounts = $('#line-account-list'); accounts.replaceChildren();
      list(row.bound_accounts).forEach((account) => {
        const item = card(txt(account.oa_name)); item.append(note(txt(account.line_user_id_hint)), note(`ผูกเมื่อ ${txt(account.bound_at ? formatDateTime(account.bound_at) : null)}`), btn('ยกเลิกบัญชีนี้', (event) => bindingAction('account', account.id, event.currentTarget), true)); accounts.append(item);
      });
      if (!accounts.children.length) accounts.append(note('ยังไม่มีบัญชีที่ผูก'));
      const history = $('#line-binding-history'); history.replaceChildren();
      list(row.history).forEach((item) => history.append(note(`${txt(item.oa_name)} · ${statuses[item.status] || txt(item.status)} · ${txt(item.line_user_id_hint, '')} · ${formatDateTime(item.revoked_at || item.bound_at || item.created_at)}`)));
      if (!history.children.length) history.append(note('ยังไม่มีประวัติ'));
      $('#line-binding-detail').hidden = false;
      const overviewRow = state.rows.find((item) => String(item.resident_id) === String(row.resident_id));
      if (overviewRow) {
        const pendingCount = list(row.pending_codes).length, boundCount = Number(row.bound_count || 0);
        Object.assign(overviewRow, { blocked: row.blocked === true, bound_count: boundCount, pending_count: pendingCount, line_binding_status: row.blocked ? 'blocked' : pendingCount ? 'pending' : boundCount ? 'bound' : 'unbound' });
        state.counts = { total: state.rows.length, bound: 0, pending: 0, unbound: 0, blocked: 0, bound_accounts: 0 };
        for (const item of state.rows) { if (Object.hasOwn(state.counts, item.line_binding_status)) state.counts[item.line_binding_status]++; state.counts.bound_accounts += Number(item.bound_count || 0); }
        renderBindings();
      }
      if (previous && Number(row.bound_count) > Number(previous.bound_count)) toast('ผู้พักผูกบัญชี LINE เพิ่มแล้ว');
    }

    async function openBinding(id) {
      const epoch = begin('binding', id, 'จัดการ LINE ของผู้พัก'); if (epoch === null) return;
      try {
        const [row, oas] = await Promise.all([api(`/api/admin/line/residents/${pathId(id)}`), api('/api/admin/line/oas')]);
        if (!isCurrent(epoch, 'binding', id)) return;
        state.oas = list(oas); state.defaultId = oas.default_oa_id;
        const available = fillOas(field(codeForm, 'oa_id')); $('button[type="submit"]', codeForm).disabled = !available;
        renderDetail(row);
      } catch (cause) { if (isCurrent(epoch, 'binding', id)) error(cause); }
    }

    async function bindingAction(action, id, button) {
      const resident = state.id, endpoint = `/api/admin/line/residents/${pathId(resident)}`;
      const suffix = action === 'code' ? `/codes/${pathId(id)}` : action === 'account' ? `/accounts/${pathId(id)}` : action === 'all' ? '/accounts' : `/${action}`;
      const reason = $('#line-block-reason').value.trim();
      if (action === 'block' && !reason) { error(new Error('กรุณาระบุเหตุผลที่ระงับการผูก')); $('#line-block-reason').focus(); return; }
      const title = action === 'unblock' ? 'ปลดการระงับ LINE' : action === 'block' ? 'ระงับ LINE ของผู้พัก' : 'ยกเลิกการผูก LINE';
      const detail = action === 'unblock' ? 'ผู้พักจะสร้างรหัสและผูกใหม่ได้ บัญชีที่เคยถูกยกเลิกจะไม่กลับมาเอง' : action === 'code' ? 'รหัสและ QR นี้จะใช้ไม่ได้ รหัสอื่นและบัญชีที่ผูกแล้วจะยังอยู่' : action === 'account' ? 'บัญชีนี้จะดูห้องและบิลไม่ได้อีก บัญชีอื่นที่ผูกจะยังอยู่' : 'ยกเลิกทุกบัญชีและทุกรหัสของผู้พักรายนี้' + (action === 'block' ? ' และหยุดการผูกใหม่จนกว่าจะปลดระงับ' : ' ผู้พักยังขอรหัสใหม่ได้');
      const succeeded = await mutate(button, () => api(endpoint + suffix, { method: ['block', 'unblock'].includes(action) ? 'POST' : 'DELETE', body: action === 'block' ? { reason } : {} }), (row) => { renderDetail(row.detail || row); toast('บันทึกการเปลี่ยนแปลงการผูกแล้ว'); }, [title, detail, 'ยืนยัน', action !== 'unblock']);
      if (succeeded) loadBindings();
    }

    function renderRecipient(row, populate = true) {
      if (state.id !== null && String(row.id) !== String(state.id)) throw new Error('ข้อมูลผู้รับไม่ตรงกับรายการที่เลือก');
      const previous = state.detail; state.detail = row; state.detailRevision++; clearCodes();
      if (populate) {
        field(recipientForm, 'label').value = row.label || '';
        field(recipientForm, 'enabled').checked = row.enabled !== false;
        field(recipientForm, 'is_owner').checked = row.is_owner === true;
        $$('[name="muted_categories"]', recipientForm).forEach((node) => { node.checked = list(row.muted_categories).includes(node.value); });
      }
      const editing = state.id !== null;
      field(recipientForm, 'oa_id').disabled = editing || !field(recipientForm, 'oa_id').options.length;
      $('#line-recipient-owner-field').hidden = editing;
      $('#line-recipient-enabled-field').hidden = !editing; $('#line-recipient-mutes').hidden = !editing;
      $('#line-recipient-delete').hidden = !editing; $('#line-recipient-refresh').hidden = !editing;
      $('#line-platform-summary').textContent = editing ? `${txt(row.oa_name)} · ${row.is_owner ? 'OWNER' : 'ADMIN'}` : 'ผู้รับต้องส่งรหัสจาก LINE ของตนเองก่อนรับแจ้งเตือน';
      $('#line-recipient-status').textContent = editing ? `${statuses[row.status] || txt(row.status)} · ${txt(row.line_user_id_hint, 'ยังไม่ยืนยัน LINE')}` : 'รหัส OWNER ใช้ได้ 5 นาที · ADMIN ใช้ได้ 10 นาที';
      const code = $('#line-recipient-code'); code.replaceChildren();
      if (row.status === 'pending') code.append(codeSurface(row), note('ให้เจ้าตัวเปิด LINE พร้อมรหัส แล้วกด “ส่ง” ไปยัง OA ที่ระบุ'));
      recipientForm.hidden = false;
      if (state.id !== null) {
        const index = state.recipients.findIndex((item) => String(item.id) === String(row.id));
        if (index >= 0) { state.recipients[index] = { ...state.recipients[index], ...row }; renderOas(); }
      }
      if (previous?.status === 'pending' && row.status === 'claimed') toast('ยืนยันบัญชีผู้รับแจ้งเตือนแล้ว');
    }

    async function openRecipient(id = null) {
      const epoch = begin('recipient', id, id === null ? 'เพิ่มผู้รับแจ้งเตือน LINE' : 'จัดการผู้รับแจ้งเตือน LINE'); if (epoch === null) return;
      try {
        const [row, oas] = await Promise.all([id === null ? Promise.resolve({ enabled: true }) : api(`/api/admin/line/recipients/${pathId(id)}`), api('/api/admin/line/oas')]);
        if (!isCurrent(epoch, 'recipient', id)) return;
        state.oas = list(oas); state.defaultId = oas.default_oa_id;
        const select = field(recipientForm, 'oa_id');
        const available = fillOas(select, row.oa_id ?? state.defaultId);
        if (id !== null) {
          if (![...select.options].some((option) => option.value === String(row.oa_id))) { const option = create('option', '', txt(row.oa_name, `OA ${row.oa_id}`)); option.value = String(row.oa_id); select.append(option); }
          select.value = String(row.oa_id);
        }
        $('button[type="submit"]', recipientForm).disabled = id === null && !available;
        renderRecipient(row);
      } catch (cause) { if (isCurrent(epoch, 'recipient', id)) error(cause); }
    }

    async function refreshDialog(silent = false) {
      if (!dialog.open || state.busy || state.read || !state.detail || !['binding', 'recipient', 'webhook'].includes(state.mode) || state.id === null) return;
      const { epoch, mode, id, detailRevision } = state;
      const token = {}; state.read = token;
      try {
        const row = await api(mode === 'binding' ? `/api/admin/line/residents/${pathId(id)}` : mode === 'recipient' ? `/api/admin/line/recipients/${pathId(id)}` : `/api/admin/line/oas/${pathId(id)}/webhook-status`);
        if (!isCurrent(epoch, mode, id) || detailRevision !== state.detailRevision || state.busy) return;
        if (mode === 'binding') renderDetail(row);
        else if (mode === 'recipient') renderRecipient(row, false);
        else {
          const becameReady = state.detail?.webhook_verified !== true && row.webhook_verified === true;
          state.detail = row; diagnostics($('#line-webhook-detail'), row);
          $('#line-platform-summary').textContent = row.webhook_verified === true ? `${txt(row.name, `OA ${id}`)} · เคย Verify Webhook สำเร็จแล้ว` : `${txt(row.name, `OA ${id}`)} · คัดลอก URL ไปตั้งใน LINE Developers แล้วกด Verify`;
          if (becameReady) { toast('Verify Webhook สำเร็จแล้ว'); loadOas(); }
        }
        if (!silent) toast('อัปเดตสถานะแล้ว');
      } catch (cause) { if (!silent && isCurrent(epoch, mode, id)) error(cause); }
      finally { if (state.read === token) state.read = null; }
    }

    oaForm.addEventListener('submit', async (event) => {
      event.preventDefault(); if (state.busy || !oaForm.reportValidity()) return;
      const id = state.id, values = {};
      ['name', 'slug', 'basic_id', 'channel_id', 'description', 'add_friend_url', 'channel_access_token', 'channel_secret'].forEach((name) => { values[name] = field(oaForm, name).value; });
      ['enabled', 'channel_access_token_clear', 'channel_secret_clear'].forEach((name) => { values[name] = field(oaForm, name).checked; });
      if ((values.channel_access_token_clear && values.channel_access_token) || (values.channel_secret_clear && values.channel_secret)) { error(new Error('เลือกกรอกค่าลับใหม่หรือล้างค่าเดิมอย่างใดอย่างหนึ่ง')); return; }
      const confirmation = values.channel_access_token_clear || values.channel_secret_clear ? ['ล้างค่าลับ LINE', 'OA นี้จะหยุดรับส่งจนกว่าจะตั้งค่าครบอีกครั้ง', 'ล้างค่าที่เลือก', true] : null;
      const succeeded = await mutate($('button[type="submit"]', oaForm), () => api('/api/admin/line/oas' + (id === null ? '' : `/${pathId(id)}`), { method: id === null ? 'POST' : 'PUT', body: values }), (row) => { closeDialog(dialog); toast('บันทึก OA แล้ว'); if (row && validId(row.id)) openWebhook(row.id); }, confirmation);
      if (succeeded) loadOas();
    });

    codeForm.addEventListener('submit', async (event) => {
      event.preventDefault(); if (state.busy || !state.detail || state.detail.blocked || !codeForm.reportValidity()) return;
      const resident = state.id, oa = field(codeForm, 'oa_id').value, ttl = Number(field(codeForm, 'ttl_days').value), replace = field(codeForm, 'replace_pending').value !== 'false';
      if (!validId(oa) || oa === '' || !Number.isInteger(ttl) || ttl < 1 || ttl > 30) { error(new Error('เลือก OA ที่พร้อมและอายุรหัส 1–30 วัน')); return; }
      const confirmation = replace && list(state.detail.pending_codes).length ? ['สร้างรหัสใหม่แทนรหัสเดิม', 'รหัสและ QR ที่ยังรอใช้ทั้งหมดของผู้พักรายนี้จะถูกยกเลิก บัญชีที่ผูกแล้วจะยังอยู่', 'ยกเลิกรหัสเดิมและสร้างใหม่', true] : null;
      let sent = false;
      const succeeded = await mutate($('button[type="submit"]', codeForm), () => {
        sent = true;
        if (replace) { clearCodes(); $('#line-pending-list').replaceChildren(note('กำลังตรวจผลสร้างรหัสใหม่…')); }
        return api(`/api/admin/line/residents/${pathId(resident)}/codes`, { method: 'POST', body: { oa_id: Number(oa), ttl_days: ttl, replace_pending: replace } });
      }, (row) => { renderDetail(row.detail); toast('สร้างรหัสแล้ว ให้ผู้พักส่งรหัสใน LINE ของตนเอง'); }, confirmation);
      if (succeeded) loadBindings();
      else if (sent) refreshDialog(true);
    });

    recipientForm.addEventListener('submit', async (event) => {
      event.preventDefault(); if (state.busy || !recipientForm.reportValidity()) return;
      const id = state.id, creating = id === null, oa = field(recipientForm, 'oa_id').value;
      if (creating && (!validId(oa) || oa === '')) { error(new Error('เลือก OA ที่พร้อมใช้งาน')); return; }
      const values = creating ? { oa_id: Number(oa), label: field(recipientForm, 'label').value, is_owner: field(recipientForm, 'is_owner').checked } : { label: field(recipientForm, 'label').value, enabled: field(recipientForm, 'enabled').checked, muted_categories: $$('[name="muted_categories"]', recipientForm).filter((node) => node.checked).map((node) => node.value) };
      const succeeded = await mutate($('button[type="submit"]', recipientForm), () => api('/api/admin/line/recipients' + (creating ? '' : `/${pathId(id)}`), { method: creating ? 'POST' : 'PUT', body: values }), (row) => { state.id = row.id; renderRecipient(row); toast(creating ? 'สร้างรหัสแล้ว ให้ผู้รับกดส่งจาก LINE ของตนเอง' : 'บันทึกผู้รับแล้ว'); }, creating && values.is_owner ? ['สร้างรหัสผู้รับหลัก OWNER', 'เมื่อยืนยันรหัสนี้ ระบบจะเปลี่ยนผู้รับหลักของ OA ตามรายการที่เลือก ให้เจ้าของตัวจริงส่งรหัสด้วยตนเอง', 'สร้างรหัส OWNER', false] : null);
      if (succeeded) loadOas();
    });

    $('#line-recipient-delete').addEventListener('click', async (event) => {
      const id = state.id;
      if (id === null) return;
      const succeeded = await mutate(event.currentTarget, () => api(`/api/admin/line/recipients/${pathId(id)}`, { method: 'DELETE', body: {} }), () => { closeDialog(dialog); toast('ยกเลิกผู้รับแล้ว'); }, ['ยกเลิกผู้รับแจ้งเตือน', 'ผู้รับนี้จะหยุดรับแจ้งเตือน และรหัสที่ยังรอใช้จะใช้ไม่ได้', 'ยกเลิกผู้รับ', true]);
      if (succeeded) loadOas();
    });
    $('#line-oa-create').addEventListener('click', () => {
      const legacy = state.oas.find((oa) => Number(oa.id) === 0 && !oa.deleted_at);
      const configured = state.oas.some((oa) => oa.credentials_ready === true && !oa.deleted_at);
      openOa(!configured && legacy ? 0 : null);
    });
    $('#line-recipient-create').addEventListener('click', () => openRecipient());
    $('#line-binding-search').addEventListener('input', renderBindings);
    $('#line-binding-filter').addEventListener('change', renderBindings);
    $('#line-binding-refresh').addEventListener('click', () => refreshDialog());
    $('#line-recipient-refresh').addEventListener('click', () => refreshDialog());
    $('#line-binding-block').addEventListener('click', (event) => bindingAction('block', null, event.currentTarget));
    $('#line-binding-unblock').addEventListener('click', (event) => bindingAction('unblock', null, event.currentTarget));
    $('#line-binding-revoke-all').addEventListener('click', (event) => bindingAction('all', null, event.currentTarget));
    dialog.addEventListener('close', () => { resetDialog(); state.mode = ''; state.id = null; });
    const onReturn = () => { if (document.visibilityState !== 'visible' || Date.now() - lastReturnAt < 1000) return; lastReturnAt = Date.now(); refreshDialog(true); };
    document.addEventListener('visibilitychange', onReturn); window.addEventListener('focus', onReturn);
    return { loadOas, loadBindings, openBinding, openOa, openRecipient, refreshDialog };
  }

  window.DormLinePlatform = { init, messageUrl, expiryTime, matchesBinding };
})();
