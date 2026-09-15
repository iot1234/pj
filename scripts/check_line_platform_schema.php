<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/Support/LinePlatformSchema.php';
try{
    $host=(string)getenv('DB_HOST');$port=(string)getenv('DB_PORT');$database=(string)getenv('DB_DATABASE');
    if(preg_match('/^[A-Za-z0-9._:-]{1,253}$/D',$host)!==1||preg_match('/^[0-9]{1,5}$/D',$port)!==1||preg_match('/^[A-Za-z0-9_]{1,64}$/D',$database)!==1)throw new RuntimeException('Invalid LINE schema check database parameters');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5];
    if(in_array(strtolower((string)getenv('DB_SSL')),['1','true','yes','on'],true)){$options[PDO::MYSQL_ATTR_SSL_CA]=(string)getenv('DB_SSL_CA');$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",(string)getenv('DB_USERNAME'),(string)getenv('DB_PASSWORD'),$options);
    $errors=Dormitory\Support\LinePlatformSchema::errors($pdo);
    foreach($errors as$error)fwrite(STDERR,$error."\n");
    if($errors!==[])exit(1);
    fwrite(STDOUT,"LINE platform schema and legacy metadata verified\n");
}catch(Throwable){fwrite(STDERR,"Could not verify LINE platform schema; check migration 014 and the database connection\n");exit(1);}
