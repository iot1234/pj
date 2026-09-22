/* Read-only explanations. Never enable controls, retry requests or change form values. */
(() => {
  'use strict';
  const catalog = Object.freeze({
    BUSY: ['กำลังดำเนินการ จึงปิดปุ่มชั่วคราว', 'รอผลครั้งนี้ก่อน ไม่กดซ้ำหรือปิดหน้า หากรอนานผิดปกติให้ตรวจรายการล่าสุดก่อนส่งอีกครั้ง'],
    LOADING: ['ยังอ่านข้อมูลล่าสุดไม่ครบ', 'รอให้โหลดเสร็จ หากมีข้อความโหลดไม่สำเร็จ ให้กดลองโหลดใหม่ในส่วนนี้ ไม่ใช้ข้อมูลเก่าเพื่อทำรายการ'],
    NO_ROOMS: ['ยังไม่มีห้องว่างให้รับเข้าพัก', 'ตรวจการจองและผู้พักปัจจุบัน หรือเพิ่มห้องที่มีอยู่จริงก่อน แล้วเปิดฟอร์มใหม่ ห้ามเปลี่ยนสถานะห้องที่ยังมีผู้พัก', 'rooms'],
    BILLED: ['เลือกซ้ำไม่ได้ เพื่อป้องกันบิลซ้ำ', 'ดูบิลเดิมด้านล่าง หรือเปลี่ยนเดือนเพื่อออกบิลรอบอื่น'],
    METER_BILLED: ['มิเตอร์งวดนี้ถูกใช้ในบิลแล้ว', 'ตรวจบิลและประวัติมิเตอร์ก่อน หากเลขผิดให้แจ้งผู้ดูแลพร้อมห้องและงวด ห้ามแก้ทับหลักฐานของบิล', 'bills'],
    METER_OPENING: ['ยังขาดเลขมิเตอร์ ณ วันเข้าพัก', 'กด “เติมเลขเริ่มต้น” ของห้องนี้ ใส่เลขจริงและบันทึก แล้วกลับมาจดมิเตอร์ ไม่ใช้ศูนย์แทนเลขที่ไม่ทราบ', 'residents'],
    METER_HISTORY: ['มิเตอร์ยังแก้ไขในงวดนี้ไม่ได้', 'อ่านเหตุผลของห้องนี้และเติมงวดที่ขาดตามลำดับ หากมีงวดถัดไปอ้างอิงแล้วให้ตรวจประวัติกับผู้ดูแลก่อน ไม่แก้ทับข้อมูลเดิม'],
    LINE_NOT_READY: ['LINE Bot ยังไม่พร้อมสร้างรหัส', 'ให้เจ้าของตรวจและบันทึกบัญชี LINE OA ของหอพักให้ครบ แล้วกลับมาโหลดสถานะใหม่ ใช้บอทเดิมบัญชีเดียว ไม่ต้องเพิ่มบอท', 'line-oas'],
    LINE_BLOCKED: ['ผู้พักถูกระงับการผูก LINE', 'ติดต่อผู้ดูแลให้ตรวจเหตุผลการระงับและปลดระงับเมื่อถูกต้อง แล้วโหลดสถานะใหม่ ไม่สร้างบัญชีอื่นเพื่อข้ามการระงับ', 'line-bindings'],
    LINE_LINKED: ['มีบัญชี LINE ผูกอยู่แล้ว', 'ใช้บัญชีเดิมได้ หากต้องการเปลี่ยนให้ตรวจตัวตนและยกเลิกการผูกเดิมก่อน การยกเลิกจะหยุดส่งไปยังบัญชีเดิม', 'line-bindings'],
    NO_BINDINGS: ['ไม่มีบัญชีหรือรหัสที่รอใช้ให้ยกเลิก', 'ไม่ต้องยกเลิกซ้ำ หากต้องการรับแจ้งเตือน ให้สร้างรหัสและให้ผู้พักยืนยันจาก LINE ของตนเอง'],
    INTEGRATION_DIRTY: ['ค่าที่แก้ไขยังไม่ได้บันทึก จึงยังทดสอบไม่ได้', 'ตรวจข้อมูลแล้วกด “บันทึก” ให้สำเร็จก่อน จากนั้นกดทดสอบ ระบบทดสอบเฉพาะค่าที่บันทึกแล้ว'],
    OWNER_ONLY: ['เฉพาะเจ้าของระบบจึงแก้ไขส่วนนี้ได้', 'ให้เจ้าของระบบตรวจหรือบันทึกการตั้งค่านี้ ไม่ต้องแก้สิทธิ์หรือเปิดปุ่มด้วยตนเอง'],
    PREVIEW_REQUIRED: ['ยังออกบิลไม่ได้ เพราะยังไม่ได้ตรวจยอดล่าสุด', 'กด “ตรวจยอดก่อน” ตรวจห้อง ยอด และกำหนดชำระ แล้วจึงยืนยัน หากเปลี่ยนห้อง เดือน หรือค่าใช้จ่ายต้องตรวจใหม่'],
    UNKNOWN: ['รายการนี้ยังไม่พร้อมใช้งาน', 'อ่านสถานะหรือข้อผิดพลาดในส่วนนี้ก่อน เก็บข้อมูลที่ยังไม่บันทึกไว้แล้วลองโหลดรายการล่าสุด หากยังไม่ได้ให้แจ้งผู้ดูแลพร้อมชื่อหน้า ปุ่ม และเวลา ไม่กดส่งซ้ำเมื่อยังไม่ทราบผลเดิม'],
  });
  function reason(code) {
    const row = Object.hasOwn(catalog, code) ? catalog[code] : catalog.UNKNOWN;
    return { title: row[0], detail: row[1], view: row[2] };
  }
  function lineReasons(bills, ready, lineReady) {
    if (!ready) return [reason('LOADING')];
    if (!bills.length) return [{title:'ยังไม่มีบิลให้ส่ง LINE ในเดือนนี้',detail:'ตรวจรอบเดือน หรือเลือกห้องและตรวจยอดก่อนออกบิล เมื่อมีบิลแล้วจึงเข้าคิวได้'}];
    const groups = new Map();
    const add = (key, title, detail, view) => { const item=groups.get(key)||{title,detail,view,count:0}; item.count++; groups.set(key,item); };
    for (const bill of bills) {
      const payment=String(bill.payment_status||'').toLowerCase();
      if (payment==='pending') add('review','มีสลิปรอตรวจ','ตรวจรายการชำระเดิมก่อน ไม่ส่ง QR ให้โอนซ้ำระหว่างรอผล','payments');
      else if (payment==='verified'||bill.status!=='pending') add('paid','บิลไม่อยู่ในสถานะรอชำระ','ดูสถานะและหลักฐานเดิม ไม่ส่งแจ้งชำระซ้ำ','payments');
      else if (!bill.line_linked) add('unlinked','ผู้พักยังไม่ผูก LINE','เปิด “การผูก LINE ผู้พัก” สร้างรหัสให้ผู้พักยืนยันด้วยบัญชีของตน แล้วกลับมาโหลดบิลใหม่','line-bindings');
      else if (!(typeof bill.line_ready==='boolean'?bill.line_ready:lineReady)) add('oa','บัญชี LINE OA ยังไม่พร้อม','ให้เจ้าของตรวจบัญชี LINE Bot เดิมและบันทึกให้พร้อม แล้วกลับมาโหลดบิลใหม่','line-oas');
      else if (['pending','processing'].includes(bill.line_status)) add('queued','บิลอยู่ในคิวหรือกำลังส่ง','รอแล้วโหลดสถานะใหม่ หากค้างนานให้เจ้าของตรวจตัวประมวลผลคิว ไม่เข้าคิวซ้ำ');
      else if (bill.line_status==='sent') add('sent','LINE รับคำขอส่งแล้ว','ไม่ส่งซ้ำ ผู้พักพิมพ์ “บิล” ในแชตเพื่อดูบิลล่าสุดได้ การรับคำขอไม่ใช่การยืนยันว่าอ่านแล้ว');
      else add('review-send','ต้องตรวจสถานะการส่งก่อน','อ่านข้อผิดพลาดรายบิลและตรวจประวัติ LINE กับผู้ดูแลก่อน คำขอเดิมอาจส่งแล้ว ห้ามบังคับส่งซ้ำ');
    }
    return [...groups.values()].map(item=>({...item,title:`${item.title} (${item.count} บิล)`}));
  }
  const doc=globalThis.document;
  const metadata=new WeakMap(), notes=new Map(), invalids=new Map(); let sequence=0, scheduled=false;
  function canNavigate(view) {
    return doc?.body.dataset.page==='admin-console' && ['rooms','bookings','residents','meters','bills','payments','settings','line-oas','line-bindings'].includes(view)
      && (!['settings','line-oas'].includes(view)||doc.body.dataset.userRole==='owner');
  }
  function present(panel, items, onAction) {
    if (!panel) return;
    const signature=JSON.stringify(items);
    if (panel.dataset.guideSignature===signature) return;
    panel.dataset.guideSignature=signature; panel.replaceChildren(); panel.hidden=!items.length;
    for (const item of items) {
      const block=doc.createElement('span'); block.className='action-help-item';
      const title=doc.createElement('strong'); title.textContent=item.title;
      const detail=doc.createElement('span'); detail.textContent=`วิธีดำเนินการ: ${item.detail}`;
      block.append(title,detail);
      if ((onAction && item.target && (item.target!=='settings'||doc.body.dataset.userRole==='owner')) || canNavigate(item.view)) {
        const button=doc.createElement('button'); button.type='button'; button.className='button button-secondary button-small';
        button.textContent=item.action||'ไปหน้าที่แก้ไขได้';
        if (onAction && item.target) button.addEventListener('click',()=>onAction(item));
        else button.dataset.recoveryView=item.view;
        block.append(button);
      }
      panel.append(block);
    }
  }
  function set(control, value) { if(control){metadata.set(control,typeof value==='string'?reason(value):value); schedule();} }
  function inferred(control) {
    if (metadata.has(control)) return metadata.get(control);
    if (control.closest('#integration-settings-form')) {
      if (doc.body.dataset.userRole!=='owner') return reason('OWNER_ONLY');
      if (control.matches('[data-test-integration]') && control.closest('form').dataset.dirty==='true') return reason('INTEGRATION_DIRTY');
      if(control.closest('form').querySelector('.form-error:not([hidden])'))return {title:'ยังอ่านค่าการเชื่อมต่อไม่สำเร็จ',detail:'อ่านข้อผิดพลาดในฟอร์มนี้แล้วกดลองโหลดการตั้งค่าใหม่ เก็บร่างที่ยังไม่บันทึกไว้ก่อน ระบบไม่ใช้ค่าที่อ่านไม่ได้ไปทดสอบ'};
      return reason('LOADING');
    }
    if (control.id==='resident-profile-fields') return {title:'ยังแก้ไขข้อมูลส่วนตัวไม่ได้',detail:doc.querySelector('#resident-profile-load-state')?.textContent||reason('LOADING').detail};
    const fallback=reason('UNKNOWN');
    if (control.title) fallback.title=control.title;
    return fallback;
  }
  function describe(control,id,add) {
    const ids=new Set((control.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean));
    if(add)ids.add(id);else ids.delete(id);
    if(ids.size)control.setAttribute('aria-describedby',[...ids].join(' '));else control.removeAttribute('aria-describedby');
  }
  function refresh() {
    scheduled=false; if(!doc?.body)return;
    const active=new Map();
    for(const control of invalids.keys())if(!control.isConnected||control.validity?.valid)clearInvalid(control);
    const controls=new Set([...doc.querySelectorAll('button[disabled],input[disabled],select[disabled],textarea[disabled],fieldset[disabled],[aria-disabled="true"]'),...invalids.keys()]);
    for (const control of controls) {
      if (control.type==='hidden'||control.closest('[hidden],[inert]')||control.hasAttribute('data-action-help')) continue;
      if (control.parentElement?.closest('fieldset[disabled]')) continue;
      const busy=control.closest('form[aria-busy="true"],dialog[aria-busy="true"]');
      const key=busy||control;
      const entry=active.get(key)||{controls:[],items:[busy||control.getAttribute('aria-busy')==='true'?reason('BUSY'):invalids.has(control)&&!control.disabled?{title:'ข้อมูลช่องนี้ยังไม่ถูกต้อง จึงส่งแบบฟอร์มไม่ได้',detail:`${control.validationMessage} แก้ช่องนี้แล้วตรวจช่องอื่นที่มีคำเตือนก่อนกดส่งอีกครั้ง`}:inferred(control)]};
      entry.controls.push(control); active.set(key,entry);
    }
    for(const [key,entry] of notes) if(!active.has(key)||!key.isConnected){
      entry.controls.forEach(control=>describe(control,entry.node.id,false));entry.node.remove();notes.delete(key);
    }
    for(const [key,value] of active){
      let entry=notes.get(key);
      if(!entry){
        const node=doc.createElement('span');node.id=`action-help-${++sequence}`;node.className='action-help';node.setAttribute('role','note');
        if(key.matches('form,dialog')) (key.querySelector('.dialog-panel,.modal-card')||key).append(node);
        else if(key.closest('label'))key.closest('label').append(node);
        else key.after(node);
        entry={node,controls:[]};notes.set(key,entry);
      }
      entry.controls.filter(control=>!value.controls.includes(control)).forEach(control=>describe(control,entry.node.id,false));
      entry.controls=value.controls;entry.controls.forEach(control=>describe(control,entry.node.id,true));
      present(entry.node,value.items);
    }
  }
  function clearInvalid(control){
    if(!invalids.has(control))return;
    const previous=invalids.get(control);if(previous===null)control.removeAttribute('aria-invalid');else control.setAttribute('aria-invalid',previous);
    invalids.delete(control);
  }
  function schedule(){if(doc&&!scheduled){scheduled=true;queueMicrotask(refresh);}}
  if(doc?.body && globalThis.MutationObserver){
    doc.addEventListener('invalid',event=>{const control=event.target;if(!invalids.has(control))invalids.set(control,control.getAttribute('aria-invalid'));control.setAttribute('aria-invalid','true');schedule();},true);
    const validated=event=>{if(invalids.has(event.target)){if(event.target.validity?.valid)clearInvalid(event.target);schedule();}};
    doc.addEventListener('input',validated,true);doc.addEventListener('change',validated,true);
    doc.addEventListener('reset',event=>{for(const control of invalids.keys())if(event.target.contains(control))clearInvalid(control);schedule();},true);
    new MutationObserver(records=>{
      // Ignore our own text/button insertions; otherwise observing notes would loop forever.
      if(records.some(record=>!record.target.closest?.('.action-help') && (record.type==='attributes'||[...record.addedNodes,...record.removedNodes].some(node=>node.nodeType===1&&!node.classList.contains('action-help')))))schedule();
    }).observe(doc.body,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled','aria-disabled','aria-busy','hidden','inert','title','data-dirty']});
    schedule();
  }
  globalThis.DormActionGuide=Object.freeze({reason,lineReasons,set,present,refresh});
})();
