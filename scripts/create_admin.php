<?php
declare(strict_types=1);

use Dormitory\Security\Password;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    /** @var Dormitory\Application $app */
    $app=require dirname(__DIR__).'/bootstrap.php';
    $options=getopt('', ['username:','password:','password-stdin','role:']);
    $username=strtolower(trim((string)($options['username']??$app->config->get('ADMIN_USERNAME',''))));
    if(array_key_exists('password-stdin',$options)&&array_key_exists('password',$options))throw new InvalidArgumentException('use only one of --password or --password-stdin');
    if(array_key_exists('password-stdin',$options)){
        $input=stream_get_contents(STDIN,201);
        if($input===false)throw new RuntimeException('cannot read password from standard input');
        $password=rtrim($input,"\r\n");
    }else{
        $password=(string)($options['password']??$app->config->get('ADMIN_PASSWORD',''));
    }
    $role=strtolower(trim((string)($options['role']??$app->config->get('ADMIN_ROLE','owner'))));
    if(!preg_match('/^[a-z0-9_.-]{3,64}$/',$username))throw new InvalidArgumentException('username must be 3-64 characters using a-z, 0-9, _, ., or -');
    Password::assertAdmin($password, $username);
    if(!in_array($role,['owner','admin'],true))throw new InvalidArgumentException('role must be owner or admin');
    $pdo=$app->database()->pdo();
    $count=(int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if($count===0)$role='owner';
    $statement=$pdo->prepare('INSERT INTO admin_users (username,password_hash,role,auth_version,active,created_by,created_at,updated_at) VALUES (?,?,?,1,1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $statement->execute([$username,Password::hash($password),$role]);
    fwrite(STDOUT,json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId(),'username'=>$username,'role'=>$role],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
}catch(Throwable $error){fwrite(STDERR,json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);exit(1);}
