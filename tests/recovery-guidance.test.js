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
const source=fs.readFileSync('public/assets/js/app.js','utf8');
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
