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
        $rows = $this->app->database()->pdo()->query($this->selectSql() . " WHERE r.deleted_at IS NULL HAVING status='available' ORDER BY r.floor,r.room_code")->fetchAll();
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
        Validator::only($input, ['room_code','floor','room_type','monthly_rent','description','amenities','image_key']);
        $data = $this->validate($input, false);
        $data['amenities'] ??= '[]';
        $data['description'] ??= null;
        $data['image_key'] ??= null;
        $statement = $this->app->database()->pdo()->prepare(
            'INSERT INTO rooms (room_code,floor,room_type,monthly_rent,description,amenities,image_key,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        try {
            $statement->execute([$data['room_code'],$data['floor'],$data['room_type'],$data['monthly_rent'],$data['description'],$data['amenities'],$data['image_key']]);
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
        Validator::only($input, ['room_code','floor','room_type','monthly_rent','description','amenities','image_key']);
        if ($input === []) {
            throw new HttpException(422, 'ไม่มีข้อมูลที่ต้องแก้ไข', 'NOTHING_TO_UPDATE');
        }
        $data = $this->validate($input, true);
        $map = ['room_code','floor','room_type','monthly_rent','description','amenities','image_key'];
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
    }

    public function delete(int $id): void
    {
        $this->app->database()->transaction(function (PDO $pdo) use ($id): void {
            $lock = $pdo->prepare('SELECT id FROM rooms WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetch()) {
                throw new HttpException(404, 'ไม่พบห้อง', 'ROOM_NOT_FOUND');
            }
            $holdSeconds=$this->app->config->intInRange('BOOKING_HOLD_SECONDS',86400,900,604800);
            $refs = $pdo->prepare("SELECT
                EXISTS(SELECT 1 FROM occupancies WHERE room_id=? AND status='active') AS occupied,
                EXISTS(SELECT 1 FROM bookings WHERE room_id=? AND (status='confirmed' OR (status='pending' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$holdSeconds} SECOND)))) AS reserved");
            $refs->execute([$id,$id]);
            $state = $refs->fetch();
            if ((bool) $state['occupied'] || (bool) $state['reserved']) {
                throw new HttpException(409, 'ลบห้องที่มีผู้เช่าหรือการจองอยู่ไม่ได้', 'ROOM_IN_USE');
            }
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
        $required = ['room_code','floor','room_type','monthly_rent'];
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
            if($rent<=0||$rent>100_000_000) throw new HttpException(422,'ค่าเช่าต้องมากกว่า 0 และไม่เกิน 1,000,000.00 บาท','VALIDATION_ERROR',['field'=>'monthly_rent']);
            $out['monthly_rent'] = Validator::decimalString($rent);
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
        return "SELECT r.id,r.room_code,r.floor,r.room_type,r.monthly_rent,r.description,r.amenities,r.image_key,
            CASE
              WHEN EXISTS(SELECT 1 FROM occupancies o WHERE o.room_id=r.id AND o.status='active') THEN 'occupied'
              WHEN EXISTS(SELECT 1 FROM bookings b WHERE b.room_id=r.id AND (b.status='confirmed' OR (b.status='pending' AND b.created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$holdSeconds} SECOND)))) THEN 'reserved'
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
            'description'=>$row['description'],'amenities'=>is_array($amenities)?$amenities:[],
            'image_key'=>$imageKey,'image_url'=>$imageKey ? '/assets/images/rooms/' . implode('/', array_map('rawurlencode', explode('/', $imageKey))) : null,
            'status'=>$row['status'],
        ];
    }
}
