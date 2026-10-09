<?php
declare(strict_types=1);

namespace Dormitory\Http;

use Dormitory\Application;
use Dormitory\Support\Validator;

/** Daily guest capabilities never grant access to the resident portal. */
final class DailyRoutes
{
    public static function register(Router $router, Application $app): void
    {
        $owner=['auth'=>'admin','role'=>'owner'];
        $id=static fn(Request $r):int=>Validator::id($r->param('id'),'id');
        $token=static function(Request $r):string{
            $value=$r->header('x-booking-access-token');
            if($value===null&&preg_match('/^Bearer ([A-Za-z0-9_-]+)$/D',(string)$r->header('authorization'),$match))$value=$match[1];
            if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$value))throw new HttpException(404,'ไม่พบการจองหรือสิทธิ์เปิดรายการหมดอายุ','DAILY_ACCESS_DENIED');
            return $value;
        };
        $throttle=static function(Request $r,string $purpose,int $limit=120)use($app):void{
            $app->limiter()->hit('daily-'.$purpose,$app->security()->clientIp($r),$limit,3600,300);
        };
        $ownerMutation=static function(Request $r,string $action,callable $work)use($app):array{
            $actor=$app->actor();
            $app->limiter()->hit('daily-owner-write',(string)$actor['id'],180,3600,300);
            return $app->database()->transaction(function()use($app,$r,$action,$work,$actor):array{
                $data=$work();
                if(isset($data['_error']))return $data;
                if(($data['idempotent_replay']??false)!==true){
                    $entityType=match($action){'daily.room_ready'=>'room','daily.room_blocked','daily.room_block_released'=>'daily_room_block',default=>'daily_booking'};
                    $entityId=$entityType==='daily_booking'?($data['booking_id']??$data['id']??$r->param('id')):($data['id']??$data['room_id']??$r->param('id'));
                    $app->audit()->writeStrict($r,$actor,$action,$entityType,$entityId,['status'=>$data['status']??null]);
                    $event=match($action){
                        'daily.booking_created_by_owner'=>'daily.booking.created',
                        'daily.cancel','daily.no-show'=>'daily.booking.cancelled',
                        'daily.check-in'=>'daily.checked_in','daily.check-out'=>'daily.checked_out',
                        'daily.cash'=>'daily.payment.verified','daily.refund'=>'daily.refund.recorded',
                        default=>null,
                    };
                    if($event!==null)\Dormitory\Domain\LineAdminEvents::enqueue($app,$event,(int)($data['id']??$data['booking_id']??$r->param('id')));
                }
                return $data;
            });
        };

        $router->get('/daily',fn(Request $r)=>Response::html($app->view()->render('public/daily.php',[
            'title'=>'จองห้องพักรายวัน','page'=>'daily-home','csrfToken'=>$app->security()->csrfToken(),
            'user'=>$app->actor()??[],'appTimezone'=>(string)$app->config->get('APP_TIMEZONE','Asia/Bangkok'),
        ])));
        $router->get('/api/public/daily/availability',function(Request $r)use($app,$throttle):Response{
            $throttle($r,'availability',240);
            return Response::json($app->dailyBookings()->availability($r->query));
        });
        $router->post('/api/public/daily/quote',function(Request $r)use($app,$throttle):Response{
            $throttle($r,'quote',180);return Response::json($app->dailyBookings()->quote($r->body));
        });
        $router->post('/api/public/daily/bookings',function(Request $r)use($app,$throttle):Response{
            $throttle($r,'booking-attempt',60);
            $data=$app->database()->transaction(function()use($app,$r):array{
                $data=$app->dailyBookings()->createPublic($r->body);
                if(($data['idempotent_replay']??false)!==true){
                    $app->limiter()->hit('daily-booking-success-ip',$app->security()->clientIp($r),12,86400,3600);
                    $app->limiter()->hit('daily-booking-success-phone',Validator::phone($r->body['phone']??null),6,86400,3600);
                    $app->audit()->writeStrict($r,null,'daily.booking_created','daily_booking',$data['id'],['room_id'=>$data['room_id'],'check_in_date'=>$data['check_in_date'],'check_out_date'=>$data['check_out_date']]);
                    \Dormitory\Domain\LineAdminEvents::enqueue($app,'daily.booking.created',$data['id']);
                }
                return $data;
            });
            return Response::json($data,($data['idempotent_replay']??false)?200:201,'รับคำขอจองแล้ว');
        });
        $router->get('/api/public/daily/bookings/{id}',function(Request $r)use($app,$id,$token,$throttle):Response{
            $throttle($r,'guest-read',240);return Response::json($app->dailyBookings()->guestDetails($id($r),$token($r)));
        });
        $router->get('/api/public/daily/bookings/{id}/payment',function(Request $r)use($app,$id,$token,$throttle):Response{
            $throttle($r,'guest-payment-read',240);return Response::json($app->dailyPayments()->status($id($r),$token($r)));
        });
        $router->post('/api/public/daily/bookings/{id}/promptpay',function(Request $r)use($app,$id,$token,$throttle):Response{
            Validator::only($r->body,[]);$throttle($r,'guest-qr',60);
            return Response::json($app->dailyPayments()->reserve($id($r),$token($r)));
        });
        $router->post('/api/public/daily/bookings/{id}/slip',function(Request $r)use($app,$id,$token,$throttle):Response{
            Validator::only($r->body,[]);$throttle($r,'guest-slip',16);
            $data=$app->dailyPayments()->upload($id($r),$token($r),is_array($r->files['slip']??null)?$r->files['slip']:[],function(array $payment)use($app,$r):void{
                if(($payment['idempotent_replay']??false)!==true){
                    $app->audit()->writeStrict($r,null,'daily.slip_uploaded','daily_payment',$payment['id'],['booking_id'=>$payment['booking_id'],'status'=>$payment['status']]);
                    $event=match($payment['status']){'verified'=>'daily.payment.verified','rejected'=>'daily.payment.rejected',default=>'daily.payment.review'};
                    \Dormitory\Domain\LineAdminEvents::enqueue($app,$event,$payment['id']);
                }
            });
            return Response::json($data,($data['idempotent_replay']??false)?200:201,'รับหลักฐานชำระแล้ว');
        });

        $router->get('/api/admin/daily/bookings',fn(Request $r)=>Response::json($app->dailyBookings()->all($r->query)),$owner);
        $router->post('/api/admin/daily/bookings',function(Request $r)use($app,$ownerMutation):Response{
            $data=$ownerMutation($r,'daily.booking_created_by_owner',fn()=>$app->dailyBookings()->createAdmin((int)$app->actor()['id'],$r->body));
            unset($data['access_token']);return Response::json($data,($data['idempotent_replay']??false)?200:201);
        },$owner);
        $router->get('/api/admin/daily/calendar',fn(Request $r)=>Response::json($app->dailyBookings()->calendar($r->query)),$owner);
        foreach(['confirm','cancel','check-in','check-out','no-show']as$action){
            $router->post('/api/admin/daily/bookings/{id}/'.$action,function(Request $r)use($app,$id,$action,$ownerMutation):Response{
                $data=$ownerMutation($r,'daily.'.$action,fn()=>$app->dailyBookings()->transitionOutcome($id($r),$action,$r->body,(int)$app->actor()['id']));
                if(isset($data['_error']))return Response::error('การจองหมดเวลาหรือเลยช่วงเข้าพักแล้ว กรุณาตรวจสถานะล่าสุด',409,(string)$data['_error']);
                return Response::json($data);
            },$owner);
        }
        $router->post('/api/admin/daily/rooms/{id}/ready',function(Request $r)use($app,$id,$ownerMutation):Response{
            return Response::json($ownerMutation($r,'daily.room_ready',fn()=>$app->dailyBookings()->markReady($id($r),$r->body,(int)$app->actor()['id'])));
        },$owner);
        $router->post('/api/admin/daily/rooms/{id}/blocks',function(Request $r)use($app,$id,$ownerMutation):Response{
            $data=$ownerMutation($r,'daily.room_blocked',fn()=>$app->dailyBookings()->createBlock($id($r),$r->body,(int)$app->actor()['id']));
            return Response::json($data,($data['idempotent_replay']??false)?200:201);
        },$owner);
        $router->post('/api/admin/daily/blocks/{id}/release',function(Request $r)use($app,$id,$ownerMutation):Response{
            return Response::json($ownerMutation($r,'daily.room_block_released',fn()=>$app->dailyBookings()->releaseBlock($id($r),$r->body,(int)$app->actor()['id'])));
        },$owner);
        $router->get('/api/admin/daily/bookings/{id}/payment',fn(Request $r)=>Response::json($app->dailyPayments()->statusForOwner($id($r))),$owner);
        $router->get('/api/admin/daily/bookings/{id}/requests/{action}',function(Request $r)use($app,$id):Response{
            $action=Validator::enum($r->param('action'),'action',['cash','refund','deposit-settlement','close']);
            $key=Validator::string($r->query['key']??null,'key',16,64);
            return Response::json($app->dailyPayments()->ownerRequestStatus($id($r),$action,$key,(int)$app->actor()['id']));
        },$owner);
        $router->post('/api/admin/daily/bookings/{id}/promptpay',function(Request $r)use($app,$id):Response{
            Validator::only($r->body,[]);$app->limiter()->hit('daily-owner-qr',(string)$app->actor()['id'],60,3600,300);
            return Response::json($app->dailyPayments()->reserveForOwner($id($r),(int)$app->actor()['id']));
        },$owner);
        $router->post('/api/admin/daily/bookings/{id}/slip',function(Request $r)use($app,$id):Response{
            Validator::only($r->body,[]);$app->limiter()->hit('daily-owner-slip',(string)$app->actor()['id'],30,3600,300);
            $data=$app->dailyPayments()->uploadForOwner($id($r),is_array($r->files['slip']??null)?$r->files['slip']:[],(int)$app->actor()['id'],function(array $payment)use($app,$r):void{
                $actor=self::currentOwnerForCommit($app);
                if(($payment['idempotent_replay']??false)!==true){
                    $app->audit()->writeStrict($r,$actor,'daily.owner_slip_upload','daily_payment',$payment['id'],['booking_id'=>$payment['booking_id'],'status'=>$payment['status']]);
                    $event=match($payment['status']){'verified'=>'daily.payment.verified','rejected'=>'daily.payment.rejected',default=>'daily.payment.review'};
                    \Dormitory\Domain\LineAdminEvents::enqueue($app,$event,$payment['id']);
                }
            });
            return Response::json($data,($data['idempotent_replay']??false)?200:201,'รับหลักฐานแล้ว กรุณาตรวจผลรายการนี้ก่อนรับเงินเพิ่ม');
        },$owner);
        foreach(['cash'=>'cash','refund'=>'refund','deposit-settlement'=>'settleDeposit']as$path=>$method){
            $router->post('/api/admin/daily/bookings/{id}/'.$path,function(Request $r)use($app,$id,$path,$method,$ownerMutation):Response{
                return Response::json($ownerMutation($r,'daily.'.$path,fn()=>$app->dailyPayments()->{$method}($id($r),$r->body,(int)$app->actor()['id'])));
            },$owner);
        }
        $router->post('/api/admin/daily/payments/{id}/retry',function(Request $r)use($app,$id,$ownerMutation):Response{
            Validator::only($r->body,[]);
            // Provider I/O must occur outside a room transaction. The service's
            // finalization callback audits only the short committed result.
            $app->limiter()->hit('daily-owner-payment-retry',(string)$app->actor()['id'],30,3600,300);
            $data=$app->dailyPayments()->retry($id($r),function(array $payment)use($app,$r):void{
                $app->audit()->writeStrict($r,self::currentOwnerForCommit($app),'daily.payment_retry','daily_payment',$payment['id'],['status'=>$payment['status']]);
                $event=match($payment['status']){'verified'=>'daily.payment.verified','rejected'=>'daily.payment.rejected',default=>'daily.payment.review'};
                \Dormitory\Domain\LineAdminEvents::enqueue($app,$event,$payment['id']);
            });
            return Response::json($data);
        },$owner);
        $router->post('/api/admin/daily/payments/{id}/close',function(Request $r)use($app,$id):Response{
            $app->limiter()->hit('daily-owner-payment-close',(string)$app->actor()['id'],30,3600,300);
            $data=$app->dailyPayments()->closePending($id($r),$r->body,(int)$app->actor()['id'],function(array $payment)use($app,$r):void{
                if(($payment['idempotent_replay']??false)!==true){
                    $app->audit()->writeStrict($r,$app->actor(),'daily.payment_closed','daily_payment',$payment['id'],['booking_id'=>$payment['booking_id'],'status'=>$payment['status']]);
                    \Dormitory\Domain\LineAdminEvents::enqueue($app,'daily.payment.review',$payment['id']);
                }
            });
            return Response::json($data,200,'พักการตรวจแล้ว ยังไม่ได้ยืนยันว่าเงินเข้าหรือไม่เข้า กรุณาตรวจหลักฐานก่อนทำรายการเงินเพิ่ม');
        },$owner);
        $router->post('/api/admin/daily/payments/{id}/restore',function(Request $r)use($app,$id):Response{
            Validator::only($r->body,[]);$app->limiter()->hit('daily-owner-evidence-restore',(string)$app->actor()['id'],20,3600,300);
            $data=$app->dailyPayments()->restoreEvidence($id($r),is_array($r->files['slip']??null)?$r->files['slip']:[],(int)$app->actor()['id'],function(array $payment)use($app,$r):void{
                $app->audit()->writeStrict($r,$app->actor(),'daily.evidence_restored','daily_payment',$payment['id'],['booking_id'=>$payment['booking_id']]);
            });
            return Response::json($data,200,'กู้ไฟล์หลักฐานเดิมแล้ว กรุณาตรวจสลิปเพื่อยืนยันการชำระ');
        },$owner);
        $router->get('/api/admin/daily/payments/{id}/slip',function(Request $r)use($app,$id):Response{
            $app->limiter()->hit('daily-owner-evidence',(string)$app->actor()['id'],120,3600,300);
            $evidence=$app->dailyPayments()->evidence($id($r));
            $app->audit()->writeStrict($r,$app->actor(),'daily.evidence_view','daily_payment',$id($r));
            return new Response($evidence['body'],200,['Content-Type'=>$evidence['mime'],'Content-Disposition'=>'inline; filename="'.$evidence['filename'].'"','Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff']);
        },$owner);
    }

    /** Provider I/O releases locks; recheck owner retirement/session version at the result commit. */
    private static function currentOwnerForCommit(Application $app): array
    {
        $actor=$app->actor();
        if(!$actor||$actor['type']!=='admin'||$actor['role']!=='owner')throw new HttpException(403,'สิทธิ์เจ้าของหมดอายุ กรุณาให้เจ้าของตรวจรายการเดิมก่อนทำรายการเงินใหม่','OWNER_ACCESS_REVOKED');
        $query=$app->database()->pdo()->prepare('SELECT role,active,retired_at,auth_version FROM admin_users WHERE id=? FOR SHARE');
        $query->execute([(int)$actor['id']]);$row=$query->fetch();
        if(!$row||$row['role']!=='owner'||!(bool)$row['active']||$row['retired_at']!==null||(int)$row['auth_version']!==(int)$actor['auth_version']){
            throw new HttpException(403,'สิทธิ์เจ้าของเปลี่ยนระหว่างตรวจสลิป หลักฐานเดิมยังอยู่ กรุณาให้เจ้าของที่มีสิทธิ์ตรวจรายการเดิม ห้ามโอนซ้ำ','OWNER_ACCESS_REVOKED');
        }
        return $actor;
    }
}
