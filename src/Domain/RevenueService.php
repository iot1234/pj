<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\DailyBookingSchema;
use PDO;

/** Owner-only collections reports. Receiving cash is distinct from earning revenue. */
final class RevenueService
{
    private const EVENT_LIMIT = 200;

    public function __construct(private readonly Application $app) {}

    public function monthly(string $period, int $ownerId, int $offset = 0): array
    {
        $bounds = self::periodBounds($period, (string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $this->validateOffset($offset);
        return $this->app->database()->transaction(function (PDO $pdo) use ($period, $ownerId, $offset, $bounds): array {
            $this->owner($pdo, $ownerId);
            $clock = $this->databaseClock($pdo);
            // Aggregate immutable bill items once per invoice before joining its single verified receipt.
            // Current room mode, rent or occupancy must never reclassify historical collections.
            $items = self::monthlyItemsSql();
            $q = $pdo->prepare(<<<SQL
SELECT COUNT(*) AS verified_payment_count,
 CAST(COALESCE(SUM(COALESCE(t.transfer_amount,p.amount)),0) AS CHAR) AS gross_receipts,
 CAST(COALESCE(SUM(b.total_amount),0) AS CHAR) AS collected_charges,
 CAST(COALESCE(SUM(i.rent),0) AS CHAR) AS rent_collections,
 CAST(COALESCE(SUM(i.water),0) AS CHAR) AS water_collections,
 CAST(COALESCE(SUM(i.electric),0) AS CHAR) AS electric_collections,
 CAST(COALESCE(SUM(i.other),0) AS CHAR) AS other_collections,
 CAST(COALESCE(SUM(COALESCE(t.adjustment_amount,0)),0) AS CHAR) AS bank_adjustment,
 CAST(COALESCE(SUM(CASE WHEN b.period<? THEN b.total_amount ELSE 0 END),0) AS CHAR) AS earlier_bill_period_collections,
 CAST(COALESCE(SUM(CASE WHEN b.period>=? THEN b.total_amount ELSE 0 END),0) AS CHAR) AS later_bill_period_collections,
 COALESCE(SUM(b.status<>'paid' OR p.amount<>b.total_amount OR i.total IS NULL OR i.total<>b.total_amount
  OR (t.bill_id IS NOT NULL AND (t.bill_amount<>b.total_amount OR t.status NOT IN('settled','released')))),0) AS invalid_snapshot_count
FROM payments p JOIN bills b ON b.id=p.bill_id
LEFT JOIN transfer_instructions t ON t.bill_id=b.id
LEFT JOIN ($items) i ON i.bill_id=b.id
WHERE p.status='verified' AND p.verified_at>=? AND p.verified_at<?
SQL);
            $q->execute([$bounds['start_date'], $bounds['end_date'], $bounds['start_sql'], $bounds['end_sql']]);
            $row = $q->fetch();
            $this->assertIntegrity($row);
            $summary = $this->moneyFields($row, ['gross_receipts','collected_charges','rent_collections','water_collections','electric_collections','other_collections','bank_adjustment','earlier_bill_period_collections','later_bill_period_collections']);
            $summary['verified_payment_count'] = self::count($row['verified_payment_count']);
            $summary['refunds_total'] = '0.00';
            $summary['refund_count'] = 0;
            $summary['net_cash_flow'] = $summary['gross_receipts'];
            $q = $pdo->prepare(<<<SQL
SELECT p.id AS event_id,'collection' AS event_type,p.verified_at AS occurred_at,
 b.bill_no AS reference_no,b.id AS bill_id,b.period AS bill_period,b.room_code_snapshot AS room_code,
 CAST(COALESCE(t.transfer_amount,p.amount) AS CHAR) AS amount,CAST(b.total_amount AS CHAR) AS charges_amount,
 CAST(COALESCE(t.adjustment_amount,0) AS CHAR) AS bank_adjustment,
 CAST(i.rent AS CHAR) AS rent_amount,CAST(i.water AS CHAR) AS water_amount,
 CAST(i.electric AS CHAR) AS electric_amount,CAST(i.other AS CHAR) AS other_amount
FROM payments p JOIN bills b ON b.id=p.bill_id LEFT JOIN transfer_instructions t ON t.bill_id=b.id LEFT JOIN ($items) i ON i.bill_id=b.id
WHERE p.status='verified' AND p.verified_at>=? AND p.verified_at<?
ORDER BY p.verified_at DESC,p.id DESC LIMIT 201 OFFSET $offset
SQL);
            $q->execute([$bounds['start_sql'], $bounds['end_sql']]);
            $events = $this->events($q->fetchAll(), $offset);
            return $this->envelope('monthly', $period, $bounds, $clock, $summary, $events);
        });
    }

    public function daily(string $period, int $ownerId, int $offset = 0): array
    {
        $bounds = self::periodBounds($period, (string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $this->validateOffset($offset);
        return $this->app->database()->transaction(function (PDO $pdo) use ($period, $ownerId, $offset, $bounds): array {
            $this->owner($pdo, $ownerId);
            DailyBookingSchema::assertReady($pdo);
            $clock = $this->databaseClock($pdo);
            $today = $clock->setTimezone(new DateTimeZone($bounds['timezone']))->format('Y-m-d');
            // Each independent event ledger is aggregated before the CROSS JOIN: refunds cannot
            // multiply a receipt or its one deposit settlement, even with several partial refunds.
            $q = $pdo->prepare(<<<'SQL'
SELECT c.verified_payment_count,c.future_stay_payment_count,
 CAST(c.gross AS CHAR) AS gross_receipts,CAST(c.room AS CHAR) AS room_collections,
 CAST(c.deposit AS CHAR) AS security_deposit_collections,CAST(c.adjustment AS CHAR) AS bank_adjustment,
 CAST(c.cash AS CHAR) AS cash_receipts,CAST(c.bank AS CHAR) AS bank_receipts,
 CAST(c.future_room AS CHAR) AS future_stay_room_collections,
 r.refund_count,CAST(r.total AS CHAR) AS refunds_total,CAST(r.deposit AS CHAR) AS deposit_refunds,
 CAST(r.cancellation AS CHAR) AS cancellation_refunds,
 s.retention_count,CAST(s.retained AS CHAR) AS damage_retention,
 CAST(c.gross-r.total AS CHAR) AS net_cash_flow,c.invalid_snapshot_count
FROM (
 SELECT COUNT(*) AS verified_payment_count,
  COALESCE(SUM(p.transfer_amount),0) AS gross,COALESCE(SUM(b.room_amount),0) AS room,
  COALESCE(SUM(b.deposit_amount),0) AS deposit,COALESCE(SUM(p.transfer_amount-p.amount),0) AS adjustment,
  COALESCE(SUM(CASE WHEN p.method='cash' THEN p.transfer_amount ELSE 0 END),0) AS cash,
  COALESCE(SUM(CASE WHEN p.method='slip' THEN p.transfer_amount ELSE 0 END),0) AS bank,
  COALESCE(SUM(CASE WHEN b.check_in_date>? AND b.status IN('pending','confirmed') THEN 1 ELSE 0 END),0) AS future_stay_payment_count,
  COALESCE(SUM(CASE WHEN b.check_in_date>? AND b.status IN('pending','confirmed') THEN b.room_amount ELSE 0 END),0) AS future_room,
  COALESCE(SUM(p.amount<>b.total_amount OR p.transfer_amount<p.amount),0) AS invalid_snapshot_count
 FROM daily_payments p JOIN daily_bookings b ON b.id=p.booking_id
 WHERE p.status='verified' AND p.verified_at>=? AND p.verified_at<?
) c CROSS JOIN (
 SELECT COUNT(*) AS refund_count,COALESCE(SUM(r.amount),0) AS total,
  COALESCE(SUM(CASE WHEN r.purpose='deposit' THEN r.amount ELSE 0 END),0) AS deposit,
  COALESCE(SUM(CASE WHEN r.purpose='cancellation' THEN r.amount ELSE 0 END),0) AS cancellation
 FROM daily_refunds r JOIN daily_payments p ON p.id=r.payment_id AND p.booking_id=r.booking_id AND p.status='verified'
 WHERE r.created_at>=? AND r.created_at<?
) r CROSS JOIN (
 SELECT COUNT(*) AS retention_count,COALESCE(SUM(s.retained_amount),0) AS retained
 FROM daily_deposit_settlements s JOIN daily_payments p ON p.booking_id=s.booking_id AND p.status='verified'
 WHERE s.created_at>=? AND s.created_at<?
) s
SQL);
            $q->execute([$today, $today, $bounds['start_sql'], $bounds['end_sql'], $bounds['start_sql'], $bounds['end_sql'], $bounds['start_sql'], $bounds['end_sql']]);
            $row = $q->fetch();
            $this->assertIntegrity($row);
            $summary = $this->moneyFields($row, ['gross_receipts','room_collections','security_deposit_collections','bank_adjustment','cash_receipts','bank_receipts','future_stay_room_collections','refunds_total','deposit_refunds','cancellation_refunds','damage_retention','net_cash_flow']);
            foreach (['verified_payment_count','future_stay_payment_count','refund_count','retention_count'] as $field) $summary[$field] = self::count($row[$field]);

            // These are CURRENT balances across every month, explicitly separate from period totals.
            // Cancellation refunds have no room/deposit allocation in the ledger. Preserve the
            // whole closed-booking refundable balance instead of guessing a split or prorating it.
            $q = $pdo->prepare(<<<'SQL'
SELECT CAST(COALESCE(SUM(CASE WHEN b.status IN('confirmed','checked_in','checked_out')
 THEN b.deposit_amount-p.deposit_refunded_amount-p.deposit_retained_amount ELSE 0 END),0) AS CHAR) AS active_stay_security_deposits,
 CAST(COALESCE(SUM(CASE WHEN b.status IN('cancelled','expired','no_show') OR (b.status='pending' AND (b.expires_at<=? OR b.check_out_date<=?))
 THEN p.transfer_amount-p.refunded_amount-p.deposit_retained_amount ELSE 0 END),0) AS CHAR) AS closed_booking_cash_refundable,
 CAST(COALESCE(SUM(CASE WHEN b.status='pending' AND b.expires_at>? AND b.check_out_date>?
 THEN p.transfer_amount-p.refunded_amount-p.deposit_retained_amount ELSE 0 END),0) AS CHAR) AS unconfirmed_verified_cash,
 COALESCE(SUM(p.refunded_amount+p.deposit_retained_amount>p.transfer_amount OR p.deposit_refunded_amount+p.deposit_retained_amount>b.deposit_amount),0) AS invalid_snapshot_count
FROM daily_payments p JOIN daily_bookings b ON b.id=p.booking_id WHERE p.status='verified'
SQL);
            $clockSql = $clock->format('Y-m-d H:i:s.u');
            $q->execute([$clockSql, $today, $clockSql, $today]);
            $row = $q->fetch();
            $this->assertIntegrity($row);
            $balances = $this->moneyFields($row, ['active_stay_security_deposits','closed_booking_cash_refundable','unconfirmed_verified_cash']);
            $balances['as_of_utc'] = $clock->format('Y-m-d\TH:i:s.u\Z');
            $q = $pdo->prepare(<<<SQL
SELECT * FROM (
 SELECT p.id AS event_id,'collection' AS event_type,p.verified_at AS occurred_at,
  b.reference_no,b.id AS booking_id,r.room_code,CAST(p.transfer_amount AS CHAR) AS amount,p.method AS payment_method,
  CAST(b.room_amount AS CHAR) AS room_amount,CAST(b.deposit_amount AS CHAR) AS deposit_amount,
  CAST(p.transfer_amount-p.amount AS CHAR) AS bank_adjustment,NULL AS refund_purpose,NULL AS refund_reference
 FROM daily_payments p JOIN daily_bookings b ON b.id=p.booking_id JOIN rooms r ON r.id=b.room_id
 WHERE p.status='verified' AND p.verified_at>=? AND p.verified_at<?
 UNION ALL
 SELECT r.id,'refund',r.created_at,b.reference_no,b.id,room.room_code,CAST(r.amount AS CHAR),'manual_bank_refund',
  NULL,NULL,NULL,r.purpose,r.reference_no
 FROM daily_refunds r JOIN daily_payments p ON p.id=r.payment_id AND p.booking_id=r.booking_id AND p.status='verified'
 JOIN daily_bookings b ON b.id=r.booking_id JOIN rooms room ON room.id=b.room_id WHERE r.created_at>=? AND r.created_at<?
 UNION ALL
 SELECT s.booking_id,'deposit_retention',s.created_at,b.reference_no,b.id,r.room_code,CAST(s.retained_amount AS CHAR),'deposit_retention',
  NULL,NULL,NULL,NULL,NULL
 FROM daily_deposit_settlements s JOIN daily_payments p ON p.booking_id=s.booking_id AND p.status='verified'
 JOIN daily_bookings b ON b.id=s.booking_id JOIN rooms r ON r.id=b.room_id WHERE s.created_at>=? AND s.created_at<?
) e ORDER BY occurred_at DESC,event_type ASC,event_id DESC LIMIT 201 OFFSET $offset
SQL);
            $q->execute([$bounds['start_sql'], $bounds['end_sql'], $bounds['start_sql'], $bounds['end_sql'], $bounds['start_sql'], $bounds['end_sql']]);
            $report = $this->envelope('daily', $period, $bounds, $clock, $summary, $this->events($q->fetchAll(), $offset));
            $report['balances'] = $balances;
            return $report;
        });
    }

    private static function monthlyItemsSql(): string
    {
        return "SELECT bill_id,SUM(amount) AS total,
            SUM(CASE WHEN item_type='rent' THEN amount ELSE 0 END) AS rent,
            SUM(CASE WHEN item_type='water' THEN amount ELSE 0 END) AS water,
            SUM(CASE WHEN item_type='electric' THEN amount ELSE 0 END) AS electric,
            SUM(CASE WHEN item_type='other' THEN amount ELSE 0 END) AS other FROM bill_items GROUP BY bill_id";
    }

    /** @return array<string,string> */
    private static function periodBounds(string $period, string $timezone): array
    {
        if (preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new HttpException(422, 'เลือกรอบเดือนในรูปแบบ YYYY-MM ที่ถูกต้อง', 'VALIDATION_ERROR', ['field'=>'period']);
        }
        try { $zone = new DateTimeZone($timezone); }
        catch (\Throwable) { throw new HttpException(503, 'ยังอ่านเขตเวลาของรายงานไม่ได้', 'REVENUE_TIMEZONE_INVALID'); }
        $start = new DateTimeImmutable($period . '-01 00:00:00', $zone);
        $end = $start->modify('+1 month');
        $utc = new DateTimeZone('UTC');
        if ((int) $start->setTimezone($utc)->format('Y') < 1000 || (int) $end->format('Y') > 9999
            || (int) $end->setTimezone($utc)->format('Y') > 9999) {
            throw new HttpException(422, 'รอบเดือนอยู่นอกช่วงวันที่ที่ฐานข้อมูลรองรับ', 'VALIDATION_ERROR', ['field'=>'period']);
        }
        return ['timezone'=>$timezone,'start_date'=>$start->format('Y-m-d'),'end_date'=>$end->format('Y-m-d'),
            'start_sql'=>$start->setTimezone($utc)->format('Y-m-d H:i:s'),'end_sql'=>$end->setTimezone($utc)->format('Y-m-d H:i:s'),
            'start_utc'=>$start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),'end_utc'=>$end->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')];
    }

    private static function decimal(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', (string) $value, $match) !== 1) {
            throw new HttpException(503, 'ข้อมูลยอดเงินในรายงานไม่ครบ กรุณาตรวจข้อมูลการเงิน', 'REVENUE_DATA_INVALID');
        }
        $whole = ltrim($match[2], '0') ?: '0';
        $fraction = str_pad($match[3] ?? '', 2, '0');
        $negative = $match[1] === '-' && ($whole !== '0' || $fraction !== '00') ? '-' : '';
        return $negative . $whole . '.' . $fraction;
    }

    private static function count(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^\d+$/D', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value > 9_007_199_254_740_991) {
            throw new HttpException(503, 'ข้อมูลจำนวนรายการไม่ครบ กรุณาโหลดรายงานใหม่', 'REVENUE_DATA_INVALID');
        }
        return (int) $value;
    }

    private function validateOffset(int $offset): void
    {
        if ($offset < 0 || $offset > 1_000_000) throw new HttpException(422, 'หน้ารายการไม่ถูกต้อง', 'VALIDATION_ERROR', ['field'=>'offset']);
    }

    private function owner(PDO $pdo, int $id): void
    {
        $q = $pdo->prepare("SELECT id FROM admin_users WHERE id=? AND role='owner' AND active=1 AND retired_at IS NULL");
        $q->execute([$id]);
        if ($q->fetchColumn() === false) throw new HttpException(403, 'ต้องเป็นเจ้าของระบบที่ใช้งานอยู่', 'FORBIDDEN');
    }

    private function databaseClock(PDO $pdo): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(), new DateTimeZone('UTC'));
    }

    private function assertIntegrity(array|false $row): void
    {
        if (!$row || self::count($row['invalid_snapshot_count'] ?? null) !== 0) throw new HttpException(503, 'ข้อมูลการเงินไม่ตรงกับรายการที่ยืนยันแล้ว กรุณาตรวจประวัติก่อนใช้รายงาน', 'REVENUE_DATA_INVALID');
    }

    private function moneyFields(array $row, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) $result[$field] = self::decimal($row[$field] ?? null);
        return $result;
    }

    private function events(array $rows, int $offset): array
    {
        $hasMore = count($rows) > self::EVENT_LIMIT;
        $rows = array_slice($rows, 0, self::EVENT_LIMIT);
        foreach ($rows as &$row) {
            foreach (['event_id','booking_id','bill_id'] as $key) if (isset($row[$key])) $row[$key] = self::count($row[$key]);
            foreach (['amount','charges_amount','bank_adjustment','rent_amount','water_amount','electric_amount','other_amount','room_amount','deposit_amount'] as $key) if (isset($row[$key])) $row[$key] = self::decimal($row[$key]);
            $row['occurred_at'] = (new DateTimeImmutable($row['occurred_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $row['cash_flow_direction'] = match ($row['event_type']) { 'collection'=>'in','refund'=>'out',default=>'none' };
        }
        unset($row);
        return ['items'=>$rows,'offset'=>$offset,'limit'=>self::EVENT_LIMIT,'has_more'=>$hasMore,'next_offset'=>$offset+count($rows)];
    }

    private function envelope(string $type, string $period, array $bounds, DateTimeImmutable $clock, array $summary, array $events): array
    {
        return ['type'=>$type,'period'=>$period,'currency'=>'THB','timezone'=>$bounds['timezone'],
            'range'=>['start_utc'=>$bounds['start_utc'],'end_utc'=>$bounds['end_utc']],
            'generated_at_utc'=>$clock->format('Y-m-d\TH:i:s.u\Z'),
            'basis'=>['collections'=>'verified_at','refunds'=>$type==='daily'?'created_at':null,'deposit_retention'=>$type==='daily'?'created_at':null,
                'earned_revenue_calculated'=>false,'future_stays_relative_to'=>'generated_at','balances_scope'=>$type==='daily'?'current_all_periods':null,
                'room_labels'=>$type==='daily'?'current_catalogue':'invoice_snapshot'],
            'summary'=>$summary,'events'=>$events];
    }
}
