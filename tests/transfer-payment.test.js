'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const context={window:{}};vm.createContext(context);vm.runInContext(fs.readFileSync('public/assets/js/transfer-payment.js','utf8'),context);
const helper=context.window.DormTransferPayment;
test('unresolved upload marker survives a reload and clears only after reconciliation',()=>{
 const saved=new Map();context.window.sessionStorage={getItem:key=>saved.get(key),setItem:(key,value)=>saved.set(key,value)};
 const first=helper.recoveryStore();first.add('7');assert.equal(helper.recoveryStore().has('7'),true);
 helper.recoveryStore().delete('7');assert.equal(helper.recoveryStore().has('7'),false);
});
test('unavailable browser storage retains an in-memory recovery guard',()=>{
 context.window.sessionStorage={getItem(){throw new Error('storage disabled');},setItem(){throw new Error('storage disabled');}};
 const store=helper.recoveryStore();store.add('8');assert.equal(store.has('8'),true);store.delete('8');assert.equal(store.has('8'),false);
});
test('malformed recovery storage cannot break payment UI initialization',()=>{
 for(const value of ['not json','null','{}','[null,{},"not-an-id"]']){
  context.window.sessionStorage={getItem:()=>value,setItem(){}};assert.equal(helper.recoveryStore().has('7'),false);
 }
});
const instruction={bill_id:4,bill_amount:'100.00',adjustment_amount:'0.25',amount:'100.25',amount_locked:true,payload:'000201TEST6304ABCD'};
test('exact transfer amount includes the declared cents and is scoped to the requested bill',()=>{assert.equal(helper.validInstruction(instruction,4),true);assert.equal(helper.validInstruction(instruction,5),false);});
test('zero, out-of-range, ambiguous and inconsistent cents never render as a valid instruction',()=>{
 for(const adjustment of ['0.00','1.00','-0.25','0.001',0.25,null])assert.equal(helper.validInstruction({...instruction,adjustment_amount:adjustment},4),false);
 assert.equal(helper.validInstruction({...instruction,amount:'100.00'},4),false);assert.equal(helper.validInstruction({...instruction,amount_locked:false},4),false);
});
test('line fallback requires the fixed official OA domain and matching encoded message',()=>{
 const message='แจ้งชำระ B123';const good={available:true,message,url:'https://line.me/R/oaMessage/%40test/?'+encodeURIComponent(message)};
 assert.ok(helper.safeLineFallback(good));
 for(const url of ['javascript:alert(1)','https://evil.test/','https://line.me.evil.test/R/oaMessage/%40test/?x',good.url+'#x'])assert.equal(helper.safeLineFallback({...good,url}),null);
 assert.equal(helper.safeLineFallback({...good,message:'changed'}),null);assert.equal(helper.safeLineFallback({...good,available:false}),null);
});
test('QR readiness no longer requires the slip provider but retains a separate upload guard',()=>{
 const source=fs.readFileSync('public/assets/js/app.js','utf8');assert.ok(source.includes('promptPayReady && capabilities.transfer_reservation_ready === true'));
 assert.ok(source.includes("state.slipReady = slipReady && !paymentInProgress && status !== 'paid'"));
 assert.ok(source.includes("showSlipLineFallback(state.lineFallback)"));
});

test('resident payment notices agree with QR availability when verification or reservations are unavailable',()=>{
 const source=fs.readFileSync('public/assets/js/app.js','utf8');
 const start=source.indexOf('const paymentConfigurationReady =');
 const end=source.indexOf("const breakdown = $('#resident-bill-breakdown');",start);
 function render({reservation=true,slip=false,payment=null}={}){
  const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,{setAttribute(){}});return nodes.get(key);};
  const state={};const paymentNotice={setAttribute(){}};let lineShown=false;
  const ctx={promptPayReady:true,slipReady:slip,capabilities:{transfer_reservation_ready:reservation},payment,status:'pending',
   state,paymentNotice,bill:{},uncertainPaymentBills:new Set(),preserveSlip:false,renderTransferSummary(){},showSlipLineFallback(){lineShown=true;},$,text:(value,fallback)=>value||fallback};
  vm.runInNewContext(source.slice(start,end),ctx);
  return {state,paymentNotice,lineShown,qrHidden:$('#resident-load-qr').hidden,uploadHidden:$('#resident-slip-form').hidden};
 }
 const available=render();assert.equal(available.qrHidden,false);assert.equal(available.uploadHidden,true);
 assert.match(available.paymentNotice.textContent,/ชำระด้วย QR ได้ตามยอดที่ระบุ/);assert.match(available.paymentNotice.textContent,/LINE Bot/);
 const unavailable=render({reservation:false});assert.equal(unavailable.qrHidden,true);
 assert.match(unavailable.paymentNotice.textContent,/ยังสร้าง QR ไม่ได้/);assert.doesNotMatch(unavailable.paymentNotice.textContent,/ชำระด้วย QR ได้|migration/);
 const pending=render({payment:{status:'pending'}});assert.equal(pending.qrHidden,true);assert.match(pending.paymentNotice.textContent,/อย่าโอนซ้ำ/);
 assert.equal(render({slip:true}).uploadHidden,false);
 const providerDown=render({slip:true,payment:{status:'pending'}});
 assert.equal(providerDown.lineShown,true);assert.equal(providerDown.qrHidden,true);assert.equal(providerDown.uploadHidden,true);
 assert.match(providerDown.paymentNotice.textContent,/LINE Bot/);
});
test('LINE fallback does not submit images, or mark the bill paid, and needs no extra identifier inputs',()=>{
 const source=fs.readFileSync('templates/resident/portal.php','utf8');const part=source.slice(source.indexOf('id="resident-line-slip-fallback"'),source.indexOf('<form class="slip-form"'));
 assert.ok(part.includes('ไม่ได้บันทึกเป็นสลิปในเว็บ'));assert.ok(!part.includes('<input'));assert.ok(part.includes('noopener noreferrer'));
});

test('runtime provisioning, bootstrap and CI all use the canonical fresh-schema table count',()=>{
 const sql=fs.readFileSync('database/schema.sql','utf8');
 const count=[...sql.matchAll(/CREATE TABLE IF NOT EXISTS [a-z_]+/g)].length;
 assert.equal(count,22);
 assert.ok(fs.readFileSync('scripts/provision_runtime_db_user.sh','utf8').includes("'"+count+"|1|1'"));
 assert.ok(fs.readFileSync('scripts/bootstrap_database.sh','utf8').includes('"$object_count" == '+count+' && "$base_table_count" == '+count));
 assert.ok(fs.readFileSync('.github/workflows/ci.yml','utf8').includes("'"+count+"|26|119'"));
});
