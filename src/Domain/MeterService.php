<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\Validator;
use PDO;

final class MeterService
{
    private const MAX_READING_SCALED = 999_999_900; // 9,999,999.00
    private const LARGE_USAGE_SCALED = 1_000_000; // 10,000.00 units/month

    public function __construct(private readonly Application $app)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(string $period): array
    {
        $periodDate = Validator::periodDate($period);
        $statement = $this->app->database()->pdo()->prepare(
            "SELECT r.id AS room_id,r.room_code,mt.meter_type,
                    m.previous_reading,m.current_reading,m.units_used,m.updated_at,
                    (SELECT history.current_reading
                       FROM meter_readings history
                      WHERE history.room_id=r.id
                        AND history.meter_type=mt.meter_type
                        AND history.period<?
                      ORDER BY history.period DESC LIMIT 1) AS prior_current
               FROM rooms r
               CROSS JOIN (
                   SELECT 'water' AS meter_type
                   UNION ALL SELECT 'electric'
               ) mt
               LEFT JOIN meter_readings m
                 ON m.room_id=r.id AND m.meter_type=mt.meter_type AND m.period=?
              WHERE r.deleted_at IS NULL
              ORDER BY r.floor,r.room_code,FIELD(mt.meter_type,'water','electric')"
        );
        $statement->execute([$periodDate,$periodDate]);
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['room_id'];
            $grouped[$id] ??= ['room_id'=>$id,'room_code'=>$row['room_code'],'period'=>$period,'water'=>null,'electric'=>null,
                'water_previous'=>null,'water_current'=>null,'water_units'=>null,'electric_previous'=>null,'electric_current'=>null,'electric_units'=>null];
            if ($row['meter_type']) {
                $hasCurrent=$row['current_reading']!==null;
                $previous=$hasCurrent?$row['previous_reading']:$row['prior_current'];
                if($hasCurrent){
                $grouped[$id][$row['meter_type']] = [
                    'previous'=>(string)$row['previous_reading'],'current'=>(string)$row['current_reading'],
                    'units'=>(string)$row['units_used'],'updated_at'=>$row['updated_at'],
                ];
                }
                $grouped[$id][$row['meter_type'].'_previous']=$previous===null?null:(string)$previous;
                $grouped[$id][$row['meter_type'].'_current']=$hasCurrent?(string)$row['current_reading']:null;
                $grouped[$id][$row['meter_type'].'_units']=$hasCurrent?(string)$row['units_used']:null;
            }
        }
        return array_values($grouped);
    }

    /** @return array<string,mixed> */
    public function record(array $input, int $adminId): array
    {
        Validator::only($input, ['room_id','period','water_current','electric_current','confirm_large_usage']);
        $roomId = Validator::id($input['room_id'] ?? null, 'room_id');
        $period = Validator::period($input['period'] ?? null);
        $periodDate = $period . '-01';
        $confirmLarge=array_key_exists('confirm_large_usage',$input)?Validator::boolean($input['confirm_large_usage'],'confirm_large_usage'):false;
        if (!array_key_exists('water_current', $input) && !array_key_exists('electric_current', $input)) {
            throw new HttpException(422, 'ต้องระบุเลขมิเตอร์น้ำหรือไฟอย่างน้อยหนึ่งรายการ', 'VALIDATION_ERROR');
        }
        $values = [];
        foreach (['water'=>'water_current','electric'=>'electric_current'] as $type=>$field) {
            if (array_key_exists($field, $input) && $input[$field] !== '' && $input[$field] !== null) {
                $values[$type] = Validator::scaledDecimal($input[$field], $field, 2, 12);
                if($values[$type]>self::MAX_READING_SCALED){
                    throw new HttpException(422,'เลขมิเตอร์ต้องไม่เกิน 9,999,999.00','METER_TOO_HIGH',['field'=>$field,'maximum'=>'9999999.00']);
                }
            }
        }
        if ($values === []) {
            throw new HttpException(422, 'ต้องระบุเลขมิเตอร์น้ำหรือไฟอย่างน้อยหนึ่งรายการ', 'VALIDATION_ERROR');
        }

        return $this->app->database()->transaction(function (PDO $pdo) use ($roomId,$period,$periodDate,$values,$adminId,$confirmLarge): array {
            $room = $pdo->prepare('SELECT id FROM rooms WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $room->execute([$roomId]);
            if (!$room->fetch()) throw new HttpException(404, 'ไม่พบห้อง', 'ROOM_NOT_FOUND');
            $result = ['room_id'=>$roomId,'period'=>$period];
            $prepared=[];
            $anomalies=[];
            foreach ($values as $type=>$currentScaled) {
                $existing = $pdo->prepare('SELECT id,previous_reading,current_reading FROM meter_readings WHERE room_id=? AND meter_type=? AND period=? FOR UPDATE');
                $existing->execute([$roomId,$type,$periodDate]);
                $row = $existing->fetch();
                $next=$pdo->prepare('SELECT id FROM meter_readings WHERE room_id=? AND meter_type=? AND period>? ORDER BY period LIMIT 1 FOR UPDATE');
                $next->execute([$roomId,$type,$periodDate]);
                $hasLater=(bool)$next->fetch();
                $oldCurrent=$row?Validator::scaledDecimal($row['current_reading'],'current_reading',2,12):null;
                if($hasLater&&($oldCurrent===null||$oldCurrent!==$currentScaled)){
                    throw new HttpException(409,'แก้เลขมิเตอร์นี้ไม่ได้ เพราะมีรอบเดือนถัดไปอ้างอิงแล้ว','METER_HISTORY_LOCKED',['meter_type'=>$type]);
                }
                $billed=$pdo->prepare('SELECT id FROM bills WHERE room_id=? AND period=? LIMIT 1 FOR UPDATE');
                $billed->execute([$roomId,$periodDate]);
                if($billed->fetch()&&($oldCurrent===null||$oldCurrent!==$currentScaled)){
                    throw new HttpException(409,'แก้เลขมิเตอร์ไม่ได้หลังออกบิลแล้ว','METER_ALREADY_BILLED',['meter_type'=>$type]);
                }
                if ($row) {
                    $previousScaled = Validator::scaledDecimal($row['previous_reading'], 'previous_reading', 2, 12);
                } else {
                    $previous = $pdo->prepare('SELECT current_reading FROM meter_readings WHERE room_id=? AND meter_type=? AND period<? ORDER BY period DESC LIMIT 1 FOR UPDATE');
                    $previous->execute([$roomId,$type,$periodDate]);
                    $previousRow = $previous->fetch();
                    $previousScaled = $previousRow
                        ? Validator::scaledDecimal($previousRow['current_reading'], 'previous_reading', 2, 12)
                        : $currentScaled;
                }
                if ($currentScaled < $previousScaled) {
                    throw new HttpException(409, 'เลขมิเตอร์ปัจจุบันน้อยกว่าเดือนก่อน', 'METER_ROLLBACK', [
                        'meter_type'=>$type,'previous'=>Validator::decimalString($previousScaled,2),'current'=>Validator::decimalString($currentScaled,2),
                    ]);
                }
                $units = $currentScaled - $previousScaled;
                if($units>self::LARGE_USAGE_SCALED){
                    $anomalies[]=[
                        'meter_type'=>$type,
                        'previous'=>Validator::decimalString($previousScaled,2),
                        'current'=>Validator::decimalString($currentScaled,2),
                        'units'=>Validator::decimalString($units,2),
                    ];
                }
                $prepared[$type]=[
                    'id'=>$row?(int)$row['id']:null,
                    'previous_scaled'=>$previousScaled,
                    'current_scaled'=>$currentScaled,
                    'units_scaled'=>$units,
                ];
            }
            if($anomalies!==[]&&!$confirmLarge){
                $first=$anomalies[0];
                throw new HttpException(409,'หน่วยที่ใช้สูงผิดปกติ กรุณาตรวจเลขทุกประเภทและยืนยันอีกครั้งหากถูกต้อง','METER_USAGE_ANOMALY',[
                    'anomalies'=>$anomalies,
                    'meter_type'=>$first['meter_type'],
                    'previous'=>$first['previous'],
                    'current'=>$first['current'],
                    'units'=>$first['units'],
                    'confirmation_required'=>true,
                ]);
            }
            foreach($prepared as$type=>$reading){
                $previousScaled=$reading['previous_scaled'];
                $currentScaled=$reading['current_scaled'];
                $units=$reading['units_scaled'];
                if ($reading['id']!==null) {
                    $update = $pdo->prepare('UPDATE meter_readings SET current_reading=?,units_used=?,recorded_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
                    $update->execute([Validator::decimalString($currentScaled,2),Validator::decimalString($units,2),$adminId,$reading['id']]);
                } else {
                    $insert = $pdo->prepare('INSERT INTO meter_readings (room_id,meter_type,period,previous_reading,current_reading,units_used,recorded_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                    $insert->execute([$roomId,$type,$periodDate,Validator::decimalString($previousScaled,2),Validator::decimalString($currentScaled,2),Validator::decimalString($units,2),$adminId]);
                }
                $result[$type] = ['previous'=>Validator::decimalString($previousScaled,2),'current'=>Validator::decimalString($currentScaled,2),'units'=>Validator::decimalString($units,2)];
            }
            $result['large_usage_confirmed']=$confirmLarge&&$anomalies!==[];
            $result['large_usage_anomalies']=$confirmLarge?$anomalies:[];
            return $result;
        });
    }
}
