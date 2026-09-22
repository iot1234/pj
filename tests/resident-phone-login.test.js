'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../public/assets/js/app.js'),'utf8');
const login=source.slice(source.indexOf('function initResidentLogin()'),source.indexOf('function initResidentPortal()'));
const template=fs.readFileSync(path.join(__dirname,'../templates/resident/login.php'),'utf8');
function harness(){
 const phone={value:'081-234-5678',focus(){this.focused=true;}},button={disabled:false},errors=[],requests=[],destinations=[];
 const form={elements:{phone},valid:true,reportValidity(){return this.valid;},querySelector:()=>button,addEventListener(_event,fn){this.submit=fn;}};
 const context={$:s=>s==='#resident-login-form'?form:{},setFormFieldsBusy:(f,b)=>{f.locked=b;},setBusy:(b,v)=>{b.disabled=v;},showFormError:(_n,m='')=>errors.push(m),
  errorMessage:(e,f)=>e.message||f,ApiError:Error,location:{assign:u=>destinations.push(u)},
  api:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))};
 vm.createContext(context);vm.runInContext(login+'\ninitResidentLogin();',context);
 return {phone,form,button,errors,requests,destinations,submit:()=>form.submit({preventDefault(){}})};
}
test('resident login has exactly one phone input and no credential or activation mode',()=>{
 assert.equal((template.match(/<input\b/g)||[]).length,1);assert.match(template,/name="phone" type="tel"/);
 for(const name of ['credential','new_password','otp','activation'])assert.ok(!template.includes(`name="${name}"`));
 assert.ok(!source.includes("INVALID_CREDENTIALS: 'เบอร์โทรหรือรหัสลับ"));
 assert.ok(!template.includes('type="password"'));assert.ok(!login.includes('firstActivation'));
});
test('an invalid empty form does not send a request',async()=>{const h=harness();h.form.valid=false;await h.submit();assert.equal(h.requests.length,0);});
test('admin onboarding and phone-change guidance agree with phone-only access',()=>{
 const admin=fs.readFileSync(path.join(__dirname,'../templates/admin/console.php'),'utf8');
 assert.doesNotMatch(admin,/ระบบจะแสดงรหัสเปิดใช้งาน|ตั้งรหัสผ่านใหม่|แล้วออก activation code/);
 assert.match(admin,/ผู้พักใช้เบอร์ใหม่เข้าสู่ระบบได้ทันที แล้วผูก LINE ใหม่/);
 assert.match(admin,/data-resident-stat="opening"/);assert.doesNotMatch(admin,/data-resident-stat="activation"/);
 const render=source.slice(source.indexOf('function renderResidents()'),source.indexOf('async function loadResidents()'));
 assert.match(render,/data-resident-stat="opening"/);assert.doesNotMatch(render,/activation_pending|ต้องออกคีย์/);
});
test('a pending phone request locks the whole form and a second submit sends nothing',async()=>{
 const h=harness(),work=h.submit();assert.equal(h.form.locked,true);await h.submit();assert.equal(h.requests.length,1);
 assert.deepEqual(JSON.parse(JSON.stringify(h.requests[0].options.body)),{phone:'081-234-5678'});
 h.requests[0].reject(new Error('ไม่พบเบอร์'));await work;assert.equal(h.form.locked,false);assert.equal(h.button.disabled,false);assert.equal(h.phone.value,'081-234-5678');
});
test('a retry after failure uses the edited phone and no password fields',async()=>{
 const h=harness();let work=h.submit();h.requests[0].reject(new Error('หมดเวลารอ'));await work;
 h.phone.value='0817900002';work=h.submit();assert.equal(h.requests[1].options.body.phone,'0817900002');
 h.requests[1].resolve({user:{type:'resident',auth_method:'phone'}});await work;assert.deepEqual(h.destinations,['/resident']);assert.equal(h.button.disabled,true);
 await h.submit();assert.equal(h.requests.length,2,'A successful navigation must not allow duplicate login submissions');
});
test('malformed success or an admin response cannot navigate into the resident portal',async()=>{
 for(const response of [{},{user:{type:'admin',auth_method:'phone'}},{user:{type:'resident',auth_method:'password'}}]){
  const h=harness(),work=h.submit();h.requests[0].resolve(response);await work;assert.equal(h.destinations.length,0);assert.equal(h.button.disabled,false);assert.equal(h.phone.focused,true);
 }
});
test('admin password fields and endpoint remain separate from the resident phone form',()=>{
 const admin=fs.readFileSync(path.join(__dirname,'../templates/admin/login.php'),'utf8');
 assert.match(admin,/name="password"/);assert.match(source,/initLogin\('#admin-login-form', '\/api\/auth\/admin\/login'/);
 assert.ok(!login.includes('admin/login'));assert.ok(login.includes("'/api/auth/resident/login'"));
});
