'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app.js','utf8');
function extract(start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert.ok(a>=0&&b>a);return source.slice(a,b);}
function node(){return {dataset:{},hidden:false,textContent:'',attributes:{},replaceChildren(){this.cleared=true;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}};}
function resident({result={bill_id:7,status:'pending'},error=null,bill={id:7,payment:{status:'pending'}},busy=false,fileSize=100}={}){
 const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};
 const calls=[],toasts=[],reads=[],unknown=new Set();let submit;
 const form=$('#resident-slip-form');form.reset=()=>{form.resets=(form.resets||0)+1;};form.querySelector=()=>node();form.addEventListener=(name,fn)=>{submit=fn;};
 const dialog={dataset:busy?{dialogBusy:'true'}:{}};
 const ctx={$,state:{slipReady:true,slipMaxBytes:1024,currentBillId:7,qrRequest:1,paymentReady:true},billDialog:dialog,
  slipInput:{files:[{type:'image/png',size:fileSize}]},uncertainPaymentBills:unknown,billDetailLoadingMessage:node(),
  FormData:class{},ApiError:class extends Error{constructor(message,status,details){super(message);this.status=status;this.details=details;}},
  showFormError:(n,message='')=>{n.textContent=message;},showSlipLineFallback(){},setBusy(){},
  setDialogBusy:(d,b)=>{d.dataset.dialogBusy=String(b);},toast:(...args)=>toasts.push(args),errorMessage:e=>e.message,
  api:async url=>{calls.push(url);if(error)throw error;return result;},
  openBill:async(...args)=>{reads.push(args);return bill;},loadAll:async()=>{reads.push('list');}};
 vm.runInNewContext(extract("$('#resident-slip-form').addEventListener('submit'",'const initialHash = location.hash'),ctx);
 return {ctx,calls,toasts,reads,unknown,form,$,submit:()=>submit({preventDefault(){},currentTarget:form})};
}
test('slip success feedback distinguishes paid, pending and rejected',async()=>{
 for(const [status,pattern]of[['verified',/ชำระสำเร็จแล้ว/],['pending',/รอผลตรวจ/],['rejected',/สลิปไม่ผ่าน/]]){
  const h=resident({result:{bill_id:7,status}});await h.submit();
  assert.match(h.toasts[0][0],pattern);assert.equal(h.calls.length,1);assert.equal(h.ctx.state.paymentReady,false);
  assert.equal(h.ctx.state.qrRequest,2);assert.equal(h.$('#resident-qr-stage').hidden,true);assert.equal(h.$('#resident-qr-stage').cleared,true);
  assert.equal(h.ctx.billDialog.dataset.dialogBusy,'false');
  assert.equal(h.ctx.slipInput.disabled,false);
 }
});
test('double submit and empty files never send requests',async()=>{
 for(const options of [{busy:true},{fileSize:0}]){const h=resident(options);await h.submit();assert.equal(h.calls.length,0);}
});
test('lost upload response preserves selected evidence and reads server state',async()=>{
 const h=resident({error:Object.assign(new Error('network lost'),{status:0}),bill:{id:7,payment:null}});await h.submit();
 assert.equal(h.unknown.has('7'),true);assert.equal(h.form.resets,undefined);assert.equal(h.reads[0][0],7);
 assert.equal(h.reads[0][1].preserveSlip,true);assert.match(h.$('#resident-slip-error').textContent,/ไม่ต้องโอนซ้ำ/);
});
test('upload locks the file selector and records recovery before dispatch',async()=>{
 const h=resident();let resolve;
 h.ctx.api=()=>{assert.equal(h.ctx.slipInput.disabled,true);assert.equal(h.unknown.has('7'),true);return new Promise(done=>{resolve=done;});};
 const request=h.submit();await h.submit();resolve({bill_id:7,status:'pending'});await request;
 assert.equal(h.ctx.slipInput.disabled,false);assert.equal(h.unknown.has('7'),false);
});
test('a saved payment is not presented as another failed submission after network loss',async()=>{
 const h=resident({error:Object.assign(new Error('network lost'),{status:0})});await h.submit();
 assert.equal(h.$('#resident-slip-error').textContent,'');assert.equal(h.calls.length,1);
});
test('unreadable reconciliation never restores a QR or invites another transfer',async()=>{
 const h=resident({error:Object.assign(new Error('server failure'),{status:503}),bill:undefined});
 h.ctx.openBill=async()=>undefined;await h.submit();
 assert.match(h.ctx.billDetailLoadingMessage.textContent,/ห้ามโอนซ้ำ/);assert.equal(h.ctx.state.paymentReady,false);
});
test('malformed or cross-bill upload success is treated as unknown, not a success toast',async()=>{
 for(const result of [null,{bill_id:8,status:'verified'},{bill_id:7,status:'other'}]){
  const h=resident({result});await h.submit();assert.equal(h.toasts.length,0);assert.equal(h.unknown.has('7'),true);assert.equal(h.form.resets,undefined);
 }
});
test('verifying polls cannot replace a form while upload owns the dialog',async()=>{
 let calls=0;const ctx={billDialog:{dataset:{dialogBusy:'true'}},state:{},api:async()=>{calls++;}};
 vm.runInNewContext(extract('async function refreshVerifyingBills(force = false)','function appendBreakdown('),ctx);
 await ctx.refreshVerifyingBills(true);assert.equal(calls,0);
});
test('changed receiver and unresolved uploads suppress QR but retain evidence submission',()=>{
 const code=extract('const paymentConfigurationReady =',"const breakdown = $('#resident-bill-breakdown');");
 for(const conflict of [true,false]){
  const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};
  const state={},paymentNotice=node();const ctx={promptPayReady:true,slipReady:true,capabilities:{transfer_reservation_ready:true,transfer_instruction_ready:!conflict},
   payment:null,status:'pending',state,paymentNotice,bill:{id:7},uncertainPaymentBills:new Set(conflict?[]:['7']),preserveSlip:false,
   renderTransferSummary(){},showSlipLineFallback(){},$,text:(x,f)=>x||f};
  vm.runInNewContext(code,ctx);assert.equal(state.paymentReady,false);assert.equal(state.slipReady,true);assert.equal($('#resident-load-qr').hidden,true);
  assert.match(paymentNotice.textContent,conflict?/บัญชีรับเงินเปลี่ยน/:/ห้ามโอนซ้ำ/);
 }
});
function adminLoader(){
 const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};$('#payment-status-filter').value='pending';
 const pending=[],states=[];let renders=0;
 const state={payments:[{id:99}],paymentListReady:true,paymentHasMore:true,paymentOffset:100,loaded:new Set()};
 const ctx={state,$,AbortController,URLSearchParams,ApiError:Error,setBusy(){},setTableState:(n,s)=>states.push(s),toast(){},errorMessage:e=>e.message,
  listFrom:d=>d.items,renderPayments:()=>{renders++;},api:()=>new Promise((resolve,reject)=>pending.push({resolve,reject}))};
 vm.runInNewContext(extract('async function loadPayments(append = false)',"loaders.payments = loadPayments;"),ctx);
 return {ctx,state,$,pending,states,get renders(){return renders;}};
}
const list={items:[{id:1}],has_more:false,next_offset:1,pending_count:1};
test('admin reload clears and disables stale payment actions until success',async()=>{
 const h=adminLoader(),request=h.ctx.loadPayments();assert.equal(h.state.payments.length,0);assert.equal(h.state.paymentListReady,false);
 assert.ok('inert' in h.$('#payment-rows').attributes);h.pending[0].resolve(list);await request;
 assert.equal(h.state.paymentListReady,true);assert.ok(!('inert' in h.$('#payment-rows').attributes));
});
test('late admin errors cannot overwrite newer filter results or release its busy marker',async()=>{
 const h=adminLoader(),old=h.ctx.loadPayments();h.$('#payment-status-filter').value='verified';const current=h.ctx.loadPayments();
 h.pending[0].reject(new Error('old failure'));await old;assert.equal(h.$('#payment-rows').attributes['aria-busy'],'true');
 h.pending[1].resolve(list);await current;assert.equal(h.states.includes('error'),false);assert.equal(h.renders,1);
});
test('malformed payment lists fail closed instead of showing an empty or actionable list',async()=>{
 const h=adminLoader(),request=h.ctx.loadPayments();h.pending[0].resolve({});await request;
 assert.equal(h.state.paymentListReady,false);assert.equal(h.states.at(-1),'error');assert.equal(h.renders,0);
});
test('load-more cannot dispatch two overlapping page requests',async()=>{
 const h=adminLoader(),first=h.ctx.loadPayments(true);await h.ctx.loadPayments(true);assert.equal(h.pending.length,1);
 h.pending[0].resolve(list);await first;
});
test('close-payment form captures its target before locking and prevents duplicate submits',async()=>{
 let submit,calls=0,resolveRequest;const form={dataset:{},reportValidity:()=>true,querySelector:()=>node(),reset(){},addEventListener:(name,fn)=>{submit=fn;}};
 const ctx={$:key=>key==='#payment-close-form'?form:node(),FormData:class{entries(){return [['payment_id','13'],['reason','fixture reason']];}},
  beginDialogSave:f=>{f.dataset.submitting='true';return true;},finishDialogSave:f=>{delete f.dataset.submitting;},setBusy(){},showFormError(){},toast(){},closeDialog(){},
  api:()=>{calls++;return new Promise(resolve=>{resolveRequest=resolve;});},loadPayments:async()=>{},loadBills:async()=>{},errorMessage:e=>e.message};
 vm.runInNewContext(extract("$('#payment-close-form').addEventListener('submit'",'function renderUsers()'),ctx);
 const event={preventDefault(){},currentTarget:form};const first=submit(event);await submit(event);assert.equal(calls,1);
 resolveRequest({});await first;assert.equal(form.dataset.submitting,undefined);
});
