'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app.js','utf8');
function extract(start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert.ok(a>=0&&b>a);return source.slice(a,b);}
function node(){return {dataset:{},attributes:{},value:'',hidden:false,disabled:false,replaceChildren(){this.cleared=true;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}};}
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};}
for(const spec of [
 {rows:'booking-rows',name:'bookings',singular:'booking',action:'confirm-booking',end:"$('#booking-cancel-form').addEventListener('submit'"},
 {rows:'user-rows',name:'users',singular:'user',action:'delete-user',end:"$('#user-form').addEventListener('submit'"},
]){
 function harness(){
  let click,calls=0,reads=0;const confirm=deferred(),request=deferred(),record={id:1},state={[spec.name]:[record],[spec.singular+'ListReady']:true};
  const button={...node(),dataset:{id:'1',action:spec.action}};
  const ctx={state,$:()=>({addEventListener(_name,fn){click=fn;}}),text:v=>String(v),confirmAction:()=>confirm.promise,
   api:()=>{calls++;return request.promise;},setBusy:(b,busy)=>{b.disabled=busy;},toast(){},errorMessage:e=>e.message,
   loadBookings:async()=>{reads++;},loadRooms:async()=>{},loadUsers:async()=>{reads++;}};
  vm.runInNewContext(extract(`$('#${spec.rows}').addEventListener('click'`,spec.end),ctx);
  return {state,confirm,request,button,get calls(){return calls;},get reads(){return reads;},click:()=>click({target:{closest:()=>button}})};
 }
 test(`${spec.name}: stale confirmation after a list reload cannot mutate the old row`,async()=>{
  const h=harness(),work=h.click();h.state[spec.name]=[{id:1}];h.confirm.resolve(true);await work;assert.equal(h.calls,0);assert.equal(h.state[spec.singular+'ActionBusy'],false);
 });
 test(`${spec.name}: confirmation and request are fenced against double clicks`,async()=>{
  const h=harness(),work=h.click();await h.click();h.confirm.resolve(true);await new Promise(setImmediate);await h.click();assert.equal(h.calls,1);
  h.request.reject(new Error('response lost'));await work;assert.equal(h.reads,1);assert.equal(h.button.disabled,false);assert.equal(h.state[spec.singular+'ActionBusy'],false);
 });
}
for(const spec of [
 {name:'bookings',singular:'booking',start:'async function loadBookings(append = false)',end:'async function refreshBookingPendingCount()',fn:'loadBookings',reply:{items:[{id:1}],next_offset:1,has_more:false,pending_count:1}},
 {name:'users',singular:'user',start:'async function loadUsers()',end:'loaders.users = loadUsers;',fn:'loadUsers',reply:[{id:1}]},
]){
 function harness(){
  const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};
  const state={loaded:new Set(),[spec.name]:[{id:9}],[spec.singular+'ListReady']:true,bookingHasMore:true,bookingOffset:1};
  const pending=[],states=[];let renders=0;
  const ctx={state,$,role:'owner',AbortController,URLSearchParams,ApiError:Error,setBusy(){},setTableState:(n,s)=>states.push(s),toast(){},errorMessage:e=>e.message,
   listFrom:d=>Array.isArray(d)?d:d.items,renderBookings:()=>renders++,renderUsers:()=>renders++,api:()=>{const d=deferred();pending.push(d);return d.promise;}};
  vm.runInNewContext(extract(spec.start,spec.end),ctx);
  return {ctx,state,$,pending,states,load:()=>ctx[spec.fn](),get renders(){return renders;}};
 }
 test(`${spec.name}: reload clears and disables stale rows; malformed data fails closed`,async()=>{
  const h=harness(),work=h.load();assert.equal(h.state[spec.name].length,0);assert.equal(h.state[spec.singular+'ListReady'],false);
  assert.ok('inert' in h.$(`#${spec.singular}-rows`).attributes);h.pending[0].resolve({});await work;
  assert.equal(h.state[spec.singular+'ListReady'],false);assert.equal(h.renders,0);assert.equal(h.states.at(-1),'error');
 });
 test(`${spec.name}: late failure cannot overwrite a newer successful reload`,async()=>{
  const h=harness(),old=h.load(),current=h.load();h.pending[1].resolve(spec.reply);await current;h.pending[0].reject(new Error('old'));await old;
  assert.equal(h.renders,1);assert.equal(h.states.includes('error'),false);assert.equal(h.state[spec.singular+'ListReady'],true);assert.ok(!('inert' in h.$(`#${spec.singular}-rows`).attributes));
 });
}
for(const [formId,end]of [
 ['booking-cancel-form',"$('#move-in-form').addEventListener('submit'"],
 ['resident-move-out-form','const meterHasPendingOpening ='],
 ['payment-close-form','function renderUsers()'],
]){
 function harness(){
  const requests=[],reload=deferred(),form={...node(),elements:{booking_id:{value:'1'},reason:{value:'fixture'}},reportValidity:()=>true,querySelector:()=>node(),reset(){},addEventListener(_name,fn){this.submit=fn;}};
  const ctx={$:key=>key===`#${formId}`?form:node(),FormData:class{entries(){return [['resident_id','2'],['payment_id','3'],['reason','fixture'],['move_out_date','2026-09-22']];}},
   beginDialogSave:f=>{f.dataset.submitting='true';return true;},finishDialogSave:f=>delete f.dataset.submitting,setBusy(){},showFormError(){},toast(){},closeDialog(){},errorMessage:e=>e.message,
   api:()=>{const d=deferred();requests.push(d);return d.promise;},loadBookings:()=>reload.promise,loadRooms:()=>reload.promise,loadResidents:()=>reload.promise,loadBills:()=>reload.promise,loadPayments:()=>reload.promise};
  vm.runInNewContext(extract(`$('#${formId}').addEventListener('submit'`,end),ctx);
  return {requests,reload,form,save:()=>form.submit({preventDefault(){},currentTarget:form})};
 }
 test(`${formId}: duplicate submit sends one request and failed save releases controls`,async()=>{
  const h=harness(),first=h.save();await h.save();assert.equal(h.requests.length,1);h.requests[0].reject(new Error('failure'));h.reload.resolve();await first;assert.equal(h.form.dataset.submitting,undefined);
 });
 test(`${formId}: late post-save reload cannot unlock a newer save`,async()=>{
  const h=harness(),first=h.save();h.requests[0].resolve({});await new Promise(setImmediate);const second=h.save();h.reload.resolve();await first;
  assert.equal(h.form.dataset.submitting,'true');assert.equal(h.requests.length,2);h.requests[1].resolve({});await second;assert.equal(h.form.dataset.submitting,undefined);
 });
}
