'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/app.js'), 'utf8');
const template = fs.readFileSync(path.join(__dirname, '../templates/admin/console.php'), 'utf8');
function extract(a,b) { const x=source.indexOf(a), y=source.indexOf(b,x); assert.ok(x>=0&&y>x); return source.slice(x,y); }
function node(value='') { const attrs=new Map(); return {value,disabled:false,checked:false,dataset:{},setAttribute:(k,v)=>attrs.set(k,v),removeAttribute:k=>attrs.delete(k),getAttribute:k=>attrs.get(k)??null,replaceChildren(){this.cleared=true;}}; }
function harness() {
 const state={billPeriod:'2026-08',bills:[{id:1}],billListAvailable:true,billController:null,loaded:new Set(),billingSettingsAvailable:true,settings:{configured:true},billPreview:{signature:'same'}};
 const nodes={'#bill-period':node('2026-08'),'#bill-admin-rows':node(),'#line-bulk-button':node(),'#bill-admin-state':node(),'#select-all-bill-rooms':node(),'#preview-bills-button':node(),'#create-bills-button':node()};
 nodes['#bill-builder-form']={elements:{period:nodes['#bill-period'],confirm_current_period:{checked:true}}};
 const requests=[], selections=[], input={checked:true,disabled:false};
 const context={state,AbortController,$:id=>nodes[id],$$:()=>[input],todayPeriod:()=> '2026-09',billPayloadSignature:()=> 'same',setStat:()=>{},
  setTableState:(n,v)=>{n.dataset.state=v;},errorMessage:e=>e.message,listFrom:(data,key)=>data[key],
  fillBillRooms:preserve=>selections.push(preserve),renderAdminBills:()=>{nodes['#line-bulk-button'].disabled=!context.billDataReady();},
  api:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
 vm.createContext(context);
 vm.runInContext(extract('function billDataReady()', 'function selectedBillRooms()')+extract('function syncBillActionState()', 'function syncCurrentPeriodConfirmation()')+extract('async function loadBills()', 'function clearPromptPayTestQr()'),context);
 return {state,nodes,requests,selections,load:context.loadBills,ready:context.billDataReady,sync:context.syncBillActionState};
}
test('bill reload locks both issuance buttons until the selected month has loaded',async()=>{
 const h=harness(),work=h.load(); assert.equal(h.ready(),false);assert.equal(h.nodes['#create-bills-button'].disabled,true);assert.equal(h.nodes['#preview-bills-button'].disabled,true);
 h.requests[0].resolve({bills:[{id:2}]});await work;assert.equal(h.ready(),true);assert.equal(h.nodes['#line-bulk-button'].disabled,false);
});
test('a late old success cannot replace a new month or unlock the latest request',async()=>{
 const h=harness(),first=h.load();h.nodes['#bill-period'].value='2026-09';const second=h.load();h.requests[0].resolve({bills:[{id:1}]});await first;
 assert.equal(h.ready(),false);assert.equal(h.state.bills.length,0);h.requests[1].resolve({bills:[{id:9}]});await second;assert.equal(h.state.bills[0].id,9);
});
test('a late old failure does not hide a successful current month',async()=>{
 const h=harness(),first=h.load();h.nodes['#bill-period'].value='2026-09';const second=h.load();h.requests[1].resolve({bills:[{id:9}]});await second;h.requests[0].reject(new Error('old failure'));await first;
 assert.equal(h.state.bills[0].id,9);assert.equal(h.ready(),true);assert.notEqual(h.nodes['#bill-admin-state'].dataset.state,'error');
});
test('a failed current reload removes stale bills and keeps all issuance disabled',async()=>{
 const h=harness(),work=h.load();h.requests[0].reject(new Error('offline'));await work;assert.equal(h.ready(),false);assert.equal(h.state.bills.length,0);
 assert.equal(h.nodes['#bill-admin-state'].dataset.state,'error');assert.equal(h.nodes['#preview-bills-button'].disabled,true);assert.equal(h.nodes['#create-bills-button'].disabled,true);assert.equal(h.nodes['#line-bulk-button'].disabled,true);
});
test('bill payload omits calculated values even if obsolete controls are injected',()=>{
 const context={$:()=>({elements:{confirm_current_period:{checked:false}}}),selectedBillRooms:()=>[7],number:Number,todayPeriod:()=> '2026-09',FormData:class {entries(){return Object.entries({period:'2026-08',water_rate:'999',electric_rate:'888',due_date:'2099-01-01',other_amount:'0'});}}};
 vm.createContext(context);vm.runInContext(extract('function billPayload()','function fillBillRooms('),context);
 const result=JSON.parse(JSON.stringify(context.billPayload()));assert.deepEqual(result,{period:'2026-08',room_ids:[7],other_description:'',other_amount:0});
});
test('automatic billing values and LINE identity are outputs, not editable or hidden inputs',()=>{
 const form=id=>{const a=template.indexOf(`id="${id}"`),b=template.indexOf('</form>',a);assert.ok(a>=0&&b>a);return template.slice(a,b);};
 const bills=form('bill-builder-form'),oa=form('line-oa-form');
 for(const name of ['water_rate','electric_rate','due_date'])assert.ok(!bills.includes(`name="${name}"`));
 for(const name of ['name','slug','basic_id','channel_id','description','add_friend_url'])assert.ok(!oa.includes(`name="${name}"`));
 for(const id of ['line-binding-code-form','line-recipient-form'])assert.ok(!form(id).includes('name="oa_id"'));
 for(const id of ['bill-water-rate','bill-electric-rate','bill-due-date'])assert.match(bills,new RegExp(`<output[^>]+id="${id}"`));
});
test('technical defaults are read-only and cannot be blanked by the normal settings form',()=>{
 for(const key of ['line_max_attempts','notification_batch_size','slip_max_bytes','slip_time_tolerance_seconds'])assert.ok(!template.includes(`name="${key}"`));
 assert.ok(template.includes('id="integration-managed-defaults"'));
 const submit=extract("integrationSettingsForm?.addEventListener('submit'", "$$('[data-test-integration]').forEach");
 assert.ok(!submit.includes('values[key] = Number(values[key])'), 'Absent technical inputs must never be converted into NaN/null updates');
});
