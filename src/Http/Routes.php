<?php
declare(strict_types=1);

namespace Dormitory\Http;

use Dormitory\Application;
use Dormitory\Domain\PromptPayService;
use Dormitory\Support\Validator;

final class Routes
{
    public static function build(Application $app): Router
    {
        $router=new Router($app);
        LineAdminRoutes::register($router,$app);
        $page=static function(string $template,string $title,string $pageId,?array $user=null) use($app): Response {
            return Response::html($app->view()->render($template,['title'=>$title,'page'=>$pageId,'csrfToken'=>$app->security()->csrfToken(),'user'=>$user??[],'appTimezone'=>(string)$app->config->get('APP_TIMEZONE','Asia/Bangkok')]));
        };
        $id=static fn(Request $r): int=>Validator::id($r->param('id'),'id');
        $queryString=static function(array $query,string $key):?string{if(!array_key_exists($key,$query)||$query[$key]==='')return null;if(!is_string($query[$key]))throw new HttpException(422,"Invalid {$key}",'VALIDATION_ERROR',['field'=>$key]);return $query[$key];};
        $queryInteger=static function(array $query,string $key,int $default):int{if(!array_key_exists($key,$query)||$query[$key]==='')return $default;if(!is_string($query[$key]))throw new HttpException(422,"Invalid {$key}",'VALIDATION_ERROR',['field'=>$key]);$raw=$query[$key];if(!preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw))throw new HttpException(422,"Invalid {$key}",'VALIDATION_ERROR',['field'=>$key]);return (int)$raw;};
        $audit=static function(Request $r,string $action,string $type,int|string|null $entityId=null,array $details=[])use($app):void{$app->audit()->write($r,$app->actor(),$action,$type,$entityId,$details);};

        $router->get('/',fn(Request $r)=>$page('public/home.php','Dormitory','public-home',$app->actor()));
        $router->get('/resident/login',function(Request $r)use($app,$page):Response{$actor=$app->actor();return $actor&&$actor['type']==='resident'?Response::redirect('/resident'):$page('resident/login.php','Resident login','resident-login');});
        $router->get('/resident',fn(Request $r)=>$page('resident/portal.php','Resident portal','resident-portal',$app->actor()),['auth'=>'resident']);
        $router->get('/admin/login',function(Request $r)use($app,$page):Response{$actor=$app->actor();return $actor&&$actor['type']==='admin'?Response::redirect('/admin'):$page('admin/login.php','Admin login','admin-login');});
        $router->get('/admin',fn(Request $r)=>$page('admin/console.php','Admin console','admin-console',$app->actor()),['auth'=>'admin']);

        $router->get('/api/public/rooms',fn(Request $r)=>Response::json($app->rooms()->available()));
        $router->get('/api/public/contact',fn(Request $r)=>Response::json($app->settings()->publicContact()));
        $router->post('/api/public/bookings',function(Request $r)use($app):Response{$clientIp=$app->security()->clientIp($r);$app->limiter()->hit('public-booking-attempt-ip',$clientIp,60,3600,3600);$app->bookings()->expirePublicPhoneHolds($r->body['phone']??null);$outcome=$app->database()->transaction(function()use($app,$r,$clientIp):array{$outcome=$app->bookings()->createPublicOutcome($r->body,$clientIp);$replay=($outcome['idempotent_replay']??false)===true;if(!$app->bookings()->isErrorOutcome($outcome)&&!$replay)$app->audit()->writeStrict($r,$app->actor(),'booking.create','booking',$outcome['id'],['room_id'=>$outcome['room_id']]);return $outcome;});$data=$app->bookings()->resolveOutcome($outcome);$replay=($data['idempotent_replay']??false)===true;return Response::json($data,$replay?200:201,$replay?'Existing booking returned':'Booking request received');});
        $router->post('/api/webhooks/line',fn(Request $r)=>Response::json($app->lineWebhook()->handle($r),200,'LINE webhook accepted'));
        $router->post('/api/webhooks/line/oa/{routeToken}',function(Request $r)use($app):Response{
            $oa=$app->lineOfficialAccounts()->byRouteToken((string)$r->param('routeToken'));
            return Response::json((new \Dormitory\Domain\LineWebhookService($app,null,(int)$oa['id']))->handle($r),200,'LINE webhook accepted');
        });

        $router->post('/api/auth/admin/login',fn(Request $r)=>Response::json(['user'=>$app->auth()->adminLogin($r,$r->body),'csrf_token'=>$app->session()->csrfToken()]));
        $router->post('/api/auth/admin/logout',function(Request $r)use($app):Response{Validator::only($r->body,[]);$app->auth()->logout($r);return Response::json(null,200,'Logged out');},['auth'=>'admin']);
        $router->post('/api/auth/resident/login',fn(Request $r)=>Response::json(['user'=>$app->auth()->residentLogin($r,$r->body),'csrf_token'=>$app->session()->csrfToken()]));
        $router->post('/api/auth/resident/logout',function(Request $r)use($app):Response{Validator::only($r->body,[]);$app->auth()->logout($r);return Response::json(null,200,'Logged out');},['auth'=>'resident']);
        $router->get('/api/auth/me',fn(Request $r)=>Response::json(['user'=>$app->actor(),'csrf_token'=>$app->security()->csrfToken()]));

        $router->get('/api/resident/profile',fn(Request $r)=>Response::json($app->residents()->profile((int)$app->actor()['id'])),['auth'=>'resident']);
        $router->put('/api/resident/profile',function(Request $r)use($app):Response{$residentId=(int)$app->actor()['id'];$data=$app->database()->transaction(function()use($app,$r,$residentId):array{$data=$app->residents()->updateProfile($residentId,$r->body);$app->audit()->writeStrict($r,$app->actor(),'resident.profile_update','resident',$data['id']);return $data;});return Response::json($data);},['auth'=>'resident']);
        $router->post('/api/resident/profile/line/code',function(Request $r)use($app):Response{
            Validator::only($r->body,[]);$actor=$app->actor();$residentId=(int)$actor['id'];
            $app->limiter()->hit('resident-line-code-issue',(string)$residentId,5,3600,600);
            $app->session()->clearLineLinkChallenge();
            $data=$app->notifications()->withLineBindingLock($residentId,function()use($app,$r,$actor,$residentId):array{
                return $app->database()->transaction(function()use($app,$r,$actor,$residentId):array{
                    $data=$app->lineBindings()->issue($residentId,(int)$actor['auth_version']);
                    $app->audit()->writeStrict($r,$actor,'resident.line_link_code_issued','resident',$residentId,['expires_at'=>$data['expires_at'],'single_use'=>true]);
                    return $data;
                });
            });
            return Response::json($data,201,'LINE link code created');
        },['auth'=>'resident']);
        $router->post('/api/resident/profile/line/unlink',function(Request $r)use($app):Response{Validator::only($r->body,[]);$actor=$app->actor();$residentId=(int)$actor['id'];$app->limiter()->hit('resident-line-unlink',(string)$residentId,5,3600,3600);$data=$app->notifications()->withLineBindingLock($residentId,function()use($app,$r,$actor,$residentId):array{return $app->database()->transaction(function()use($app,$r,$actor,$residentId):array{$data=$app->residents()->unlinkLine($residentId,$r->body);$app->lineBindings()->revokePending($residentId);$app->audit()->writeStrict($r,$actor,'resident.line_unlinked','resident',$residentId);return $data;});});$app->session()->clearLineLinkChallenge();return Response::json($data,200,'LINE account unlinked');},['auth'=>'resident']);
        $router->get('/api/resident/bills',fn(Request $r)=>Response::json($app->billing()->residentList((int)$app->actor()['id'])),['auth'=>'resident']);
        $router->get('/api/resident/bills/{id}',fn(Request $r)=>Response::json($app->billing()->residentDetail((int)$app->actor()['id'],$id($r))),['auth'=>'resident']);
        $router->get('/api/resident/bills/{id}/promptpay',function(Request $r)use($app,$id):Response{
            $residentId=(int)$app->actor()['id'];$billId=$id($r);
            $envelope=$app->database()->transaction(function(\PDO $pdo)use($app,$residentId,$billId):array{
                // Serialize this short snapshot against payment reservation,
                // bill finalization, and integration-setting rotation. The QR
                // must never combine values observed at different revisions.
                $billLock=$pdo->prepare('SELECT id FROM bills WHERE id=? AND resident_id=? FOR SHARE');
                $billLock->execute([$billId,$residentId]);
                if($billLock->fetchColumn()===false)throw new HttpException(404,'Bill not found','BILL_NOT_FOUND');
                $settingsId=$pdo->query('SELECT id FROM integration_settings WHERE id=1 FOR SHARE')->fetchColumn();
                if($settingsId===false)throw new HttpException(503,'ยังไม่พบการตั้งค่าระบบ กรุณาติดต่อผู้ดูแล','INTEGRATION_SETTINGS_MISSING');
                $bill=$app->billing()->residentDetail($residentId,$billId);
                $app->billing()->assertPromptPayAvailable($bill);
                $target=trim((string)$app->settings()->value('promptpay_target',''));
                if($target==='')throw new HttpException(503,'ยังไม่ได้ตั้งค่า PromptPay กรุณาติดต่อผู้ดูแลก่อนโอน','PROMPTPAY_NOT_CONFIGURED');
                return ['bill_id'=>$bill['id'],'amount'=>$bill['total_amount'],'target'=>$target,'name'=>$app->settings()->value('promptpay_name'),'payload'=>PromptPayService::payload($target,(string)$bill['total_amount'])];
            });
            return Response::json($envelope);
        },['auth'=>'resident']);
        $router->post('/api/resident/bills/{id}/slip',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$resident=(int)$app->actor()['id'];$app->limiter()->hit('slip-resident',(string)$resident,8,3600,3600);$data=$app->payments()->upload($id($r),$resident,is_array($r->files['slip']??null)?$r->files['slip']:[],function(array $payment)use($app,$r):void{$replay=($payment['idempotent_replay']??false)===true;$app->audit()->writeStrict($r,$app->actor(),$replay?'payment.slip_upload_replayed':'payment.slip_upload','payment',$payment['id'],['bill_id'=>$payment['bill_id'],'status'=>$payment['status']]);});$replay=($data['idempotent_replay']??false)===true;return Response::json($data,$replay?200:201,$replay?'Existing slip result returned':'Slip received');},['auth'=>'resident']);

        $owner=['auth'=>'admin','role'=>'owner'];$admin=['auth'=>'admin'];
        $router->get('/api/admin/users',fn(Request $r)=>Response::json($app->adminUsers()->list()),$owner);
        $router->post('/api/admin/users',function(Request $r)use($app):Response{$data=$app->database()->transaction(function()use($app,$r):array{$data=$app->adminUsers()->create($r->body,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'admin_user.create','admin_user',$data['id'],['role'=>$data['role']]);return $data;});return Response::json($data,201);},$owner);
        $router->put('/api/admin/users/{id}',function(Request $r)use($app,$id):Response{$target=$id($r);$data=$app->database()->transaction(function()use($app,$r,$target):array{$data=$app->adminUsers()->update($target,$r->body,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'admin_user.update','admin_user',$data['id'],['role'=>$data['role'],'active'=>$data['active']]);return $data;});return Response::json($data);},$owner);
        $router->delete('/api/admin/users/{id}',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$target=$id($r);$app->database()->transaction(function()use($app,$r,$target):void{$app->adminUsers()->delete($target,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'admin_user.disable','admin_user',$target);});return Response::json(null,200,'Admin disabled');},$owner);

        $router->get('/api/admin/rooms',fn(Request $r)=>Response::json($app->rooms()->all()),$admin);
        $router->post('/api/admin/rooms',function(Request $r)use($app):Response{$data=$app->database()->transaction(function()use($app,$r):array{$data=$app->rooms()->create($r->body);$app->audit()->writeStrict($r,$app->actor(),'room.create','room',$data['id']);return $data;});return Response::json($data,201);},$admin);
        $router->put('/api/admin/rooms/{id}',function(Request $r)use($app,$id):Response{$target=$id($r);$data=$app->database()->transaction(function()use($app,$r,$target):array{$data=$app->rooms()->update($target,$r->body);$app->audit()->writeStrict($r,$app->actor(),'room.update','room',$data['id']);return $data;});return Response::json($data);},$admin);
        $router->delete('/api/admin/rooms/{id}',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$target=$id($r);$app->database()->transaction(function()use($app,$r,$target):void{$app->rooms()->delete($target);$app->audit()->writeStrict($r,$app->actor(),'room.delete','room',$target);});return Response::json(null,200,'Room deleted');},$admin);
        $router->get('/api/admin/residents',fn(Request $r)=>Response::json($app->residents()->list()),$admin);
        $router->post('/api/admin/occupancies/{id}/opening-readings',function(Request $r)use($app,$id):Response{
            $target=$id($r);$actor=$app->actor();
            $app->limiter()->hit('occupancy-opening-readings',(string)$actor['id'],60,3600,300);
            $data=$app->database()->transaction(function()use($app,$r,$target,$actor):array{
                $data=$app->meters()->setOpeningReadings($target,$r->body);
                if(!$data['idempotent_replay']){
                    $app->audit()->writeStrict($r,$actor,'occupancy.opening_readings_set','occupancy',$target,[
                        'room_id'=>$data['room_id'],'opening_water_reading'=>$data['opening_water_reading'],
                        'opening_electric_reading'=>$data['opening_electric_reading'],
                    ]);
                }
                return $data;
            });
            return Response::json($data,200,'บันทึกเลขมิเตอร์เริ่มต้นแล้ว');
        },$admin);
        $router->get('/api/admin/residents/{id}/line',fn(Request $r)=>Response::json($app->residents()->lineStatus($id($r))),$admin);
        $router->post('/api/admin/residents/{id}/line/code',function(Request $r)use($app,$id):Response{
            Validator::only($r->body,[]);$actor=$app->actor();$target=$id($r);
            $app->limiter()->hit('admin-line-code-issue',(string)$actor['id'],30,3600,600);
            $app->limiter()->hit('resident-line-code-issue',(string)$target,5,3600,600);
            $data=$app->notifications()->withLineBindingLock($target,function()use($app,$r,$actor,$target):array{
                return $app->database()->transaction(function()use($app,$r,$actor,$target):array{
                    $data=$app->lineBindings()->issueForAdmin($target);
                    $app->audit()->writeStrict($r,$actor,'resident.line_link_code_issued','resident',$target,['expires_at'=>$data['expires_at'],'single_use'=>true,'issued_by_admin'=>true]);
                    return ['resident_id'=>$target]+$data;
                });
            });
            return Response::json($data,201,'Resident LINE link code created');
        },$admin);
        $router->post('/api/admin/residents/{id}/line/unlink',function(Request $r)use($app,$id):Response{
            Validator::only($r->body,[]);$actor=$app->actor();$target=$id($r);
            $app->limiter()->hit('admin-line-unlink',(string)$actor['id'],30,3600,600);
            $app->limiter()->hit('resident-line-unlink',(string)$target,5,3600,600);
            $data=$app->notifications()->withLineBindingLock($target,function()use($app,$r,$actor,$target):array{
                return $app->database()->transaction(function()use($app,$r,$actor,$target):array{
                    $app->residents()->unlinkLine($target,[]);
                    $app->lineBindings()->revokePending($target);
                    $app->audit()->writeStrict($r,$actor,'resident.line_unlinked','resident',$target,['reason'=>'admin_unlink']);
                    return $app->residents()->lineStatus($target);
                });
            });
            return Response::json($data,200,'Resident LINE account unlinked');
        },$admin);
        $router->post('/api/admin/residents',function(Request $r)use($app):Response{$adminId=(int)$app->actor()['id'];$app->limiter()->hit('resident-admin-create',(string)$adminId,60,3600,300);$app->bookings()->expirePublicPhoneHolds($r->body['phone']??null);$data=$app->database()->transaction(function()use($app,$r,$adminId):array{$data=$app->bookings()->createAdminResident($adminId,$r->body);$replay=($data['idempotent_replay']??false)===true;if(!$replay)$app->audit()->writeStrict($r,$app->actor(),'resident.admin_create','resident',$data['resident_id'],['booking_id'=>$data['booking_id'],'occupancy_id'=>$data['occupancy_id'],'room_id'=>$data['room_id'],'move_in_date'=>$data['move_in_date'],'opening_water_reading'=>$r->body['opening_water_reading']??null,'opening_electric_reading'=>$r->body['opening_electric_reading']??null]);return $data;});$replay=($data['idempotent_replay']??false)===true;return Response::json($data,$replay?200:201,$replay?'Existing resident check-in returned':'Resident checked in');},$admin);
        $router->put('/api/admin/residents/{id}',function(Request $r)use($app,$id):Response{$target=$id($r);$data=$app->notifications()->withLineBindingLock($target,function()use($app,$r,$target):array{return $app->database()->transaction(function()use($app,$r,$target):array{$data=$app->residents()->updateByAdmin($target,$r->body);$lineAudit=is_array($data['_line_unlinked_audit']??null)?$data['_line_unlinked_audit']:null;unset($data['_line_unlinked_audit']);if(($data['sessions_revoked']??false)===true)$app->lineBindings()->revokePending($target);if($lineAudit!==null)$app->audit()->writeStrict($r,$app->actor(),'resident.line_unlinked','resident',$target,$lineAudit);$app->audit()->writeStrict($r,$app->actor(),'resident.admin_update','resident',$target,['changed_fields'=>$data['changed_fields'],'sessions_revoked'=>$data['sessions_revoked']]);return $data;});});return Response::json($data);},$admin);
        $router->post('/api/admin/residents/{id}/access/reissue',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$target=$id($r);$adminId=(int)$app->actor()['id'];$app->limiter()->hit('resident-access-reissue',(string)$adminId,30,3600,600);$data=$app->notifications()->withLineBindingLock($target,function()use($app,$r,$target):array{return $app->database()->transaction(function()use($app,$r,$target):array{$data=$app->residents()->reissueAccess($target);$app->audit()->writeStrict($r,$app->actor(),'resident.access_reissued','resident',$target,['sessions_revoked'=>true,'activation_expires_at'=>$data['resident_access']['expires_at']??null]);return $data;});});return Response::json($data,200,'Resident access reissued');},$admin);
        $router->post('/api/admin/residents/{id}/move-out',function(Request $r)use($app,$id):Response{$target=$id($r);$data=$app->notifications()->withLineBindingLock($target,function()use($app,$r,$target):array{return $app->database()->transaction(function()use($app,$r,$target):array{$data=$app->residents()->moveOut($target,$r->body);$app->lineBindings()->revokePending($target);$lineAudit=is_array($data['_line_unlinked_audit']??null)?$data['_line_unlinked_audit']:null;unset($data['_line_unlinked_audit']);if($lineAudit!==null)$app->audit()->writeStrict($r,$app->actor(),'resident.line_unlinked','resident',$target,$lineAudit);$app->audit()->writeStrict($r,$app->actor(),'resident.move_out','resident',$target,['occupancy_id'=>$data['occupancy_id'],'room_id'=>$data['room_id'],'move_out_date'=>$data['move_out_date'],'closing_bill_id'=>$data['closing_bill_id']]);return $data;});});return Response::json($data,200,'Resident moved out');},$admin);
        $router->get('/api/admin/bookings',fn(Request $r)=>Response::json($app->bookings()->all($queryString($r->query,'status'),$queryInteger($r->query,'offset',0),$queryInteger($r->query,'limit',100))),$admin);
        $router->post('/api/admin/bookings/{id}/confirm',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$target=$id($r);$outcome=$app->database()->transaction(function()use($app,$r,$target):array{$outcome=$app->bookings()->confirmOutcome($target,(int)$app->actor()['id']);if(!$app->bookings()->isErrorOutcome($outcome))$app->audit()->writeStrict($r,$app->actor(),'booking.confirm','booking',$outcome['id']);return $outcome;});$data=$app->bookings()->resolveOutcome($outcome);return Response::json($data);},$admin);
        $router->post('/api/admin/bookings/{id}/cancel',function(Request $r)use($app,$id):Response{Validator::only($r->body,['reason']);if(isset($r->body['reason'])&&!is_string($r->body['reason']))throw new HttpException(422,'reason ไม่ถูกต้อง','VALIDATION_ERROR',['field'=>'reason']);$target=$id($r);$reason=$r->body['reason']??null;$data=$app->database()->transaction(function()use($app,$r,$target,$reason):array{$data=$app->bookings()->cancel($target,(int)$app->actor()['id'],$reason);$app->audit()->writeStrict($r,$app->actor(),'booking.cancel','booking',$data['id'],['reason'=>$reason]);return $data;});return Response::json($data);},$admin);
        $router->post('/api/admin/bookings/{id}/move-in',function(Request $r)use($app,$id):Response{$target=$id($r);$data=$app->database()->transaction(function()use($app,$r,$target):array{$data=$app->bookings()->moveIn($target,(int)$app->actor()['id'],$r->body);$app->audit()->writeStrict($r,$app->actor(),($data['idempotent_replay']??false)?'booking.move_in_replayed':'booking.move_in','booking',$data['booking_id'],['resident_id'=>$data['resident_id'],'occupancy_id'=>$data['occupancy_id'],'opening_water_reading'=>$r->body['opening_water_reading']??null,'opening_electric_reading'=>$r->body['opening_electric_reading']??null]);return $data;});return Response::json($data);},$admin);
        $router->get('/api/admin/meters',function(Request $r)use($app):Response{return Response::json($app->meters()->list(Validator::period($r->query['period']??null)));},$admin);
        $router->post('/api/admin/meters',function(Request $r)use($app):Response{$data=$app->database()->transaction(function()use($app,$r):array{$data=$app->meters()->record($r->body,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'meter.record','meter',(string)$data['room_id'].'@'.$data['period'],['occupancy_id'=>$data['occupancy_id']??null,'changes'=>$data['changes']??[],'unchanged_meter_types'=>$data['unchanged_meter_types']??[],'large_usage_confirmed'=>$data['large_usage_confirmed']??false,'large_usage_anomalies'=>$data['large_usage_anomalies']??[]]);return $data;});return Response::json($data);},$admin);
        $router->post('/api/admin/bills/preview',fn(Request $r)=>Response::json($app->billing()->preview($r->body)),$admin);
        $router->post('/api/admin/bills/bulk',function(Request $r)use($app):Response{$data=$app->database()->transaction(function()use($app,$r):array{$data=$app->billing()->bulk($r->body,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'bill.bulk_create','bill',null,['period'=>$data['period'],'created'=>count($data['created'])]);return $data;});return Response::json($data,201);},$admin);
        $router->post('/api/admin/bills/generate-closed',function(Request $r)use($app):Response{Validator::only($r->body,['period','due_date']);$period=Validator::period($r->body['period']??null);$dueDate=array_key_exists('due_date',$r->body)?Validator::date($r->body['due_date'],'due_date'):null;$adminId=(int)$app->actor()['id'];$data=$app->billing()->generateClosedPeriod($period,$adminId,$dueDate,function(array $result)use($app,$r,$period):void{$app->audit()->writeStrict($r,$app->actor(),'bill.monthly_generate','bill',null,['period'=>$period,'created'=>count($result['created']??[]),'skipped'=>count($result['skipped']??[])]);});return Response::json($data,200,'Completed month generated');},$admin);
        $router->get('/api/admin/bills/candidates',fn(Request $r)=>Response::json($app->billing()->roomCandidates(Validator::period($r->query['period']??null))),$admin);
        $router->get('/api/admin/bills',fn(Request $r)=>Response::json($app->billing()->adminList($queryString($r->query,'period'))),$admin);
        $router->post('/api/admin/bills/{id}/line',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$billId=$id($r);$data=$app->lineOfficialAccounts()->withRegistryLock(fn()=>$app->database()->transaction(function()use($app,$r,$billId):array{$data=$app->notifications()->enqueueBill($billId);$app->audit()->writeStrict($r,$app->actor(),'line.enqueue','notification',$data['id'],['bill_id'=>$data['bill_id'],'enqueue_state'=>$data['enqueue_state']??null]);return $data;}));return Response::json($data,202);},$admin);
        $router->post('/api/admin/bills/line-bulk',function(Request $r)use($app):Response{$data=$app->lineOfficialAccounts()->withRegistryLock(fn()=>$app->database()->transaction(function()use($app,$r):array{$data=$app->notifications()->enqueuePeriod($r->body);$app->audit()->writeStrict($r,$app->actor(),'line.enqueue_bulk','notification',null,['period'=>$data['period'],'queued'=>count($data['queued']),'already'=>count($data['already']??[])]);return $data;}));return Response::json($data,202);},$admin);
        $router->get('/api/admin/payments',function(Request $r)use($app,$queryString,$queryInteger):Response{
            return Response::json($app->payments()->list($queryString($r->query,'status'),$queryInteger($r->query,'offset',0),$queryInteger($r->query,'limit',100)));
        },$admin);
        $router->get('/api/admin/payments/{id}/slip',function(Request $r)use($app,$id):Response{$target=$id($r);$app->limiter()->hit('payment-evidence-admin',(string)$app->actor()['id'],120,3600,300);$evidence=$app->payments()->evidence($target);$app->audit()->writeStrict($r,$app->actor(),'payment.evidence_view','payment',$target);return new Response($evidence['body'],200,['Content-Type'=>$evidence['mime'],'Content-Disposition'=>'inline; filename="'.$evidence['filename'].'"','Content-Length'=>(string)strlen($evidence['body']),'Cache-Control'=>'no-store, private','X-Download-Options'=>'noopen']);},$admin);
        $router->post('/api/admin/payments/{id}/retry',function(Request $r)use($app,$id):Response{Validator::only($r->body,[]);$target=$id($r);$app->limiter()->hit('payment-retry-admin',(string)$app->actor()['id'],30,3600,300);$data=$app->payments()->retry($target,function(array $payment)use($app,$r):void{$app->audit()->writeStrict($r,$app->actor(),'payment.retry','payment',$payment['id'],['bill_id'=>$payment['bill_id'],'status'=>$payment['status']]);});return Response::json($data,200,'Payment verification retried');},$admin);
        $router->post('/api/admin/payments/{id}/close',function(Request $r)use($app,$id):Response{$target=$id($r);$data=$app->database()->transaction(function()use($app,$r,$target):array{$data=$app->payments()->closePending($target,$r->body);$app->audit()->writeStrict($r,$app->actor(),'payment.close_pending','payment',$target,['bill_id'=>$data['bill_id'],'status'=>$data['status']]);return $data;});return Response::json($data,200,'Pending payment closed');},$admin);
        $router->get('/api/admin/settings',function(Request $r)use($app):Response{$settings=$app->billing()->settings();$settings['integrations']=$app->settings()->publicSettings();return Response::json($settings);},$admin);
        $router->put('/api/admin/settings',function(Request $r)use($app):Response{$data=$app->database()->transaction(function()use($app,$r):array{$data=$app->billing()->updateSettings($r->body,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'billing_settings.update','billing_settings',1,$data);return $data;});return Response::json($data);},$admin);
        $router->put('/api/admin/settings/integrations',function(Request $r)use($app):Response{
            if(array_intersect(array_keys($r->body),['line_basic_id','line_channel_access_token','line_channel_access_token_clear','line_channel_secret','line_channel_secret_clear'])!==[])throw new HttpException(422,'กรุณาตั้งค่าคีย์ LINE ในหน้าบัญชี LINE OA','LINE_MANAGED_SEPARATELY');
            $data=$app->database()->transaction(function()use($app,$r):array{$data=$app->settings()->update($r->body,(int)$app->actor()['id']);$app->audit()->writeStrict($r,$app->actor(),'integration_settings.update','integration_settings',1,['configured_fields'=>$data['configured_fields']??[],'readiness'=>$data['readiness']??[]]);return $data;});return Response::json($data);
        },$owner);
        $router->post('/api/admin/settings/integrations/test',function(Request $r)use($app,$audit):Response{Validator::only($r->body,['integration']);$app->limiter()->hit('integration-test',(string)$app->actor()['id'],30,3600,300);$integration=Validator::string($r->body['integration']??null,'integration',1,20);$data=$app->settings()->testConnection($integration);$audit($r,'integration_settings.test','integration_settings',1,['integration'=>$integration,'ready'=>$data['ready']??false]);return Response::json($data);},$owner);
        $router->get('/api/admin/operations/health',fn(Request $r)=>Response::json([
            'notifications'=>$app->notifications()->workerHealth(),
        ]),$owner);

        return $router;
    }
}
