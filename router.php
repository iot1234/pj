<?php
declare(strict_types=1);

$public=__DIR__.'/public';
$path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');
$decoded=rawurldecode($path);
if(!str_contains($decoded,"\0")&&!str_contains(str_replace('\\','/',$decoded),'/../')){
    $candidate=realpath($public.$decoded);
    $root=realpath($public);
    if($candidate!==false&&$root!==false&&str_starts_with($candidate,$root.DIRECTORY_SEPARATOR)&&is_file($candidate))return false;
}
require $public.'/index.php';
