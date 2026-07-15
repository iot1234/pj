<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
/** @var Dormitory\Application $app */
$app=require dirname(__DIR__).'/bootstrap.php';
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
        fwrite(STDERR,json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
        // Provider-level failures are handled per outbox item. Reaching this
        // catch means the worker infrastructure (for example PDO) is broken;
        // exit so Docker/supervisor can recreate a clean process/connection.
        exit(1);
    }
    if($loop)sleep($sleepSeconds);
}while($loop);
