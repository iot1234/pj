// Opt-in: run after operations_mysql.php on a disposable schema and loopback server.
import assert from 'node:assert/strict';
const base=process.env.OPERATIONS_TEST_URL || 'http://127.0.0.1:18947';
if(process.env.APP_ENV!=='testing' || !/^appj_(?:line_test_)?operations(?:_[a-z0-9_]+)?$/.test(process.env.DB_DATABASE||'')
  || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base))throw new Error('Disposable operations fixture and loopback server required');
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser=await chromium.launch({channel:process.env.BROWSER_CHANNEL || 'msedge',headless:true});
const errors=[],external=[],loginBodies=[];
async function pageFor(){
  const context=await browser.newContext({viewport:{width:390,height:900}});
  await context.route('**/*',route=>{
    const url=new URL(route.request().url());
    if(url.origin!==base){external.push(url.href);return route.abort();}
    if(url.pathname==='/api/auth/resident/login')loginBodies.push(route.request().postDataJSON());
    return route.continue();
  });
  const page=await context.newPage();page.setDefaultTimeout(15000);page.on('pageerror',e=>errors.push(e.message));return page;
}
async function api(page,path,method='GET',body){
  return page.evaluate(async({path,method,body})=>{
    const response=await fetch(path,{method,headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},
      ...(body===undefined?{}:{body:JSON.stringify(body)})});
    return {status:response.status,...await response.json()};
  },{path,method,body});
}
function phoneAccess(data){
  assert.equal(data.resident_access.auth_method,'phone');assert.equal(data.resident_access.activation_required,false);
  for(const field of ['activation_code','expires_at','single_use'])assert.equal(Object.hasOwn(data.resident_access,field),false);
}
async function login(page,phone){
  await page.goto(base+'/resident/login');
  assert.equal(await page.locator('#resident-login-form input').count(),1);
  assert.equal(await page.locator('#resident-login-form input[name="phone"][type="tel"]').count(),1);
  assert.equal(await page.locator('#resident-login-form input[type="password"]').count(),0);
  await page.locator('#resident-phone').fill(phone);
  await page.locator('#resident-login-form [type="submit"]').click();await page.waitForURL(base+'/resident');
  await page.locator('[data-resident-view="profile"]:visible').first().click();
  await page.locator('#resident-profile-form [name="full_name"]').waitFor();
  await page.waitForFunction(()=>!document.querySelector('#resident-profile-fields').disabled);
}
const pass=message=>console.log('PASS '+message);
try{
  const owner=await pageFor();await owner.goto(base+'/admin/login');
  await owner.locator('[name="username"]').fill('operations_owner');await owner.locator('[name="password"]').fill('Operations-Owner-Fixture-2026!');
  await owner.locator('#admin-login-form [type="submit"]').click();await owner.waitForURL(base+'/admin');
  assert.equal(await owner.locator('#resident-access-dialog').count(),0);
  const room=await api(owner,'/api/admin/rooms','POST',{room_code:'PHONE-E2E-01',floor:1,room_type:'Phone fixture',monthly_rent:'2900'});
  assert.equal(room.status,201);
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Bangkok',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const input={room_id:room.data.id,full_name:'Phone-only browser resident',phone:'0817744401',move_in_date:today,
    opening_water_reading:'1',opening_electric_reading:'2',idempotency_key:'phone-only-browser-checkin-20261002'};
  const created=await api(owner,'/api/admin/residents','POST',input);assert.equal(created.status,201);phoneAccess(created.data);
  const replay=await api(owner,'/api/admin/residents','POST',input);assert.equal(replay.status,200);phoneAccess(replay.data);
  assert.equal(replay.data.resident_id,created.data.resident_id);
  pass('check-in and explicit replay return phone access without any activation code or expiry');
  const resident=await pageFor();await login(resident,'081-774-4401');
  let profile=await api(resident,'/api/resident/profile');assert.equal(profile.status,200);assert.equal(profile.data.room_id,room.data.id);
  assert.equal((await api(resident,'/api/admin/rooms')).status,401);
  await resident.locator('#resident-profile-form [name="full_name"]').fill('Updated phone-only resident');
  await resident.locator('#resident-profile-form [name="email"]').fill('phone-browser@example.test');
  const savedProfile=resident.waitForResponse(r=>r.url().endsWith('/api/resident/profile')&&r.request().method()==='PUT');
  await resident.locator('#resident-profile-form [type="submit"]').click();assert.equal((await savedProfile).status(),200);
  await resident.waitForFunction(()=>document.querySelector('#resident-profile-form').dataset.dirty==='false');
  assert.equal((await api(resident,'/api/resident/profile')).data.full_name,'Updated phone-only resident');
  assert.equal((await api(resident,'/api/resident/profile','PUT',{full_name:'Must not save',phone:'0817744499'})).status,422);
  pass('single-field phone login opens the correct room, edits own profile and denies owner APIs/identity edits');
  await owner.goto(base+'/admin#residents');
  const resetButton=owner.locator(`#resident-rows tr[data-resident-id="${created.data.resident_id}"] [data-action="revoke-resident-sessions"]`);
  await resetButton.click();
  const resetResponse=owner.waitForResponse(r=>r.url().endsWith(`/residents/${created.data.resident_id}/access/reissue`)&&r.request().method()==='POST');
  await owner.locator('#confirm-dialog [data-confirm-accept]').click();
  const reset=await resetResponse;assert.equal(reset.status(),200);phoneAccess((await reset.json()).data);
  assert.equal((await api(resident,'/api/resident/profile')).status,401);
  await resident.goto(base+'/resident');await resident.waitForURL(base+'/resident/login');
  await login(resident,'0817744401');
  pass('owner reset revokes the existing session; resident signs in again with the same phone and no key');
  const changed=await api(owner,`/api/admin/residents/${created.data.resident_id}`,'PUT',{phone:'+66817744402'});
  assert.equal(changed.status,200);phoneAccess(changed.data);assert.equal(changed.data.phone,'0817744402');
  assert.equal((await api(resident,'/api/resident/profile')).status,401);
  await resident.goto(base+'/resident/login');
  const old=await api(resident,'/api/auth/resident/login','POST',{phone:'0817744401'});assert.equal(old.status,401);
  await login(resident,'+66 81 774 4402');
  profile=await api(resident,'/api/resident/profile');assert.equal(profile.data.room_id,room.data.id);assert.equal(profile.data.phone,'0817744402');
  const anotherDevice=await pageFor();await login(anotherDevice,'0817744402');assert.equal((await api(anotherDevice,'/api/resident/profile')).data.id,created.data.resident_id);
  pass('phone change revokes old sessions and number; normalized new phone works immediately on another device');
  assert.ok(loginBodies.length>=5);for(const payload of loginBodies)assert.deepEqual(Object.keys(payload),['phone']);
  assert.deepEqual(errors,[]);assert.deepEqual(external,[]);
  pass('all resident login requests contain only phone, with zero browser errors or external requests');
}finally{await browser.close();}
