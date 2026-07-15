<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$reportInfrastructureFailure=static function(Throwable $error):never{
    $requestId=bin2hex(random_bytes(8));
    error_log(sprintf('[notification-worker:%s] infrastructure failure (%s)',$requestId,$error::class));
    fwrite(STDERR,json_encode([
        'ok'=>false,
        'message'=>'Notification worker is temporarily unavailable',
        'request_id'=>$requestId,
    ],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
};
try{
    /** @var Dormitory\Application $app */
    $app=require dirname(__DIR__).'/bootstrap.php';
}catch(Throwable $error){
    $reportInfrastructureFailure($error);
}
$limit=null;
$loop=false;
$sleepSeconds=15;
foreach(array_slice($argv,1)as$argument){
    if($argument==='--loop')$loop=true;
    elseif(preg_match('/^--limit=(\d+)$/',$argument,$match))$limit=max(1,min(100,(int)$match[1]));
    elseif(preg_match('/^--sleep=(\d+)$/',$argument,$match))$sleepSeconds=max(1,min(300,(int)$match[1]));
}

do{
    try{
        // Read the DB-backed batch size for every loop so an owner can tune
        // the worker from the admin console without restarting it. A CLI
        // --limit remains an explicit one-process override.
        $effectiveLimit=$limit??$app->settings()->intValue('notification_batch_size',25);
        $result=$app->notifications()->process($effectiveLimit);
        if(!$loop||$result['processed']>0){
            fwrite(STDOUT,json_encode(['ok'=>true,'data'=>$result],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }catch(Throwable $error){
        // Provider-level failures are handled per outbox item. Reaching this
        // catch means the worker infrastructure (for example PDO) is broken;
        // exit so Docker/supervisor can recreate a clean process/connection.
        $reportInfrastructureFailure($error);
    }
    if($loop)sleep($sleepSeconds);
}while($loop);
