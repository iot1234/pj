'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../public/assets/js/app.js'),'utf8');
const a=source.indexOf('  async function api('),b=source.indexOf('  function setBusy(',a);assert.ok(a>0&&b>a);
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};};
function harness(){
 const calls=[],timers=new Map(),activeMutations=new Set();let next=0;
 class ApiError extends Error{constructor(message,status=0,details=null){super(message);this.status=status;this.details=details;}}
 const context={ApiError,activeMutations,maxApiResponseBytes:2097152,csrfToken:'fixture',Headers,FormData,AbortController,DOMException,
 window:{setTimeout:fn=>{timers.set(++next,fn);return next;},clearTimeout:id=>timers.delete(id)},
 $:()=>null,location:{pathname:'/admin',assign:()=>{throw Error('Unexpected redirect');}},
 fetch:(url,options)=>{const call={...deferred(),url,options};calls.push(call);return call.promise;}};
 vm.createContext(context);vm.runInContext(source.slice(a,b),context);
 return {api:context.api,calls,timers,activeMutations,expire(){for(const [id,fn]of [...timers]){timers.delete(id);fn();}}};
}
const reply=data=>({status:200,ok:true,headers:new Headers(),text:async()=>JSON.stringify({ok:true,data})});
test('hung read ends without depending on abort support in the transport',async()=>{
 const h=harness(),work=h.api('/api/admin/settings');const failure=assert.rejects(work,/ช้า|กำหนด/);h.expire();await failure;
 assert.equal(h.calls[0].options.signal.aborted,true);assert.equal(h.timers.size,0);
});
test('hung response body ends and a mutation remains an unknown outcome',async()=>{
 const h=harness(),body=deferred(),work=h.api('/api/admin/settings',{method:'PUT',body:{}});
 h.calls[0].resolve({...reply({}),text:()=>body.promise});await Promise.resolve();
 const failure=assert.rejects(work,e=>e.details?.code==='MUTATION_OUTCOME_UNKNOWN');h.expire();await failure;
 assert.equal(h.activeMutations.size,0);assert.equal(h.timers.size,0);
});
test('late response from a timed-out call cannot release a newer mutation lock',async()=>{
 const h=harness(),first=h.api('/api/admin/settings',{method:'PUT',body:{}});
 const failure=assert.rejects(first,e=>e.details?.code==='MUTATION_OUTCOME_UNKNOWN');h.expire();await failure;
 const second=h.api('/api/admin/settings',{method:'PUT',body:{}});assert.equal(h.calls.length,2);
 h.calls[0].resolve(reply({old:true}));await Promise.resolve();await Promise.resolve();
 await assert.rejects(h.api('/api/admin/settings',{method:'PUT',body:{}}),e=>e.details?.code==='REQUEST_IN_PROGRESS');
 assert.equal(h.calls.length,2);h.calls[1].resolve(reply({saved:true}));assert.equal((await second).saved,true);
 assert.equal(h.activeMutations.size,0);
});
test('external cancellation stops waiting even when fetch ignores its signal',async()=>{
 const h=harness(),controller=new AbortController(),work=h.api('/api/admin/rooms',{signal:controller.signal});
 const failure=assert.rejects(work,e=>e.name==='AbortError');controller.abort();await failure;assert.equal(h.timers.size,0);
});
test('successful response clears its deadline and duplicate guard normally',async()=>{
 const h=harness(),work=h.api('/api/admin/settings',{method:'PUT',body:{}});h.calls[0].resolve(reply({saved:true}));
 assert.equal((await work).saved,true);assert.equal(h.activeMutations.size,0);assert.equal(h.timers.size,0);
});
