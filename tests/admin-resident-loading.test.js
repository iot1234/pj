'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app.js','utf8');
function extract(start,end){const a=source.indexOf(start),b=source.indexOf(end,a);assert.ok(a>=0&&b>a);return source.slice(a,b);}
function node(){return {dataset:{},attributes:{},hidden:false,disabled:false,replaceChildren(){this.cleared=true;},setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}};}
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};}
const validator=extract('const requiredEntityList =', 'const errorMessagesByCode =');
for(const kind of ['rooms','residents']){
 const singular=kind==='rooms'?'room':'resident',rows=kind==='rooms'?'#admin-room-rows':'#resident-rows';
 function harness(){
  const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};
  const state={loaded:new Set(),[kind]:[{id:1}],[singular+'ListReady']:true,residentLoadGeneration:0};
  const pending=[],states=[];let renders=0;
  const ctx={$,state,AbortController,ApiError:Error,setTableState:(_n,s)=>states.push(s),errorMessage:e=>e.message,setStat(){},invalidateBillPreview(){},syncBillActionState(){},fillBillRooms(){},
   renderRooms:()=>renders++,renderResidents:()=>renders++,api:()=>{const d=deferred();pending.push(d);return d.promise;}};
  vm.runInNewContext(validator+extract(`async function load${kind==='rooms'?'Rooms':'Residents'}()`,kind==='rooms'?'function openRoomForm(':'loaders.residents ='),ctx);
  return {state,$,pending,states,get renders(){return renders;},load:()=>ctx[kind==='rooms'?'loadRooms':'loadResidents']()};
 }
 test(`${kind}: pending reload immediately removes cached and visible actions`,async()=>{
  const h=harness(),work=h.load();assert.equal(h.state[kind].length,0);assert.equal(h.state[singular+'ListReady'],false);assert.ok(h.$(rows).cleared);assert.ok('inert'in h.$(rows).attributes);
  h.pending[0].resolve([{id:2}]);await work;assert.equal(h.state[singular+'ListReady'],true);assert.equal(h.renders,1);
 });
 test(`${kind}: invalid, duplicate and incomplete entities cannot become an actionable list`,async()=>{
  for(const value of [{},[{id:1},{id:1}],[null],[{id:'1'}],[{id:0}]]){
   const h=harness(),work=h.load();h.pending[0].resolve(value);await work;assert.equal(h.state[singular+'ListReady'],false);assert.equal(h.renders,0);assert.equal(h.states.at(-1),'error');
  }
 });
 test(`${kind}: older success cannot undo a newer failed refresh`,async()=>{
  const h=harness(),old=h.load(),current=h.load();h.pending[1].reject(new Error('current failure'));await current;h.pending[0].resolve([{id:1}]);await old;
  assert.equal(h.state[singular+'ListReady'],false);assert.equal(h.state[kind].length,0);assert.equal(h.renders,0);assert.equal(h.states.at(-1),'error');
 });
 test(`${kind}: older failure cannot overwrite newer successful data`,async()=>{
  const h=harness(),old=h.load(),current=h.load();h.pending[1].resolve([{id:2}]);await current;h.pending[0].reject(new Error('old'));await old;
  assert.equal(h.state[kind][0].id,2);assert.equal(h.state[singular+'ListReady'],true);assert.equal(h.states.includes('error'),false);
 });
}
test('resident actions and search cannot revive a not-ready list',()=>{
 const state={residentListReady:false,residents:[{id:1}]};let touched=0;
 const ctx={state,$:()=>{touched++;throw new Error('stale UI touched');}};
 vm.runInNewContext(extract('function renderResidents()', 'async function loadResidents()'),ctx);ctx.renderResidents();assert.equal(touched,0);
 let handler;ctx.$=()=>({addEventListener:(_name,fn)=>{handler=fn;}});
 vm.runInNewContext(extract("$('#resident-rows').addEventListener('click'", "$('#opening-readings-form').addEventListener('submit'"),ctx);
 handler({target:{closest:()=>{throw new Error('stale click handled');}}});
});
test('resident create opening ignores obsolete loads and never resets a live form',async()=>{
 const pending=[],state={residentCreateOpenGeneration:0,roomListReady:true},form={...node(),elements:{idempotency_key:{},move_in_date:{},room_id:{},full_name:{}},reset(){this.resets=(this.resets||0)+1;}},dialog={open:false};
 const ctx={state,$:key=>key==='#resident-create-form'?form:dialog,dialogCloseBlocked:d=>d.busy,loadRooms:()=>{const d=deferred();pending.push(d);return d.promise;},
  showFormError(){},resetResidentCreateReuse(){},isoToday:()=>'',isoDateOffsetDays:()=>'',populateResidentCreateRooms(){},openDialog:d=>{d.open=true;},toast(){},window:{crypto:{randomUUID:()=> 'fixture-unique'},setTimeout(){}}};
 vm.runInNewContext(extract('async function openResidentCreateForm(', 'function renderResidents()'),ctx);
 const a=ctx.openResidentCreateForm(),b=ctx.openResidentCreateForm();pending[0].resolve();await a;assert.equal(form.resets,undefined);
 pending[1].resolve();await b;assert.equal(form.resets,1);await ctx.openResidentCreateForm();assert.equal(form.resets,1);assert.equal(pending.length,2);
});
test('failed fresh-room read keeps the resident-create form closed',async()=>{
 const state={residentCreateOpenGeneration:0,roomListReady:false},dialog={open:false},form={dataset:{}};let notices=0;
 const ctx={state,$:key=>key==='#resident-create-form'?form:dialog,dialogCloseBlocked:()=>false,loadRooms:async()=>{},toast:()=>notices++};
 vm.runInNewContext(extract('async function openResidentCreateForm(', 'function renderResidents()'),ctx);
 await ctx.openResidentCreateForm();assert.equal(dialog.open,false);assert.equal(notices,1);
});
test('room delete rechecks a refreshed record and fences duplicate confirmations',async()=>{
 for(const stale of [false,true]){
  let handler,calls=0,reads=0;const confirm=deferred(),record={id:1},button={dataset:{id:'1',action:'delete-room'}},state={rooms:[record],roomListReady:true};
  const ctx={state,$:()=>({addEventListener:(_name,fn)=>{handler=fn;}}),text:String,confirmAction:()=>confirm.promise,setBusy:(b,v)=>{b.disabled=v;},api:async()=>{calls++;},loadRooms:async()=>{reads++;},toast(){},errorMessage:e=>e.message};
  vm.runInNewContext(extract("$('#admin-room-rows').addEventListener('click'", "$('#room-form').addEventListener('submit'"),ctx);
  const event={target:{closest:()=>button}},work=handler(event);await handler(event);if(stale)state.rooms=[{id:1}];confirm.resolve(true);await work;
  assert.equal(calls,stale?0:1);assert.equal(reads,stale?0:1);assert.equal(state.roomActionBusy,false);
 }
});
