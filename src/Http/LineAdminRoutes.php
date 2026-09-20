<?php
declare(strict_types=1);
namespace Dormitory\Http;

use Dormitory\Application;
use Dormitory\Support\Validator;

final class LineAdminRoutes
{
    public static function register(Router $router,Application $app): void
    {
        $auth=['auth'=>'admin'];
        $admin=static fn():int=>(int)$app->actor()['id'];
        $id=static fn(Request $r):int=>Validator::id($r->param('id'),'id');
        $oa=static function(Request $r)use($app):int{$value=$r->param('id');if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',$value))throw new HttpException(422,'OA ID ไม่ถูกต้อง','VALIDATION_ERROR');$app->lineOfficialAccounts()->assertBotId((int)$value);return(int)$value;};
        $empty=static function(Request $r):void{Validator::only($r->body,[]);};
        $limited=static function(string $kind)use($app,$admin):void{$app->limiter()->hit('admin-line-platform-'.$kind,(string)$admin(),60,3600,300);};
        $router->get('/api/admin/line/oas',function(Request $r)use($app):Response{
            $rows=$app->lineOfficialAccounts()->all();$default=null;
            foreach($rows as$row)if($row['is_default']&&$row['enabled'])$default=$row['id'];
            return Response::json(['rows'=>$rows,'default_oa_id'=>$default]);
        },$auth);
        $router->post('/api/admin/line/oas',function(Request $r)use($app,$admin,$limited):Response{$limited('oa');return Response::json($app->lineOfficialAccounts()->create($r->body,$admin()),201);},$auth);
        $router->get('/api/admin/line/oas/{id}',fn(Request $r)=>Response::json($app->lineOfficialAccounts()->get($oa($r))),$auth);
        $router->put('/api/admin/line/oas/{id}',function(Request $r)use($app,$admin,$limited,$oa):Response{$limited('oa');return Response::json($app->lineOfficialAccounts()->update($oa($r),$r->body,$admin()));},$auth);
        $router->delete('/api/admin/line/oas/{id}',function(Request $r)use($app,$admin,$limited,$oa,$empty):Response{$empty($r);$limited('oa');return Response::json($app->lineOfficialAccounts()->remove($oa($r),$admin()));},$auth);
        foreach(['test'=>'test','rotate-route'=>'rotateRoute']as$path=>$method)$router->post('/api/admin/line/oas/{id}/'.$path,function(Request $r)use($app,$admin,$limited,$oa,$empty,$method):Response{$empty($r);$limited('oa');return Response::json($app->lineOfficialAccounts()->$method($oa($r),$admin()));},$auth);
        $router->get('/api/admin/line/oas/{id}/webhook-status',fn(Request $r)=>Response::json($app->lineOfficialAccounts()->get($oa($r))),$auth);
        $router->get('/api/admin/line/bindings',fn(Request $r)=>Response::json($app->lineRoomBindings()->overview()),$auth);
        $router->get('/api/admin/line/residents/{id}',fn(Request $r)=>Response::json($app->lineRoomBindings()->detail($id($r))),$auth);
        $router->post('/api/admin/line/residents/{id}/codes',function(Request $r)use($app,$admin,$limited,$id):Response{$limited('codes');$target=$id($r);$app->limiter()->hit('line-platform-room-issue',(string)$target,20,3600,300);return Response::json($app->lineRoomBindings()->issue($target,$r->body,$admin()),201);},$auth);
        foreach(['codes'=>'revokeCode','accounts'=>'revokeAccount']as$path=>$method)$router->delete('/api/admin/line/residents/{id}/'.$path.'/{bindingId}',function(Request $r)use($app,$admin,$limited,$id,$empty,$method):Response{$empty($r);$limited('bindings');$binding=$r->param('bindingId');if(!is_string($binding)||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',$binding))throw new HttpException(422,'Binding ID ไม่ถูกต้อง','VALIDATION_ERROR');return Response::json($app->lineRoomBindings()->$method($id($r),(int)$binding,$admin()));},$auth);
        $router->delete('/api/admin/line/residents/{id}/accounts',function(Request $r)use($app,$admin,$limited,$id,$empty):Response{$empty($r);$limited('bindings');return Response::json($app->lineRoomBindings()->revokeAll($id($r),$admin()));},$auth);
        $router->post('/api/admin/line/residents/{id}/block',function(Request $r)use($app,$admin,$limited,$id):Response{Validator::only($r->body,['reason']);$reason=Validator::string($r->body['reason']??'','reason',1,500);$limited('bindings');return Response::json($app->lineRoomBindings()->block($id($r),$reason,$admin()));},$auth);
        $router->post('/api/admin/line/residents/{id}/unblock',function(Request $r)use($app,$admin,$limited,$id,$empty):Response{$empty($r);$limited('bindings');return Response::json($app->lineRoomBindings()->unblock($id($r),$admin()));},$auth);
        $router->get('/api/admin/line/recipients',fn(Request $r)=>Response::json(['rows'=>$app->lineAdminRecipients()->all()]),$auth);
        $router->post('/api/admin/line/recipients',function(Request $r)use($app,$admin,$limited):Response{$limited('recipients');return Response::json($app->lineAdminRecipients()->issue($r->body,$admin()),201);},$auth);
        $router->get('/api/admin/line/recipients/{id}',fn(Request $r)=>Response::json($app->lineAdminRecipients()->get($id($r))),$auth);
        $router->put('/api/admin/line/recipients/{id}',function(Request $r)use($app,$admin,$limited,$id):Response{$limited('recipients');return Response::json($app->lineAdminRecipients()->update($id($r),$r->body,$admin()));},$auth);
        $router->delete('/api/admin/line/recipients/{id}',function(Request $r)use($app,$admin,$limited,$id,$empty):Response{$empty($r);$limited('recipients');return Response::json($app->lineAdminRecipients()->revoke($id($r),$admin()));},$auth);
    }
}
