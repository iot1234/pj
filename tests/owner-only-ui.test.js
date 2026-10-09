'use strict';
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/app.js', 'utf8');
function extract(start, end) { const a = source.indexOf(start), b = source.indexOf(end, a); assert.ok(a >= 0 && b > a); return source.slice(a, b); }
function node(content = '') { return {dataset:{}, textContent:content, children:[], hidden:false, append(...items){this.children.push(...items);}, replaceChildren(){this.children=[];}, reset(){this.resets=(this.resets||0)+1;}, reportValidity:()=>true, focus(){}}; }

test('owner account form has one fixed role and no intermediary admin option', () => {
  const template = fs.readFileSync('templates/admin/console.php', 'utf8');
  const form = template.slice(template.indexOf('id="user-form"'), template.indexOf('</form>', template.indexOf('id="user-form"')));
  assert.match(form, /name="role" value="owner"/); assert.doesNotMatch(form, /value="admin"|<select[^>]*name="role"/);
  assert.doesNotMatch(template, /name="is_owner"/);
});

test('legacy and tombstoned account rows never offer edit, password or reactivation controls', () => {
  const nodes = new Map(), $ = key => {if (!nodes.has(key)) nodes.set(key,node());return nodes.get(key);};
  const actions = [], state = {users:[{id:1,role:'admin',is_active:true,username:'legacy'},{id:2,role:'owner',retired:true,is_active:false,username:'migrated'},{id:3,role:'owner',is_active:true,username:'owner'}]};
  const context = {state,$,create:(_tag,_class,content)=>node(content),text:String,formatDate:String,td:value=>value,pill:value=>value,rowActions:(...items)=>items,
    actionButton:(_label,action,id)=>{actions.push({action,id});return node();},setTableState(){}};
  vm.runInNewContext(extract('function isRetiredAccount(', 'async function loadUsers()'), context); context.renderUsers();
  assert.deepEqual(actions,[{action:'edit-user',id:3},{action:'delete-user',id:3}]);
});

test('a manually changed role input cannot create an admin account through the owner form', async () => {
  let submit; const form=node(), button=node(), writes=[];
  form.elements={is_active:{checked:true}};form.querySelector=()=>button;form.addEventListener=(_name,fn)=>submit=fn;
  const context = {role:'owner',$:key=>key==='#user-form'?form:node(),FormData:class{entries(){return [['id',''],['username','owner'],['password','Test-Owner-Password!'],['role','admin']];}},
    showFormError(){},beginDialogSave:()=>true,finishDialogSave(){},setBusy(){},api:async(url,options)=>writes.push({url,options}),closeDialog(){},toast(){},loadUsers(){}};
  vm.runInNewContext(extract("$('#user-form').addEventListener('submit'", 'billingSettingsForm?.addEventListener'),context);
  await submit({preventDefault(){},currentTarget:form}); assert.equal(writes.length,1);assert.equal(writes[0].options.body.role,'owner');
});

test('a removed account cannot open an editable account form, including after role normalization', () => {
  const form=node(), context={role:'owner',$:()=>form};
  vm.runInNewContext(extract('function isRetiredAccount(', 'function renderUsers()')+extract('function openUserForm(', "$('[data-open-user-dialog]')"),context);
  for(const user of [{role:'admin'},{role:'owner',retired:true}])context.openUserForm(user);
  assert.equal(form.resets,undefined);
});

function booking(reply) {
  let submit; const nodes=new Map(), $=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};
  $('#public-booking-success').hidden=true;$('#booking-idempotency').value='unchanged-booking-key-123';
  const bookingForm=node(), bookingDialog=node(), button=node(), writes=[];let reloads=0;
  bookingForm.dataset.roomCode='A101';bookingForm.querySelector=()=>button;bookingForm.addEventListener=(_name,fn)=>submit=fn;
  const fields={room_id:'7',full_name:'Test Guest',phone:'0812345678',idempotency_key:'unchanged-booking-key-123'};
  const context={bookingForm,bookingDialog,$,ApiError:Error,FormData:class{get(name){return fields[name];}},objectFrom:value=>value,text:String,maskBookingPhone:String,formatDateTime:String,
    showFormError:(n,value)=>n.error=value,setDialogBusy:(n,value)=>n.dataset.dialogBusy=String(value),setFormFieldsBusy(){},setBusy(){},api:async(url,options)=>{writes.push({url,options});return reply;},load:()=>reloads++,window:{}};
  vm.runInNewContext(extract("bookingForm.addEventListener('submit'", '    load();\n  }\n\n  function initLogin('),context);
  return {save:()=>submit({preventDefault(){}}),bookingForm,$,writes,get reloads(){return reloads;}};
}
test('booking success requires the selected room and a usable reference while preserving retry identity',async()=>{
  for(const reply of [{},{id:2,room_id:8,status:'pending',reference_no:'BK-OTHER'},{id:2,room_id:7,status:'cancelled',reference_no:'BK-OLD'},{id:2,room_id:7,status:'pending',reference_no:''}]){
    const ui=booking(reply);await ui.save();assert.equal(ui.bookingForm.hidden,false);assert.equal(ui.$('#public-booking-success').hidden,true);assert.equal(ui.$('#booking-idempotency').value,'unchanged-booking-key-123');assert.equal(ui.reloads,0);
    assert.match(ui.$('#public-booking-error').error.message,/ยังยืนยันผลการจองไม่ได้/);
  }
  const ui=booking({id:2,room_id:7,status:'pending',reference_no:'BK-TEST'});await ui.save();assert.equal(ui.bookingForm.hidden,true);assert.equal(ui.$('#public-booking-success').hidden,false);assert.equal(ui.reloads,1);
});
