'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const test=require('node:test'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../public/assets/js/billing-guidance.js'),'utf8');
const context={window:{}};vm.createContext(context);vm.runInContext(source,context);const guide=context.window.DormBillingGuide;
const clean={period:'2026-08',currentPeriod:'2026-09',settingsState:'ready',dataState:'ready',configured:true,selected:1,candidates:1,confirmed:false,other_amount:'0',other_description:''};
const codes=state=>Array.from(guide.blockers({...clean,...state}),item=>item.code);
test('all billed rooms explain duplicate protection and lead to existing bills, not impossible selection',()=>{
 assert.deepEqual(codes({selected:0,candidates:2,unbilled:0}),['ALL_ROOMS_BILLED']);
 const item=guide.blockers({...clean,selected:0,candidates:2,unbilled:0})[0];assert.equal(item.focus,'existing');assert.match(item.detail,/ไม่ต้องออกบิลอีก/);
 assert.deepEqual(codes({selected:0,candidates:2,unbilled:1}),['NO_ROOMS_SELECTED']);
 assert.deepEqual(codes({selected:0,candidates:0,unbilled:0}),['NO_BILLING_CANDIDATES']);
});
test('unconfirmed settings have an actionable repair rather than a silent disabled button',()=>{
 const list=guide.blockers({...clean,configured:false});assert.equal(list[0].code,'BILLING_SETTINGS_NOT_CONFIRMED');assert.equal(list[0].target,'settings');assert.equal(list[0].focus,'water_rate');
});
test('the screenshot with a description and zero amount explicitly offers repair or clear',()=>{
 const list=guide.fields({other_description:'10',other_amount:'0'});assert.equal(list[0].code,'OTHER_AMOUNT_REQUIRED');assert.equal(list[0].clearExtra,true);assert.equal(list[0].focus,'other_amount');
});
test('zero intentionally configured rates do not block billing and other fees remain optional',()=>{
 assert.deepEqual(codes({water_rate:0,electric_rate:0}),[]);assert.deepEqual(codes({other_amount:'12.50',other_description:'Cleaning'}),[]);
});
test('amount validation rejects negatives, extra precision, blank, infinity and exponent notation',()=>{
 for(const amount of ['','-1','1.001','NaN','Infinity','1e3','10000000'])assert.equal(guide.fields({other_amount:amount})[0].code,'OTHER_AMOUNT_INVALID');
 assert.equal(guide.fields({other_amount:'1'})[0].code,'OTHER_DESCRIPTION_REQUIRED');
});
test('current and future periods require their own explicit validation',()=>{
 assert.ok(codes({period:'2026-09'}).includes('CURRENT_BILLING_PERIOD_NOT_FINALIZED'));assert.deepEqual(codes({period:'2026-09',confirmed:true}),[]);
 for(const period of ['2026-13','2026-1','2026-10',''])assert.ok(codes({period}).includes('BILL_PERIOD_INVALID'));
});
test('failed reads are not treated as free rates or empty successful data',()=>{
 assert.ok(codes({settingsState:'error'}).includes('BILL_SETTINGS_UNAVAILABLE'));assert.ok(codes({dataState:'error'}).includes('BILL_DATA_UNAVAILABLE'));assert.equal(codes({dataState:'loading'})[0],'BILL_DATA_LOADING');
});
test('no occupancy in a historical month leads to period selection, not automatic backdating',()=>{
 const issue=guide.blockers({...clean,candidates:0,selected:0})[0];assert.equal(issue.code,'NO_BILLING_CANDIDATES');assert.equal(issue.target,'bills');assert.equal(issue.focus,'period');
});
test('nested meter problems retain the exact room, occupancy and billing period',()=>{
 const items=guide.expand([{code:'METER_CHAIN_INVALID',room_id:7,room_code:'A7',occupancy_id:3,meter_issues:[{code:'METER_HISTORY_GAP',required_previous_period:'2026-07'},{code:'METER_HISTORY_GAP',required_previous_period:'2026-07'}]}],{period:'2026-08'});
 assert.equal(items.length,1);assert.equal(items[0].room_id,7);assert.equal(items[0].occupancy_id,3);assert.equal(items[0].period,'2026-08');assert.equal(items[0].required_previous_period,'2026-07');assert.equal(items[0].target,'meters');
});
test('the recovery plan cannot take target URLs or selectors from an error response',()=>{
 for(const code of ['MISSING_METER','__proto__','constructor','toString','UNKNOWN']){
  const result=guide.explain({code,room_id:'1;alert(1)',target:'https://evil.invalid',focus:'body',url:'javascript:alert(1)'},{period:'2026-08'});
  assert.equal(result.room_id,null);assert.ok(['meters','reload'].includes(result.target));assert.ok(!Object.values(result).includes('https://evil.invalid'));
 }
});
test('a missing opening goes to the exact occupancy rather than creating a fake baseline',()=>{
 const item=guide.explain({code:'METER_OPENING_REQUIRED',room_id:8,occupancy_id:21},{period:'2026-08'});assert.equal(item.target,'residents');assert.equal(item.focus,'opening');assert.equal(item.occupancy_id,21);
});
test('unknown issuance outcome offers a read-only status check instead of resubmitting',()=>{
 const result=guide.explain({code:'MUTATION_OUTCOME_UNKNOWN'});assert.equal(result.target,'reload');assert.match(result.detail,/ไม่ส่งคำขอ/);
});
test('the real page includes a loaded guidance module, inline repair results and a return path',()=>{
 const root=path.join(__dirname,'..'),layout=fs.readFileSync(path.join(root,'templates/layout.php'),'utf8'),html=fs.readFileSync(path.join(root,'templates/admin/console.php'),'utf8');
 assert.ok(layout.indexOf('/assets/js/billing-guidance.js')<layout.indexOf('/assets/js/app.js'));
 for(const id of ['billing-next-steps','bill-recovery-issues','billing-recovery-context','billing-recovery-back','billing-recovery-retry','bill-clear-extra'])assert.ok(html.includes(`id="${id}"`));
});
