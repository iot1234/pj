'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const context={};vm.runInNewContext(fs.readFileSync('public/assets/js/action-guidance.js','utf8'),context);
const guide=context.DormActionGuide;
const bill=extra=>({id:1,status:'pending',line_linked:true,line_ready:true,...extra});
test('disabled business controls have both an explanation and a recovery without enabling them',()=>{
 for(const code of ['BUSY','LOADING','NO_ROOMS','BILLED','METER_BILLED','METER_OPENING','METER_HISTORY','LINE_NOT_READY','LINE_BLOCKED','LINE_LINKED','NO_BINDINGS','INTEGRATION_DIRTY','OWNER_ONLY','PREVIEW_REQUIRED']){
  const item=guide.reason(code);assert.ok(item.title.length>10);assert.ok(item.detail.length>30);assert.notEqual(item.title,guide.reason('UNKNOWN').title);
 }
 assert.match(guide.reason('BILLED').title,/ป้องกันบิลซ้ำ/);assert.match(guide.reason('BUSY').detail,/ไม่กดซ้ำ/);
});
test('unknown reason names are plain safe fallback, including object prototype names',()=>{
 for(const code of ['__proto__','constructor','<script>'])assert.equal(guide.reason(code).title,guide.reason('UNKNOWN').title);
});
test('LINE bulk guidance distinguishes data loading and an empty month',()=>{
 assert.match(guide.lineReasons([],false,false)[0].title,/ยังอ่านข้อมูล/);
 assert.match(guide.lineReasons([],true,true)[0].title,/ยังไม่มีบิล/);
});
test('LINE reasons count all blockers and payment guards take precedence over linkage',()=>{
 const rows=[bill({payment_status:'pending',line_linked:false}),bill({payment_status:'verified'}),bill({line_linked:false}),bill({line_linked:false}),bill({line_ready:false}),bill({line_status:'pending'}),bill({line_status:'processing'}),bill({line_status:'sent'}),bill({line_status:'failed',line_can_queue:false})];
 const reasons=guide.lineReasons(rows,true,true);assert.equal(reasons.length,7);
 assert.match(reasons[0].title,/รอตรวจ \(1 บิล\)/);assert.match(reasons[2].title,/ยังไม่ผูก LINE \(2 บิล\)/);assert.match(reasons[4].title,/\(2 บิล\)/);
 assert.equal(reasons.reduce((sum,row)=>sum+row.count,0),rows.length);assert.match(reasons[6].detail,/ห้ามบังคับส่งซ้ำ/);
});
test('per-bill OA readiness overrides legacy global readiness',()=>{
 assert.match(guide.lineReasons([bill({line_status:'sent'})],true,false)[0].title,/LINE รับคำขอ/);
 assert.match(guide.lineReasons([bill({line_ready:false})],true,true)[0].title,/ยังไม่พร้อม/);
});
test('helper contains no network, HTML injection or control-unlocking operations',()=>{
 const source=fs.readFileSync('public/assets/js/action-guidance.js','utf8');
 assert.doesNotMatch(source,/fetch\(|innerHTML|\.disabled\s*=|\.submit\(|\.click\(|location\./);
 assert.match(source,/aria-describedby/);assert.match(source,/button\.type='button'/);
});
