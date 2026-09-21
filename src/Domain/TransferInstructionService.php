<?php
declare(strict_types=1);
namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\Validator;
use PDO;

/** Locked transfer instructions, independent from the slip provider's availability. */
final class TransferInstructionService
{
    private ?bool $available = null;
    public function __construct(private readonly Application $app) {}
    public function available(): bool
    {
        if ($this->available !== null) return $this->available;
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='transfer_instructions'");
        if((int)$q->fetchColumn()!==1)return $this->available=false;
        $q=$this->app->database()->pdo()->query("SELECT non_unique AS is_non_unique,column_name AS indexed_column,seq_in_index FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='transfer_instructions' AND index_name='uq_transfer_active_amount'");
        $index=$q->fetchAll();
        $q=$this->app->database()->pdo()->query("SELECT generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='transfer_instructions' AND column_name='active_amount'");
        $literal=strtolower(str_replace("\\'", "'", (string)$q->fetchColumn()));
        $literal=preg_replace("/_[a-z0-9]+'/", "'", $literal)??'';
        $expression=preg_replace('/[[:space:]]+/', '', str_replace([chr(96),'(',')'],'',$literal));
        $valid=count($index)===1&&(int)$index[0]['is_non_unique']===0&&$index[0]['indexed_column']==='active_amount'
            &&substr_count($literal,"'")===4&&substr_count($literal,"'reserved'")===1&&substr_count($literal,"'settled'")===1
            &&$expression==="casewhenstatusin'reserved','settled'thentransfer_amountelsenullend";
        if(!$valid)throw new HttpException(503,'โครงสร้างจองยอดไม่ครบ กรุณาให้ผู้ดูแลตรวจ migration 016','TRANSFER_SCHEMA_REQUIRED');
        return $this->available=true;
    }
    public function find(int $billId): ?array
    {
        if (!$this->available()) return null;
        $q=$this->app->database()->pdo()->prepare('SELECT * FROM transfer_instructions WHERE bill_id=?');
        $q->execute([$billId]); $row=$q->fetch();
        return $row ? $this->map($row) : null;
    }
    /** Server allocation is idempotent by bill; never regenerate a shown amount. */
    public function reserve(int $billId,int $residentId): array
    {
        if (!$this->available()) throw new HttpException(503,'ต้องติดตั้ง migration 016 สำหรับจองยอดโอนก่อน กรุณาติดต่อผู้ดูแล','TRANSFER_SCHEMA_REQUIRED');
        return $this->app->database()->transaction(function(PDO $pdo)use($billId,$residentId):array{
            $q=$pdo->prepare('SELECT id,resident_id,total_amount,status FROM bills WHERE id=? AND resident_id=? FOR UPDATE');
            $q->execute([$billId,$residentId]); $bill=$q->fetch();
            if (!$bill) throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            if ($bill['status']!=='pending') throw new HttpException(409,'บิลนี้ชำระแล้ว ไม่ต้องโอนซ้ำ','BILL_ALREADY_PAID');
            // Same lock order as payments: bill, settings, then instruction/amount registry.
            $settings=$pdo->query('SELECT promptpay_target,promptpay_name FROM integration_settings WHERE id=1 FOR SHARE')->fetch();
            $target=trim((string)($settings['promptpay_target']??''));
            if ($target==='') throw new HttpException(503,'ยังไม่ได้ตั้งบัญชีพร้อมเพย์ กรุณาติดต่อผู้ดูแลก่อนโอน','PROMPTPAY_NOT_CONFIGURED');
            PromptPayService::payload($target,'1.00');
            $p=$pdo->prepare("SELECT id FROM payments WHERE bill_id=? AND status IN ('pending','verified') LIMIT 1 FOR UPDATE");
            $p->execute([$billId]);
            if ($p->fetch()) throw new HttpException(409,'มีสลิปรอตรวจหรือชำระแล้ว ตรวจรายการเดิมก่อน ไม่ต้องโอนซ้ำ','PAYMENT_ALREADY_PENDING');
            $existing=$this->find($billId);
            if ($existing) { $this->assertCurrentTarget($existing,$target); return $existing; }
            $base=Validator::scaledDecimal($bill['total_amount'],'bill_amount',2,12);
            if($base<=0||$base>99_999_999_999_999-99)throw new HttpException(422,'ยอดบิลไม่อยู่ในช่วงที่สร้าง QR ได้','TRANSFER_AMOUNT_INVALID');
            $this->app->limiter()->lockBucket('transfer-allocation','global');
            // Retain completed amounts for seven days; late transfers still need a bank reference.
            $pdo->exec("UPDATE transfer_instructions SET status='released' WHERE status='settled' AND settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)");
            $q=$pdo->prepare('SELECT active_amount FROM transfer_instructions WHERE active_amount BETWEEN ? AND ? FOR UPDATE');
            $q->execute([Validator::decimalString($base+1),Validator::decimalString($base+99)]);
            $used=array_map(static fn($v):int=>Validator::scaledDecimal($v,'reserved_amount',2,12),$q->fetchAll(PDO::FETCH_COLUMN));
            // Avoid unallocated pending bill totals too (legacy direct-transfer amounts).
            $q=$pdo->prepare("SELECT total_amount FROM bills WHERE status='pending' AND id<>? AND total_amount BETWEEN ? AND ?");
            $q->execute([$billId,Validator::decimalString($base+1),Validator::decimalString($base+99)]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN)as$v)$used[]=Validator::scaledDecimal($v,'legacy_amount',2,12);
            $free=array_values(array_filter(range(1,99),static fn(int $delta):bool=>!in_array($base+$delta,$used,true)));
            if(!$free)throw new HttpException(409,'ยอดช่วงนี้ถูกจองครบแล้ว มีได้สูงสุด 99 ยอดต่อฐานเดียวกัน กรุณาติดต่อผู้ดูแล ไม่ต้องลองสุ่มหรือโอนยอดเอง','TRANSFER_SLOTS_FULL');
            $delta=$free[random_int(0,count($free)-1)];
            $q=$pdo->prepare('INSERT INTO transfer_instructions(bill_id,resident_id,bill_amount,adjustment_amount,transfer_amount,promptpay_target,recipient_name) VALUES(?,?,?,?,?,?,?)');
            $q->execute([$billId,$residentId,$bill['total_amount'],Validator::decimalString($delta),Validator::decimalString($base+$delta),$target,$settings['promptpay_name']??null]);
            return $this->find($billId)??throw new \RuntimeException('Transfer instruction was not stored');
        });
    }
    private function assertCurrentTarget(array $instruction,string $target): void
    {
        if(!hash_equals($instruction['promptpay_target'],$target))throw new HttpException(409,'บัญชีรับเงินเปลี่ยนหลังจองยอด กรุณาติดต่อผู้ดูแลตรวจรายการเดิม ไม่สร้างยอดใหม่และไม่โอนซ้ำ','TRANSFER_TARGET_CHANGED');
    }
    /** Use actual transfer amount for verification; payments.amount still represents bill principal. */
    public function expectedAmount(int $billId,string $principal): string
    {
        $row=$this->find($billId);
        if(!$row)return $principal;
        $target=trim((string)$this->app->settings()->value('promptpay_target',''));
        $this->assertCurrentTarget($row,$target);
        if($row['bill_amount']!==$principal)throw new HttpException(409,'ยอดบิลเปลี่ยนจากยอดโอนที่จองไว้ กรุณาตรวจสอบ','TRANSFER_BILL_CHANGED');
        return $row['transfer_amount'];
    }
    public function settle(PDO $pdo,int $billId): void
    {
        if(!$this->available())return;
        $q=$pdo->prepare("UPDATE transfer_instructions SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE bill_id=? AND status='reserved'");
        $q->execute([$billId]);
    }
    public function envelope(array $instruction,array $bill): array
    {
        $contact=$this->lineFallback($bill,$instruction['transfer_amount']);
        return ['bill_id'=>(int)$bill['id'],'amount'=>$instruction['transfer_amount'],
            'bill_amount'=>$instruction['bill_amount'],'adjustment_amount'=>$instruction['adjustment_amount'],
            'target'=>$instruction['promptpay_target'],'name'=>$instruction['recipient_name'],
            'payload'=>PromptPayService::payload($instruction['promptpay_target'],$instruction['transfer_amount']),
            'line_fallback'=>$contact,'amount_locked'=>true];
    }
    public function lineFallback(array $bill,?string $amount=null): array
    {
        // This opens an OA conversation only. It neither sends the image nor verifies payment.
        $contact=$this->app->settings()->publicContact();
        $basic=$contact['line_basic_id']??null;
        $message='แจ้งชำระ '.(string)($bill['bill_no']??$bill['id'])."\nห้อง ".(string)($bill['room_code']??$bill['room_code_snapshot']??'');
        if($amount!==null)$message.="\nยอดโอน ".$amount.' บาท';
        $message.="\nกรุณาตรวจสลิปที่ส่งถัดไป ยังไม่ยืนยันการรับเงิน";
        $ready=is_string($basic)&&preg_match('/^@[A-Za-z0-9._-]{1,32}$/D',$basic)===1;
        return ['available'=>$ready,'message'=>$message,'url'=>$ready?'https://line.me/R/oaMessage/'.rawurlencode($basic).'/?'.rawurlencode($message):null];
    }
    private function map(array $row): array
    {
        $row['bill_id']=(int)$row['bill_id'];$row['resident_id']=(int)$row['resident_id'];
        unset($row['active_amount']);
        return $row;
    }
}
