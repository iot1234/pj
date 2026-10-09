<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\Validator;
use PDO;

final class RoomService
{
    private const IMAGE_KEYS = [
        'room-standard.jpg',
        'room-deluxe.jpg',
        'room-suite.jpg',
        'room-studio.jpg',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    /** @return list<array<string,mixed>> */
    public function available(): array
    {
        $rows = $this->app->database()->pdo()->query($this->selectSql() . " WHERE r.deleted_at IS NULL AND r.rental_mode='monthly' HAVING status='available' ORDER BY r.floor,r.room_code")->fetchAll();
        return array_map($this->map(...), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->app->database()->pdo()->query($this->selectSql() . ' WHERE r.deleted_at IS NULL ORDER BY r.floor,r.room_code')->fetchAll();
        return array_map($this->map(...), $rows);
    }

    /** @return array<string,mixed> */
    public function create(array $input): array
    {
        Validator::only($input, ['room_code','floor','room_type','monthly_rent','description','amenities','image_key','rental_mode','daily_rate','max_guests','daily_deposit']);
        $data = $this->validate($input, false);
        $data += ['rental_mode'=>'monthly','daily_rate'=>null,'max_guests'=>2,'daily_deposit'=>'0.00'];
        $this->validateRentalConfiguration($data);
        $data['amenities'] ??= '[]';
        $data['description'] ??= null;
        $data['image_key'] ??= null;
        $statement = $this->app->database()->pdo()->prepare(
            'INSERT INTO rooms (room_code,floor,room_type,monthly_rent,description,amenities,image_key,rental_mode,daily_rate,max_guests,daily_deposit,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        try {
            $statement->execute([$data['room_code'],$data['floor'],$data['room_type'],$data['monthly_rent'],$data['description'],$data['amenities'],$data['image_key'],$data['rental_mode'],$data['daily_rate'],$data['max_guests'],$data['daily_deposit']]);
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new HttpException(409, 'เลขห้องนี้มีอยู่แล้ว', 'ROOM_CODE_EXISTS');
            }
            throw $error;
        }
        return $this->find((int) $this->app->database()->pdo()->lastInsertId());
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $input): array
    {
        Validator::only($input, ['room_code','floor','room_type','monthly_rent','description','amenities','image_key','rental_mode','daily_rate','max_guests','daily_deposit','expected_version']);
        $expectedVersion=null;
        if(array_key_exists('expected_version',$input)){
            $expectedVersion=Validator::string($input['expected_version'],'expected_version',64,64);
            if(!preg_match('/^[0-9a-f]{64}$/D',$expectedVersion))throw new HttpException(422,'กรุณาโหลดข้อมูลห้องล่าสุดก่อนแก้ไข','VALIDATION_ERROR',['field'=>'expected_version']);
            unset($input['expected_version']);
        }
        if ($input === []) {
            throw new HttpException(422, 'ไม่มีข้อมูลที่ต้องแก้ไข', 'NOTHING_TO_UPDATE');
        }
        $data = $this->validate($input, true);
        return $this->app->database()->transaction(function(PDO $pdo)use($id,$data,$expectedVersion):array{
        $lock=$pdo->prepare('SELECT * FROM rooms WHERE id=? AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([$id]);$current=$lock->fetch();
        if(!$current)throw new HttpException(404,'ไม่พบห้อง','ROOM_NOT_FOUND');
        if($expectedVersion!==null&&!hash_equals($this->editVersion($current),$expectedVersion))throw new HttpException(409,'ข้อมูลห้องถูกแก้ไขแล้ว กรุณาโหลดข้อมูลล่าสุดและตรวจค่าก่อนบันทึก','ROOM_VERSION_CONFLICT');
        $effective=array_replace($current,$data);$this->validateRentalConfiguration($effective);
        if($effective['rental_mode']!==$current['rental_mode']||(int)$effective['max_guests']<(int)$current['max_guests'])$this->app->dailyBookings()->expireRoomHoldsUnderLock($pdo,$id);
        if((int)$effective['max_guests']<(int)$current['max_guests']){
            $capacity=$pdo->prepare("SELECT MAX(guests) FROM daily_bookings WHERE room_id=? AND guests>? AND (status IN ('confirmed','checked_in') OR (status='pending' AND expires_at>UTC_TIMESTAMP(6)))");
            $capacity->execute([$id,(int)$effective['max_guests']]);$bookedCapacity=$capacity->fetchColumn();
            if($bookedCapacity!==null&&$bookedCapacity!==false)throw new HttpException(409,'ห้องมีผู้พักหรือการจองสำหรับ '.(int)$bookedCapacity.' คน จึงลดจำนวนผู้พักต่ำกว่านี้ไม่ได้','ROOM_CAPACITY_IN_USE',['field'=>'max_guests','minimum'=>(int)$bookedCapacity]);
        }
        if($effective['rental_mode']!==$current['rental_mode']){
            $this->app->bookings()->expireRoomHoldsUnderLock($pdo,$id);
            $this->assertNoCurrentUse($pdo,$id);
            if($current['housekeeping_status']!=='ready')throw new HttpException(409,'กรุณาตรวจห้องและทำเครื่องหมายพร้อมใช้งานก่อนเปลี่ยนประเภท','ROOM_NOT_READY');
        }
        $map = ['room_code','floor','room_type','monthly_rent','description','amenities','image_key','rental_mode','daily_rate','max_guests','daily_deposit'];
        $sets = [];
        $values = [];
        foreach ($map as $column) {
            if (array_key_exists($column, $data)) {
                $sets[] = "{$column}=?";
                $values[] = $data[$column];
            }
        }
        $values[] = $id;
        try {
            $statement = $this->app->database()->pdo()->prepare('UPDATE rooms SET ' . implode(',', $sets) . ',updated_at=UTC_TIMESTAMP() WHERE id=? AND deleted_at IS NULL');
            $statement->execute($values);
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new HttpException(409, 'เลขห้องนี้มีอยู่แล้ว', 'ROOM_CODE_EXISTS');
            }
            throw $error;
        }
        if ($statement->rowCount() === 0) {
            $this->find($id);
        }
        return $this->find($id);
        });
    }

    public function delete(int $id): void
    {
        $this->app->database()->transaction(function (PDO $pdo) use ($id): void {
            $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetch()) {
                throw new HttpException(404, 'ไม่พบห้อง', 'ROOM_NOT_FOUND');
            }
            $this->app->bookings()->expireRoomHoldsUnderLock($pdo,$id);
            $this->app->dailyBookings()->expireRoomHoldsUnderLock($pdo,$id);
            $this->assertNoCurrentUse($pdo,$id);
            $delete = $pdo->prepare('UPDATE rooms SET deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?');
            $delete->execute([$id]);
        });
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $statement = $this->app->database()->pdo()->prepare($this->selectSql() . ' WHERE r.id=? AND r.deleted_at IS NULL LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) {
            throw new HttpException(404, 'ไม่พบห้อง', 'ROOM_NOT_FOUND');
        }
        return $this->map($row);
    }

    /** @return array<string,mixed> */
    private function validate(array $input, bool $partial): array
    {
        $out = [];
        $required = ['room_code','floor','room_type'];
        if(!$partial&&($input['rental_mode']??'monthly')!=='daily')$required[]='monthly_rent';
        foreach ($required as $field) {
            if (!$partial && !array_key_exists($field, $input)) {
                throw new HttpException(422, "ต้องระบุ {$field}", 'VALIDATION_ERROR', ['field' => $field]);
            }
        }
        if (array_key_exists('room_code', $input)) {
            $code = strtoupper(Validator::string($input['room_code'], 'room_code', 1, 32));
            if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
                throw new HttpException(422, 'เลขห้องใช้ได้เฉพาะ A-Z, 0-9, _ และ -', 'VALIDATION_ERROR', ['field' => 'room_code']);
            }
            $out['room_code'] = $code;
        }
        if (array_key_exists('floor', $input)) {
            $floor = Validator::id($input['floor'], 'floor');
            if ($floor > 200) throw new HttpException(422, 'ชั้นไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'floor']);
            $out['floor'] = $floor;
        }
        if (array_key_exists('room_type', $input)) $out['room_type'] = Validator::string($input['room_type'], 'room_type', 1, 50);
        if (array_key_exists('monthly_rent', $input)) {
            $rent=Validator::scaledDecimal($input['monthly_rent'], 'monthly_rent');
            if($rent>100_000_000) throw new HttpException(422,'ค่าเช่าต้องไม่เกิน 1,000,000.00 บาท','VALIDATION_ERROR',['field'=>'monthly_rent']);
            $out['monthly_rent'] = Validator::decimalString($rent);
        }
        if(!$partial&&!isset($out['monthly_rent']))$out['monthly_rent']='0.00';
        if(array_key_exists('rental_mode',$input)){
            if(!is_string($input['rental_mode'])||!in_array($input['rental_mode'],['monthly','daily'],true))throw new HttpException(422,'เลือกประเภทการให้เช่าให้ถูกต้อง','VALIDATION_ERROR',['field'=>'rental_mode']);
            $out['rental_mode']=$input['rental_mode'];
        }
        if(array_key_exists('daily_rate',$input)){
            $rate=$input['daily_rate']===null?null:Validator::scaledDecimal($input['daily_rate'],'daily_rate');
            if($rate!==null&&($rate<=0||$rate>100_000_000))throw new HttpException(422,'ราคาต่อคืนต้องมากกว่า 0 และไม่เกิน 1,000,000 บาท','VALIDATION_ERROR',['field'=>'daily_rate']);
            $out['daily_rate']=$rate===null?null:Validator::decimalString($rate);
        }
        if(array_key_exists('daily_deposit',$input)){
            $deposit=Validator::scaledDecimal($input['daily_deposit'],'daily_deposit');
            if($deposit>100_000_000)throw new HttpException(422,'เงินประกันต้องไม่เกิน 1,000,000 บาท','VALIDATION_ERROR',['field'=>'daily_deposit']);
            $out['daily_deposit']=Validator::decimalString($deposit);
        }
        if(array_key_exists('max_guests',$input)){
            $guests=Validator::id($input['max_guests'],'max_guests');
            if($guests>20)throw new HttpException(422,'จำนวนผู้พักต้องอยู่ระหว่าง 1–20 คน','VALIDATION_ERROR',['field'=>'max_guests']);
            $out['max_guests']=$guests;
        }
        if (array_key_exists('description', $input)) {
            if($input['description']===null)$out['description']=null;
            else{
                $description=Validator::string($input['description'], 'description', 0, 2000);
                $out['description']=$description===''?null:$description;
            }
        }
        if (array_key_exists('amenities', $input)) {
            if (!is_array($input['amenities']) || count($input['amenities']) > 30) throw new HttpException(422, 'amenities ไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'amenities']);
            $amenities = [];
            foreach ($input['amenities'] as $amenity) $amenities[] = Validator::string($amenity, 'amenities', 1, 80);
            $out['amenities'] = json_encode(array_values(array_unique($amenities)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('image_key', $input)) {
            if($input['image_key']!==null&&!is_string($input['image_key']))throw new HttpException(422, 'image_key ไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'image_key']);
            $key = is_string($input['image_key'])?trim($input['image_key']):'';
            if ($key !== '' && !in_array($key, self::IMAGE_KEYS, true)) {
                throw new HttpException(422, 'image_key ไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'image_key']);
            }
            $out['image_key'] = $key === '' ? null : $key;
        }
        return $out;
    }

    private function selectSql(): string
    {
        $holdSeconds=$this->app->config->intInRange('BOOKING_HOLD_SECONDS',86400,900,604800);
        $today=$this->databaseToday($this->app->database()->pdo());
        return "SELECT r.id,r.room_code,r.floor,r.room_type,r.monthly_rent,r.description,r.amenities,r.image_key,r.rental_mode,r.daily_rate,r.max_guests,r.daily_deposit,r.housekeeping_status,r.housekeeping_version,
            (EXISTS(SELECT 1 FROM occupancies oi WHERE oi.room_id=r.id AND oi.status='active')
             OR EXISTS(SELECT 1 FROM bookings bi WHERE bi.room_id=r.id AND (bi.status='confirmed' OR (bi.status='pending' AND bi.created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$holdSeconds} SECOND))))
             OR EXISTS(SELECT 1 FROM daily_bookings di WHERE di.room_id=r.id AND (di.status IN ('confirmed','checked_in') OR (di.status='checked_out' AND di.check_out_date>'{$today}') OR (di.status='pending' AND di.expires_at>UTC_TIMESTAMP(6) AND di.check_out_date>'{$today}')))
             OR EXISTS(SELECT 1 FROM daily_room_blocks ri WHERE ri.room_id=r.id AND ri.active=1 AND ri.end_date>'{$today}')) AS in_use,
            CASE
              WHEN EXISTS(SELECT 1 FROM occupancies o WHERE o.room_id=r.id AND o.status='active') THEN 'occupied'
              WHEN EXISTS(SELECT 1 FROM daily_bookings d WHERE d.room_id=r.id AND d.status='checked_in') THEN 'occupied'
              WHEN EXISTS(SELECT 1 FROM bookings b WHERE b.room_id=r.id AND (b.status='confirmed' OR (b.status='pending' AND b.created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$holdSeconds} SECOND)))) THEN 'reserved'
              WHEN EXISTS(SELECT 1 FROM daily_bookings d WHERE d.room_id=r.id AND d.check_in_date<='{$today}' AND d.check_out_date>'{$today}' AND (d.status IN ('confirmed','checked_out') OR (d.status='pending' AND d.expires_at>UTC_TIMESTAMP(6)))) THEN 'reserved'
              ELSE 'available'
            END AS status
          FROM rooms r";
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function map(array $row): array
    {
        $amenities = is_string($row['amenities'] ?? null) ? json_decode($row['amenities'], true) : ($row['amenities'] ?? []);
        $imageKey = $row['image_key'] ?: null;
        return [
            'id'=>(int)$row['id'],'room_code'=>$row['room_code'],'floor'=>(int)$row['floor'],
            'room_type'=>$row['room_type'],'monthly_rent'=>(string)$row['monthly_rent'],
            'rental_mode'=>$row['rental_mode'],'daily_rate'=>$row['daily_rate']===null?null:(string)$row['daily_rate'],
            'max_guests'=>(int)$row['max_guests'],'daily_deposit'=>(string)$row['daily_deposit'],'housekeeping_status'=>$row['housekeeping_status'],
            'housekeeping_version'=>(int)$row['housekeeping_version'],
            'room_version'=>$this->editVersion($row),'in_use'=>(bool)($row['in_use']??false),'can_delete'=>!(bool)($row['in_use']??false),
            'description'=>$row['description'],'amenities'=>is_array($amenities)?$amenities:[],
            'image_key'=>$imageKey,'image_url'=>$imageKey ? '/assets/images/rooms/' . implode('/', array_map('rawurlencode', explode('/', $imageKey))) : null,
            'status'=>$row['status'],
        ];
    }

    private function validateRentalConfiguration(array $data): void
    {
        if(($data['rental_mode']??'monthly')==='monthly'&&Validator::scaledDecimal($data['monthly_rent'],'monthly_rent')<=0)throw new HttpException(422,'กรุณาระบุค่าเช่ารายเดือนมากกว่า 0','VALIDATION_ERROR',['field'=>'monthly_rent']);
        if(($data['rental_mode']??'monthly')==='daily'&&(($data['daily_rate']??null)===null||Validator::scaledDecimal($data['daily_rate'],'daily_rate')<=0))throw new HttpException(422,'กรุณาระบุราคาต่อคืนก่อนเปิดจองรายวัน','VALIDATION_ERROR',['field'=>'daily_rate']);
    }

    private function editVersion(array $row): string
    {
        $fields=[];
        foreach(['id','room_code','floor','room_type','monthly_rent','description','amenities','image_key','rental_mode','daily_rate','max_guests','daily_deposit']as$key){
            $value=$row[$key]??null;
            if($key==='amenities'&&is_string($value))$value=json_decode($value,true,512,JSON_THROW_ON_ERROR);
            if(in_array($key,['id','floor','max_guests'],true)&&$value!==null)$value=(int)$value;
            $fields[$key]=$value;
        }
        return hash_hmac('sha256',"room-catalogue:v1\0".json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$this->app->config->appKey());
    }

    private function assertNoCurrentUse(PDO $pdo,int $roomId): void
    {
        $hold=$this->app->config->intInRange('BOOKING_HOLD_SECONDS',86400,900,604800);
        $today=$this->databaseToday($pdo);
        $q=$pdo->prepare("SELECT
          EXISTS(SELECT 1 FROM occupancies WHERE room_id=? AND status='active') AS occupied,
          EXISTS(SELECT 1 FROM bookings WHERE room_id=? AND (status='confirmed' OR (status='pending' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$hold} SECOND)))) AS reserved,
          EXISTS(SELECT 1 FROM daily_bookings WHERE room_id=? AND (status IN ('confirmed','checked_in') OR (status='checked_out' AND check_out_date>?) OR (status='pending' AND expires_at>UTC_TIMESTAMP(6) AND check_out_date>?))) AS daily_reserved,
          EXISTS(SELECT 1 FROM daily_room_blocks WHERE room_id=? AND active=1 AND end_date>?) AS blocked");
        $q->execute([$roomId,$roomId,$roomId,$today,$today,$roomId,$today]);$state=$q->fetch();
        if((bool)$state['occupied']||(bool)$state['reserved']||(bool)$state['daily_reserved']||(bool)$state['blocked'])throw new HttpException(409,'ห้องมีผู้พัก การจอง หรือช่วงปิดขายอยู่ กรุณาจัดการรายการเดิมก่อน','ROOM_IN_USE');
    }

    /** Daily inventory and expiry share MySQL's clock, including across local midnight. */
    private function databaseToday(PDO $pdo): string
    {
        $now=new \DateTimeImmutable((string)$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(),new \DateTimeZone('UTC'));
        return $now->setTimezone(new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok')))->format('Y-m-d');
    }
}
