<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\DailyBookingSchema;
use Dormitory\Support\Validator;
use PDO;

/** Dedicated daily rooms, priced on the server and allocated one night at a time. */
final class DailyBookingService
{
    public const HOLD_SECONDS = 1800;
    public const QUOTE_SECONDS = 600;
    public function __construct(private readonly Application $app) {}

    public function availability(array $input): array
    {
        Validator::only($input, ['check_in_date','check_out_date','guests']);
        [$start,$end,$guests,$nights] = $this->dates($input,false);
        $pdo=$this->pdo();$today=$this->databaseToday($pdo);$this->dates($input,true,$today);
        $query=$pdo->prepare("SELECT r.* FROM rooms r WHERE r.deleted_at IS NULL
            AND r.rental_mode='daily' AND r.daily_rate>0 AND r.max_guests>=?
            AND NOT EXISTS(SELECT 1 FROM occupancies o WHERE o.room_id=r.id AND o.status='active')
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.room_id=r.id AND (b.status='confirmed' OR (b.status='pending' AND b.created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$this->monthlyHold()." SECOND))))
            AND NOT EXISTS(SELECT 1 FROM daily_booking_nights n JOIN daily_bookings d ON d.id=n.booking_id
                WHERE n.room_id=r.id AND n.active=1 AND n.stay_date>=? AND n.stay_date<?
                AND (d.status IN ('confirmed','checked_in','checked_out') OR (d.status='pending' AND d.expires_at>UTC_TIMESTAMP(6))))
            AND NOT EXISTS(SELECT 1 FROM daily_room_blocks b WHERE b.room_id=r.id AND b.active=1 AND b.start_date<? AND b.end_date>?)
            AND NOT EXISTS(SELECT 1 FROM daily_bookings d WHERE d.room_id=r.id AND d.status='checked_in' AND d.check_out_date<=?)
            AND (? > ? OR r.housekeeping_status='ready') ORDER BY r.floor,r.room_code");
        $query->execute([$guests,$start,$end,$end,$start,$today,$start,$today]);
        $items=[];
        foreach($query->fetchAll() as $room) $items[]=$this->roomQuote($room,$start,$end,$guests,$nights,false);
        return ['items'=>$items,'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>$guests,'nights'=>$nights];
    }

    public function quote(array $input): array
    {
        Validator::only($input,['room_id','check_in_date','check_out_date','guests']);
        $roomId=Validator::id($input['room_id']??null,'room_id');
        [$start,$end,$guests,$nights]=$this->dates($input,false);
        $pdo=$this->pdo();$clock=$this->databaseClock($pdo);$this->dates($input,true,$clock->setTimezone($this->timezone())->format('Y-m-d'));$query=$pdo->prepare('SELECT * FROM rooms WHERE id=?');$query->execute([$roomId]);
        $room=$query->fetch(); if(!$room)throw new HttpException(404,'ไม่พบห้อง','ROOM_NOT_FOUND');
        $this->assertAvailable($pdo,$room,$start,$end,$guests);
        return $this->roomQuote($room,$start,$end,$guests,$nights,true,$clock->getTimestamp());
    }

    public function createPublic(array $input): array { return $this->create($input,null,true); }
    public function createAdmin(int $adminId,array $input): array
    {
        $result=$this->create($input,$adminId,false);unset($result['access_token']);return $result;
    }

    private function create(array $input,?int $adminId,bool $requiresQuote): array
    {
        Validator::only($input,['room_id','full_name','phone','check_in_date','check_out_date','guests','quote_token','idempotency_key']);
        $roomId=Validator::id($input['room_id']??null,'room_id');
        $name=Validator::string($input['full_name']??null,'full_name',2,150);
        $phone=Validator::phone($input['phone']??null);
        $key=$this->key($input['idempotency_key']??null);
        [$start,$end,$guests,$nights]=$this->dates($input,false);
        $quoteToken=($requiresQuote||isset($input['quote_token']))?Validator::string($input['quote_token']??null,'quote_token',40,3000):null;
        $requestHash=$this->hash(['room_id'=>$roomId,'full_name'=>$name,'phone'=>$phone,'check_in_date'=>$start,
            'check_out_date'=>$end,'guests'=>$guests,'quote_token'=>$quoteToken,'created_by'=>$adminId]);
        $this->pdo();
        return $this->app->database()->transaction(function(PDO $pdo)use($roomId,$name,$phone,$key,$start,$end,$guests,$nights,$quoteToken,$requestHash,$adminId):array {
            $room=$this->lockRoom($pdo,$roomId);
            if($adminId!==null)$this->assertOwner($pdo,$adminId);
            // Do not gap-lock a missing global idempotency key on another room.
            $existing=$pdo->prepare('SELECT * FROM daily_bookings WHERE idempotency_key=?');$existing->execute([$key]);
            if($row=$existing->fetch()) {
                if(!hash_equals($row['request_hash'],$requestHash))throw new HttpException(409,'คำขอนี้ถูกใช้กับข้อมูลการจองอื่นแล้ว','IDEMPOTENCY_KEY_REUSED');
                $this->expirePendingForRoom($pdo,$roomId);
                $fresh=$this->fetch($pdo,(int)$row['id']);
                return $this->map($fresh)+['access_token'=>$this->accessToken($fresh),'idempotent_replay'=>true];
            }
            $clock=$this->databaseClock($pdo);$this->dates(['check_in_date'=>$start,'check_out_date'=>$end,'guests'=>$guests],true,$clock->setTimezone($this->timezone())->format('Y-m-d'));
            $this->expirePendingForRoom($pdo,$roomId);
            $this->assertAvailable($pdo,$room,$start,$end,$guests);
            $quote=$this->roomQuote($room,$start,$end,$guests,$nights,false);
            if($quoteToken!==null)$this->assertQuote($quoteToken,$room,$start,$end,$guests,$quote,$this->databaseClock($pdo)->getTimestamp());
            $reference='DY-'.$clock->format('ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
            $token=$this->accessToken(['reference_no'=>$reference,'idempotency_key'=>$key]);
            try {
                $insert=$pdo->prepare("INSERT INTO daily_bookings
                    (reference_no,room_id,full_name,phone_norm,check_in_date,check_out_date,guests,nightly_rate,room_amount,deposit_amount,total_amount,status,expires_at,access_token_hash,idempotency_key,request_hash,created_by)
                    SELECT ?,?,?,?,?,?,?,?,?,?,?,'pending',LEAST(DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ".self::HOLD_SECONDS." SECOND),?),?,?,?,?
                    WHERE ?>UTC_TIMESTAMP(6) AND ?>UTC_TIMESTAMP(6)");
                $endBoundary=$this->checkoutBoundary($end);$nextStart=(new \DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d');
                $insert->execute([$reference,$roomId,$name,$phone,$start,$end,$guests,$quote['nightly_rate'],$quote['room_amount'],$quote['deposit_amount'],$quote['total_amount'],$endBoundary,hash('sha256',$token),$key,$requestHash,$adminId,$endBoundary,$this->checkoutBoundary($nextStart)]);
                if($insert->rowCount()!==1)throw new HttpException(409,'ช่วงวันที่เข้าพักผ่านไปแล้ว กรุณาค้นหาและคำนวณราคาใหม่','DAILY_STAY_ENDED');
                $id=(int)$pdo->lastInsertId();
                $night=$pdo->prepare('INSERT INTO daily_booking_nights (booking_id,room_id,stay_date,nightly_rate) VALUES (?,?,?,?)');
                foreach(self::nightDates($start,$end) as $day)$night->execute([$id,$roomId,$day,$quote['nightly_rate']]);
            } catch(\PDOException $e) {
                if((int)($e->errorInfo[1]??0)===1062)throw new HttpException(409,'ห้องถูกจองพร้อมกัน กรุณาค้นหาห้องใหม่หรือส่งคำขอเดิมซ้ำ','DAILY_BOOKING_CONFLICT',['retryable'=>true]);
                throw $e;
            }
            return $this->map($this->fetch($pdo,$id))+['access_token'=>$token,'idempotent_replay'=>false];
        });
    }

    public function guestDetails(int $id,string $token): array { return $this->validateGuestAccess($id,$token); }
    public function validateGuestAccess(int $id,string $token): array
    {
        $pdo=$this->pdo();
        $query=$pdo->prepare("SELECT d.*,r.room_code,d.expires_at<=UTC_TIMESTAMP(6) AS hold_expired FROM daily_bookings d JOIN rooms r ON r.id=d.room_id WHERE d.id=?");$query->execute([$id]);$row=$query->fetch();
        if(!preg_match('/^[0-9a-f]{64}$/D',$token)||!$row||!hash_equals($row['access_token_hash'],hash('sha256',$token)))
            throw new HttpException(404,'ไม่พบการจองหรือรหัสเข้าถึงไม่ถูกต้อง','DAILY_BOOKING_NOT_FOUND');
        return $this->map($this->withEffectiveHold($pdo,$row));
    }

    public function all(array $filters=[],bool $includeOverdue=false): array
    {
        Validator::only($filters,['status','from','to','room_id','offset','limit']);
        $from=isset($filters['from'])&&$filters['from']!==''?Validator::date($filters['from'],'from'):null;$to=isset($filters['to'])&&$filters['to']!==''?Validator::date($filters['to'],'to'):null;
        if($from!==null&&$to!==null&&$to<=$from)throw new HttpException(422,'วันสิ้นสุดต้องหลังวันเริ่มต้น','VALIDATION_ERROR');
        $limit=isset($filters['limit'])?Validator::id($filters['limit'],'limit'):500;if($limit>5000)throw new HttpException(422,'limit ต้องอยู่ระหว่าง 1–5000','VALIDATION_ERROR');
        $pdo=$this->pdo();$today=$this->databaseToday($pdo);$where=[];$args=[$today,$today];
        if(isset($filters['status'])&&$filters['status']!==''){
            $status=Validator::enum($filters['status'],'status',self::statuses());
            if($status==='expired'){$where[]="(d.status='expired' OR (d.status='pending' AND (d.expires_at<=UTC_TIMESTAMP(6) OR d.check_out_date<=?)))";$args[]=$today;}
            elseif($status==='pending'){$where[]="(d.status='pending' AND d.expires_at>UTC_TIMESTAMP(6) AND d.check_out_date>?)";$args[]=$today;}
            else{$where[]='d.status=?';$args[]=$status;}
        }
        if(isset($filters['room_id'])&&$filters['room_id']!==''){$where[]='d.room_id=?';$args[]=Validator::id($filters['room_id'],'room_id');}
        if($from!==null){$where[]=$includeOverdue?"(d.check_out_date>? OR (d.status='checked_in' AND d.check_out_date<=?))":'d.check_out_date>?';$args[]=$from;if($includeOverdue)$args[]=$today;}
        if($to!==null){$where[]='d.check_in_date<?';$args[]=$to;}
        $offset=isset($filters['offset'])?filter_var($filters['offset'],FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]):0;
        if($offset===false)throw new HttpException(422,'offset ไม่ถูกต้อง','VALIDATION_ERROR');$pageSize=$limit+1;
        $query=$pdo->prepare("SELECT d.*,r.room_code,(d.expires_at<=UTC_TIMESTAMP(6) OR d.check_out_date<=?) AS hold_expired,(d.status='checked_in' AND d.check_out_date<=?) AS overdue_checkout FROM daily_bookings d JOIN rooms r ON r.id=d.room_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY d.check_in_date,d.id DESC LIMIT {$pageSize} OFFSET {$offset}");
        $query->execute($args);$rows=$query->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
        return ['items'=>array_map($this->map(...),$rows),'has_more'=>$hasMore,'next_offset'=>(int)$offset+count($rows)];
    }

    public function calendar(array $input): array
    {
        Validator::only($input,['from','to','room_id']);
        $from=Validator::date($input['from']??null,'from');$to=Validator::date($input['to']??null,'to');
        if($to<=$from||count(self::nightDates($from,$to,367))>366)throw new HttpException(422,'ช่วงปฏิทินต้องอยู่ระหว่าง 1–366 วัน','VALIDATION_ERROR');
        $result=$this->all($input+['limit'=>5000],true);if($result['has_more'])throw new HttpException(422,'การจองในช่วงนี้มากเกินไป กรุณาลดช่วงวันหรือเลือกห้อง','DAILY_CALENDAR_TOO_LARGE');$bookings=$result['items'];
        $pdo=$this->pdo();$args=[$to,$from];$roomSql='';
        if(isset($input['room_id'])&&$input['room_id']!==''){$roomSql=' AND b.room_id=?';$args[]=Validator::id($input['room_id'],'room_id');}
        $blocks=$pdo->prepare('SELECT b.*,r.room_code FROM daily_room_blocks b JOIN rooms r ON r.id=b.room_id WHERE b.active=1 AND b.start_date<? AND b.end_date>?'.$roomSql.' ORDER BY b.start_date,b.id');$blocks->execute($args);
        $roomQuery=$pdo->query("SELECT id,room_code,housekeeping_status,housekeeping_version FROM rooms WHERE rental_mode='daily' AND deleted_at IS NULL ORDER BY floor,room_code");$allRooms=array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'room_code'=>$r['room_code'],'housekeeping_status'=>$r['housekeeping_status'],'housekeeping_version'=>(int)$r['housekeeping_version']],$roomQuery->fetchAll());
        $rooms=isset($input['room_id'])&&$input['room_id']!==''?array_values(array_filter($allRooms,static fn(array $r):bool=>$r['id']===(int)$input['room_id'])):$allRooms;
        return ['items'=>$bookings,'overdue_items'=>array_values(array_filter($bookings,static fn(array $b):bool=>$b['overdue_checkout'])),'blocks'=>array_map($this->mapBlock(...),$blocks->fetchAll()),'rooms'=>$rooms,'all_rooms'=>$allRooms,'from'=>$from,'to'=>$to];
    }

    public function transition(int $id,string $action,array $input,int $adminId): array
    {
        $outcome=$this->transitionOutcome($id,$action,$input,$adminId);
        if(isset($outcome['_error']))throw new HttpException(409,'คำขอจองหมดเวลายืนยันหรือเลยวันออกแล้ว กรุณาตรวจรายการล่าสุด','DAILY_BOOKING_EXPIRED');
        return $outcome;
    }

    /** Allows route-level audit transactions to commit automatic expiry before returning HTTP 409. */
    public function transitionOutcome(int $id,string $action,array $input,int $adminId): array
    {
        Validator::enum($action,'action',['confirm','cancel','check-in','check-out','no-show']);
        Validator::only($input,['expected_version','idempotency_key','reason']);
        $version=Validator::id($input['expected_version']??null,'expected_version');$key=$this->key($input['idempotency_key']??null);
        $reason=isset($input['reason'])&&$input['reason']!==''?Validator::string($input['reason'],'reason',1,500):null;
        $hash=$this->hash(['action'=>$action,'expected_version'=>$version,'reason'=>$reason,'admin_id'=>$adminId]);
        $this->pdo();
        $outcome=$this->app->database()->transaction(function(PDO $pdo)use($id,$action,$version,$key,$reason,$hash,$adminId):array {
            $lookup=$pdo->prepare('SELECT room_id FROM daily_bookings WHERE id=?');$lookup->execute([$id]);$roomId=$lookup->fetchColumn();
            if($roomId===false)throw new HttpException(404,'ไม่พบการจอง','DAILY_BOOKING_NOT_FOUND');
            $room=$this->lockRoom($pdo,(int)$roomId);$this->assertOwner($pdo,$adminId);
            $booking=$this->fetch($pdo,$id,true);
            $prior=$pdo->prepare('SELECT request_hash,response_json FROM daily_booking_actions WHERE booking_id=? AND idempotency_key=?');$prior->execute([$id,$key]);
            if($row=$prior->fetch()){
                if(!hash_equals($row['request_hash'],$hash))throw new HttpException(409,'รหัสคำขอนี้ถูกใช้กับการเปลี่ยนสถานะอื่นแล้ว','IDEMPOTENCY_KEY_REUSED');
                // The original receipt remains immutable in the action ledger;
                // replayed APIs report current state after later owner actions.
                return $this->map($booking)+['idempotent_replay'=>true,'performed_action'=>$action];
            }
            $this->expirePendingForRoom($pdo,(int)$roomId);$booking=$this->fetch($pdo,$id,true);
            if($booking['status']==='expired')return ['_error'=>'DAILY_BOOKING_EXPIRED'];
            if((int)$booking['version']!==$version)throw new HttpException(409,'ข้อมูลการจองเปลี่ยนแล้ว กรุณารีเฟรช','DAILY_VERSION_CONFLICT',['version'=>(int)$booking['version']]);
            $allowed=['confirm'=>['pending'],'cancel'=>['pending','confirmed'],'check-in'=>['confirmed'],'check-out'=>['checked_in'],'no-show'=>['confirmed']];
            if(!in_array($booking['status'],$allowed[$action],true))throw new HttpException(409,'เปลี่ยนสถานะนี้ไม่ได้จากสถานะปัจจุบัน','DAILY_BOOKING_BAD_STATE',['status'=>$booking['status']]);
            $today=$this->databaseToday($pdo);
            if($action==='confirm'){$this->app->dailyPayments()->assertConfirmReady($pdo,$id);$this->assertAllocated($pdo,$booking);}
            if($action==='check-in'){
                $this->app->dailyPayments()->assertConfirmReady($pdo,$id);
                $this->assertAllocated($pdo,$booking);
                if($today<$booking['check_in_date']||$today>=$booking['check_out_date'])throw new HttpException(409,'เช็กอินได้ในช่วงวันที่จองเท่านั้น','DAILY_CHECK_IN_DATE');
                if($room['housekeeping_status']!=='ready')throw new HttpException(409,'ห้องยังรอทำความสะอาด','ROOM_NOT_READY');
                $other=$pdo->prepare("SELECT id FROM daily_bookings WHERE room_id=? AND status='checked_in' AND id<>? LIMIT 1");$other->execute([$roomId,$id]);
                if($other->fetch())throw new HttpException(409,'ห้องยังมีผู้เข้าพัก กรุณาเช็กเอาต์รายเดิมก่อน','ROOM_OCCUPIED');
            }
            if($action==='no-show'&&$today<=$booking['check_in_date'])throw new HttpException(409,'ระบุไม่มาเข้าพักได้หลังวันเช็กอิน','DAILY_NO_SHOW_TOO_EARLY');
            if($action==='check-out')$this->app->dailyPayments()->assertCheckoutReady($pdo,$id);
            $target=['confirm'=>'confirmed','cancel'=>'cancelled','check-in'=>'checked_in','check-out'=>'checked_out','no-show'=>'no_show'][$action];
            $evidence=match($action){'confirm'=>',confirmed_at=UTC_TIMESTAMP(6)','check-in'=>',actual_check_in_at=UTC_TIMESTAMP(6)','check-out'=>',actual_check_out_at=UTC_TIMESTAMP(6),closed_at=UTC_TIMESTAMP(6)','cancel','no-show'=>',closed_at=UTC_TIMESTAMP(6),close_reason=?'};
            $args=[$target];if(in_array($action,['cancel','no-show'],true))$args[]=$reason;$args[]=$id;$args[]=$version;
            $update=$pdo->prepare('UPDATE daily_bookings SET status=?,version=version+1'.$evidence.',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND version=?');$update->execute($args);
            if(in_array($action,['cancel','no-show'],true))$this->releaseNights($pdo,$id);
            if($action==='check-out')$pdo->prepare("UPDATE rooms SET housekeeping_status='cleaning',housekeeping_version=housekeeping_version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$roomId]);
            $result=$this->map($this->fetch($pdo,$id));$result['idempotent_replay']=false;
            $pdo->prepare('INSERT INTO daily_booking_actions (booking_id,action,idempotency_key,request_hash,response_json) VALUES (?,?,?,?,?)')->execute([$id,$action,$key,$hash,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            return $result;
        });
        return $outcome;
    }

    /** Caller holds room then booking locks. A receipt alone never recreates expired inventory. */
    public function confirmPaidBooking(PDO $pdo,array $booking): bool
    {
        if(in_array($booking['status'],['confirmed','checked_in','checked_out'],true))return true;
        if($booking['status']!=='pending')return false;
        $this->expirePendingForRoom($pdo,(int)$booking['room_id']);$fresh=$this->fetch($pdo,(int)$booking['id'],true);
        if($fresh['status']!=='pending')return false;
        $count=$pdo->prepare('SELECT COUNT(*) FROM daily_booking_nights WHERE booking_id=? AND active=1');$count->execute([$booking['id']]);
        if((int)$count->fetchColumn()!==count(self::nightDates($fresh['check_in_date'],$fresh['check_out_date'])))return false;
        $pdo->prepare("UPDATE daily_bookings SET status='confirmed',confirmed_at=UTC_TIMESTAMP(6),version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'")->execute([$booking['id']]);return true;
    }

    public function markReady(int $roomId,array $input=[],int $adminId=0): array
    {
        Validator::only($input,['expected_version','idempotency_key']);$key=$this->key($input['idempotency_key']??null);$version=Validator::id($input['expected_version']??null,'expected_version');$hash=$this->hash(['room_id'=>$roomId,'expected_version'=>$version,'admin_id'=>$adminId]);$this->pdo();
        return $this->app->database()->transaction(function(PDO $pdo)use($roomId,$adminId,$key,$version,$hash):array {
            $room=$this->lockRoom($pdo,$roomId);$this->assertOwner($pdo,$adminId);
            $prior=$pdo->prepare('SELECT request_hash,response_json FROM daily_housekeeping_actions WHERE room_id=? AND idempotency_key=?');$prior->execute([$roomId,$key]);
            if($row=$prior->fetch()){if(!hash_equals($row['request_hash'],$hash))throw new HttpException(409,'รหัสคำขอถูกใช้แล้ว','IDEMPOTENCY_KEY_REUSED');return ['id'=>$roomId,'room_id'=>$roomId,'rental_mode'=>$room['rental_mode'],'housekeeping_status'=>$room['housekeeping_status'],'housekeeping_version'=>(int)$room['housekeeping_version'],'idempotent_replay'=>true,'performed_action'=>'ready'];}
            if((int)$room['housekeeping_version']!==$version)throw new HttpException(409,'สถานะทำความสะอาดเปลี่ยนแล้ว กรุณารีเฟรช','DAILY_VERSION_CONFLICT');
            if($room['rental_mode']!=='daily'||$room['deleted_at']!==null)throw new HttpException(409,'ห้องนี้ไม่ใช่ห้องรายวันที่เปิดใช้งาน','ROOM_NOT_DAILY');
            $occupied=$pdo->prepare("SELECT id FROM daily_bookings WHERE room_id=? AND status='checked_in' LIMIT 1");$occupied->execute([$roomId]);
            if($occupied->fetch())throw new HttpException(409,'ห้องมีผู้เข้าพักอยู่','ROOM_OCCUPIED');
            $pdo->prepare("UPDATE rooms SET housekeeping_status='ready',housekeeping_version=housekeeping_version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND housekeeping_version=?")->execute([$roomId,$version]);
            $result=['id'=>$roomId,'room_id'=>$roomId,'housekeeping_status'=>'ready','housekeeping_version'=>$version+1];
            $pdo->prepare('INSERT INTO daily_housekeeping_actions (room_id,idempotency_key,request_hash,response_json) VALUES (?,?,?,?)')->execute([$roomId,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result+['idempotent_replay'=>false];
        });
    }

    public function createBlock(int $roomId,array $input,int $adminId): array
    {
        Validator::only($input,['start_date','end_date','reason','idempotency_key']);
        $start=Validator::date($input['start_date']??null,'start_date');$end=Validator::date($input['end_date']??null,'end_date');
        if($end<=$start||count(self::nightDates($start,$end,367))>366)throw new HttpException(422,'ช่วงปิดห้องไม่ถูกต้อง ต้องวันสิ้นสุดหลังวันเริ่มและไม่เกิน 366 วัน','VALIDATION_ERROR');
        $reason=Validator::string($input['reason']??null,'reason',1,500);$key=$this->key($input['idempotency_key']??null);
        $hash=$this->hash(['room_id'=>$roomId,'start_date'=>$start,'end_date'=>$end,'reason'=>$reason]);$this->pdo();
        return $this->app->database()->transaction(function(PDO $pdo)use($roomId,$start,$end,$reason,$key,$hash,$adminId):array{
            $room=$this->lockRoom($pdo,$roomId);$this->assertOwner($pdo,$adminId);$this->expirePendingForRoom($pdo,$roomId);
            $existing=$pdo->prepare('SELECT * FROM daily_room_blocks WHERE idempotency_key=?');$existing->execute([$key]);
            if($row=$existing->fetch()){if(!hash_equals($row['request_hash'],$hash))throw new HttpException(409,'รหัสคำขอถูกใช้แล้ว','IDEMPOTENCY_KEY_REUSED');return $this->mapBlock($row)+['idempotent_replay'=>true];}
            if($start<$this->databaseToday($pdo))throw new HttpException(422,'วันเริ่มปิดห้องต้องไม่ย้อนหลัง','VALIDATION_ERROR');
            if($room['rental_mode']!=='daily'||$room['deleted_at']!==null)throw new HttpException(409,'ห้องนี้ไม่ใช่ห้องรายวัน','ROOM_NOT_DAILY');
            $conflict=$pdo->prepare("SELECT id FROM daily_bookings WHERE room_id=? AND check_in_date<? AND check_out_date>? AND status IN ('pending','confirmed','checked_in','checked_out') LIMIT 1");$conflict->execute([$roomId,$end,$start]);
            if($conflict->fetch())throw new HttpException(409,'ช่วงปิดห้องทับการจองที่มีอยู่','DAILY_BLOCK_CONFLICT');
            $pdo->prepare('INSERT INTO daily_room_blocks (room_id,start_date,end_date,reason,idempotency_key,request_hash) VALUES (?,?,?,?,?,?)')->execute([$roomId,$start,$end,$reason,$key,$hash]);
            $id=(int)$pdo->lastInsertId();$query=$pdo->prepare('SELECT * FROM daily_room_blocks WHERE id=?');$query->execute([$id]);return $this->mapBlock($query->fetch())+['idempotent_replay'=>false];
        });
    }

    public function releaseBlock(int $id,array $input,int $adminId): array
    {
        Validator::only($input,['expected_version','idempotency_key']);$version=Validator::id($input['expected_version']??null,'expected_version');$key=$this->key($input['idempotency_key']??null);$hash=$this->hash(['id'=>$id,'expected_version'=>$version]);$this->pdo();
        return $this->app->database()->transaction(function(PDO $pdo)use($id,$version,$key,$hash,$adminId):array{
            $query=$pdo->prepare('SELECT room_id FROM daily_room_blocks WHERE id=?');$query->execute([$id]);$roomId=$query->fetchColumn();if($roomId===false)throw new HttpException(404,'ไม่พบช่วงปิดห้อง','DAILY_BLOCK_NOT_FOUND');
            $this->lockRoom($pdo,(int)$roomId);$this->assertOwner($pdo,$adminId);$query=$pdo->prepare('SELECT * FROM daily_room_blocks WHERE id=? FOR UPDATE');$query->execute([$id]);$row=$query->fetch();
            if($row['release_key']!==null&&hash_equals($row['release_key'],$key)){if(!hash_equals($row['release_hash'],$hash))throw new HttpException(409,'รหัสคำขอถูกใช้แล้ว','IDEMPOTENCY_KEY_REUSED');return $this->mapBlock($row)+['idempotent_replay'=>true];}
            if((int)$row['version']!==$version||!(bool)$row['active'])throw new HttpException(409,'ข้อมูลช่วงปิดห้องเปลี่ยนแล้ว กรุณารีเฟรช','DAILY_VERSION_CONFLICT');
            $pdo->prepare('UPDATE daily_room_blocks SET active=0,version=version+1,release_key=?,release_hash=?,released_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$key,$hash,$id]);$query->execute([$id]);return $this->mapBlock($query->fetch())+['idempotent_replay'=>false];
        });
    }

    private function assertAvailable(PDO $pdo,array $room,string $start,string $end,int $guests): void
    {
        if($room['deleted_at']!==null||$room['rental_mode']!=='daily'||$room['daily_rate']===null)throw new HttpException(409,'ห้องนี้ไม่เปิดจองรายวัน','ROOM_NOT_DAILY');
        if($guests>(int)$room['max_guests'])throw new HttpException(422,'จำนวนผู้เข้าพักเกินความจุห้อง','DAILY_GUEST_CAPACITY');
        $today=$this->databaseToday($pdo);
        if($start<=$today&&$room['housekeeping_status']!=='ready')throw new HttpException(409,'ห้องยังรอทำความสะอาด','ROOM_NOT_READY');
        $query=$pdo->prepare("SELECT
            EXISTS(SELECT 1 FROM occupancies WHERE room_id=? AND status='active') AS monthly_occupied,
            EXISTS(SELECT 1 FROM bookings WHERE room_id=? AND (status='confirmed' OR (status='pending' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$this->monthlyHold()." SECOND)))) AS monthly_reserved,
            EXISTS(SELECT 1 FROM daily_booking_nights n JOIN daily_bookings d ON d.id=n.booking_id WHERE n.room_id=? AND n.active=1 AND n.stay_date>=? AND n.stay_date<? AND (d.status IN ('confirmed','checked_in','checked_out') OR (d.status='pending' AND d.expires_at>UTC_TIMESTAMP(6)))) AS daily_reserved,
            EXISTS(SELECT 1 FROM daily_room_blocks WHERE room_id=? AND active=1 AND start_date<? AND end_date>?) AS blocked,
            EXISTS(SELECT 1 FROM daily_bookings WHERE room_id=? AND status='checked_in' AND check_out_date<=?) AS overstayed");
        $query->execute([$room['id'],$room['id'],$room['id'],$start,$end,$room['id'],$end,$start,$room['id'],$today]);
        foreach($query->fetch() as $value)if((bool)$value)throw new HttpException(409,'ห้องไม่ว่างในช่วงวันที่เลือก กรุณาค้นหาใหม่','DAILY_ROOM_NOT_AVAILABLE');
    }

    private function roomQuote(array $room,string $start,string $end,int $guests,int $nights,bool $signed,?int $now=null): array
    {
        $rate=Validator::scaledDecimal($room['daily_rate'],'daily_rate');$deposit=Validator::scaledDecimal($room['daily_deposit'],'daily_deposit');$amount=$rate*$nights;
        $amenities=is_string($room['amenities'])?json_decode($room['amenities'],true):$room['amenities'];
        $image=$room['image_key']?:null;
        $out=['room_id'=>(int)$room['id'],'id'=>(int)$room['id'],'room_code'=>$room['room_code'],'floor'=>(int)$room['floor'],'room_type'=>$room['room_type'],
            'description'=>$room['description'],'amenities'=>$amenities?:[],'image_key'=>$image,'image_url'=>$image?'/assets/images/rooms/'.rawurlencode($image):null,
            'rental_mode'=>'daily','housekeeping_status'=>$room['housekeeping_status'],'housekeeping_version'=>(int)$room['housekeeping_version'],'max_guests'=>(int)$room['max_guests'],
            'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>$guests,'nights'=>$nights,'nightly_rate'=>Validator::decimalString($rate),
            'daily_rate'=>Validator::decimalString($rate),'room_amount'=>Validator::decimalString($amount),'deposit_amount'=>Validator::decimalString($deposit),'total_amount'=>Validator::decimalString($amount+$deposit)];
        if($signed){$endTime=(new \DateTimeImmutable($this->checkoutBoundary($end),new \DateTimeZone('UTC')))->getTimestamp();$expires=min(($now??time())+self::QUOTE_SECONDS,$endTime);$payload=['version'=>1,'room_id'=>(int)$room['id'],'room_version'=>$room['updated_at'],'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>$guests,'nightly_rate'=>$out['nightly_rate'],'deposit_amount'=>$out['deposit_amount'],'total_amount'=>$out['total_amount'],'expires'=>$expires];$body=rtrim(strtr(base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),'+/','-_'),'=');$out['quote_token']=$body.'.'.hash_hmac('sha256',"daily-quote:v1\0".$body,$this->app->config->appKey());$out['quote_expires_at']=gmdate('Y-m-d\TH:i:s\Z',$expires);}
        return $out;
    }

    private function assertQuote(string $token,array $room,string $start,string $end,int $guests,array $quote,?int $now=null): void
    {
        $parts=explode('.',$token);if(count($parts)!==2||!preg_match('/^[0-9a-f]{64}$/D',$parts[1])||!hash_equals(hash_hmac('sha256',"daily-quote:v1\0".$parts[0],$this->app->config->appKey()),$parts[1]))throw new HttpException(409,'ใบเสนอราคาไม่ถูกต้อง กรุณาคำนวณราคาใหม่','DAILY_QUOTE_INVALID');
        $payload=json_decode(base64_decode(strtr($parts[0],'-_','+/'),true)?:'',true);
        $expected=['version'=>1,'room_id'=>(int)$room['id'],'room_version'=>$room['updated_at'],'check_in_date'=>$start,'check_out_date'=>$end,'guests'=>$guests,'nightly_rate'=>$quote['nightly_rate'],'deposit_amount'=>$quote['deposit_amount'],'total_amount'=>$quote['total_amount']];
        if(!is_array($payload)||!is_int($payload['expires']??null)||$payload['expires']<=($now??time()))throw new HttpException(409,'ใบเสนอราคาหมดอายุ กรุณาคำนวณราคาใหม่','DAILY_QUOTE_EXPIRED');
        unset($payload['expires']);if($payload!==$expected)throw new HttpException(409,'วันที่ จำนวนคน หรือราคาห้องเปลี่ยน กรุณาคำนวณราคาใหม่','DAILY_QUOTE_CHANGED');
    }

    private function dates(array $input,bool $future=true,?string $today=null): array
    {
        $start=Validator::date($input['check_in_date']??null,'check_in_date');$end=Validator::date($input['check_out_date']??null,'check_out_date');$guests=Validator::id($input['guests']??null,'guests');
        if($guests>20)throw new HttpException(422,'จำนวนคนต้องอยู่ระหว่าง 1–20 คน','VALIDATION_ERROR',['field'=>'guests']);
        $today??=$this->today();$maximum=(new \DateTimeImmutable($today))->modify('+365 days')->format('Y-m-d');
        if(($future&&($start<$today||$start>$maximum))||$end<=$start)throw new HttpException(422,'วันเข้าพักต้องไม่ย้อนหลัง วันออกต้องหลังวันเข้า และจองล่วงหน้าได้ไม่เกิน 365 วัน','VALIDATION_ERROR');
        $nights=count(self::nightDates($start,$end,91));if($nights>90)throw new HttpException(422,'จองได้ครั้งละไม่เกิน 90 คืน','VALIDATION_ERROR');
        return [$start,$end,$guests,$nights];
    }

    /** Checkout is exclusive; use a bounded iterator even for malformed ranges. */
    public static function nightDates(string $start,string $end,int $limit=91): array
    {
        $days=[];$day=new \DateTimeImmutable($start);$until=new \DateTimeImmutable($end);
        while($day<$until&&count($days)<$limit){$days[]=$day->format('Y-m-d');$day=$day->modify('+1 day');}return $days;
    }

    private function expirePendingForRoom(PDO $pdo,int $roomId): int
    {
        $candidates=$pdo->prepare("SELECT id,check_out_date FROM daily_bookings WHERE room_id=? AND status='pending' ORDER BY id FOR UPDATE");$candidates->execute([$roomId]);$rows=$candidates->fetchAll();$expired=0;
        $update=$pdo->prepare("UPDATE daily_bookings SET status='expired',version=version+1,closed_at=UTC_TIMESTAMP(6),close_reason='เวลายืนยันหมดอายุหรือเลยวันออก',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending' AND (expires_at<=UTC_TIMESTAMP(6) OR ?<=UTC_TIMESTAMP(6))");
        foreach($rows as $row){$update->execute([$row['id'],$this->checkoutBoundary($row['check_out_date'])]);if($update->rowCount()===1){$expired++;$request=new \Dormitory\Http\Request('POST','/system/daily-expiry',['user-agent'=>'daily-booking-expiry'],[],[],[],['REMOTE_ADDR'=>'127.0.0.1'],'daily-expiry-'.bin2hex(random_bytes(12)));$this->app->audit()->writeStrict($request,['type'=>'system','id'=>null],'daily.booking.expired','daily_booking',(int)$row['id'],['room_id'=>$roomId]);}}
        $pdo->prepare("UPDATE daily_booking_nights n JOIN daily_bookings b ON b.id=n.booking_id SET n.active=0,n.released_at=UTC_TIMESTAMP(6) WHERE n.room_id=? AND n.active=1 AND b.status IN ('expired','cancelled','no_show')")->execute([$roomId]);
        return $expired;
    }
    /** RoomService holds this physical room before changing mode, capacity or retirement state. */
    public function expireRoomHoldsUnderLock(PDO $pdo,int $roomId): int
    {
        if(!$pdo->inTransaction())throw new \LogicException('Room hold expiry requires a transaction and its room lock');
        return $this->expirePendingForRoom($pdo,$roomId);
    }
    /** A bounded worker pass; reads still exclude expired holds when the worker is unavailable. */
    public function expireHolds(int $limit=100): array
    {
        $pdo=$this->pdo();$limit=max(1,min(1000,$limit));
        $roomQuery=$pdo->prepare("SELECT DISTINCT room_id FROM daily_bookings WHERE status='pending' AND (expires_at<=UTC_TIMESTAMP(6) OR check_out_date<=?) ORDER BY room_id LIMIT ".($limit+1));$roomQuery->execute([$this->databaseToday($pdo)]);$rooms=$roomQuery->fetchAll(PDO::FETCH_COLUMN);$hasMore=count($rooms)>$limit;if($hasMore)array_pop($rooms);
        $expired=0;
        foreach($rooms as $roomId)$expired+=$this->app->database()->transaction(function(PDO $pdo)use($roomId):int{
            $this->lockRoom($pdo,(int)$roomId);return $this->expirePendingForRoom($pdo,(int)$roomId);
        });
        return ['expired'=>$expired,'rooms_processed'=>count($rooms),'has_more'=>$hasMore];
    }
    private function releaseNights(PDO $pdo,int $id): void {$pdo->prepare('UPDATE daily_booking_nights SET active=0,released_at=UTC_TIMESTAMP(6) WHERE booking_id=? AND active=1')->execute([$id]);}

    /** Shared DB clock for finance: a pending stay cannot remain payable after its checkout day begins. */
    public function withEffectiveHold(PDO $pdo,array $booking): array
    {
        $boundary=$this->checkoutBoundary((string)$booking['check_out_date']);
        $q=$pdo->prepare('SELECT (expires_at>UTC_TIMESTAMP(6) AND ?>UTC_TIMESTAMP(6)) AS hold_live,(?<=UTC_TIMESTAMP(6)) AS stay_ended,LEAST(expires_at,?) AS effective_expires_at FROM daily_bookings WHERE id=?');$q->execute([$boundary,$boundary,$boundary,$booking['id']]);$clock=$q->fetch();
        if(!$clock)throw new HttpException(404,'ไม่พบการจอง','DAILY_BOOKING_NOT_FOUND');
        $booking['hold_live']=(bool)$clock['hold_live'];$booking['hold_expired']=!$booking['hold_live'];$booking['effective_expires_at']=$clock['effective_expires_at'];$booking['overdue_checkout']=$booking['status']==='checked_in'&&(bool)$clock['stay_ended'];return $booking;
    }

    private function timezone(): \DateTimeZone {return new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok'));}
    private function checkoutBoundary(string $day): string {return (new \DateTimeImmutable($day.' 00:00:00',$this->timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    private function databaseClock(PDO $pdo): \DateTimeImmutable {return new \DateTimeImmutable((string)$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(),new \DateTimeZone('UTC'));}
    private function databaseToday(PDO $pdo): string {return $this->databaseClock($pdo)->setTimezone($this->timezone())->format('Y-m-d');}
    private function assertAllocated(PDO $pdo,array $booking): void {$query=$pdo->prepare('SELECT COUNT(*) FROM daily_booking_nights WHERE booking_id=? AND active=1');$query->execute([$booking['id']]);if((int)$query->fetchColumn()!==count(self::nightDates($booking['check_in_date'],$booking['check_out_date'])))throw new HttpException(409,'รายการกันห้องไม่ครบ กรุณาติดต่อเจ้าของตรวจสอบ','DAILY_ALLOCATION_INCOMPLETE');}
    private function lockRoom(PDO $pdo,int $id): array {$query=$pdo->prepare('SELECT * FROM rooms WHERE id=? FOR UPDATE');$query->execute([$id]);$room=$query->fetch();if(!$room)throw new HttpException(404,'ไม่พบห้อง','ROOM_NOT_FOUND');return $room;}
    private function fetch(PDO $pdo,int $id,bool $lock=false): array {$query=$pdo->prepare('SELECT d.*,r.room_code FROM daily_bookings d JOIN rooms r ON r.id=d.room_id WHERE d.id=?'.($lock?' FOR UPDATE':''));$query->execute([$id]);$row=$query->fetch();if(!$row)throw new HttpException(404,'ไม่พบการจอง','DAILY_BOOKING_NOT_FOUND');return $this->withEffectiveHold($pdo,$row);}
    private function map(array $row): array
    {
        $state=$row['status'];if($state==='pending'&&(array_key_exists('hold_expired',$row)?(bool)$row['hold_expired']:strtotime($row['expires_at'].' UTC')<=time()))$state='expired';
        $effectiveExpiry=$row['effective_expires_at']??min($row['expires_at'],$this->checkoutBoundary($row['check_out_date']));
        return ['id'=>(int)$row['id'],'reference_no'=>$row['reference_no'],'reference'=>$row['reference_no'],'room_id'=>(int)$row['room_id'],'room_code'=>$row['room_code']??null,'full_name'=>$row['full_name'],'phone'=>$row['phone_norm'],'check_in_date'=>$row['check_in_date'],'check_out_date'=>$row['check_out_date'],'guests'=>(int)$row['guests'],'nights'=>count(self::nightDates($row['check_in_date'],$row['check_out_date'])),'nightly_rate'=>$row['nightly_rate'],'room_amount'=>$row['room_amount'],'deposit_amount'=>$row['deposit_amount'],'total_amount'=>$row['total_amount'],'status'=>$state,'overdue_checkout'=>(bool)($row['overdue_checkout']??false),'expires_at'=>$this->utc($effectiveExpiry),'version'=>(int)$row['version'],'confirmed_at'=>$this->utc($row['confirmed_at']),'actual_check_in_at'=>$this->utc($row['actual_check_in_at']),'actual_check_out_at'=>$this->utc($row['actual_check_out_at']),'closed_at'=>$this->utc($row['closed_at']),'close_reason'=>$row['close_reason'],'created_at'=>$this->utc($row['created_at'])];
    }
    private function mapBlock(array $row): array {return ['id'=>(int)$row['id'],'room_id'=>(int)$row['room_id'],'room_code'=>$row['room_code']??null,'start_date'=>$row['start_date'],'end_date'=>$row['end_date'],'reason'=>$row['reason'],'active'=>(bool)$row['active'],'version'=>(int)$row['version'],'released_at'=>$this->utc($row['released_at'])];}
    private function utc(?string $value): ?string {return $value===null?null:(new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');}
    private function accessToken(array $row): string {return hash_hmac('sha256',"daily-access:v1\0".$row['reference_no']."\0".$row['idempotency_key'],$this->app->config->appKey());}
    private function hash(array $data): string {return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
    private function key(mixed $value): string {$key=Validator::string($value,'idempotency_key',16,64);if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$key))throw new HttpException(422,'รหัสคำขอไม่ถูกต้อง','VALIDATION_ERROR',['field'=>'idempotency_key']);return $key;}
    private function pdo(): PDO {$pdo=$this->app->database()->pdo();DailyBookingSchema::assertReady($pdo);return $pdo;}
    private function today(): string {return (new \DateTimeImmutable('today',new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok'))))->format('Y-m-d');}
    private function monthlyHold(): int {return $this->app->config->intInRange('BOOKING_HOLD_SECONDS',86400,900,604800);}
    private static function statuses(): array {return ['pending','confirmed','checked_in','checked_out','cancelled','expired','no_show'];}
    private function assertOwner(PDO $pdo,int $id): void {$query=$pdo->prepare("SELECT id FROM admin_users WHERE id=? AND role='owner' AND active=1 AND retired_at IS NULL FOR SHARE");$query->execute([$id]);if(!$query->fetchColumn())throw new HttpException(403,'ต้องเป็นเจ้าของระบบที่เปิดใช้งาน','ADMIN_INACTIVE');}
}
