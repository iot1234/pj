<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

final class ResidentService
{
    public function __construct(private readonly Application $app) {}

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows=$this->app->database()->pdo()->query("SELECT r.id,r.full_name,r.phone_norm AS phone,r.email,r.line_user_id,r.active,o.id AS occupancy_id,o.move_in_date,rm.id AS room_id,rm.room_code
            FROM occupancies o
            JOIN residents r ON r.id=o.resident_id AND r.active=1
            JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
            WHERE o.status='active'
            ORDER BY rm.floor,rm.room_code")->fetchAll();
        $bindings=[];foreach($rows as $row)$bindings[]=['resident_id'=>(int)$row['id'],'line_user_id'=>$row['line_user_id']??null];
        $verified=$this->app->notifications()->verifiedLineBindings($bindings);
        foreach($rows as &$row){$row['id']=(int)$row['id'];$row['occupancy_id']=(int)$row['occupancy_id'];$row['room_id']=$row['room_id']!==null?(int)$row['room_id']:null;$row['active']=(bool)$row['active'];$row['line_verified']=$verified[$row['id']]??false;}
        return $rows;
    }

    /** @return array<string,mixed> */
    public function profile(int $id): array
    {
        $statement=$this->app->database()->pdo()->prepare("SELECT r.id,r.full_name,r.phone_norm AS phone,r.email,r.line_user_id,r.auth_version,rm.id AS room_id,rm.room_code FROM residents r JOIN occupancies o ON o.resident_id=r.id AND o.status='active' JOIN rooms rm ON rm.id=o.room_id WHERE r.id=?");
        $statement->execute([$id]);$row=$statement->fetch();if(!$row)throw new HttpException(404,'Resident not found','RESIDENT_NOT_FOUND');$row['id']=(int)$row['id'];$row['room_id']=(int)$row['room_id'];$row['line_verified']=$this->app->notifications()->isLineBindingVerified($row['id'],$row['line_user_id']??null);
        $challenge=$this->app->session()->lineLinkChallenge();$now=time();
        if(is_array($challenge)&&(int)($challenge['resident_id']??0)===$row['id']
            &&(int)($challenge['auth_version']??-1)===(int)$row['auth_version']
            &&(int)($challenge['expires_at']??0)>$now&&(int)($challenge['attempts']??0)<5
            &&is_string($challenge['line_user_id']??null)){
            $alreadyVerified=$row['line_verified']===true&&is_string($row['line_user_id'])&&hash_equals($row['line_user_id'],$challenge['line_user_id']);
            if(!$alreadyVerified)$row['line_link_pending']=['line_user_id_hint'=>'•••'.substr($challenge['line_user_id'],-6),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$challenge['expires_at'])];
        }elseif(is_array($challenge)&&(int)($challenge['resident_id']??0)===$row['id']){
            $this->app->session()->clearLineLinkChallenge();
        }
        unset($row['auth_version']);return $row;
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

        $pdo=$this->app->database()->pdo();
        $lock=$pdo->prepare("SELECT r.full_name,r.phone_norm,r.email,r.line_user_id
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
        $phone=array_key_exists('phone',$input)
            ?Validator::phone($input['phone'])
            :(string)$current['phone_norm'];
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

        try{
            $update=$pdo->prepare('UPDATE residents SET full_name=?,phone_norm=?,email=?,auth_version=auth_version+?,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
            $update->execute([$name,$phone,$email,$phoneChanged?1:0,$id]);
        }catch(PDOException $error){
            if((int)($error->errorInfo[1]??0)===1062||(string)$error->getCode()==='23000'){
                throw new HttpException(409,'เบอร์โทรนี้ผูกกับผู้พักรายอื่นแล้ว','RESIDENT_IDENTITY_IN_USE');
            }
            throw $error;
        }

        $profile=$this->profile($id);
        $profile['changed_fields']=$changed;
        $profile['sessions_revoked']=$phoneChanged;
        return $profile;
    }

    /** @return array{line_user_id_hint:string,expires_in:int} */
    public function startLineLink(int $id,array $input): array
    {
        Validator::only($input,['line_user_id','current_pin']);
        $lineUserId=trim(is_string($input['line_user_id']??null)?$input['line_user_id']:'');
        if(!preg_match('/^U[0-9A-Za-z_-]{20,80}$/D',$lineUserId)){
            throw new HttpException(422,'LINE User ID ไม่ถูกต้อง','VALIDATION_ERROR',['field'=>'line_user_id']);
        }
        $this->profile($id);
        $authVersion=$this->assertCurrentPin($id,is_string($input['current_pin']??null)?$input['current_pin']:'');
        $code=(string)random_int(100000,999999);
        $nonce=bin2hex(random_bytes(16));
        $expiresAt=time()+600;
        $challenge=[
            'resident_id'=>$id,
            'auth_version'=>$authVersion,
            'line_user_id'=>$lineUserId,
            'nonce'=>$nonce,
            'digest'=>$this->lineLinkDigest($id,$lineUserId,$code,$nonce),
            'expires_at'=>$expiresAt,
            'attempts'=>0,
        ];
        $this->app->session()->storeLineLinkChallenge($challenge);
        try{
            $this->app->notifications()->sendLineLinkCode($lineUserId,$code);
        }catch(HttpException $error){
            if($error->errorCode==='LINE_NOT_CONFIGURED')$this->app->session()->clearLineLinkChallenge();
            throw $error;
        }
        return ['line_user_id_hint'=>'•••'.substr($lineUserId,-6),'expires_in'=>600];
    }

    /** @return array<string,mixed> */
    public function confirmLineLink(int $id,array $input): array
    {
        Validator::only($input,['code']);
        $code=trim(is_string($input['code']??null)?$input['code']:'');
        if(preg_match('/^\d{6}$/D',$code)!==1){
            throw new HttpException(422,'กรุณากรอกรหัสยืนยัน 6 หลัก','VALIDATION_ERROR',['field'=>'code']);
        }
        $challenge=$this->app->session()->lineLinkChallenge();
        if(!is_array($challenge)||(int)($challenge['resident_id']??0)!==$id
            ||(int)($challenge['expires_at']??0)<=time()
            ||!is_string($challenge['line_user_id']??null)
            ||!is_string($challenge['nonce']??null)
            ||!is_string($challenge['digest']??null)
            ||!is_int($challenge['auth_version']??null)){
            $this->app->session()->clearLineLinkChallenge();
            throw new HttpException(410,'รหัสยืนยันหมดอายุ กรุณาขอรหัสใหม่','LINE_LINK_EXPIRED');
        }
        $attempts=(int)($challenge['attempts']??0);
        $actual=$this->lineLinkDigest($id,$challenge['line_user_id'],$code,$challenge['nonce']);
        if($attempts>=5||!hash_equals($challenge['digest'],$actual)){
            $challenge['attempts']=$attempts+1;
            if($challenge['attempts']>=5)$this->app->session()->clearLineLinkChallenge();
            else $this->app->session()->storeLineLinkChallenge($challenge);
            throw new HttpException(422,'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ','LINE_LINK_INVALID');
        }

        $pdo=$this->app->database()->pdo();
        if(!$pdo->inTransaction())throw new \RuntimeException('LINE confirmation requires a database transaction');
        $lock=$pdo->prepare('SELECT auth_version FROM residents WHERE id=? AND active=1 FOR UPDATE');
        $lock->execute([$id]);$currentVersion=$lock->fetchColumn();
        if($currentVersion===false)throw new HttpException(404,'Resident not found','RESIDENT_NOT_FOUND');
        if((int)$currentVersion!==(int)$challenge['auth_version']){
            $this->app->session()->clearLineLinkChallenge();
            throw new HttpException(409,'ข้อมูลยืนยันหมดอายุหลังมีการเปลี่ยน PIN กรุณาขอรหัสใหม่','LINE_LINK_STALE');
        }
        try{
            $update=$pdo->prepare('UPDATE residents SET line_user_id=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
            $update->execute([$challenge['line_user_id'],$id]);
        }catch(PDOException $error){
            if((int)($error->errorInfo[1]??0)===1062||(string)$error->getCode()==='23000'){
                throw new HttpException(409,'LINE User ID นี้ผูกกับผู้พักรายอื่นแล้ว','LINE_ID_IN_USE',['field'=>'line_user_id']);
            }
            throw $error;
        }
        return $this->profile($id);
    }

    /** @return array<string,mixed> */
    public function unlinkLine(int $id,array $input): array
    {
        Validator::only($input,['current_pin']);
        $this->assertCurrentPin($id,is_string($input['current_pin']??null)?$input['current_pin']:'',true);
        $statement=$this->app->database()->pdo()->prepare('UPDATE residents SET line_user_id=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
        $statement->execute([$id]);
        if($statement->rowCount()===0)$this->profile($id);
        return $this->profile($id);
    }

    private function lineLinkDigest(int $residentId,string $lineUserId,string $code,string $nonce): string
    {
        return hash_hmac('sha256',"line-link\0{$residentId}\0{$lineUserId}\0{$code}\0{$nonce}",$this->app->config->appKey());
    }

    private function assertCurrentPin(int $residentId,string $pin,bool $forUpdate=false): int
    {
        $pdo=$this->app->database()->pdo();
        if($forUpdate&&!$pdo->inTransaction())throw new \RuntimeException('Current PIN lock requires a database transaction');
        $statement=$pdo->prepare('SELECT pin_hash,auth_version FROM residents WHERE id=? AND active=1 LIMIT 1'.($forUpdate?' FOR UPDATE':''));
        $statement->execute([$residentId]);
        $row=$statement->fetch();
        if(!$row||!Password::verify($pin,(string)$row['pin_hash'])){
            throw new HttpException(401,'PIN ปัจจุบันไม่ถูกต้อง','INVALID_CURRENT_PIN');
        }
        return (int)$row['auth_version'];
    }

    public function changePin(int $id,array $input): void
    {
        Validator::only($input,['current_pin','new_pin']);$current=(string)($input['current_pin']??'');$new=(string)($input['new_pin']??'');Password::assertPin($new);
        $newHash=Password::hash($new);
        $this->app->database()->transaction(function(PDO $pdo) use($id,$current,$new,$newHash): void {
            $statement=$pdo->prepare('SELECT pin_hash FROM residents WHERE id=? AND active=1 FOR UPDATE');$statement->execute([$id]);$row=$statement->fetch();
            if(!$row||!Password::verify($current,(string)$row['pin_hash']))throw new HttpException(401,'PIN ปัจจุบันไม่ถูกต้อง','INVALID_CURRENT_PIN');
            if(hash_equals($current,$new))throw new HttpException(422,'PIN ใหม่ต้องไม่ซ้ำกับ PIN ปัจจุบัน','PIN_UNCHANGED');
            $pdo->prepare('UPDATE residents SET pin_hash=?,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1')->execute([$newHash,$id]);
        });
    }

    public function resetPin(int $id,array $input): void
    {
        Validator::only($input,['new_pin']);
        $pin=(string)($input['new_pin']??'');
        Password::assertPin($pin);
        $hash=Password::hash($pin);
        $this->app->database()->transaction(function(PDO $pdo)use($id,$hash):void{
            $statement=$pdo->prepare("SELECT r.id
                FROM residents r
                JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                WHERE r.id=? AND r.active=1
                FOR UPDATE");
            $statement->execute([$id]);
            if(!$statement->fetch())throw new HttpException(404,'ไม่พบผู้พักอาศัยปัจจุบัน','RESIDENT_NOT_FOUND');
            $update=$pdo->prepare('UPDATE residents SET pin_hash=?,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
            $update->execute([$hash,$id]);
            if($update->rowCount()!==1)throw new HttpException(409,'สถานะผู้พักเปลี่ยนแปลงแล้ว กรุณารีเฟรช','RESIDENT_CHANGED');
        });
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
            $lock=$pdo->prepare("SELECT o.id AS occupancy_id,o.room_id,o.move_in_date,rm.room_code
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
            $laterBills=$pdo->prepare('SELECT id,bill_no,period FROM bills WHERE occupancy_id=? AND period>? ORDER BY period,id FOR UPDATE');
            $laterBills->execute([$occupancy['occupancy_id'],$period]);
            $laterRows=$laterBills->fetchAll();
            if($laterRows!==[]){
                throw new HttpException(409,'มีบิลรอบเดือนหลังวันที่ย้ายออก กรุณาเลือกวันที่ให้ตรงประวัติหรือจัดการเอกสารย้อนหลังผ่านกระบวนการที่ได้รับอนุมัติ','MOVE_OUT_HAS_LATER_BILLS',[
                    'later_bill_ids'=>array_map(static fn(array $row):int=>(int)$row['id'],$laterRows),
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
            $deactivate=$pdo->prepare('UPDATE residents SET active=0,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=? AND active=1');
            $deactivate->execute([$id]);
            if($deactivate->rowCount()!==1)throw new HttpException(409,'สถานะผู้พักเปลี่ยนแปลงแล้ว กรุณารีเฟรช','RESIDENT_CHANGED');

            return [
                'resident_id'=>$id,
                'occupancy_id'=>(int)$occupancy['occupancy_id'],
                'room_id'=>(int)$occupancy['room_id'],
                'room_code'=>$occupancy['room_code'],
                'move_out_date'=>$moveOut,
                'closing_bill_id'=>(int)$paidBill['id'],
                'status'=>'ended',
            ];
        });
    }
}
