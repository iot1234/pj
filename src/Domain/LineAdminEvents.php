<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;

/** Fixed administrator messages contain no resident, bill, payment or invitation data. */
final class LineAdminEvents
{
    private const EVENTS = [
        'booking.created' => ['booking','bookings','มีคำขอจองห้องใหม่ กรุณาเปิดระบบเพื่อตรวจสอบ'],
        'booking.confirmed' => ['booking','bookings','ยืนยันการจองห้องแล้ว กรุณาเปิดระบบเพื่อดูสถานะล่าสุด'],
        'booking.cancelled' => ['booking','bookings','มีการยกเลิกการจองห้อง กรุณาเปิดระบบเพื่อดูสถานะล่าสุด'],
        'tenancy.moved_in' => ['tenancy','residents','บันทึกการเข้าพักเรียบร้อยแล้ว กรุณาเปิดระบบเพื่อดูข้อมูลล่าสุด'],
        'tenancy.moved_out' => ['tenancy','residents','บันทึกการย้ายออกเรียบร้อยแล้ว กรุณาเปิดระบบเพื่อดูข้อมูลล่าสุด'],
        'security.access_reissued' => ['security','residents','มีการออกสิทธิ์เข้าใช้งานของผู้พักใหม่ กรุณาเปิดระบบเพื่อตรวจสอบ'],
        'payment.submitted' => ['payment','payments','ได้รับสลิปชำระเงินใหม่ ระบบกำลังตรวจสอบ กรุณาเปิดระบบเพื่อติดตามผล'],
        'payment.review' => ['payment','payments','มีสลิปที่ยังตรวจสอบไม่สำเร็จ กรุณาเปิดระบบเพื่อตรวจสอบหรือลองใหม่'],
        'payment.verified' => ['payment','payments','ตรวจสอบการชำระเงินสำเร็จและปรับสถานะบิลแล้ว กรุณาเปิดระบบเพื่อดูรายละเอียด'],
        'payment.rejected' => ['payment','payments','มีรายการชำระเงินที่ไม่ผ่านการตรวจสอบหรือถูกปิด กรุณาเปิดระบบเพื่อดูรายละเอียด'],
        'billing.created' => ['billing','bills','สร้างบิลใหม่เรียบร้อยแล้ว กรุณาเปิดระบบเพื่อตรวจสอบและส่งแจ้งเตือน'],
    ];

    /**
     * Call inside the successful domain mutation transaction. A positive row ID
     * identifies one transition, or a SHA-256 identifies a deterministic batch.
     * No registry lock or provider request occurs while domain rows are locked.
     */
    public static function enqueue(Application $app, string $event, int|string $id): int
    {
        if (!isset(self::EVENTS[$event]) || (is_int($id) ? $id < 1 : preg_match('/^[a-f0-9]{64}$/D',$id) !== 1)) {
            throw new \InvalidArgumentException('Invalid LINE admin event');
        }
        if (!$app->database()->pdo()->inTransaction()) throw new \LogicException('LINE admin events require the domain transaction');
        [$category,$view,$text] = self::EVENTS[$event];
        $url = rtrim($app->config->require('APP_URL'),'/') . '/admin#' . $view;
        return $app->lineNotices()->enqueueAdmin($category, $text . "\n" . $url, 'domain:' . $event . ':' . $id);
    }
}
