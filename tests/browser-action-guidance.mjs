// Called by the disposable operations browser suite. Only local reads and mocked billing data.
import assert from 'node:assert/strict';
export async function verifyActionGuidance(page, base) {
  if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback fixture required');
  const log=message=>console.log('PASS '+message);
  let mode='billed',writes=0;
  const envelope=data=>({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data})});
  const settings='**/api/admin/settings',bills='**/api/admin/bills?*',candidates='**/api/admin/bills/candidates?*';
  await page.route(settings,r=>r.request().method()==='GET'?r.fulfill(envelope({configured:true,water_rate:18,electric_rate:8,due_days:7,integrations:{line_ready:true,slip_provider:'none'}})):r.abort());
  await page.route(bills,r=>{
    if(mode==='failure')return r.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,data:{code:'INTERNAL_ERROR'},message:'Fixture unavailable'})});
    const rows=mode==='billed'?[{id:91001,bill_no:'GUIDE-1',room_id:91001,room_code:'8483',resident_name:'Fixture One',status:'pending',total_amount:100,due_date:'2026-09-28',line_linked:false,line_ready:true,line_can_queue:false},{id:91002,bill_no:'GUIDE-2',room_id:91002,room_code:'1241',resident_name:'Fixture Two',status:'paid',payment_status:'verified',total_amount:100,due_date:'2026-09-28',line_linked:true,line_ready:true,line_can_queue:false}]:[];
    return r.fulfill(envelope(rows));
  });
  await page.route(candidates,r=>r.fulfill(envelope({period:new URL(r.request().url()).searchParams.get('period'),rooms:[{id:91001,room_code:'8483',is_billed:mode==='billed'},{id:91002,room_code:'1241',is_billed:mode==='billed'}]})));
  const blockWrites=async r=>{if(r.request().method()==='GET')return r.fallback();writes++;return r.abort();};
  await page.route('**/api/admin/bills/**',blockWrites);
  await page.route('**/api/admin/bills',blockWrites);
  try {
    await page.goto(base+'/admin#bills');await page.waitForLoadState('networkidle');
    await page.locator('#bill-builder-form [name="confirm_current_period"]').check();
    assert.equal(await page.locator('#preview-bills-button').isDisabled(),true);
    assert.equal(await page.locator('#create-bills-button').isDisabled(),true);
    assert.match(await page.locator('#bill-action-help').innerText(),/ทุกห้องในงวดนี้ออกบิลแล้ว/);
    assert.doesNotMatch(await page.locator('#bill-action-help').innerText(),/ไปเลือกห้อง/);
    assert.equal(await page.locator('#bill-room-options .action-help').count(),2);
    assert.match(await page.locator('#bill-line-help').innerText(),/ยังไม่ผูก LINE \(1 บิล\)/);
    assert.match(await page.locator('#bill-line-help').innerText(),/ไม่อยู่ในสถานะรอชำระ \(1 บิล\)/);
    await page.locator('#bill-admin-rows .line-action-explanation summary').first().click();
    assert.match(await page.locator('#bill-admin-rows .line-action-explanation').first().innerText(),/สร้างรหัสให้ผู้พักยืนยัน/);
    await page.locator('#bill-action-help button').click();
    // The application honors smooth scrolling; wait for the destination rather than one animation frame.
    await page.waitForFunction(()=>{const r=document.querySelector('#bill-existing-section').getBoundingClientRect();return r.top>=0&&r.top<innerHeight;});
    await page.locator('#bill-builder-form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    assert.equal(writes,0);
    for(const width of [320,390,1280]){
      await page.setViewportSize({width,height:900});
      assert.ok(await page.locator('#bill-action-help').evaluate(el=>{const r=el.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&el.scrollWidth<=el.clientWidth+1;}));
    }
    if(process.env.GUIDANCE_SCREENSHOT)await page.locator('[data-admin-view="bills"]').screenshot({path:process.env.GUIDANCE_SCREENSHOT});
    log('all-billed screenshot scenario: nearby causes, existing-bill navigation, per-room explanations, LINE counts, 320/390/1280px and zero writes');

    mode='available';await page.reload();await page.waitForLoadState('networkidle');await page.locator('#bill-builder-form [name="confirm_current_period"]').check();
    if(!await page.locator('#select-all-bill-rooms').isChecked())await page.locator('#select-all-bill-rooms').check();
    assert.equal(await page.locator('#preview-bills-button').isDisabled(),false);
    assert.equal(await page.locator('#create-bills-button').isDisabled(),true);
    assert.match(await page.locator('#bill-action-help').innerText(),/ยังไม่ได้ตรวจยอดล่าสุด/);
    await page.locator('#select-all-bill-rooms').uncheck();assert.match(await page.locator('#bill-action-help').innerText(),/ยังไม่ได้เลือกห้อง/);
    await page.locator('#select-all-bill-rooms').check();await page.locator('#bill-builder-form [name="other_description"]').fill('10');
    assert.match(await page.locator('#bill-action-help').innerText(),/มีชื่อรายการอื่น แต่ยอดเงินเป็น 0/);
    await page.locator('#bill-builder-form [name="other_description"]').fill('');
    assert.equal(await page.locator('#preview-bills-button').isDisabled(),false);assert.equal(writes,0);
    log('correcting prerequisites updates help and enables preview only; issuance still requires a fresh preview');

    mode='failure';await page.reload();await page.waitForLoadState('networkidle');
    assert.equal(await page.locator('#line-bulk-button').isDisabled(),true);
    assert.match(await page.locator('#bill-line-help').innerText(),/อ่านบิลล่าสุดไม่สำเร็จ/);
    assert.match(await page.locator('#bill-action-help').innerText(),/อ่านข้อมูลห้องหรือบิลไม่สำเร็จ/);
    log('read failures keep actions blocked and explain retry instead of reporting ready or no selection');
  } finally {await page.unroute(settings);await page.unroute(bills);await page.unroute(candidates);await page.unroute('**/api/admin/bills/**',blockWrites);await page.unroute('**/api/admin/bills',blockWrites);}

  await page.goto(base+'/admin#settings');await page.waitForLoadState('networkidle');
  await page.locator('#integration-settings-form [name="promptpay_name"]').fill('Guidance unsaved fixture');
  const testButton=page.locator('[data-test-integration="promptpay"]');assert.equal(await testButton.isDisabled(),true);
  const description=await testButton.getAttribute('aria-describedby');assert.ok(description);
  assert.match(await page.locator('#'+description).innerText(),/ค่าที่แก้ไขยังไม่ได้บันทึก/);
  log('unsaved integration credentials explain save-before-test without testing any provider');

  // DOM lifecycle, keyboard and role filtering use synthetic controls, never application actions.
  await page.evaluate(()=>{
    const area=document.createElement('section');area.id='guide-regression';
    const description=document.createElement('p');description.id='original-guide-description';description.textContent='Original';area.append(description);
    const form=document.createElement('form');form.id='guide-test-form';
    for(const id of ['guide-first','guide-second']){const b=document.createElement('button');b.id=id;b.type='button';b.textContent='Test';b.disabled=true;b.setAttribute('aria-describedby','original-guide-description');form.append(b);DormActionGuide.set(b,'NO_ROOMS');}
    area.append(form);document.body.append(area);
  });
  await page.locator('#guide-test-form .action-help').first().waitFor();
  assert.equal(await page.locator('#guide-test-form .action-help').count(),2);
  await page.evaluate(()=>document.querySelector('#guide-test-form').hidden=true);
  await page.waitForFunction(()=>document.querySelectorAll('#guide-test-form .action-help').length===0);
  await page.evaluate(()=>document.querySelector('#guide-test-form').hidden=false);
  await page.waitForFunction(()=>document.querySelectorAll('#guide-test-form .action-help').length===2);
  assert.match(await page.locator('#guide-first').getAttribute('aria-describedby'),/^original-guide-description action-help-/);
  await page.evaluate(()=>document.querySelector('#guide-test-form').setAttribute('aria-busy','true'));
  await page.waitForFunction(()=>document.querySelectorAll('#guide-test-form .action-help').length===1);
  assert.match(await page.locator('#guide-test-form .action-help').innerText(),/กำลังดำเนินการ/);
  await page.evaluate(()=>{document.querySelector('#guide-test-form').removeAttribute('aria-busy');document.querySelectorAll('#guide-test-form > button').forEach(b=>b.disabled=false);});
  await page.waitForFunction(()=>document.querySelectorAll('#guide-test-form .action-help').length===0);
  assert.equal(await page.locator('#guide-first').getAttribute('aria-describedby'),'original-guide-description');
  await page.evaluate(()=>{const input=document.createElement('input');input.id='guide-required';input.required=true;document.querySelector('#guide-test-form').append(input);input.reportValidity();});
  await page.locator('#guide-test-form .action-help').waitFor();
  assert.equal(await page.locator('#guide-required').getAttribute('aria-invalid'),'true');
  assert.match(await page.locator('#guide-test-form .action-help').innerText(),/ข้อมูลช่องนี้ยังไม่ถูกต้อง/);
  await page.locator('#guide-required').fill('Valid');
  await page.waitForFunction(()=>document.querySelectorAll('#guide-test-form .action-help').length===0);
  assert.equal(await page.locator('#guide-required').getAttribute('aria-invalid'),null);
  await page.evaluate(()=>{const panel=document.createElement('div');panel.id='guide-xss';document.querySelector('#guide-regression').append(panel);DormActionGuide.present(panel,[{title:'<img src=x onerror=alert(1)>',detail:'<script>bad()</script>',view:'javascript:alert(1)'}]);});
  assert.equal(await page.locator('#guide-xss img,#guide-xss script,#guide-xss button').count(),0);
  await page.evaluate(()=>{document.body.dataset.userRole='admin';DormActionGuide.present(document.querySelector('#guide-xss'),[DormActionGuide.reason('LINE_NOT_READY')]);});
  assert.equal(await page.locator('#guide-xss button').count(),0);
  await page.evaluate(()=>{document.body.dataset.userRole='owner';document.querySelector('#guide-regression').remove();});
  log('disabled help lifecycle preserves accessibility descriptions, groups busy controls, escapes text and hides owner-only routes');
}
