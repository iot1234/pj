<?php
declare(strict_types=1);
namespace Dormitory\Domain;

/** Read-side preflight mirrors metering rules; null is never proof of a new vacant meter. */
final class MeterReadiness
{
    public static function describe(array $row, string $periodDate): array
    {
        $hasCurrent = $row['current_reading'] !== null;
        $occupied = $row['selected_occupancy_id'] !== null;
        $first = $occupied && $row['occupancy_move_in_period'] === $periodDate;
        $priorPeriod = (new \DateTimeImmutable($periodDate,new \DateTimeZone('UTC')))->modify('first day of previous month')->format('Y-m-01');
        $expected = $first ? $row['occupancy_opening'] : $row['prior_current'];
        $state = $first ? 'opening' : ($expected !== null ? 'previous' : 'vacant_baseline');
        $reason = null; $code = null; $message = null;
        if ((int)$row['occupancy_count'] > 1) {
            $reason='ambiguous_occupancy'; $code='AMBIGUOUS_OCCUPANCY'; $message='พบผู้พักมากกว่าหนึ่งรอบในเดือนเดียวกัน กรุณาตรวจวันเข้าและวันย้ายออกก่อน';
        } elseif ((bool)$row['opening_readings_pending']) {
            $reason='opening_readings_pending'; $code='METER_OPENING_REQUIRED'; $message='ยังขาดเลขมิเตอร์น้ำและไฟ ณ วันเข้าพัก กรุณาเติมเลขเริ่มต้นก่อน';
        } elseif ($hasCurrent && ($row['occupancy_id'] === null ? null : (int)$row['occupancy_id']) !== ($row['selected_occupancy_id'] === null ? null : (int)$row['selected_occupancy_id'])) {
            $reason='occupancy_mismatch'; $code='METER_OCCUPANCY_MISMATCH'; $message='เลขมิเตอร์นี้เป็นของผู้พักคนละรอบ ห้ามย้ายหรือใช้ข้อมูลข้ามผู้พัก';
        } elseif ($occupied && !$first && ($row['prior_period'] !== $priorPeriod || $row['prior_occupancy_id'] === null || (int)$row['prior_occupancy_id'] !== (int)$row['selected_occupancy_id'])) {
            $reason='history_gap'; $code='METER_HISTORY_GAP'; $message='ยังไม่มีมิเตอร์เดือนก่อนของผู้พักรอบนี้ ต้องเติมงวดที่ขาดก่อน ไม่ใช่เดือนแรกที่ใช้หน่วยศูนย์'; $state='history_gap'; $expected=null;
        } elseif ($hasCurrent && $occupied && $expected !== null && (string)$row['previous_reading'] !== (string)$expected) {
            $reason='baseline_mismatch'; $code='METER_BASELINE_MISMATCH'; $message='เลขตั้งต้นที่บันทึกไม่ตรงกับประวัติผู้พัก กรุณาตรวจประวัติก่อนแก้ไข';
        }
        if ($reason === null && (bool)$row['is_billed']) {
            $reason='billed'; $code='METER_ALREADY_BILLED'; $message='ออกบิลจากเลขนี้แล้ว จึงไม่แก้ทับหลักฐานเดิม ให้ตรวจใบแจ้งหนี้ของรอบนี้';
        } elseif ($reason === null && (bool)$row['has_later_reading']) {
            $reason='later_reading'; $code='METER_HISTORY_LOCKED'; $message='มีมิเตอร์งวดถัดไปอ้างอิงแล้ว จึงไม่แก้ทับเลขนี้โดยทำให้ประวัติขาด';
        }
        if ($reason !== null && $reason !== 'billed' && $reason !== 'later_reading') $state=$reason;
        return ['baseline_state'=>$state, 'previous'=>$hasCurrent ? $row['previous_reading'] : $expected,
            'locked'=>$reason !== null, 'lock_reason'=>$reason, 'issue_code'=>$code, 'issue_message'=>$message,
            'required_previous_period'=>$reason==='history_gap' ? substr($priorPeriod,0,7) : null,
            'is_vacant_baseline'=>!$occupied && !$hasCurrent && $row['prior_current']===null];
    }

    /** Opaque, server-generated edit version; never ask an administrator to type it. */
    public static function version(int $roomId, string $period, string $type, ?array $row, string $key, array $context = []): string
    {
        $identity=$row===null ? null : [(int)$row['id'], $row['occupancy_id']===null?null:(int)$row['occupancy_id'],
            (string)$row['previous_reading'],(string)$row['current_reading'],(string)$row['units_used'],(string)$row['updated_at']];
        return hash_hmac('sha256',json_encode(['meter-edit-v1',$roomId,$period,$type,$identity,$context],JSON_THROW_ON_ERROR),$key);
    }
}
