<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$arguments=array_slice($argv,1);
if($arguments===['--help']){
    fwrite(STDOUT,implode(PHP_EOL,[
        'Usage: php scripts/process_notifications.php [--loop] [--limit=1-100] [--sleep=1-300]',
        'Unknown, duplicate, or out-of-range arguments fail before database bootstrap.',
    ]).PHP_EOL);
    exit(0);
}
if(in_array('--help',$arguments,true)){
    fwrite(STDERR,'--help must be used by itself'.PHP_EOL);
    exit(64);
}
$limit=null;
$loop=false;
$sleepSeconds=15;
$seenArguments=[];
foreach($arguments as$argument){
    $option=null;$value=null;
    if($argument==='--loop')$option='loop';
    elseif(preg_match('/^--limit=([1-9]\d{0,2})$/D',$argument,$match)===1){
        $option='limit';$value=(int)$match[1];
        if($value>100)$option=null;
    }elseif(preg_match('/^--sleep=([1-9]\d{0,2})$/D',$argument,$match)===1){
        $option='sleep';$value=(int)$match[1];
        if($value>300)$option=null;
    }
    if($option===null){
        fwrite(STDERR,'Unknown or invalid argument'.PHP_EOL);
        exit(64);
    }
    if(isset($seenArguments[$option])){
        fwrite(STDERR,'Duplicate argument: --'.$option.PHP_EOL);
        exit(64);
    }
    $seenArguments[$option]=true;
    if($option==='loop')$loop=true;
    elseif($option==='limit')$limit=$value;
    else$sleepSeconds=$value;
}

$app=null;
$workerIdentity='';
foreach(['WORKER_INSTANCE_ID','RAILWAY_REPLICA_ID','HOSTNAME']as$name){
    $value=getenv($name);
    if(is_string($value)&&trim($value)!==''){$workerIdentity=trim($value);break;}
}
if($workerIdentity==='')$workerIdentity=bin2hex(random_bytes(16));
if(strlen($workerIdentity)>512)$workerIdentity=hash('sha256',$workerIdentity);
$reportInfrastructureFailure=static function(Throwable $error)use(&$app,$workerIdentity):never{
    $requestId=bin2hex(random_bytes(8));
    if($app instanceof Dormitory\Application){
        try{$app->notifications()->recordWorkerHeartbeat(
            $workerIdentity,
            'error',
            null,
            'Notification worker infrastructure failure ('.$error::class.')'
        );}
        catch(Throwable){}
    }
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
    $app->notifications()->recordWorkerHeartbeat($workerIdentity,'starting');
}catch(Throwable $error){
    $reportInfrastructureFailure($error);
}

do{
    try{
        // Read the DB-backed batch size for every loop so an owner can tune
        // the worker from the admin console without restarting it. A CLI
        // --limit remains an explicit one-process override.
        $effectiveLimit=$limit??$app->settings()->intValue('notification_batch_size',25);
        $result=$app->notifications()->process($effectiveLimit);
        $app->notifications()->recordWorkerHeartbeat($workerIdentity,'running',$result);
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
