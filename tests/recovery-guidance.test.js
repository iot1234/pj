'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const context={window:{}};
vm.runInNewContext(fs.readFileSync('public/assets/js/recovery-guidance.js','utf8'),context);
const explain=context.window.DormRecoveryGuide.explain;
const error=(code,details={})=>Object.assign(new Error(code),{details:{code,...details}});
const admin={page:'admin-console',role:'owner'};
test('recovery maps business blockers to the correct read-only destination',()=>{
 for(const [code,view]of Object.entries({MOVE_OUT_HAS_PENDING_BILLS:'bills',MOVE_OUT_MISSING_BILLS:'bills',MOVE_OUT_HAS_LATER_METERS:'meters',METER_OPENING_REQUIRED:'residents',ROOM_OCCUPIED:'residents',BOOKING_EXPIRED:'bookings',BILLING_SETTINGS_UNCONFIRMED:'settings',LINE_NOT_CONFIGURED:'line-oas',TRANSFER_SLOTS_FULL:'payments'})){
  const guide=explain(error(code),admin);assert.equal(guide.view,view);assert.ok(guide.detail.length>20);
 }
});
test('owner-only directions never offer privileged navigation to other roles or residents',()=>{
 for(const code of ['PROMPTPAY_NOT_CONFIGURED','LINE_NOT_CONFIGURED','SLIP_NOT_CONFIGURED']){
  assert.equal(explain(error(code),{page:'admin-console',role:'admin'}).view,null);
  assert.equal(explain(error(code),{page:'resident-portal',role:'resident'}).view,null);
 }
});
test('field guidance accepts known form names only and never renders untrusted code as navigation',()=>{
 assert.equal(explain(error('VALIDATION_ERROR',{field:'phone'})).field,'phone');
 for(const field of ['__proto__','constructor','<img onerror=alert(1)>'])assert.equal(explain(error('VALIDATION_ERROR',{field})).field,undefined);
 for(const code of ['__proto__','constructor','javascript:alert(1)'])assert.equal(explain(error(code),admin).view,undefined);
});
test('unknown write outcomes, session expiry and rate limits never suggest an automatic write retry',()=>{
 for(const code of ['MUTATION_OUTCOME_UNKNOWN','CSRF_INVALID','UNAUTHENTICATED','RATE_LIMITED']){
  const guide=explain(error(code),admin);assert.equal(guide.view,undefined);assert.equal(guide.field,undefined);
 }
 assert.match(explain(error('MUTATION_OUTCOME_UNKNOWN')).detail,/ตรวจรายการล่าสุดก่อนทำซ้ำ/);
 assert.match(explain(error('SLIP_NOT_CONFIGURED')).detail,/ไม่ต้องโอนซ้ำ/);
});
test('nonpayment conflicts guide to existing records without bypassing identity or owner guards',()=>{
 for(const [code,view]of Object.entries({ROOM_IN_USE:'rooms',BOOKING_BAD_STATE:'bookings',BOOKING_PHONE_ACTIVE:'bookings',RESIDENT_IDENTITY_IN_USE:'residents',BILL_PREVIEW_CHANGED:'bills'}))assert.equal(explain(error(code),admin).view,view);
 assert.equal(explain(error('ROOM_CODE_EXISTS'),admin).field,'room_code');
 for(const code of ['SELF_DELETE','SELF_OWNER_CHANGE','LAST_OWNER']){const guide=explain(error(code),admin);assert.equal(guide.view,undefined);assert.match(guide.detail,/อย่างน้อยหนึ่ง/);}
});
const source=fs.readFileSync('public/assets/js/app.js','utf8');
test('billing, meter, payment and LINE aliases route to an actual repair without bypassing safeguards',()=>{
 for(const [code,view]of Object.entries({BILLING_SETTINGS_NOT_CONFIRMED:'settings',METER_ALREADY_BILLED:'bills',METER_HISTORY_LOCKED:'meters',RESIDENT_REUSE_CONFIRMATION_REQUIRED:'residents',BILL_PAYMENT_VERIFIED:'payments',DUPLICATE_SLIP:'payments',LINE_LINK_CODE_INVALID:'line-bindings',LINE_TOKEN_REJECTED:'line-oas',LINE_SINGLE_BOT_ONLY:'line-oas'})){
  const result=explain(error(code),admin);assert.equal(result.view,view);assert.ok(result.detail.length>30);
 }
 for(const code of ['SLIP_TOO_LARGE','SLIP_FILE_UNREADABLE','SLIP_TYPE_INVALID'])assert.match(explain(error(code)).detail,/ไม่ต้องโอนซ้ำ/);
 assert.equal(explain(error('LINE_TOKEN_REJECTED'),{page:'admin-console',role:'admin'}).view,null);
});
test('credential, permissions and missing records have honest manual recovery, no write retry',()=>{
 assert.match(explain(error('INVALID_CREDENTIALS'),{page:'admin-login'}).detail,/รหัสผ่าน/);
 assert.match(explain(error('INVALID_CREDENTIALS'),{page:'resident-login'}).detail,/เบอร์โทร/);
 for(const code of ['FORBIDDEN','RESIDENT_SESSION_STALE','REQUEST_IN_PROGRESS','BILL_NOT_FOUND','PAYMENT_NOT_FOUND']){const result=explain(error(code),admin);assert.equal(result.view,undefined);assert.ok(result.detail.length>30);}
});
test('locked receiver and uncertain LINE errors guide to review without resending or clearing amounts',()=>{
 for(const code of ['PROMPTPAY_HAS_RESERVED_BILLS','LINE_DELIVERY_RECONCILIATION_REQUIRED','LINE_RETRY_WINDOW_EXPIRED']){
  const guide=explain(error(code),admin);assert.equal(guide.view,'bills');
  assert.equal(explain(error(code),{role:'resident',page:'resident-portal'}).view,null);
 }
 assert.match(explain(error('PROMPTPAY_HAS_RESERVED_BILLS'),admin).detail,/บัญชีเดิมยังไม่ถูกเปลี่ยน/);
 assert.match(explain(error('LINE_DELIVERY_RECONCILIATION_REQUIRED'),admin).detail,/ห้ามเข้าคิวซ้ำ/);
});
function extract(start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert.ok(a>=0&&b>a);return source.slice(a,b);}
function node(){return {dataset:{},children:[],append(n){this.children.push(n);},addEventListener(name,fn){this[name]=fn;}};}
test('form recovery focuses the invalid field without submitting, including after a save lock releases',()=>{
 let focused=0,scrolled=0;const input={type:'text',disabled:true,focus(){focused++;},scrollIntoView(){scrolled++;}};
 const form={elements:{namedItem:name=>name==='phone'?input:null}};
 const element={...node(),closest:()=>form};
 const ctx={Error,window:context.window,body:{dataset:{userRole:'owner',page:'admin-console'}},errorMessage:e=>e.message,create:(_tag,_cls,label)=>({...node(),textContent:label})};
 vm.runInNewContext(extract('function showFormError(', 'function toast('),ctx);
 ctx.showFormError(element,error('VALIDATION_ERROR',{field:'phone'}));
 assert.equal(element.hidden,false);assert.equal(element.children[1].type,'button');
 element.children[1].click();assert.equal(focused,0);input.disabled=false;element.children[1].click();assert.equal(focused,1);assert.equal(scrolled,1);
});
test('form recovery exposes only a known destination, with a separate user click required',()=>{
 const element={...node(),closest:()=>null};
 const ctx={Error,window:context.window,body:{dataset:{userRole:'owner',page:'admin-console'}},errorMessage:e=>e.message,create:()=>node()};
 vm.runInNewContext(extract('function showFormError(', 'function toast('),ctx);
 ctx.showFormError(element,error('MOVE_OUT_HAS_PENDING_BILLS'));assert.equal(element.children[1].dataset.recoveryView,'bills');
 assert.equal(element.children[1].type,'button');
 const navigation=extract("doc.addEventListener('click', (event) => {\n      const button = event.target.closest('[data-recovery-view]');", "$$('[data-admin-nav]')");
 assert.match(navigation,/dialogCloseBlocked/);assert.match(navigation,/window.confirm/);assert.match(navigation,/Object.hasOwn\(titles/);assert.doesNotMatch(navigation,/api\(/);
});
