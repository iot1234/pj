'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/app.js','utf8');
function harness(){
 const timers=[],nodes=[],region={append(node){nodes.push(node);}};
 const create=(_tag,_class,textContent)=>({textContent,children:[],classList:{add(){},remove(){}},setAttribute(){},append(child){this.children.push(child);},addEventListener(name,fn){this[name]=fn;},remove(){this.removed=true;}});
 const context={Error,$:()=>region,create,requestAnimationFrame:fn=>fn(),window:{setTimeout:(fn,delay)=>timers.push({fn,delay})},errorMessage:e=>e.message,showFormError:(element,error)=>{element.failure=error;}};
 vm.runInNewContext(source.slice(source.indexOf('function toast('),source.indexOf('function openDialog(')),context);
 return {toast:context.toast,timers,nodes};
}
test('error alerts persist until dismissal, preserve structured errors and do not retry requests',()=>{
 const h=harness(),error=Object.assign(new Error('Temporary failure'),{details:{code:'MUTATION_OUTCOME_UNKNOWN'}});
 h.toast(error,'error');assert.equal(h.nodes[0].failure,error);assert.equal(h.timers.length,0);
 const close=h.nodes[0].children[0];assert.equal(close.type,'button');assert.equal(close.textContent,'ปิดข้อความ');close.click();assert.equal(h.nodes[0].removed,true);
});
test('successful notifications keep their existing automatic timeout',()=>{
 const h=harness();h.toast('Saved');assert.equal(h.timers[0].delay,4200);assert.equal(h.nodes[0].children.length,0);
});
