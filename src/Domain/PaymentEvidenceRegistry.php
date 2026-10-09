<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use PDO;
use PDOException;

/** The bank reference and canonical image identify money across both modules. */
final class PaymentEvidenceRegistry
{
    public function __construct(private readonly Application $app) {}

    public function available(): bool
    {
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry'");
        if((int)$q->fetchColumn()!==1)return false;
        $columns=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_evidence_registry' AND column_name IN('claim_status','active_slip_hmac','active_txn_ref')");
        if((int)$columns->fetchColumn()!==3)throw new HttpException(503,'กรุณาติดตั้ง migration 020 ก่อนตรวจหลักฐานร่วมรายเดือนและรายวัน','PAYMENT_REVIEW_SCHEMA_REQUIRED');
        return true;
    }

    public function claimSlip(PDO $pdo,string $type,int $id,string $hmac): void
    {
        if(!$this->available())return; // Monthly installations remain usable before migration 019.
        $this->assertIdentity($type,$id);
        if(preg_match('/^[a-f0-9]{64}$/D',$hmac)!==1)throw new \InvalidArgumentException('Invalid evidence digest');
        if($type==='daily'){
            $legacy=$pdo->prepare("SELECT id FROM payments WHERE slip_hmac=? AND status IN('pending','verified') LIMIT 1");$legacy->execute([$hmac]);
            if($legacy->fetchColumn()!==false)throw new HttpException(409,'สลิปนี้เคยใช้กับบิลรายเดือนแล้ว','DUPLICATE_SLIP');
        }
        $q=$pdo->prepare('INSERT INTO payment_evidence_registry(subject_type,subject_id,slip_hmac) VALUES(?,?,?) ON DUPLICATE KEY UPDATE subject_id=subject_id');
        $q->execute([$type,$id,$hmac]);
        $q=$pdo->prepare("SELECT subject_type,subject_id FROM payment_evidence_registry WHERE active_slip_hmac=? FOR UPDATE");$q->execute([$hmac]);$row=$q->fetch();
        if(!$row||$row['subject_type']!==$type||(int)$row['subject_id']!==$id)throw new HttpException(409,'สลิปนี้เคยใช้กับรายการชำระอื่นแล้ว','DUPLICATE_SLIP');
    }

    public function claimTransaction(PDO $pdo,string $type,int $id,string $reference): bool
    {
        if(!$this->available())return true;
        $this->assertIdentity($type,$id);
        $reference=trim($reference);
        if($reference===''||strlen($reference)>191||preg_match('/[\x00-\x1F\x7F]/',$reference))return false;
        // Guard historical/direct SQL monthly records as well as migrated registry entries.
        $legacy=$pdo->prepare("SELECT id FROM payments WHERE transaction_ref=? AND status='verified' LIMIT 1");$legacy->execute([$reference]);$legacyId=$legacy->fetchColumn();
        if($legacyId!==false&&($type!=='monthly'||(int)$legacyId!==$id))return false;
        $q=$pdo->prepare('INSERT INTO payment_evidence_registry(subject_type,subject_id) VALUES(?,?) ON DUPLICATE KEY UPDATE subject_id=subject_id');$q->execute([$type,$id]);
        try{
            $q=$pdo->prepare('UPDATE payment_evidence_registry SET transaction_ref=? WHERE subject_type=? AND subject_id=? AND (transaction_ref IS NULL OR transaction_ref=?)');
            $q->execute([$reference,$type,$id,$reference]);
        }catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062)return false;throw $e;}
        $q=$pdo->prepare('SELECT subject_type,subject_id FROM payment_evidence_registry WHERE active_txn_ref=? FOR UPDATE');$q->execute([$reference]);$row=$q->fetch();
        return $row&&$row['subject_type']===$type&&(int)$row['subject_id']===$id;
    }

    /** Rejected evidence never credited money; release only its lookup projection, not raw history. */
    public function releaseRejected(PDO $pdo,string $type,int $id): void
    {
        if(!$this->available())return;$this->assertIdentity($type,$id);
        $pdo->prepare("UPDATE payment_evidence_registry SET claim_status='released' WHERE subject_type=? AND subject_id=? AND claim_status='active'")->execute([$type,$id]);
    }

    /** Call under the shared transfer-allocation bucket lock, inside the caller's transaction. */
    public function reclaimAmounts(PDO $pdo,?string $minimum=null,?string $maximum=null): void
    {
        if(!$pdo->inTransaction())throw new \LogicException('Transfer reclamation requires a transaction');
        $pdo->exec("UPDATE transfer_instructions SET status='released' WHERE status='settled' AND settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)");
        $range=$minimum!==null&&$maximum!==null?' AND a.transfer_amount BETWEEN ? AND ?':'';
        // Read old release candidates without locking current monthly reservations/finalizations.
        $monthly=$pdo->prepare("SELECT a.subject_id,t.settled_at AS settlement_time FROM payment_amount_registry a JOIN transfer_instructions t ON t.bill_id=a.subject_id
            WHERE a.subject_type='monthly' AND a.status='settled' AND a.settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY) AND t.status='released'".$range.' ORDER BY a.subject_id');
        $monthly->execute($range!==''?[$minimum,$maximum]:[]);
        $monthlyRelease=$pdo->prepare("UPDATE payment_amount_registry SET status='released',settled_at=? WHERE subject_type='monthly' AND subject_id=? AND status='settled' AND settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)");
        foreach($monthly->fetchAll()as$row)$monthlyRelease->execute([$row['settlement_time'],(int)$row['subject_id']]);
        $candidates=$pdo->prepare("SELECT a.subject_id FROM payment_amount_registry a
            WHERE a.subject_type='daily' AND a.status IN('reserved','settled')".$range."
              AND NOT EXISTS(SELECT 1 FROM daily_payments p WHERE p.booking_id=a.subject_id AND p.status='pending')
              AND ((a.status='settled' AND a.settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY))
                OR (a.status='reserved' AND EXISTS(SELECT 1 FROM daily_bookings b WHERE b.id=a.subject_id
                  AND ((b.status IN('pending','expired') AND b.expires_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY))
                    OR (b.status IN('cancelled','no_show') AND b.closed_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)))))) ORDER BY a.subject_id");
        $candidates->execute($range!==''?[$minimum,$maximum]:[]);
        $ids=$candidates->fetchAll(PDO::FETCH_COLUMN);
        // Candidate reads may be stale. Lock BOOK -> evidence -> amount, matching late finalization.
        // No old room locks are needed: these terminal/expired holds cannot recreate inventory.
        $book=$pdo->prepare("SELECT id,((status IN('pending','expired') AND expires_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY)) OR (status IN('cancelled','no_show') AND closed_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY))) AS terminal_ready FROM daily_bookings WHERE id=? FOR UPDATE");
        $pending=$pdo->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND status='pending' LIMIT 1 FOR SHARE");
        $amount=$pdo->prepare("SELECT status,settled_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 7 DAY) AS settled_ready FROM payment_amount_registry WHERE subject_type='daily' AND subject_id=? FOR UPDATE");
        $release=$pdo->prepare("UPDATE payment_amount_registry SET status='released' WHERE subject_type='daily' AND subject_id=? AND status IN('reserved','settled')");
        foreach($ids as$id){
            $book->execute([(int)$id]);$currentBook=$book->fetch();if(!$currentBook)continue;
            $pending->execute([(int)$id]);if($pending->fetchColumn()!==false)continue;
            $amount->execute([(int)$id]);$currentAmount=$amount->fetch();if(!$currentAmount)continue;
            if(($currentAmount['status']==='reserved'&&(bool)$currentBook['terminal_ready'])||($currentAmount['status']==='settled'&&(bool)$currentAmount['settled_ready']))$release->execute([(int)$id]);
        }
    }

    private function assertIdentity(string $type,int $id): void
    {
        if(!in_array($type,['monthly','daily'],true)||$id<1)throw new \InvalidArgumentException('Invalid payment registry subject');
    }
}
