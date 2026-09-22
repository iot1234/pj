'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app.js','utf8');
function extract(a,b){const start=source.indexOf(a),end=source.indexOf(b,start);assert.ok(start>=0&&end>start);return source.slice(start,end);}
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};}
function node(){return {dataset:{},disabled:false,textContent:'',hidden:false,setAttribute(){}};}
function login(){
 let submit;const button=node(),form={...node(),reportValidity:()=>true,querySelector:()=>button,addEventListener:(_n,fn)=>submit=fn};
 const requests=[],messages=[],destinations=[];
 const ctx={$:key=>key==='#admin-login-form'?form:node(),FormData:class{entries(){return [['username','fixture'],['password','unchanged']];}},
  api:(_url,options)=>{const d=deferred();requests.push({...d,options});return d.promise;},showFormError:(_n,m)=>messages.push(m),errorMessage:e=>e.message,
  setBusy:(n,busy)=>n.disabled=busy,setFormFieldsBusy:(f,busy)=>f.fieldsDisabled=busy,location:{assign:url=>destinations.push(url)}};
 vm.runInNewContext(extract('function initLogin(', 'function initResidentLogin()'),ctx);ctx.initLogin('#admin-login-form','/api/auth/admin/login','/admin');
 return {button,form,requests,messages,destinations,save:()=>submit({preventDefault(){}})};
}
test('administrator login double submit cannot unlock or replace the pending credentials',async()=>{
 const h=login(),work=h.save();await h.save();assert.equal(h.requests.length,1);assert.equal(h.button.disabled,true);assert.equal(h.form.fieldsDisabled,true);
 h.requests[0].resolve({});await work;assert.deepEqual(h.destinations,['/admin']);assert.equal(h.button.disabled,true);await h.save();assert.equal(h.requests.length,1);
});
test('administrator login failure restores fields and permits a deliberate retry',async()=>{
 const h=login(),first=h.save();h.requests[0].reject(new Error('offline'));await first;assert.equal(h.form.fieldsDisabled,false);assert.equal(h.button.disabled,false);
 const retry=h.save();h.requests[1].resolve({});await retry;assert.equal(h.requests.length,2);
});
function profile(){
 let submit;const nodes=new Map(),$=key=>{if(!nodes.has(key))nodes.set(key,node());return nodes.get(key);};
 const form={...node(),elements:Object.fromEntries(['full_name','email','phone','room_code'].map(name=>[name,{value:name}])),reportValidity:()=>true,querySelector:()=>node(),addEventListener:(_n,fn)=>submit=fn};
 const requests=[],state={profile:{},bills:[],loadRequest:0,profileRevision:0};
 const ctx={$,profileForm:form,state,FormData:class{entries(){return [['full_name','New name'],['email','new@example.test']];}},
  api:(url,options)=>{const d=deferred();requests.push({...d,url,options});return d.promise;},objectFrom:v=>v,listFrom:v=>v,renderLineStatus(){},renderBills(){},errorMessage:e=>e.message,text:String,
  showFormError(){},toast(){},setFormFieldsBusy:(f,busy)=>f.locked=busy,setBusy(){}};
 vm.runInNewContext(extract('function fillProfile()', 'async function refreshVerifyingBills(')+extract("profileForm.addEventListener('submit'", "lineStartForm.addEventListener('submit'"),ctx);
 return {state,form,requests,save:()=>submit({preventDefault(){}}),load:()=>ctx.loadAll()};
}
test('profile duplicate submission cannot invalidate a successful first response',async()=>{
 const h=profile(),work=h.save(),revision=h.state.profileRevision;await h.save();assert.equal(h.requests.length,1);assert.equal(h.state.profileRevision,revision);assert.equal(h.form.locked,true);
 h.requests[0].resolve({full_name:'New name',email:'new@example.test'});await work;
 assert.equal(h.form.elements.full_name.value,'New name');assert.equal(h.state.profileSaving,false);assert.equal(h.form.locked,false);
});
test('a profile read started during saving cannot overwrite the committed result even when it finishes later',async()=>{
 const h=profile(),work=h.save(),read=h.load();h.requests[0].resolve({full_name:'New name',email:'new@example.test'});await work;
 h.requests[1].resolve({full_name:'Old name',email:'old@example.test'});h.requests[2].resolve([]);await read;
 assert.equal(h.form.elements.full_name.value,'New name');assert.equal(h.state.profile.full_name,'New name');
});
test('profile read during pending save does not unlock or refill edited controls',async()=>{
 const h=profile(),work=h.save(),read=h.load();h.requests[1].resolve({full_name:'Old name'});h.requests[2].resolve([]);await read;
 assert.equal(h.form.elements.full_name.value,'full_name');assert.equal(h.form.locked,true);
 h.requests[0].reject(new Error('network lost'));await work;assert.equal(h.form.locked,false);assert.equal(h.form.elements.full_name.value,'full_name');
});
