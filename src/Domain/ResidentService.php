<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Security\ResidentAccessCredential;
use Dormitory\Support\MySqlError;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

final class ResidentService
{
    public function __construct(private readonly Application $app) {}

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows=$this->app->database()->pdo()->query("SELECT r.id,r.full_name,r.phone_norm AS phone,r.email,r.line_user_id,r.active,
                    r.access_password_hash IS NOT NULL AS access_active,
                    (r.activation_code_hash IS NOT NULL
                        AND r.activation_consumed_at IS NULL
                        AND r.activation_expires_at>UTC_TIMESTAMP(6)) AS activation_pending,
                    o.id AS occupancy_id,o.move_in_date,rm.id AS room_id,rm.room_code
            FROM occupancies o
            JOIN residents r ON r.id=o.resident_id AND r.active=1
            JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
            WHERE o.status='active'
            ORDER BY rm.floor,rm.room_code")->fetchAll();
        foreach($rows as &$row){$row['id']=(int)$row['id'];$row['occupancy_id']=(int)$row['occupancy_id'];$row['room_id']=$row['room_id']!==null?(int)$row['room_id']:null;$row['active']=(bool)$row['active'];$row['access_active']=(bool)$row['access_active'];$row['activation_pending']=(bool)$row['activation_pending'];$row=array_replace($row,$this->app->lineRoomBindings()->status($row['id']));unset($row['line_user_id']);}
        return $rows;
    }

    /** @return array<string,mixed> */
    public function profile(int $id): array
    {
        $statement=$this->app->database()->pdo()->prepare("SELECT r.id,r.full_name,r.phone_norm AS phone,r.email,r.line_user_id,r.auth_version,rm.id AS room_id,rm.room_code FROM residents r JOIN occupancies o ON o.resident_id=r.id AND o.status='active' JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL WHERE r.id=? AND r.active=1 ORDER BY o.id DESC LIMIT 2");
        $statement->execute([$id]);$rows=$statement->fetchAll();$row=count($rows)===1?$rows[0]:false;if(!$row)throw new HttpException(404,'Resident not found','RESIDENT_NOT_FOUND');$row['id']=(int)$row['id'];$row['room_id']=(int)$row['room_id'];$row['line_verified']=$this->app->notifications()->isLineBindingVerified($row['id'],$row['line_user_id']??null);
        $row=array_replace($row,$this->app->lineRoomBindings()->status($id));
        $selfServiceOa=$this->app->lineOfficialAccounts()->get(0);
        $row['line_add_friend_url']=$selfServiceOa['line_add_friend_url']??null;
        $row['line_binding_ready']=($selfServiceOa['line_binding_ready']??false)===true;
        unset($row['line_user_id'],$row['auth_version']);return $row;
    }

    /** Safe LINE status for the administrative resident dialog; never returns a code. */
    public function lineStatus(int $id): array
    {
        return $this->app->database()->transaction(function (PDO $pdo) use ($id): array {
            $profile=$this->profile($id);
            $statement=$pdo->prepare("SELECT expires_at FROM line_link_codes
                WHERE resident_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP(6)
                ORDER BY id DESC LIMIT 1");
            $statement->execute([$id]);
            $expires=$statement->fetchColumn();
            return [
                'resident_id'=>$id,
                'full_name'=>$profile['full_name'],
                'room_code'=>$profile['room_code'],
                'line_verified'=>$profile['line_verified'],
                'line_user_id_hint'=>$profile['line_user_id_hint'],
                'line_bound_count'=>$profile['line_bound_count'],
                'line_blocked'=>$profile['line_blocked'],
                'line_add_friend_url'=>$profile['line_add_friend_url'],
                'line_binding_ready'=>$profile['line_binding_ready'],
                'pending_expires_at'=>$expires===false?null:(string)$expires,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function updateProfile(int $id,array $input): array
    {
        Validator::only($input,['full_name','email']);
        $name=Validator::string($input['full_name']??null,'full_name',1,150);$email=Validator::nullableEmail($input['email']??null);
        $pdo=$this->app->database()->pdo();
        $lock=$pdo->prepare('SELECT id FROM residents WHERE id=? AND active=1 FOR UPDATE');
        $lock->execute([$id]);$current=$lock->fetch();
        if(!$current)throw new HttpException(404,'Resident not found','RESIDENT_NOT_FOUND');
        $update=$pdo->prepare('UPDATE residents SET full_name=?,email=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
        $update->execute([$name,$email,$id]);
        return $this->profile($id);
    }

    /** @return array<string,mixed> */
    public function updateByAdmin(int $id,array $input): array
    {
        Validator::only($input,['full_name','phone','email']);
        if($input===[])throw new HttpException(422,'กรุณาระบุข้อมูลที่ต้องการแก้ไข','VALIDATION_ERROR');
        $requestedPhone=array_key_exists('phone',$input)?Validator::phone($input['phone']):null;

        return $this->app->database()->transaction(function(PDO $pdo)use($id,$input,$requestedPhone):array{
            // Take the shared cross-table mutex before resident/occupancy row
            // locks. Booking writers may already hold a room lock before this
            // mutex, so this order avoids a resident-row <-> phone-lock cycle.
            if($requestedPhone!==null){
                $this->app->limiter()->lockBucket('resident-booking-phone',$requestedPhone);
            }
            $lock=$pdo->prepare("SELECT r.full_name,r.phone_norm,r.email,r.line_user_id,r.auth_version
                FROM residents r
                JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                WHERE r.id=? AND r.active=1
                FOR UPDATE");
            $lock->execute([$id]);
            $current=$lock->fetch();
            if(!$current)throw new HttpException(404,'ไม่พบผู้พักอาศัยปัจจุบัน','RESIDENT_NOT_FOUND');

            $name=array_key_exists('full_name',$input)
                ?Validator::string($input['full_name'],'full_name',1,150)
                :(string)$current['full_name'];
            $phone=$requestedPhone??(string)$current['phone_norm'];
            $email=array_key_exists('email',$input)
                ?Validator::nullableEmail($input['email'])
                :($current['email']!==null?(string)$current['email']:null);
            $changed=[];
            foreach([
                'full_name'=>[$current['full_name'],$name],
                'phone'=>[$current['phone_norm'],$phone],
                'email'=>[$current['email'],$email],
            ] as $field=>$values){
                if((string)($values[0]??'')!==(string)($values[1]??''))$changed[]=$field;
            }
            $phoneChanged=in_array('phone',$changed,true);

            if($phoneChanged){
                // Serialize against booking creation/confirmation/check-in.
                // The generated unique columns protect each table separately;
                // this mutex closes the missing-row race between the tables.
                $activeBooking=$pdo->prepare('SELECT id FROM bookings WHERE active_phone_norm=? LIMIT 1 FOR UPDATE');
                $activeBooking->execute([$phone]);
                if($activeBooking->fetch()){
                    throw new HttpException(409,'เบอร์โทรนี้มีคำขอจองที่ยังดำเนินการอยู่','BOOKING_PHONE_ACTIVE');
                }
            }

            $residentAccess=null;
            try{
                if($phoneChanged){
                    $nextAuthVersion=(int)$current['auth_version']+1;
                    $residentAccess=$this->newResidentAccess($id,$nextAuthVersion);
                    $update=$pdo->prepare("UPDATE residents
                        SET full_name=?,phone_norm=?,email=?,line_user_id=NULL,
                            access_password_hash=NULL,activation_code_hash=?,
                            activation_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL {$residentAccess['ttl_seconds']} SECOND),
                            activation_consumed_at=NULL,auth_version=?,updated_at=UTC_TIMESTAMP(6)
                        WHERE id=? AND active=1 AND auth_version=?");
                    $update->execute([
                        $name,$phone,$email,$residentAccess['hash'],$nextAuthVersion,$id,
                        (int)$current['auth_version'],
                    ]);
                    if($update->rowCount()!==1){
                        throw new HttpException(409,'Resident changed; refresh and retry','RESIDENT_CHANGED');
                    }
                }else{
                    $update=$pdo->prepare('UPDATE residents SET full_name=?,phone_norm=?,email=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
                    $update->execute([$name,$phone,$email,$id]);
                }
            }catch(PDOException $error){
                if(MySqlError::isDuplicateKey($error,'uq_residents_phone_norm')){
                    throw new HttpException(409,'เบอร์โทรนี้ผูกกับผู้พักรายอื่นแล้ว','RESIDENT_IDENTITY_IN_USE');
                }
                throw $error;
            }

            if($phoneChanged){$this->app->lineBindings()->revokePending($id);$this->app->lineRoomBindings()->revokeForIdentity($id);}
            $profile=$this->profile($id);
            $profile['changed_fields']=$changed;
            $profile['sessions_revoked']=$phoneChanged;
            if($phoneChanged&&is_string($current['line_user_id'])&&$current['line_user_id']!==''){
                $profile['_line_unlinked_audit']=[
                    'line_user_id_hint'=>'•••'.substr($current['line_user_id'],-6),
                    'line_user_id_hash'=>$this->app->notifications()->lineBindingHash(
                        $id,
                        $current['line_user_id']
                    ),
                    'reason'=>'admin_phone_change',
                ];
            }
            if($residentAccess!==null){
                $expiry=$pdo->prepare('SELECT activation_expires_at FROM residents WHERE id=?');
                $expiry->execute([$id]);
                $profile['resident_access']=[
                    'activation_required'=>true,
                    'activation_code'=>$residentAccess['code'],
                    'expires_at'=>(string)$expiry->fetchColumn(),
                    'single_use'=>true,
                ];
            }
            return $profile;
        });
    }

    /** @return array<string,mixed> */
    public function reissueAccess(int $id): array
    {
        return $this->app->database()->transaction(function(PDO $pdo)use($id):array{
            $lock=$pdo->prepare("SELECT r.auth_version
                FROM residents r
                JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                WHERE r.id=? AND r.active=1
                LIMIT 1 FOR UPDATE");
            $lock->execute([$id]);
            $row=$lock->fetch();
            if(!$row)throw new HttpException(404,'Resident not found','RESIDENT_NOT_FOUND');
            $nextAuthVersion=(int)$row['auth_version']+1;
            $credential=$this->newResidentAccess($id,$nextAuthVersion);
            $update=$pdo->prepare("UPDATE residents
                SET access_password_hash=NULL,activation_code_hash=?,
                    activation_expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL {$credential['ttl_seconds']} SECOND),
                    activation_consumed_at=NULL,auth_version=?,updated_at=UTC_TIMESTAMP(6)
                WHERE id=? AND active=1 AND auth_version=?");
            $update->execute([
                $credential['hash'],$nextAuthVersion,$id,(int)$row['auth_version'],
            ]);
            if($update->rowCount()!==1){
                throw new HttpException(409,'Resident changed; refresh and retry','RESIDENT_CHANGED');
            }
            // A credential rotation invalidates every one-time action issued by
            // the previous resident session. The HTTP route also holds the
            // resident LINE advisory lock so this cannot race webhook consume.
            $this->app->lineBindings()->revokePending($id);
            $this->app->lineRoomBindings()->revokeForIdentity($id);
            LineAdminEvents::enqueue($this->app,'security.access_reissued',hash('sha256',$id.':'.$nextAuthVersion));
            $expiry=$pdo->prepare('SELECT activation_expires_at FROM residents WHERE id=?');
            $expiry->execute([$id]);
            return [
                'resident_id'=>$id,
                'sessions_revoked'=>true,
                'resident_access'=>[
                    'activation_required'=>true,
                    'activation_code'=>$credential['code'],
                    'expires_at'=>(string)$expiry->fetchColumn(),
                    'single_use'=>true,
                ],
            ];
        });
    }

    /** @return array{code:string,hash:string,ttl_seconds:int} */
    private function newResidentAccess(int $residentId,int $authVersion): array
    {
        $credential=ResidentAccessCredential::issue(
            $this->app->config,
            $residentId,
            $authVersion
        );
        return $credential+[
            'ttl_seconds'=>ResidentAccessCredential::ttlSeconds($this->app->config),
        ];
    }

    /** @return array<string,mixed> */
    public function unlinkLine(int $id,array $input): array
    {
        Validator::only($input,[]);
        $this->activeAuthVersion($id,true);
        $statement=$this->app->database()->pdo()->prepare('UPDATE residents SET line_user_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
        $statement->execute([$id]);
        $this->app->lineBindings()->revokePending($id);
        $this->app->lineRoomBindings()->revokeForIdentity($id);
        return $this->profile($id);
    }

    private function activeAuthVersion(int $residentId,bool $forUpdate=false): int
    {
        $pdo=$this->app->database()->pdo();
        if($forUpdate&&!$pdo->inTransaction())throw new \RuntimeException('Resident lock requires a database transaction');
        $statement=$pdo->prepare("SELECT r.auth_version
            FROM residents r
            JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
            JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
            WHERE r.id=? AND r.active=1
            ORDER BY o.id DESC LIMIT 2".($forUpdate?' FOR UPDATE':''));
        $statement->execute([$residentId]);
        $rows=$statement->fetchAll();
        if(count($rows)!==1)throw new HttpException(404,'Resident not found','RESIDENT_NOT_FOUND');
        return (int)$rows[0]['auth_version'];
    }

    /** @return array<string,mixed> */
    public function moveOut(int $id,array $input): array
    {
        Validator::only($input,['move_out_date']);
        $moveOut=Validator::date($input['move_out_date']??null,'move_out_date');
        $timezone=new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok'));
        $today=(new \DateTimeImmutable('today',$timezone))->format('Y-m-d');
        if($moveOut>$today)throw new HttpException(422,'วันที่ย้ายออกต้องไม่เป็นวันในอนาคต','VALIDATION_ERROR',['field'=>'move_out_date']);

        return $this->app->database()->transaction(function(PDO $pdo)use($id,$moveOut):array{
            $lock=$pdo->prepare("SELECT o.id AS occupancy_id,o.room_id,o.move_in_date,rm.room_code,r.line_user_id
                FROM occupancies o
                JOIN residents r ON r.id=o.resident_id AND r.active=1
                JOIN rooms rm ON rm.id=o.room_id
                WHERE o.resident_id=? AND o.status='active'
                FOR UPDATE");
            $lock->execute([$id]);
            $occupancy=$lock->fetch();
            if(!$occupancy)throw new HttpException(404,'ไม่พบผู้พักอาศัยปัจจุบัน','RESIDENT_NOT_FOUND');
            if($moveOut<(string)$occupancy['move_in_date'])throw new HttpException(422,'วันที่ย้ายออกต้องไม่ก่อนวันที่เข้าพัก','VALIDATION_ERROR',['field'=>'move_out_date']);

            $pending=$pdo->prepare("SELECT id,bill_no FROM bills WHERE occupancy_id=? AND status='pending' ORDER BY period,id FOR UPDATE");
            $pending->execute([$occupancy['occupancy_id']]);
            $pendingRows=$pending->fetchAll();
            if($pendingRows!==[]){
                throw new HttpException(409,'ยังมีบิลค้างชำระ ต้องชำระให้ครบก่อนย้ายออก','MOVE_OUT_HAS_PENDING_BILLS',[
                    'pending_bill_ids'=>array_map(static fn(array $row):int=>(int)$row['id'],$pendingRows),
                ]);
            }

            $period=substr($moveOut,0,7).'-01';
            // Meter readings belong to the physical room and form an ordered
            // monthly chain. Back-dating an occupancy past a later reading
            // would leave that reading available to the next resident even
            // though it was recorded while this occupancy was current.
            $laterMeters=$pdo->prepare('SELECT id,meter_type,period FROM meter_readings WHERE room_id=? AND period>? ORDER BY period,id FOR UPDATE');
            $laterMeters->execute([$occupancy['room_id'],$period]);
            $laterMeterRows=$laterMeters->fetchAll();
            if($laterMeterRows!==[]){
                $laterMeterReadings=array_map(static fn(array $row):array=>[
                    'id'=>(int)$row['id'],
                    'meter_type'=>(string)$row['meter_type'],
                    'period'=>substr((string)$row['period'],0,7),
                ],$laterMeterRows);
                throw new HttpException(409,'มีเลขมิเตอร์ของรอบเดือนหลังวันที่ย้ายออก กรุณาตรวจสอบวันที่หรือแก้ประวัติมิเตอร์ก่อน','MOVE_OUT_HAS_LATER_METERS',[
                    'later_meter_ids'=>array_column($laterMeterReadings,'id'),
                    'later_meter_periods'=>array_values(array_unique(array_column($laterMeterReadings,'period'))),
                    'later_meter_readings'=>$laterMeterReadings,
                ]);
            }
            $laterBills=$pdo->prepare('SELECT id,bill_no,period FROM bills WHERE occupancy_id=? AND period>? ORDER BY period,id FOR UPDATE');
            $laterBills->execute([$occupancy['occupancy_id'],$period]);
            $laterRows=$laterBills->fetchAll();
            if($laterRows!==[]){
                throw new HttpException(409,'มีบิลรอบเดือนหลังวันที่ย้ายออก กรุณาเลือกวันที่ให้ตรงประวัติหรือจัดการเอกสารย้อนหลังผ่านกระบวนการที่ได้รับอนุมัติ','MOVE_OUT_HAS_LATER_BILLS',[
                    'later_bill_ids'=>array_map(static fn(array $row):int=>(int)$row['id'],$laterRows),
                ]);
            }

            // A paid closing-month bill alone is not sufficient evidence that
            // the complete tenancy was billed. Fail closed when any calendar
            // month from move-in through move-out has no bill for this exact
            // occupancy, otherwise historical rent can be silently skipped.
            $firstPeriod=substr((string)$occupancy['move_in_date'],0,7).'-01';
            $billedPeriods=$pdo->prepare('SELECT period FROM bills WHERE occupancy_id=? AND period>=? AND period<=? ORDER BY period FOR UPDATE');
            $billedPeriods->execute([$occupancy['occupancy_id'],$firstPeriod,$period]);
            $actualPeriods=array_map(static fn(array $row):string=>(string)$row['period'],$billedPeriods->fetchAll());
            $missingPeriods=array_values(array_diff(self::billingPeriods($firstPeriod,$period),$actualPeriods));
            if($missingPeriods!==[]){
                throw new HttpException(409,'ยังออกบิลไม่ครบทุกรอบเดือนของการเข้าพัก กรุณาออกและยืนยันการชำระบิลที่ขาดก่อนย้ายออก','MOVE_OUT_MISSING_BILLS',[
                    'missing_periods'=>array_map(static fn(string $value):string=>substr($value,0,7),$missingPeriods),
                ]);
            }
            $closingBill=$pdo->prepare("SELECT id,bill_no FROM bills WHERE occupancy_id=? AND period=? AND status='paid' LIMIT 1 FOR UPDATE");
            $closingBill->execute([$occupancy['occupancy_id'],$period]);
            $paidBill=$closingBill->fetch();
            if(!$paidBill){
                throw new HttpException(409,'ต้องออกบิลและยืนยันการชำระของเดือนที่ย้ายออกก่อน','MOVE_OUT_BILL_REQUIRED',['period'=>substr($moveOut,0,7)]);
            }

            $end=$pdo->prepare("UPDATE occupancies SET status='ended',move_out_date=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='active'");
            $end->execute([$moveOut,$occupancy['occupancy_id']]);
            if($end->rowCount()!==1)throw new HttpException(409,'สถานะการเข้าพักเปลี่ยนแปลงแล้ว กรุณารีเฟรช','OCCUPANCY_CHANGED');
            // A returning resident must verify LINE again. Clearing the old ID
            // here also avoids reserving it indefinitely on an inactive row.
            $deactivate=$pdo->prepare('UPDATE residents
                SET active=0,line_user_id=NULL,access_password_hash=NULL,
                    activation_code_hash=NULL,activation_expires_at=NULL,
                    activation_consumed_at=NULL,auth_version=auth_version+1,
                    updated_at=UTC_TIMESTAMP()
                WHERE id=? AND active=1');
            $deactivate->execute([$id]);
            if($deactivate->rowCount()!==1)throw new HttpException(409,'สถานะผู้พักเปลี่ยนแปลงแล้ว กรุณารีเฟรช','RESIDENT_CHANGED');
            $this->app->lineBindings()->revokePending($id);
            $this->app->lineRoomBindings()->revokeForIdentity($id);

            $lineUserId=is_string($occupancy['line_user_id']??null)?(string)$occupancy['line_user_id']:'';
            LineAdminEvents::enqueue($this->app,'tenancy.moved_out',(int)$occupancy['occupancy_id']);
            return [
                'resident_id'=>$id,
                'occupancy_id'=>(int)$occupancy['occupancy_id'],
                'room_id'=>(int)$occupancy['room_id'],
                'room_code'=>$occupancy['room_code'],
                'move_out_date'=>$moveOut,
                'closing_bill_id'=>(int)$paidBill['id'],
                'status'=>'ended',
                '_line_unlinked_audit'=>$lineUserId!==''?[
                    'line_user_id_hint'=>'•••'.substr($lineUserId,-6),
                    'line_user_id_hash'=>$this->app->notifications()->lineBindingHash($id,$lineUserId),
                    'reason'=>'move_out',
                ]:null,
            ];
        });
    }

    /** @return list<string> First-of-month dates, inclusive. */
    private static function billingPeriods(string $firstPeriod,string $lastPeriod): array
    {
        $cursor=new \DateTimeImmutable($firstPeriod,new \DateTimeZone('UTC'));
        $end=new \DateTimeImmutable($lastPeriod,new \DateTimeZone('UTC'));
        $periods=[];
        while($cursor<=$end){
            $periods[]=$cursor->format('Y-m-01');
            $cursor=$cursor->modify('first day of next month');
        }
        return $periods;
    }
}
