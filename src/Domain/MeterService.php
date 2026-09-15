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

    /** Fill a legacy opening pair once; existing billing evidence is never rewritten. */
    public function setOpeningReadings(int $occupancyId, array $input): array
    {
        Validator::only($input,['opening_water_reading','opening_electric_reading']);
        $readings=[];
        foreach(['opening_water_reading','opening_electric_reading'] as $field){
            $scaled=Validator::scaledDecimal($input[$field]??null,$field,2,12);
            if($scaled>self::MAX_READING_SCALED){
                throw new HttpException(422,'เลขมิเตอร์ต้องไม่เกิน 9,999,999.00','METER_TOO_HIGH',['field'=>$field]);
            }
            $readings[$field]=Validator::decimalString($scaled);
        }
        return $this->app->database()->transaction(function(PDO $pdo)use($occupancyId,$readings):array{
            $lookup=$pdo->prepare('SELECT room_id FROM occupancies WHERE id=?');
            $lookup->execute([$occupancyId]);
            $roomId=$lookup->fetchColumn();
            if($roomId===false)throw new HttpException(404,'ไม่พบรายการเข้าอยู่','OCCUPANCY_NOT_FOUND');
            // Match normal metering lock order so repair and meter writes cannot race.
            $room=$pdo->prepare('SELECT id FROM rooms WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $room->execute([$roomId]);
            if(!$room->fetch())throw new HttpException(404,'ไม่พบห้อง','ROOM_NOT_FOUND');
            $query=$pdo->prepare('SELECT id,room_id,resident_id,status,move_in_date,move_out_date,
                opening_water_reading,opening_electric_reading FROM occupancies WHERE id=? FOR UPDATE');
            $query->execute([$occupancyId]);
            $occupancy=$query->fetch();
            if(!$occupancy||(int)$occupancy['room_id']!==(int)$roomId||$occupancy['status']!=='active'){
                throw new HttpException(409,'เติมเลขเริ่มต้นได้เฉพาะรายการที่ยังเข้าอยู่','OCCUPANCY_NOT_ACTIVE');
            }
            $resident=$pdo->prepare('SELECT active FROM residents WHERE id=?');
            $resident->execute([$occupancy['resident_id']]);
            if((int)$resident->fetchColumn()!==1){
                throw new HttpException(409,'ข้อมูลผู้เช่ากับห้องไม่สอดคล้อง กรุณาตรวจสอบก่อน','OCCUPANCY_STATE_INVALID');
            }
            $result=['occupancy_id'=>$occupancyId,'room_id'=>(int)$roomId]+$readings+['opening_readings_pending'=>false];
            if($occupancy['opening_water_reading']!==null||$occupancy['opening_electric_reading']!==null){
                if($occupancy['opening_water_reading']===$readings['opening_water_reading']
                    &&$occupancy['opening_electric_reading']===$readings['opening_electric_reading']){
                    return $result+['idempotent_replay'=>true];
                }
                throw new HttpException(409,'เลขมิเตอร์เริ่มต้นถูกบันทึกแล้ว จึงแก้ทับไม่ได้','METER_OPENING_LOCKED');
            }
            $history=$pdo->prepare("SELECT
                EXISTS(SELECT 1 FROM meter_readings m WHERE m.occupancy_id=?
                    OR (m.room_id=? AND m.period>=DATE_FORMAT(?,'%Y-%m-01')
                        AND (? IS NULL OR m.period<=LAST_DAY(?))))
                OR EXISTS(SELECT 1 FROM bills b WHERE b.occupancy_id=?
                    OR (b.room_id=? AND b.period>=DATE_FORMAT(?,'%Y-%m-01')
                        AND (? IS NULL OR b.period<=LAST_DAY(?)))) AS has_history");
            $history->execute([
                $occupancyId,$roomId,$occupancy['move_in_date'],$occupancy['move_out_date'],$occupancy['move_out_date'],
                $occupancyId,$roomId,$occupancy['move_in_date'],$occupancy['move_out_date'],$occupancy['move_out_date'],
            ]);
            if((bool)$history->fetchColumn()){
                throw new HttpException(409,'มีประวัติมิเตอร์หรือบิลแล้ว กรุณาตรวจสอบหลักฐานก่อนแก้ข้อมูล','METER_OPENING_HISTORY_CONFLICT');
            }
            $update=$pdo->prepare('UPDATE occupancies SET opening_water_reading=?,opening_electric_reading=?,
                updated_at=UTC_TIMESTAMP(6) WHERE id=? AND opening_water_reading IS NULL AND opening_electric_reading IS NULL');
            $update->execute([$readings['opening_water_reading'],$readings['opening_electric_reading'],$occupancyId]);
            if($update->rowCount()!==1)throw new HttpException(409,'ข้อมูลเปลี่ยนแล้ว กรุณาโหลดหน้าใหม่','METER_OPENING_LOCKED');
            return $result+['idempotent_replay'=>false];
        });
    }

    /** @return list<array<string,mixed>> */
    public function list(string $period): array
    {
        $periodDate = Validator::periodDate($period);
        $this->assertPeriodIsNotFuture(substr($periodDate, 0, 7));
        $statement = $this->app->database()->pdo()->prepare(
            "SELECT r.id AS room_id,r.room_code,mt.meter_type,
                    m.previous_reading,m.current_reading,m.units_used,m.updated_at,m.occupancy_id,
                    (SELECT history.current_reading
                       FROM meter_readings history
                      WHERE history.room_id=r.id
                        AND history.meter_type=mt.meter_type
                        AND history.period<?
                      ORDER BY history.period DESC LIMIT 1) AS prior_current,
                    (SELECT CASE mt.meter_type
                                WHEN 'water' THEN o.opening_water_reading
                                ELSE o.opening_electric_reading
                            END
                       FROM occupancies o
                      WHERE o.room_id=r.id
                        AND o.move_in_date<=LAST_DAY(?)
                        AND (o.move_out_date IS NULL OR o.move_out_date>=?)
                      ORDER BY o.id DESC LIMIT 1) AS occupancy_opening,
                    (SELECT DATE_FORMAT(o.move_in_date,'%Y-%m-01')
                       FROM occupancies o
                      WHERE o.room_id=r.id
                        AND o.move_in_date<=LAST_DAY(?)
                        AND (o.move_out_date IS NULL OR o.move_out_date>=?)
                      ORDER BY o.id DESC LIMIT 1) AS occupancy_move_in_period,
                    EXISTS(
                        SELECT 1 FROM occupancies o
                         WHERE o.room_id=r.id
                           AND o.move_in_date<=LAST_DAY(?)
                           AND (o.move_out_date IS NULL OR o.move_out_date>=?)
                           AND (o.opening_water_reading IS NULL OR o.opening_electric_reading IS NULL)
                    ) AS opening_readings_pending,
                    EXISTS(
                        SELECT 1 FROM bills b
                         WHERE b.room_id=r.id AND b.period=?
                    ) AS is_billed,
                    EXISTS(
                        SELECT 1 FROM meter_readings later
                         WHERE later.room_id=r.id
                           AND later.meter_type=mt.meter_type
                           AND later.period>?
                    ) AS has_later_reading
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
        $statement->execute([
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
            $periodDate,
        ]);
        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['room_id'];
            $grouped[$id] ??= ['room_id'=>$id,'room_code'=>$row['room_code'],'period'=>$period,'water'=>null,'electric'=>null,
                'is_billed'=>(bool)$row['is_billed'],
                'opening_readings_pending'=>(bool)$row['opening_readings_pending'],
                'water_previous'=>null,'water_current'=>null,'water_units'=>null,'electric_previous'=>null,'electric_current'=>null,'electric_units'=>null];
            if ($row['meter_type']) {
                $meterType=(string)$row['meter_type'];
                $hasCurrent=$row['current_reading']!==null;
                $startsThisPeriod=(string)($row['occupancy_move_in_period']??'')===$periodDate;
                $previous=$hasCurrent
                    ?$row['previous_reading']
                    :($startsThisPeriod?$row['occupancy_opening']:$row['prior_current']);
                if($hasCurrent){
                $grouped[$id][$row['meter_type']] = [
                    'previous'=>(string)$row['previous_reading'],'current'=>(string)$row['current_reading'],
                    'units'=>(string)$row['units_used'],'updated_at'=>$row['updated_at'],
                ];
                }
                $grouped[$id][$meterType.'_previous']=$previous===null?null:(string)$previous;
                $grouped[$id][$meterType.'_current']=$hasCurrent?(string)$row['current_reading']:null;
                $grouped[$id][$meterType.'_units']=$hasCurrent?(string)$row['units_used']:null;
                $grouped[$id][$meterType.'_opening_required']=(bool)$row['opening_readings_pending'];
                $grouped[$id][$meterType.'_locked']=(bool)$row['opening_readings_pending']||(bool)$row['is_billed']||(bool)$row['has_later_reading'];
                $grouped[$id][$meterType.'_lock_reason']=(bool)$row['opening_readings_pending']
                    ?'opening_readings_pending'
                    :((bool)$row['is_billed']
                    ?'billed'
                    :((bool)$row['has_later_reading']?'later_reading':null));
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
        $this->assertPeriodIsNotFuture($period);
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
            $occupancy=$this->occupancyForPeriod($pdo,$roomId,$periodDate,true);
            if($occupancy!==null&&($occupancy['opening_water_reading']===null||$occupancy['opening_electric_reading']===null)){
                throw new HttpException(409,'กรุณาเติมเลขมิเตอร์น้ำและไฟ ณ วันเข้าอยู่ในหน้าผู้เช่าก่อนบันทึกมิเตอร์','METER_OPENING_REQUIRED',[
                    'occupancy_id'=>(int)$occupancy['id'],'period'=>$period,
                ]);
            }
            $result = [
                'room_id'=>$roomId,
                'period'=>$period,
                'occupancy_id'=>$occupancy['id']??null,
                'changes'=>[],
                'unchanged_meter_types'=>[],
            ];
            $prepared=[];
            $anomalies=[];
            foreach ($values as $type=>$currentScaled) {
                $existing = $pdo->prepare('SELECT id,occupancy_id,previous_reading,current_reading FROM meter_readings WHERE room_id=? AND meter_type=? AND period=? FOR UPDATE');
                $existing->execute([$roomId,$type,$periodDate]);
                $row = $existing->fetch();
                $expectedOccupancyId=$occupancy['id']??null;
                if($row){
                    $storedOccupancyId=$row['occupancy_id']===null?null:(int)$row['occupancy_id'];
                    if($storedOccupancyId!==null&&$storedOccupancyId!==$expectedOccupancyId){
                        throw new HttpException(
                            409,
                            'This meter row belongs to a different occupancy and cannot be reassigned',
                            'METER_OCCUPANCY_MISMATCH',
                            [
                                'meter_type'=>$type,
                                'stored_occupancy_id'=>$storedOccupancyId,
                                'expected_occupancy_id'=>$expectedOccupancyId,
                            ]
                        );
                    }
                }
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
                    $previous = $pdo->prepare('SELECT period,occupancy_id,current_reading
                        FROM meter_readings
                        WHERE room_id=? AND meter_type=? AND period<?
                        ORDER BY period DESC LIMIT 1 FOR UPDATE');
                    $previous->execute([$roomId,$type,$periodDate]);
                    $previousRow = $previous->fetch();
                    $moveInPeriod=isset($occupancy['move_in_date'])
                        ?substr((string)$occupancy['move_in_date'],0,7).'-01'
                        :null;
                    if($moveInPeriod===$periodDate){
                        $openingField=$type==='water'?'opening_water_reading':'opening_electric_reading';
                        if(($occupancy[$openingField]??null)===null){
                            throw new HttpException(
                                409,
                                'Opening meter reading is required before recording the first resident period',
                                'METER_OPENING_REQUIRED',
                                ['meter_type'=>$type,'occupancy_id'=>(int)$occupancy['id'],'period'=>$period]
                            );
                        }
                        $previousScaled=Validator::scaledDecimal(
                            $occupancy[$openingField],
                            $openingField,
                            2,
                            12
                        );
                    }elseif($occupancy!==null){
                        $expectedPriorPeriod=(new \DateTimeImmutable($periodDate,new \DateTimeZone('UTC')))
                            ->modify('first day of previous month')
                            ->format('Y-m-01');
                        $priorOccupancyId=$previousRow&&$previousRow['occupancy_id']!==null
                            ?(int)$previousRow['occupancy_id']
                            :null;
                        if(!$previousRow
                            ||(string)$previousRow['period']!==$expectedPriorPeriod
                            ||$priorOccupancyId!==(int)$occupancy['id']){
                            throw new HttpException(
                                409,
                                'An earlier meter period is missing or belongs to another occupancy',
                                'METER_HISTORY_GAP',
                                [
                                    'meter_type'=>$type,
                                    'occupancy_id'=>(int)$occupancy['id'],
                                    'required_previous_period'=>substr($expectedPriorPeriod,0,7),
                                    'requested_period'=>$period,
                                ]
                            );
                        }
                        $previousScaled=Validator::scaledDecimal(
                            $previousRow['current_reading'],
                            'previous_reading',
                            2,
                            12
                        );
                    }elseif($previousRow){
                        $previousScaled=Validator::scaledDecimal(
                            $previousRow['current_reading'],
                            'previous_reading',
                            2,
                            12
                        );
                    }else{
                        // A vacant room may establish a physical meter baseline.
                        // No resident usage or bill can be derived from this row.
                        $previousScaled=$currentScaled;
                    }
                }
                if($row&&$occupancy!==null){
                    $moveInPeriod=substr((string)$occupancy['move_in_date'],0,7).'-01';
                    $expectedBaseline=null;
                    if($moveInPeriod===$periodDate){
                        $openingField=$type==='water'?'opening_water_reading':'opening_electric_reading';
                        $expectedBaseline=Validator::scaledDecimal(
                            $occupancy[$openingField],
                            $openingField,
                            2,
                            12
                        );
                    }else{
                        $expectedPriorPeriod=(new \DateTimeImmutable($periodDate,new \DateTimeZone('UTC')))
                            ->modify('first day of previous month')
                            ->format('Y-m-01');
                        $baseline=$pdo->prepare('SELECT current_reading
                            FROM meter_readings
                            WHERE room_id=? AND occupancy_id=? AND meter_type=? AND period=?
                            LIMIT 1 FOR UPDATE');
                        $baseline->execute([$roomId,(int)$occupancy['id'],$type,$expectedPriorPeriod]);
                        $priorCurrent=$baseline->fetchColumn();
                        if($priorCurrent===false){
                            throw new HttpException(
                                409,
                                'The previous meter period for this occupancy is missing',
                                'METER_HISTORY_GAP',
                                [
                                    'meter_type'=>$type,
                                    'occupancy_id'=>(int)$occupancy['id'],
                                    'required_previous_period'=>substr($expectedPriorPeriod,0,7),
                                    'requested_period'=>$period,
                                ]
                            );
                        }
                        $expectedBaseline=Validator::scaledDecimal(
                            $priorCurrent,
                            'previous_reading',
                            2,
                            12
                        );
                    }
                    $storedPrevious=Validator::scaledDecimal(
                        $row['previous_reading'],
                        'previous_reading',
                        2,
                        12
                    );
                    if($storedPrevious!==$expectedBaseline){
                        throw new HttpException(
                            409,
                            'The stored meter baseline does not match this occupancy history',
                            'METER_BASELINE_MISMATCH',
                            ['meter_type'=>$type,'occupancy_id'=>(int)$occupancy['id']]
                        );
                    }
                }
                if ($currentScaled < $previousScaled) {
                    throw new HttpException(409, 'เลขมิเตอร์ปัจจุบันน้อยกว่าเดือนก่อน', 'METER_ROLLBACK', [
                        'meter_type'=>$type,'previous'=>Validator::decimalString($previousScaled,2),'current'=>Validator::decimalString($currentScaled,2),
                    ]);
                }
                $units = $currentScaled - $previousScaled;
                if($units>self::LARGE_USAGE_SCALED&&($oldCurrent===null||$oldCurrent!==$currentScaled)){
                    $anomalies[]=[
                        'meter_type'=>$type,
                        'previous'=>Validator::decimalString($previousScaled,2),
                        'current'=>Validator::decimalString($currentScaled,2),
                        'units'=>Validator::decimalString($units,2),
                    ];
                }
                $prepared[$type]=[
                    'id'=>$row?(int)$row['id']:null,
                    'old_current_scaled'=>$oldCurrent,
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
                $unchanged=$reading['id']!==null&&$reading['old_current_scaled']===$currentScaled;
                if ($reading['id']!==null) {
                    if(!$unchanged){
                        $update = $pdo->prepare('UPDATE meter_readings SET occupancy_id=?,current_reading=?,units_used=?,recorded_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
                        $update->execute([
                            $occupancy['id']??null,
                            Validator::decimalString($currentScaled,2),
                            Validator::decimalString($units,2),
                            $adminId,
                            $reading['id'],
                        ]);
                    }
                } else {
                    $insert = $pdo->prepare('INSERT INTO meter_readings (room_id,occupancy_id,meter_type,period,previous_reading,current_reading,units_used,recorded_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
                    $insert->execute([
                        $roomId,
                        $occupancy['id']??null,
                        $type,
                        $periodDate,
                        Validator::decimalString($previousScaled,2),
                        Validator::decimalString($currentScaled,2),
                        Validator::decimalString($units,2),
                        $adminId,
                    ]);
                }
                $result[$type] = ['previous'=>Validator::decimalString($previousScaled,2),'current'=>Validator::decimalString($currentScaled,2),'units'=>Validator::decimalString($units,2)];
                if($unchanged){
                    $result['unchanged_meter_types'][]=$type;
                }else{
                    $result['changes'][]=[
                        'meter_type'=>$type,
                        'previous'=>Validator::decimalString($previousScaled,2),
                        'old_current'=>$reading['old_current_scaled']===null
                            ?null
                            :Validator::decimalString($reading['old_current_scaled'],2),
                        'new_current'=>Validator::decimalString($currentScaled,2),
                        'units'=>Validator::decimalString($units,2),
                    ];
                }
            }
            $result['large_usage_confirmed']=$confirmLarge&&$anomalies!==[];
            $result['large_usage_anomalies']=$confirmLarge?$anomalies:[];
            return $result;
        });
    }

    /**
     * @return array{id:int,move_in_date:string,opening_water_reading:mixed,opening_electric_reading:mixed}|null
     */
    private function occupancyForPeriod(
        PDO $pdo,
        int $roomId,
        string $periodDate,
        bool $forUpdate,
    ): ?array {
        if($forUpdate&&!$pdo->inTransaction()){
            throw new \RuntimeException('Occupancy meter binding requires a transaction');
        }
        $sql='SELECT id,move_in_date,opening_water_reading,opening_electric_reading
                FROM occupancies
               WHERE room_id=?
                 AND move_in_date<=LAST_DAY(?)
                 AND (move_out_date IS NULL OR move_out_date>=?)
               ORDER BY id
               LIMIT 2'.($forUpdate?' FOR UPDATE':'');
        $statement=$pdo->prepare($sql);
        $statement->execute([$roomId,$periodDate,$periodDate]);
        $rows=$statement->fetchAll();
        if(count($rows)>1){
            throw new HttpException(
                409,
                'More than one occupancy overlaps this meter period',
                'AMBIGUOUS_OCCUPANCY',
                ['room_id'=>$roomId,'period'=>substr($periodDate,0,7)]
            );
        }
        if($rows===[])return null;
        $rows[0]['id']=(int)$rows[0]['id'];
        return $rows[0];
    }

    private function assertPeriodIsNotFuture(string $period, ?\DateTimeImmutable $now = null): void
    {
        $timezone = new \DateTimeZone((string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $maximum = ($now ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone)->format('Y-m');
        if ($period > $maximum) {
            throw new HttpException(
                422,
                'period cannot be later than the current month',
                'VALIDATION_ERROR',
                ['field' => 'period', 'maximum' => $maximum]
            );
        }
    }
}
