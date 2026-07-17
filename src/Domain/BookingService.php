<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Support\Validator;
use PDO;

final class BookingService
{
    public function __construct(private readonly Application $app)
    {
    }

    /** @return array<string,mixed> */
    public function createPublic(array $input): array
    {
        return $this->resolveOutcome($this->createPublicOutcome($input));
    }

    /**
     * Route-level transactions use this outcome form so an automatic expiry
     * can commit before the HTTP conflict is raised outside the transaction.
     * @return array<string,mixed>
     */
    public function createPublicOutcome(array $input): array
    {
        Validator::only($input, ['room_id','full_name','phone','idempotency_key']);
        $roomId = Validator::id($input['room_id'] ?? null, 'room_id');
        $fullName = Validator::string($input['full_name'] ?? null, 'full_name', 2, 120);
        $phone = Validator::phone($input['phone'] ?? null);
        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotency)) {
            throw new HttpException(422, 'idempotency_key ไม่ถูกต้อง', 'VALIDATION_ERROR', ['field'=>'idempotency_key']);
        }

        $this->expirePending();

        return $this->app->database()->transaction(function (PDO $pdo) use ($roomId,$fullName,$phone,$idempotency): array {
            // Do not gap-lock a missing idempotency key before locking the
            // room: concurrent bookings for one room could otherwise deadlock.
            $existing = $pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=? LIMIT 1');
            $lockedExisting=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=? LIMIT 1 FOR UPDATE');
            $existing->execute([$idempotency]);
            if ($row = $existing->fetch()) {
                return $this->replay($pdo,$row,$roomId,$fullName,$phone);
            }
            $room = $pdo->prepare('SELECT id,monthly_rent FROM rooms WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $room->execute([$roomId]);
            $roomRow=$room->fetch();if (!$roomRow) throw new HttpException(404, 'ไม่พบห้อง', 'ROOM_NOT_FOUND');
            $this->expirePending($roomId);

            // The room lock serializes same-room requests. Recheck after a
            // possible wait with a current locking read so a same-key replay
            // returns its original row even under REPEATABLE READ.
            $lockedExisting->execute([$idempotency]);
            if ($row = $lockedExisting->fetch()) {
                return $this->replay($pdo,$row,$roomId,$fullName,$phone);
            }

            $occupied = $pdo->prepare("SELECT id FROM occupancies WHERE room_id=? AND status='active' LIMIT 1 FOR UPDATE");
            $occupied->execute([$roomId]);
            $reserved = $pdo->prepare("SELECT id FROM bookings WHERE room_id=? AND status IN ('pending','confirmed') LIMIT 1 FOR UPDATE");
            $reserved->execute([$roomId]);
            if ($occupied->fetch() || $reserved->fetch()) {
                throw new HttpException(409, 'ห้องนี้ไม่ว่างแล้ว กรุณาเลือกห้องอื่น', 'ROOM_NOT_AVAILABLE');
            }
            // Invalid/non-available room probes must not exhaust a victim's
            // phone bucket. Idempotent replays returned above do not consume
            // it either. The enclosing transaction makes the limit and the
            // successfully created reservation one atomic outcome.
            $this->app->limiter()->hit('public-booking-phone',$phone,2,86400);
            $reference = 'BK-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
            try {
                $insert = $pdo->prepare("INSERT INTO bookings (reference_no,room_id,full_name,phone_norm,booked_monthly_rent,status,idempotency_key,created_at,updated_at) VALUES (?,?,?,?,?,'pending',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $insert->execute([$reference,$roomId,$fullName,$phone,$roomRow['monthly_rent'],$idempotency]);
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) {
                    $conflict=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=? LIMIT 1 FOR UPDATE');
                    $conflict->execute([$idempotency]);
                    if($row=$conflict->fetch()){
                        return $this->replay($pdo,$row,$roomId,$fullName,$phone);
                    }
                    throw new HttpException(409, 'ห้องนี้ถูกจองพร้อมกันโดยผู้ใช้อื่น', 'ROOM_NOT_AVAILABLE');
                }
                throw $error;
            }
            $created=$pdo->prepare('SELECT * FROM bookings WHERE id=?');
            $created->execute([(int)$pdo->lastInsertId()]);
            return $this->map($created->fetch());
        });
    }

    /** @return array{items:list<array<string,mixed>>,has_more:bool,next_offset:int,pending_count:int} */
    public function all(?string $status=null,int $offset=0,int $limit=100): array
    {
        if($offset<0||$offset>1000000||$limit<1||$limit>200)throw new HttpException(422,'Invalid booking pagination','VALIDATION_ERROR');
        $this->expirePending();
        $params=[];$where='';
        if($status!==null&&$status!==''){
            if(!in_array($status,['pending','confirmed','cancelled','moved_in'],true))throw new HttpException(422,'Invalid booking status','VALIDATION_ERROR');
            $where=' WHERE b.status=?';$params[]=$status;
        }
        $pageSize=$limit+1;
        $statement=$this->app->database()->pdo()->prepare(
            "SELECT b.*,r.room_code,r.monthly_rent,au.username AS confirmed_by_username,
                    existing.id AS existing_resident_id,existing.full_name AS existing_resident_name,
                    existing.email AS existing_resident_email,existing.line_user_id AS existing_resident_line_user_id
               FROM bookings b JOIN rooms r ON r.id=b.room_id
               LEFT JOIN admin_users au ON au.id=b.confirmed_by
               LEFT JOIN residents existing ON existing.phone_norm=b.phone_norm".$where.
              " ORDER BY (b.status IN ('pending','confirmed')) DESC,b.created_at DESC,b.id DESC LIMIT {$pageSize} OFFSET {$offset}"
        );
        $statement->execute($params);$rows=$statement->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
        $pendingCount=(int)$this->app->database()->pdo()->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
        return ['items'=>array_map($this->map(...),$rows),'has_more'=>$hasMore,'next_offset'=>$offset+count($rows),'pending_count'=>$pendingCount];
    }

    /** @return array<string,mixed> */
    public function confirm(int $id, int $adminId): array
    {
        return $this->resolveOutcome($this->confirmOutcome($id,$adminId));
    }

    /** @return array<string,mixed> */
    public function confirmOutcome(int $id, int $adminId): array
    {
        return $this->transition($id, ['pending'], 'confirmed', function (PDO $pdo, array $booking) use ($adminId): void {
            $statement = $pdo->prepare("UPDATE bookings SET status='confirmed',confirmed_by=?,confirmed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?");
            $statement->execute([$adminId,$booking['id']]);
        });
    }

    /** @return array<string,mixed> */
    public function cancel(int $id, int $adminId, ?string $reason): array
    {
        $reason = $reason !== null && trim($reason) !== '' ? Validator::string($reason, 'reason', 3, 500) : null;
        return $this->transition($id, ['pending','confirmed'], 'cancelled', function (PDO $pdo, array $booking) use ($adminId,$reason): void {
            $statement = $pdo->prepare("UPDATE bookings SET status='cancelled',cancelled_by=?,cancelled_at=UTC_TIMESTAMP(),cancel_reason=?,updated_at=UTC_TIMESTAMP() WHERE id=?");
            $statement->execute([$adminId,$reason,$booking['id']]);
        });
    }

    /** @return array<string,mixed> */
    public function moveIn(int $id, int $adminId, array $input): array
    {
        Validator::only($input, ['pin','email','move_in_date','reuse_resident_id']);
        $pin = (string) ($input['pin'] ?? '');
        Password::assertPin($pin);
        $emailProvided=array_key_exists('email',$input);
        $email=$emailProvided?Validator::nullableEmail($input['email']):null;
        $moveIn = Validator::date($input['move_in_date'] ?? null, 'move_in_date');
        $timezone=new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok'));
        if($moveIn>(new \DateTimeImmutable('today',$timezone))->format('Y-m-d')){
            throw new HttpException(422,'move_in_date cannot be in the future','VALIDATION_ERROR',['field'=>'move_in_date']);
        }
        $reuseResidentId=array_key_exists('reuse_resident_id',$input)?Validator::id($input['reuse_resident_id'],'reuse_resident_id'):null;

        return $this->app->database()->transaction(function (PDO $pdo) use ($id,$adminId,$pin,$email,$emailProvided,$moveIn,$reuseResidentId,$timezone): array {
            $lock = $pdo->prepare("SELECT b.*,r.monthly_rent,r.deleted_at FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.id=? FOR UPDATE");
            $lock->execute([$id]);
            $booking = $lock->fetch();
            if (!$booking) throw new HttpException(404, 'ไม่พบการจอง', 'BOOKING_NOT_FOUND');
            if ($booking['status'] !== 'confirmed') throw new HttpException(409, 'ต้องยืนยันการจองก่อนย้ายเข้า', 'BOOKING_BAD_STATE', ['status'=>$booking['status']]);
            if ($booking['deleted_at'] !== null) throw new HttpException(409, 'ห้องนี้ถูกลบแล้ว', 'ROOM_DELETED');
            $bookedDate=(new \DateTimeImmutable((string)$booking['created_at'],new \DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
            if($moveIn<$bookedDate)throw new HttpException(422,'move_in_date cannot be before the booking date','VALIDATION_ERROR',['field'=>'move_in_date']);

            $occupied = $pdo->prepare("SELECT id FROM occupancies WHERE room_id=? AND status='active' LIMIT 1 FOR UPDATE");
            $occupied->execute([$booking['room_id']]);
            if ($occupied->fetch()) throw new HttpException(409, 'ห้องนี้มีผู้เช่าแล้ว', 'ROOM_OCCUPIED');

            // Monthly meters cannot be split safely between two occupancies.
            // A room whose previous occupancy ended in this month can accept
            // the next resident from the first day of the following month.
            $periodStart=substr($moveIn,0,7).'-01';
            $nextPeriod=(new \DateTimeImmutable($periodStart))->modify('first day of next month')->format('Y-m-d');
            $turnover=$pdo->prepare("SELECT id,move_out_date FROM occupancies WHERE room_id=? AND status='ended' AND move_out_date>=? AND move_out_date<? ORDER BY move_out_date DESC LIMIT 1 FOR UPDATE");
            $turnover->execute([$booking['room_id'],$periodStart,$nextPeriod]);
            if($prior=$turnover->fetch()){
                throw new HttpException(409,"ห้องนี้ปิดรอบผู้พักเดิมในเดือนเดียวกัน กรุณารับเข้าพักตั้งแต่ {$nextPeriod}",'MOVE_IN_PERIOD_CONFLICT',['previous_occupancy_id'=>(int)$prior['id'],'previous_move_out_date'=>$prior['move_out_date'],'earliest_move_in_date'=>$nextPeriod]);
            }

            $residentLookup = $pdo->prepare('SELECT * FROM residents WHERE phone_norm=? LIMIT 1 FOR UPDATE');
            $residentLookup->execute([$booking['phone_norm']]);
            $resident = $residentLookup->fetch();
            try { if ($resident) {
                $residentId = (int) $resident['id'];
                if($reuseResidentId!==$residentId){
                    throw new HttpException(409,'This phone belongs to a historical resident. Confirm the same identity before linking prior bill history.','RESIDENT_REUSE_CONFIRMATION_REQUIRED',['resident_id'=>$residentId,'resident_name'=>$resident['full_name']]);
                }
                $hasOccupancy = $pdo->prepare("SELECT id FROM occupancies WHERE resident_id=? AND status='active' LIMIT 1 FOR UPDATE");
                $hasOccupancy->execute([$residentId]);
                if ($hasOccupancy->fetch()) throw new HttpException(409, 'เบอร์นี้ผูกกับผู้เช่าที่มีห้องอยู่แล้ว', 'RESIDENT_ALREADY_OCCUPIED');
                $residentEmail=$emailProvided?$email:$resident['email'];
                $update = $pdo->prepare('UPDATE residents SET full_name=?,email=?,line_user_id=?,pin_hash=?,active=1,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?');
                // A returning resident must prove control of the LINE account
                // again instead of inheriting a potentially stale binding.
                $update->execute([$booking['full_name'],$residentEmail,null,Password::hash($pin),$residentId]);
            } else {
                $insertResident = $pdo->prepare('INSERT INTO residents (full_name,phone_norm,email,pin_hash,line_user_id,auth_version,active,created_at,updated_at) VALUES (?,?,?,?,?,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                $insertResident->execute([$booking['full_name'],$booking['phone_norm'],$email,Password::hash($pin),null]);
                $residentId = (int) $pdo->lastInsertId();
            }} catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) throw new HttpException(409,'Phone or LINE account is already assigned to another resident','RESIDENT_IDENTITY_CONFLICT');
                throw $error;
            }
            try {
                $occupancy = $pdo->prepare("INSERT INTO occupancies (resident_id,room_id,booking_id,monthly_rent,status,move_in_date,created_at,updated_at) VALUES (?,?,?,?,'active',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $occupancy->execute([$residentId,$booking['room_id'],$id,$booking['booked_monthly_rent'],$moveIn]);
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null) === 1062) throw new HttpException(409, 'ห้องหรือผู้เช่าถูกผูกใช้งานแล้ว', 'OCCUPANCY_CONFLICT');
                throw $error;
            }
            $occupancyId = (int) $pdo->lastInsertId();
            $updateBooking = $pdo->prepare("UPDATE bookings SET status='moved_in',resident_id=?,moved_in_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?");
            $updateBooking->execute([$residentId,$id]);
            return [
                'booking_id'=>$id,'status'=>'moved_in','resident_id'=>$residentId,
                'occupancy_id'=>$occupancyId,'room_id'=>(int)$booking['room_id'],'move_in_date'=>$moveIn,
            ];
        });
    }

    /** @param list<string> $allowed
     *  @return array<string,mixed>
     */
    private function transition(int $id, array $allowed, string $target, callable $mutation): array
    {
        return $this->app->database()->transaction(function (PDO $pdo) use ($id,$allowed,$target,$mutation): array {
            $lock = $pdo->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $booking = $lock->fetch();
            if (!$booking) throw new HttpException(404, 'ไม่พบการจอง', 'BOOKING_NOT_FOUND');
            $room = $pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');
            $room->execute([$booking['room_id']]);
            if($target==='confirmed'&&$booking['status']==='cancelled'&&$this->wasAutomaticallyExpired($booking)){
                return $this->errorOutcome(409,'Booking hold expired; refresh the booking list','BOOKING_EXPIRED');
            }
            if (!in_array($booking['status'], $allowed, true)) {
                throw new HttpException(409, 'เปลี่ยนสถานะการจองจากสถานะปัจจุบันไม่ได้', 'BOOKING_BAD_STATE', ['status'=>$booking['status'],'target'=>$target]);
            }
            if($target==='confirmed'&&$this->isPendingExpired($pdo,$booking)){
                $this->cancelExpiredBooking($pdo,$booking);
                return $this->errorOutcome(409,'Booking hold expired; refresh the booking list','BOOKING_EXPIRED');
            }
            $mutation($pdo, $booking);
            $fresh=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$fresh->execute([$id]);
            return $this->map($fresh->fetch());
        });
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function map(array $row): array
    {
        $expiresAt=null;
        if(($row['status']??null)==='pending'&&isset($row['created_at'])){
            $created=new \DateTimeImmutable((string)$row['created_at'],new \DateTimeZone('UTC'));
            $expiresAt=$created->modify('+'.$this->bookingHoldSeconds().' seconds')->format('Y-m-d\TH:i:s\Z');
        }
        return [
            'id'=>(int)$row['id'],'reference_no'=>$row['reference_no'],'reference'=>$row['reference_no'],'room_id'=>(int)$row['room_id'],
            'room_code'=>$row['room_code']??null,'full_name'=>$row['full_name'],'phone'=>$row['phone_norm'],
            'status'=>$row['status'],'confirmed_at'=>$row['confirmed_at']??null,
            'cancelled_at'=>$row['cancelled_at']??null,'cancel_reason'=>$row['cancel_reason']??null,
            'moved_in_at'=>$row['moved_in_at']??null,'resident_id'=>isset($row['resident_id'])?(int)$row['resident_id']:null,
            'created_at'=>$row['created_at']??null,'expires_at'=>$expiresAt,
            'booked_monthly_rent'=>isset($row['booked_monthly_rent'])?(string)$row['booked_monthly_rent']:null,
            'existing_resident'=>isset($row['existing_resident_id'])&&$row['existing_resident_id']!==null?[
                'id'=>(int)$row['existing_resident_id'],'full_name'=>$row['existing_resident_name'],
                'email'=>$row['existing_resident_email']??null,'line_user_id'=>$row['existing_resident_line_user_id']??null,
            ]:null,
        ];
    }

    /** @return array<string,mixed> */
    private function replay(PDO $pdo,array $candidate,int $roomId,string $fullName,string $phone): array
    {
        $lock=$pdo->prepare('SELECT * FROM bookings WHERE id=? FOR UPDATE');
        $lock->execute([(int)$candidate['id']]);
        $row=$lock->fetch();
        if(!$row)return $this->errorOutcome(409,'Booking changed while replaying the request','BOOKING_INACTIVE');
        if((int)$row['room_id']!==$roomId||!hash_equals((string)$row['full_name'],$fullName)||!hash_equals((string)$row['phone_norm'],$phone)){
            return $this->errorOutcome(409,'Idempotency key was already used for a different booking','IDEMPOTENCY_KEY_REUSED');
        }
        if($row['status']==='pending'&&$this->isPendingExpired($pdo,$row)){
            $this->cancelExpiredBooking($pdo,$row);
            return $this->errorOutcome(409,'Booking hold expired; submit a new request','BOOKING_EXPIRED');
        }
        if(!in_array($row['status'],['pending','confirmed'],true)){
            $expired=$row['status']==='cancelled'&&$this->wasAutomaticallyExpired($row);
            return $this->errorOutcome(409,$expired?'Booking hold expired; submit a new request':'Booking is no longer active; submit a new request',$expired?'BOOKING_EXPIRED':'BOOKING_INACTIVE');
        }
        return $this->map($row);
    }

    private function expirePending(?int $roomId=null): void
    {
        $seconds=$this->bookingHoldSeconds();
        $reason=$this->automaticExpiryReason();
        $roomSql=$roomId===null?'':' AND room_id=?';
        $statement=$this->app->database()->pdo()->prepare("UPDATE bookings
            SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),cancel_reason=?,updated_at=UTC_TIMESTAMP()
            WHERE status='pending' AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$seconds} SECOND){$roomSql}");
        $parameters=[$reason];if($roomId!==null)$parameters[]=$roomId;
        $statement->execute($parameters);
    }

    /** @param array<string,mixed> $booking */
    private function cancelExpiredBooking(PDO $pdo,array $booking): void
    {
        $statement=$pdo->prepare("UPDATE bookings SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),cancel_reason=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
        $statement->execute([$this->automaticExpiryReason(),(int)$booking['id']]);
    }

    /** @param array<string,mixed> $booking */
    private function wasAutomaticallyExpired(array $booking): bool
    {
        return str_starts_with((string)($booking['cancel_reason']??''),'Automatically expired after ');
    }

    private function automaticExpiryReason(): string
    {
        return 'Automatically expired after '.$this->bookingHoldSeconds().' seconds';
    }

    /** @return array<string,mixed> */
    private function errorOutcome(int $status,string $message,string $code,array $details=[]): array
    {
        return ['_booking_error'=>['status'=>$status,'message'=>$message,'code'=>$code,'details'=>$details]];
    }

    /** @param array<string,mixed> $outcome */
    public function isErrorOutcome(array $outcome): bool
    {
        return is_array($outcome['_booking_error']??null);
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    public function resolveOutcome(array $outcome): array
    {
        if(!$this->isErrorOutcome($outcome))return $outcome;
        $error=$outcome['_booking_error'];
        throw new HttpException((int)($error['status']??409),(string)($error['message']??'Booking conflict'),(string)($error['code']??'BOOKING_CONFLICT'),is_array($error['details']??null)?$error['details']:[]);
    }

    /** @param array<string,mixed> $booking */
    private function isPendingExpired(PDO $pdo,array $booking): bool
    {
        if(($booking['status']??null)!=='pending'||!isset($booking['created_at']))return false;
        $seconds=$this->bookingHoldSeconds();
        $statement=$pdo->prepare("SELECT created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$seconds} SECOND) FROM bookings WHERE id=?");
        $statement->execute([(int)$booking['id']]);
        return (bool)$statement->fetchColumn();
    }

    private function bookingHoldSeconds(): int
    {
        return $this->app->config->intInRange('BOOKING_HOLD_SECONDS',86400,900,604800);
    }
}
