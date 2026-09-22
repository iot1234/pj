<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/Domain/LineBotService.php';

use Dormitory\Domain\LineBotService;

$passed = 0;
$check = static function(string $name, callable $test) use (&$passed): void {
    $test(); $passed++; fwrite(STDOUT, "PASS {$name}\n");
};
$assert = static function(bool $condition): void { if (!$condition) throw new RuntimeException('Assertion failed'); };

$check('explicit Thai and English command aliases', static function() use ($assert): void {
    foreach (['help','ช่วย','เริ่ม','start','menu','เมนู'] as $text) $assert(LineBotService::intent($text)==='help');
    foreach (['สถานะ','status','ห้อง','room'] as $text) $assert(LineBotService::intent($text)==='status');
    foreach (['บิล','bill','bills','invoice'] as $text) $assert(LineBotService::intent($text)==='bills');
});
$check('copy/paste normalizes full-width ASCII, zero-width and case', static function() use ($assert): void {
    $assert(LineBotService::intent("  ＳＴＡＴＵＳ\u{200B}  ")==='status');
    $assert(LineBotService::normalize("\u{FEFF}ＢＩＮＤ－ＡＢＣ\u{200D}")==='BIND-ABC');
    $assert(LineBotService::intent('biLLs')==='bills');
});
$check('free chat and embedded commands do not trigger a bot response', static function() use ($assert): void {
    foreach (['สวัสดีค่ะ','ขอคุยกับผู้ดูแล','please send bills','https://example.test/status','prefix BIND-ABC',''] as $text) {
        $assert(LineBotService::intent($text)===null);
    }
});
$check('slip image marker and official prefilled payment text reach guidance intent',static function()use($assert):void{
    $assert(LineBotService::intent('SLIP_IMAGE_REVIEW_ONLY')==='slip_guidance');
    $assert(LineBotService::intent("แจ้งชำระ BILL-TEST\nห้อง A-101")==='slip_guidance');
    $assert(LineBotService::intent('ขอสอบถามเรื่องแจ้งชำระ')===null);
});
$bill = ['bill_no'=>'B-TEST','period'=>'2026-09-01','due_date'=>'2026-09-20',
    'room_code_snapshot'=>'A-101','total_amount'=>'4848.50','status'=>'pending','payment_status'=>null];
$check('bill output keeps the stored decimal amount and period', static function() use ($assert,$bill): void {
    $text=LineBotService::billsText('ผู้พัก',[$bill],'2026-09-15','https://example.test/resident#bills');
    foreach (['4,848.50 บาท','รอบ 2026-09','ครบกำหนด 2026-09-20','รอชำระ','ต้องเข้าสู่ระบบ','โอนตามยอด QR ห้ามปัดเศษ','ส่งเลขบิลและรูปสลิปในแชตนี้'] as $part) $assert(str_contains($text,$part));
});
$check('pending or verified slips prevent a repeated payment instruction', static function() use ($assert,$bill): void {
    foreach (['pending','verified'] as $payment) {
        $text=LineBotService::billsText('ผู้พัก',[array_replace($bill,['payment_status'=>$payment])],'2026-09-30','https://example.test/resident#bills');
        $assert(str_contains($text,'อย่าโอนซ้ำ')); $assert(!str_contains($text,'เกินกำหนดชำระ'));
        $assert(!str_contains($text,'โอนตามยอด QR'));
    }
});
$check('paid and rejected payments have distinct recovery messages', static function() use ($assert,$bill): void {
    $paid=LineBotService::billsText('ผู้พัก',[array_replace($bill,['status'=>'paid','payment_status'=>'verified'])],'2026-09-30','https://example.test');
    $assert(str_contains($paid,'ชำระแล้ว — ไม่ต้องโอนซ้ำ'));
    $assert(!str_contains($paid,'โอนตามยอด QR'));
    $rejected=LineBotService::billsText('ผู้พัก',[array_replace($bill,['payment_status'=>'rejected'])],'2026-09-30','https://example.test');
    $assert(str_contains($rejected,'เกินกำหนดชำระ')); $assert(str_contains($rejected,'ตรวจยอดก่อนโอนซ้ำ'));
    $assert(!str_contains($rejected,'โอนตามยอด QR'));
});
$check('reply limits history to three bills and sanitizes record labels', static function() use ($assert,$bill): void {
    $bills=[]; for($i=1;$i<=4;$i++) $bills[]=array_replace($bill,['bill_no'=>'BILL-'.$i]);
    $text=LineBotService::billsText("ชื่อ\nปลอม\u{202E}",$bills,'2026-09-15','https://example.test');
    $assert(str_contains($text,'BILL-3')); $assert(!str_contains($text,'BILL-4')); $assert(!str_contains($text,"\u{202E}"));
});
fwrite(STDOUT,"{$passed} LINE bot tests passed\n");
