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
        :Response::html('<h1>'.e($error->status).'</h1><p>'.e($error->getMessage()).'</p>',$error->status);
}catch(Throwable $error){
    error_log(sprintf('[%s] %s: %s',$requestId,$error::class,$error->getMessage()));
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
    $response=str_starts_with($path,'/api/')
        ?Response::error('Internal server error',500,'INTERNAL_ERROR',['request_id'=>$requestId])
        :Response::html('<h1>500</h1><p>Internal server error</p>',500);
}
$response->send($app->security()->headers($requestId));
