<?php
declare(strict_types=1);

namespace Dormitory\Security;

use Dormitory\Config;

final class ResidentAccessCredential
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const CODE_LENGTH = 20;

    /** @return array{code:string,hash:string} */
    public static function issue(Config $config,int $residentId,int $authVersion): array
    {
        if($residentId<1||$authVersion<1)throw new \InvalidArgumentException('Invalid resident credential context');
        $code=self::code($config,$residentId,$authVersion);
        return ['code'=>$code,'hash'=>self::digest($config,$residentId,$authVersion,self::normalize($code)??'')];
    }

    public static function verify(Config $config,int $residentId,int $authVersion,string $plain,?string $storedHash): bool
    {
        $normalized=self::normalize($plain);
        $candidate=self::digest($config,$residentId,$authVersion,$normalized??str_repeat('0',self::CODE_LENGTH));
        return $normalized!==null
            &&is_string($storedHash)
            &&preg_match('/^[a-f0-9]{64}$/D',$storedHash)===1
            &&hash_equals($storedHash,$candidate);
    }

    public static function restore(Config $config,int $residentId,int $authVersion,?string $storedHash): ?string
    {
        $issued=self::issue($config,$residentId,$authVersion);
        return is_string($storedHash)&&hash_equals($storedHash,$issued['hash'])?$issued['code']:null;
    }

    public static function ttlSeconds(Config $config): int
    {
        return $config->intInRange('RESIDENT_ACTIVATION_TTL_SECONDS',604800,900,2592000);
    }

    private static function code(Config $config,int $residentId,int $authVersion): string
    {
        $bytes=hash_hmac('sha256',"resident-activation-code\0{$residentId}\0{$authVersion}",$config->appKey(),true);
        $characters='';$buffer=0;$bits=0;
        for($offset=0;$offset<strlen($bytes)&&strlen($characters)<self::CODE_LENGTH;$offset++){
            $buffer=($buffer<<8)|ord($bytes[$offset]);$bits+=8;
            while($bits>=5&&strlen($characters)<self::CODE_LENGTH){
                $bits-=5;
                $characters.=self::ALPHABET[($buffer>>$bits)&31];
                $buffer=$bits===0?0:$buffer&((1<<$bits)-1);
            }
        }
        return implode('-',str_split($characters,5));
    }

    private static function normalize(string $plain): ?string
    {
        $normalized=strtoupper(preg_replace('/[\s-]+/','',trim($plain))??'');
        $normalized=strtr($normalized,['O'=>'0','I'=>'1','L'=>'1']);
        return strlen($normalized)===self::CODE_LENGTH
            &&preg_match('/^[0-9A-HJKMNP-TV-Z]{20}$/D',$normalized)===1
            ?$normalized:null;
    }

    private static function digest(Config $config,int $residentId,int $authVersion,string $normalized): string
    {
        return hash_hmac('sha256',"resident-activation-digest\0{$residentId}\0{$authVersion}\0{$normalized}",$config->appKey());
    }
}
