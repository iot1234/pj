<?php
declare(strict_types=1);

use Dormitory\Http\Request;
use Dormitory\Http\Response;
use Dormitory\Http\Routes;
use Dormitory\Http\HttpException;

/** @var Dormitory\Application $app */
$app=require dirname(__DIR__).'/bootstrap.php';
$requestId=bin2hex(random_bytes(8));
try{
    $request=Request::capture();
    $requestId=$request->requestId;
    $response=Routes::build($app)->dispatch($request);
}catch(HttpException $error){
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
    $response=str_starts_with($path,'/api/')
        ?Response::error($error->getMessage(),$error->status,$error->errorCode,$error->details)
        :Response::htmlError($error->status,$error->status>=500?$requestId:null);
}catch(Throwable $error){
    error_log(sprintf('[%s] %s: %s',$requestId,$error::class,$error->getMessage()));
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
    $response=str_starts_with($path,'/api/')
        ?Response::error('Internal server error',500,'INTERNAL_ERROR',['request_id'=>$requestId])
        :Response::htmlError(500,$requestId);
}
$response->send($app->security()->headers($requestId));
