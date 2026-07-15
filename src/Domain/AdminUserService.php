<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

final class AdminUserService
{
    public function __construct(private readonly Application $app) {}

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $rows = $this->app->database()->pdo()->query('SELECT id,username,role,active,created_at,updated_at FROM admin_users ORDER BY username')->fetchAll();
        foreach ($rows as &$row) { $row['id']=(int)$row['id']; $row['active']=(bool)$row['active']; $row['is_active']=$row['active']; }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function create(array $input, int $ownerId): array
    {
        Validator::only($input, ['username','password','role','active','is_active']);
        $username = strtolower(Validator::string($input['username'] ?? null, 'username', 3, 64));
        if (!preg_match('/^[a-z0-9_.-]+$/', $username)) throw new HttpException(422, 'Invalid username', 'VALIDATION_ERROR', ['field'=>'username']);
        $password=(string)($input['password']??''); Password::assertAdmin($password,$username);
        $role=Validator::enum($input['role']??'admin','role',['owner','admin']);
        $active = self::requestedActive($input) ?? true;
        try {
            $statement=$this->app->database()->pdo()->prepare('INSERT INTO admin_users (username,password_hash,role,auth_version,active,created_by,created_at,updated_at) VALUES (?,?,?,1,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $statement->execute([$username,Password::hash($password),$role,$active?1:0,$ownerId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode()==='23000') throw new HttpException(409,'Username already exists','USERNAME_EXISTS');
            throw $e;
        }
        return ['id'=>(int)$this->app->database()->pdo()->lastInsertId(),'username'=>$username,'role'=>$role,'active'=>$active,'is_active'=>$active];
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $input, int $ownerId): array
    {
        Validator::only($input,['username','password','role','active','is_active']);
        if($input===[])throw new HttpException(422,'No fields to update','NOTHING_TO_UPDATE');
        $requestedActive=self::requestedActive($input);
        return $this->app->database()->transaction(function(PDO $pdo) use($id,$input,$ownerId,$requestedActive): array {
            $lock=$pdo->query("SELECT id,username,role,active FROM admin_users ORDER BY id FOR UPDATE");
            $all=$lock->fetchAll(); $current=null;
            foreach($all as $row) if((int)$row['id']===$id) $current=$row;
            if(!$current) throw new HttpException(404,'Admin user not found','ADMIN_NOT_FOUND');
            $username=array_key_exists('username',$input)?strtolower(Validator::string($input['username'],'username',3,64)):(string)$current['username'];
            if(!preg_match('/^[a-z0-9_.-]+$/',$username)) throw new HttpException(422,'Invalid username','VALIDATION_ERROR',['field'=>'username']);
            $role=array_key_exists('role',$input)?Validator::enum($input['role'],'role',['owner','admin']):(string)$current['role'];
            $active=$requestedActive ?? (bool)$current['active'];
            if($id===$ownerId && (!$active || $role!=='owner')) throw new HttpException(409,'You cannot disable or demote your own owner account','SELF_OWNER_CHANGE');
            if((string)$current['role']==='owner' && (bool)$current['active'] && (!$active || $role!=='owner')) {
                $count=0; foreach($all as $row) if($row['role']==='owner' && (bool)$row['active']) $count++;
                if($count<=1) throw new HttpException(409,'At least one active owner is required','LAST_OWNER');
            }
            $passwordHash=null;
            if(array_key_exists('password',$input) && (string)$input['password']!=='') { Password::assertAdmin((string)$input['password'],$username); $passwordHash=Password::hash((string)$input['password']); }
            try {
                $sql='UPDATE admin_users SET username=?,role=?,active=?,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP()';
                $params=[$username,$role,$active?1:0];
                if($passwordHash!==null){$sql.=',password_hash=?';$params[]=$passwordHash;}
                $sql.=' WHERE id=?';$params[]=$id;
                $pdo->prepare($sql)->execute($params);
            } catch(PDOException $e){if((string)$e->getCode()==='23000')throw new HttpException(409,'Username already exists','USERNAME_EXISTS');throw $e;}
            return ['id'=>$id,'username'=>$username,'role'=>$role,'active'=>$active,'is_active'=>$active];
        });
    }

    public function delete(int $id, int $ownerId): void
    {
        if($id===$ownerId) throw new HttpException(409,'You cannot disable your own account','SELF_DELETE');
        $this->app->database()->transaction(function(PDO $pdo) use($id): void {
            $rows=$pdo->query('SELECT id,role,active FROM admin_users ORDER BY id FOR UPDATE')->fetchAll();$target=null;$owners=0;
            foreach($rows as $row){if((int)$row['id']===$id)$target=$row;if($row['role']==='owner'&&(bool)$row['active'])$owners++;}
            if(!$target)throw new HttpException(404,'Admin user not found','ADMIN_NOT_FOUND');
            if($target['role']==='owner'&&(bool)$target['active']&&$owners<=1)throw new HttpException(409,'At least one active owner is required','LAST_OWNER');
            $pdo->prepare('UPDATE admin_users SET active=0,auth_version=auth_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        });
    }

    /** @param array<string,mixed> $input */
    private static function requestedActive(array $input): ?bool
    {
        $hasActive=array_key_exists('active',$input);
        $hasAlias=array_key_exists('is_active',$input);
        if(!$hasActive&&!$hasAlias)return null;
        $active=$hasActive?Validator::boolean($input['active'],'active'):null;
        $alias=$hasAlias?Validator::boolean($input['is_active'],'is_active'):null;
        if($hasActive&&$hasAlias&&$active!==$alias){
            throw new HttpException(422,'active and is_active must agree','VALIDATION_ERROR',['fields'=>['active','is_active']]);
        }
        return $hasActive?$active:$alias;
    }
}
