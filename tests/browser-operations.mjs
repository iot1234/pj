// Opt-in browser regression: run only after operations_mysql.php in its disposable schema.
import assert from 'node:assert/strict';
const base=process.env.OPERATIONS_TEST_URL || 'http://127.0.0.1:18947';
if(process.env.APP_ENV!=='testing' || !/^appj_(?:line_test_)?operations(?:_[a-z0-9_]+)?$/.test(process.env.DB_DATABASE||'')
  || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base))throw new Error('Disposable operations fixture and loopback HTTP server required');
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser=await chromium.launch({channel:process.env.BROWSER_CHANNEL || 'msedge',headless:true});
async function localContext(){const context=await browser.newContext({viewport:{width:1280,height:900}});await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());return context;}
const context=await localContext();
const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
const log=message=>console.log('PASS '+message);
async function api(path,method='GET',body,target=page){return target.evaluate(async({path,method,body})=>{
 const response=await fetch(path,{method,headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},...(body?{body:JSON.stringify(body)}:{})});
 return {status:response.status,...await response.json()};
},{path,method,body});}
try {
 await page.goto(base+'/admin/login');await page.locator('[name="username"]').fill('operations_owner');await page.locator('[name="password"]').fill('Operations-Owner-Fixture-2026!');
 await page.locator('#admin-login-form [type="submit"]').click();await page.waitForURL(base+'/admin');
 await page.goto(base+'/admin#residents');await page.locator('#resident-rows tr[data-resident-id="1"]').waitFor();
 // Initial read failure, malformed response and recovery must all hide stale controls.
 const residents='**/api/admin/residents';
 await page.route(residents,r=>r.request().method()==='GET'?r.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,data:{code:'INTERNAL_ERROR'},message:'Simulated outage'})}):r.continue());
 await page.reload();await page.locator('#resident-state [data-recovery-view="residents"]').waitFor();
 await page.locator('#resident-search').fill('New');assert.equal(await page.locator('#resident-rows tr').count(),0);
 await page.unroute(residents);await page.route(residents,r=>r.request().method()==='GET'?r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{},message:'malformed fixture'})}):r.continue());
 await page.locator('#resident-state [data-recovery-view="residents"]').click();await page.locator('#resident-state').getByText('ข้อมูลรายการไม่ครบ',{exact:false}).waitFor();
 assert.equal(await page.locator('#resident-rows tr').count(),0);
 await page.unroute(residents);await page.locator('#resident-search').fill('');await page.locator('#resident-state [data-recovery-view="residents"]').click();await page.locator('#resident-rows tr').first().waitFor();
 log('resident read failure/malformed payload cannot revive stale actions; retry restores real records');
 // A genuine server-side validation conflict preserves the form and account.
 await page.locator('#resident-rows tr[data-resident-id="1"] [data-action="edit-resident"]').click();
 await page.locator('#resident-edit-form [name="phone"]').fill('0817700002');await page.locator('#resident-edit-form [type="submit"]').click();
 await page.locator('#confirm-dialog').waitFor();assert.equal(await page.locator('#resident-edit-form [name="full_name"]').isDisabled(),true);
 const denied=page.waitForResponse(r=>r.url().endsWith('/api/admin/residents/1')&&r.request().method()==='PUT');await page.locator('#confirm-dialog [data-confirm-accept]').click();assert.equal((await denied).status(),409);
 await page.locator('#resident-edit-error').waitFor();assert.equal(await page.locator('#resident-edit-form [name="phone"]').inputValue(),'0817700002');assert.equal(await page.locator('#resident-edit-form [name="phone"]').isDisabled(),false);
 await page.locator('#resident-edit-form [data-close-dialog]').first().click();
 assert.equal((await api('/api/admin/residents')).data[0].phone,'0817700003');
 log('real phone conflict preserves submitted draft, unlocks recovery and leaves original phone intact');
 // Create through the actual form. Hold transport to verify a duplicate submit sends once.
 await page.locator('[data-open-resident-create]').click();await page.locator('#resident-create-dialog').waitFor();
 const rooms=(await api('/api/admin/rooms')).data,room=rooms.find(r=>r.room_code==='OPS-C');assert.ok(room);
 await page.locator('#resident-create-form [name="room_id"]').selectOption(String(room.id));
 await page.locator('#resident-create-form [name="full_name"]').fill('Browser Operations');await page.locator('#resident-create-form [name="phone"]').fill('0817700005');
 await page.locator('#resident-create-form [name="opening_water_reading"]').fill('10');await page.locator('#resident-create-form [name="opening_electric_reading"]').fill('20');
 let release,arrive;const barrier=new Promise(r=>release=r),reached=new Promise(r=>arrive=r);let writes=0;
 await page.route(residents,async r=>{if(r.request().method()!=='POST')return r.continue();writes++;arrive();await barrier;await r.continue();});
 const saved=page.waitForResponse(r=>r.url().endsWith('/api/admin/residents')&&r.request().method()==='POST');
 await page.locator('#resident-create-form [type="submit"]').click();await reached;
 assert.equal(await page.locator('#resident-create-form [name="full_name"]').isDisabled(),true);
 await page.locator('#resident-create-form').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
 release();assert.equal((await saved).status(),201);await page.locator('#resident-create-dialog').waitFor({state:'hidden'});assert.equal(writes,1);await page.unroute(residents);
 if(await page.locator('#resident-access-dialog').isVisible())await page.locator('#resident-access-dialog [data-explicit-close]').click();
 const current=(await api('/api/admin/residents')).data.filter(r=>r.phone==='0817700005');assert.equal(current.length,1);assert.equal(current[0].room_id,room.id);
 assert.equal((await api('/api/admin/rooms')).data.find(r=>r.id===room.id).status,'occupied');
 log('direct check-in locks all fields, sends one request, creates one resident and updates room occupancy');
 const staff=await api('/api/admin/users','POST',{username:'browser_operations_staff',password:'Browser-Operations-Staff-2026!',role:'admin'});assert.equal(staff.status,201);
 const staffContext=await localContext(),staffPage=await staffContext.newPage();staffPage.on('pageerror',e=>errors.push(e.message));
 await staffPage.goto(base+'/admin/login');await staffPage.locator('[name="username"]').fill('browser_operations_staff');await staffPage.locator('[name="password"]').fill('Browser-Operations-Staff-2026!');await staffPage.locator('#admin-login-form [type="submit"]').click();await staffPage.waitForURL(base+'/admin');
 assert.equal((await api('/api/admin/rooms','GET',undefined,staffPage)).status,200);
 assert.equal((await api('/api/admin/users','GET',undefined,staffPage)).status,403);
 assert.equal((await api('/api/admin/settings/integrations','PUT',{promptpay_target:'0812345678'},staffPage)).status,403);
 await api('/api/admin/users/'+staff.data.id,'DELETE',{});assert.equal((await api('/api/admin/rooms','GET',undefined,staffPage)).status,401);
 log('real staff session cannot use owner-only APIs; disabling staff revokes the live session');
 const residentContext=await localContext(),tenant=await residentContext.newPage();tenant.on('pageerror',e=>errors.push(e.message));
 await tenant.goto(base+'/resident/login');await tenant.locator('#resident-phone').fill('0817700005');await tenant.locator('#resident-login-form [type="submit"]').click();await tenant.waitForURL(base+'/resident');
 assert.equal((await api('/api/resident/profile','GET',undefined,tenant)).status,200);
 assert.equal((await api('/api/resident/bills/1','GET',undefined,tenant)).status,404);
 assert.equal((await api('/api/admin/rooms','GET',undefined,tenant)).status,401);
 const badCsrf=await tenant.evaluate(async()=>{const r=await fetch('/api/resident/profile',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-Token':'incorrect'},body:JSON.stringify({full_name:'Must not save',email:null})});return r.status;});assert.equal(badCsrf,403);
 assert.equal((await api('/api/resident/profile','GET',undefined,tenant)).data.full_name,'Browser Operations');
 log('resident cannot read another room bill or admin data; invalid CSRF leaves profile unchanged');
 // Never touch real provider endpoints. Other views are read-only here.
 for(const view of ['overview','rooms','bookings','residents','meters','bills','payments','users','settings','line-oas','line-bindings']){
  await page.goto(base+'/admin#'+view);await page.locator(`[data-admin-view="${view}"]`).waitFor();await page.waitForLoadState('networkidle');
 }
 assert.deepEqual(errors,[]);log('all 11 admin views remain compatible; no JavaScript errors or external calls');
} finally {await browser.close();}
