<?php
declare(strict_types=1);

// Included by tests/run.php. In-memory PDO doubles never open a database.
final class OwnerRoleFixturePdo extends PDO
{
    public array $rows;
    public array $audits = [];
    public ?Closure $beforeLockedLogin = null;
    public bool $failSuccessfulAudit = false;
    private bool $transaction = false;
    private int $lastId = 2;

    public function __construct()
    {
        $this->rows = [
            1=>['id'=>1,'username'=>'fixture_owner','password_hash'=>Dormitory\Security\Password::hash('Owner-Fixture-Secure-2026!'),'role'=>'owner','active'=>1,'auth_version'=>3,'retired_at'=>null],
            2=>['id'=>2,'username'=>'fixture_legacy','password_hash'=>Dormitory\Security\Password::hash('Legacy-Fixture-Secure-2026!'),'role'=>'admin','active'=>1,'auth_version'=>4,'retired_at'=>null],
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new OwnerRoleFixtureStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function beginTransaction(): bool { $this->transaction=true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->transaction=false; return true; }
    public function rollBack(): bool { $this->transaction=false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }

    public function executeFixture(string $query, array $params): array
    {
        if (str_contains($query, 'FROM rate_limits')) {
            return [['bucket_key'=>$params[0], 'window_started_at'=>gmdate('Y-m-d H:i:s'), 'hits'=>0, 'blocked_until'=>null]];
        }
        if (str_starts_with($query, 'INSERT INTO rate_limits') || str_starts_with($query, 'UPDATE rate_limits')) return [];
        if (str_starts_with($query, 'INSERT INTO audit_logs')) {
            if ($this->failSuccessfulAudit && ($params[2]??null)==='auth.admin_login') throw new RuntimeException('audit fixture unavailable');
            $this->audits[]=$params;
            return [];
        }
        if (str_starts_with($query, 'SELECT') && str_contains($query, 'FROM admin_users')) {
            if (str_contains($query, 'FOR SHARE') && $this->beforeLockedLogin !== null) ($this->beforeLockedLogin)($this);
            if (str_contains($query, 'WHERE username=?')) return array_values(array_filter($this->rows, static fn(array $row):bool=>$row['username']===$params[0]));
            if (str_contains($query, 'WHERE id=?')) {
                $row=$this->rows[(int)$params[0]]??null;
                if (!$row || (str_contains($query,'AND active=1') && !$row['active'])
                    || (str_contains($query,"AND role='owner'") && $row['role']!=='owner')
                    || (str_contains($query,'AND retired_at IS NULL') && $row['retired_at']!==null)) return [];
                return [$row];
            }
            return array_values($this->rows);
        }
        if (str_starts_with($query, 'INSERT INTO admin_users')) {
            $id=++$this->lastId;
            $this->rows[$id]=['id'=>$id,'username'=>$params[0],'password_hash'=>$params[1],'role'=>$params[2],'auth_version'=>1,'active'=>$params[3],'retired_at'=>null];
            return [];
        }
        if (str_starts_with($query, 'UPDATE admin_users')) {
            $id=(int)$params[array_key_last($params)];
            if (str_contains($query, 'retired_at=COALESCE')) {
                $this->rows[$id]['role']='owner';
                $this->rows[$id]['active']=0;
                $this->rows[$id]['retired_at']??=gmdate('Y-m-d H:i:s');
            } elseif (str_contains($query, 'username=?,role=?,active=?')) {
                $this->rows[$id]['username']=$params[0];
                $this->rows[$id]['role']=$params[1];
                $this->rows[$id]['active']=$params[2];
                if (str_contains($query, ',password_hash=?')) $this->rows[$id]['password_hash']=$params[3];
            } elseif (str_contains($query, 'SET active=0')) {
                $this->rows[$id]['active']=0;
            } else {
                throw new RuntimeException('Unexpected account fixture mutation: '.$query);
            }
            $this->rows[$id]['auth_version']++;
            return [];
        }
        throw new RuntimeException('Unexpected owner-role fixture query: '.$query);
    }
}

final class OwnerRoleFixtureStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private readonly OwnerRoleFixturePdo $pdo, private readonly string $sql) {}
    public function execute(?array $params = null): bool { $this->rows=$this->pdo->executeFixture($this->sql,$params??[]); return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT, int $cursorOrientation=PDO::FETCH_ORI_NEXT, int $cursorOffset=0): mixed { return array_shift($this->rows)??false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column=0): mixed { $row=array_shift($this->rows); return $row===null ? false : array_values($row)[$column]; }
    public function rowCount(): int { return 1; }
}

$withOwnerRoles=static function(callable $callback) use($app):void {
    $database=$app->database();
    $property=new ReflectionProperty(Dormitory\Database::class,'pdo');
    $original=$property->getValue($database);
    $cookies=$_COOKIE;
    $fixture=new OwnerRoleFixturePdo();
    try {
        $app->session()->logout();
        $app->clearActorCache();
        $property->setValue($database,$fixture);
        $callback($fixture);
    } finally {
        $app->session()->logout();
        $app->clearActorCache();
        $_COOKIE=$cookies;
        $property->setValue($database,$original);
    }
};
$ownerRequest=static fn():Dormitory\Http\Request=>new Dormitory\Http\Request('GET','/api/admin/rooms',[],[],[],[],['REMOTE_ADDR'=>'127.0.0.92'],'owner-role-unit');
$ownerSession=['type'=>'admin','role'=>'owner','id'=>1,'auth_version'=>3];

$test('all management routes, including LINE and logout, explicitly require owner',function() use($same,$app):void {
    $routes=(new ReflectionProperty(Dormitory\Http\Router::class,'routes'))->getValue(Dormitory\Http\Routes::build($app));
    $management=0;
    foreach($routes as$route) {
        if(($route['options']['auth']??null)!=='admin') continue;
        $management++;
        $same('owner',$route['options']['role']??null);
    }
    $same(true,$management>50);
});

$test('session layer rejects removed administrators and invalidates persisted legacy actors',function() use($withOwnerRoles,$same,$app,$ownerSession):void {
    $withOwnerRoles(function() use($same,$app,$ownerSession):void {
        foreach([['type'=>'admin','role'=>'admin'],['type'=>'admin'],['type'=>'superadmin'],['type'=>'admin','role'=>'owner','retired_at'=>'2026-10-02 00:00:00']]as$identity) {
            try { $app->session()->login($identity+['id'=>2,'auth_version'=>4]); throw new RuntimeException('Removed actor created a session'); }
            catch(InvalidArgumentException) {}
        }
        foreach([['id'=>[1]],['auth_version'=>[3]],['id'=>0],['auth_version'=>0]]as$change) {
            try { $app->session()->login(array_replace($ownerSession,$change)); throw new RuntimeException('Malformed actor created a session'); }
            catch(InvalidArgumentException) {}
        }
        $app->session()->login($ownerSession);
        $oldId=session_id();$oldToken=$app->session()->csrfToken();
        $_SESSION['dormitory_actor']=['type'=>'admin','role'=>'admin','id'=>2,'auth_version'=>4];
        $same(null,$app->session()->actor());
        $same(false,hash_equals($oldId,session_id()));
        $same(false,hash_equals($oldToken,$app->session()->csrfToken()));
    });
});

$test('owner resolution rechecks database role, retirement, activity and auth version',function() use($withOwnerRoles,$same,$app,$ownerSession):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($same,$app,$ownerSession):void {
        foreach([['role'=>'admin'],['retired_at'=>'2026-10-02 00:00:00'],['active'=>0],['auth_version'=>4]]as$change) {
            $pdo->rows[1]=array_replace($pdo->rows[1],['role'=>'owner','retired_at'=>null,'active'=>1,'auth_version'=>3],$change);
            $app->session()->login($ownerSession);
            $same(null,$app->auth()->resolveActor());
            $same(null,$app->session()->actor());
        }
        $pdo->rows[1]=array_replace($pdo->rows[1],['role'=>'owner','retired_at'=>null,'active'=>1,'auth_version'=>3]);
        $app->session()->login($ownerSession);
        $same('owner',$app->auth()->resolveActor()['role']);
    });
});

$test('guard denies cached legacy administrators even on a route without an explicit role',function() use($withOwnerRoles,$throws,$app,$ownerRequest):void {
    $withOwnerRoles(function() use($throws,$app,$ownerRequest):void {
        $cache=new ReflectionProperty(Dormitory\Application::class,'actorCache');
        foreach([['type'=>'admin','role'=>'admin','id'=>2],['type'=>'admin','role'=>'owner','retired_at'=>'2026-10-02','id'=>2]]as$actor) {
            $cache->setValue($app,$actor);
            $throws(fn()=>$app->guard($ownerRequest(),['auth'=>'admin']),'FORBIDDEN');
        }
    });
});

$test('correct passwords and trusted devices cannot sign in removed or retired administrators',function() use($withOwnerRoles,$throws,$same,$app,$ownerRequest):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($throws,$same,$app,$ownerRequest):void {
        $token=(new ReflectionMethod(Dormitory\Domain\AuthService::class,'createLoginDeviceToken'))->invoke($app->auth(),'admin',2,4,time()+300);
        $name=(new ReflectionMethod(Dormitory\Domain\AuthService::class,'loginDeviceCookieName'))->invoke($app->auth(),'admin',2);
        $_COOKIE[$name]=$token;
        $throws(fn()=>$app->auth()->adminLogin($ownerRequest(),['username'=>'fixture_legacy','password'=>'Legacy-Fixture-Secure-2026!']),'INVALID_CREDENTIALS');
        $pdo->rows[2]['role']='owner';$pdo->rows[2]['retired_at']='2026-10-02 00:00:00';
        $throws(fn()=>$app->auth()->adminLogin($ownerRequest(),['username'=>'fixture_legacy','password'=>'Legacy-Fixture-Secure-2026!']),'INVALID_CREDENTIALS');
        $same(null,$app->session()->actor());
        $same(['auth.admin_failed','auth.admin_failed'],array_column($pdo->audits,2));
    });
});

$test('owner login locks and rechecks concurrent retirement before issuing a session',function() use($withOwnerRoles,$throws,$same,$app,$ownerRequest):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($throws,$same,$app,$ownerRequest):void {
        $pdo->beforeLockedLogin=static function(OwnerRoleFixturePdo $fixture):void { $fixture->rows[1]['retired_at']='2026-10-02 00:00:00';$fixture->rows[1]['active']=0; };
        $throws(fn()=>$app->auth()->adminLogin($ownerRequest(),['username'=>'fixture_owner','password'=>'Owner-Fixture-Secure-2026!']),'INVALID_CREDENTIALS');
        $same(null,$app->session()->actor());$same([],$pdo->audits);
    });
});

$test('active owner login succeeds and audit persistence is required before a session',function() use($withOwnerRoles,$same,$app,$ownerRequest):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($same,$app,$ownerRequest):void {
        $actor=$app->auth()->adminLogin($ownerRequest(),['username'=>'fixture_owner','password'=>'Owner-Fixture-Secure-2026!']);
        $same('owner',$actor['role']);$same(1,$app->session()->actor()['id']);
        $same(['auth.admin_login'],array_column($pdo->audits,2));
        $app->session()->logout();$pdo->failSuccessfulAudit=true;
        try { $app->auth()->adminLogin($ownerRequest(),['username'=>'fixture_owner','password'=>'Owner-Fixture-Secure-2026!']);throw new RuntimeException('Unaudited session was issued'); }
        catch(RuntimeException $error) { $same('audit fixture unavailable',$error->getMessage()); }
        $same(null,$app->session()->actor());
    });
});

$test('owner account creation defaults to owner and rejects the removed admin role',function() use($withOwnerRoles,$throws,$same,$app):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($throws,$same,$app):void {
        $input=['username'=>'new_owner','password'=>'New-Owner-Secure-Fixture-2026!'];
        $throws(fn()=>$app->adminUsers()->create($input+['role'=>'admin'],1),'VALIDATION_ERROR');
        $same(2,count($pdo->rows));
        $created=$app->adminUsers()->create($input,1);
        $same('owner',$created['role']);$same(true,$created['active']);$same(false,$created['retired']);
    });
});

$test('legacy account deactivation creates an immutable retirement without granting ownership access',function() use($withOwnerRoles,$throws,$same,$app):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($throws,$same,$app):void {
        $listed=$app->adminUsers()->list();$same(true,$listed[1]['retired']);$same(false,$listed[1]['active']);
        foreach([['active'=>true],['role'=>'owner'],['password'=>'New-Owner-Secure-Fixture-2026!'],['username'=>'promoted_legacy']]as$change) {
            $throws(fn()=>$app->adminUsers()->update(2,$change,1),'ADMIN_ROLE_REMOVED');
        }
        $retired=$app->adminUsers()->update(2,['active'=>false],1);
        $same(true,$retired['retired']);$same(false,$retired['active']);
        $same('owner',$pdo->rows[2]['role']);$same(0,$pdo->rows[2]['active']);$same(5,$pdo->rows[2]['auth_version']);
        $same(true,is_string($pdo->rows[2]['retired_at']));
        $throws(fn()=>$app->adminUsers()->update(2,['active'=>true],1),'ADMIN_ROLE_REMOVED');
        $app->adminUsers()->delete(2,1);$same(0,$pdo->rows[2]['active']);$same(6,$pdo->rows[2]['auth_version']);
    });
});

$test('last owner and self protections ignore retired historical accounts',function() use($withOwnerRoles,$throws,$same,$app):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($throws,$same,$app):void {
        $pdo->rows[2]['role']='owner';$pdo->rows[2]['retired_at']='2026-10-02 00:00:00';$pdo->rows[2]['active']=0;
        $throws(fn()=>$app->adminUsers()->delete(1,1),'SELF_DELETE');
        $throws(fn()=>$app->adminUsers()->update(1,['active'=>false],1),'SELF_OWNER_CHANGE');
        $throws(fn()=>$app->adminUsers()->delete(1,9),'LAST_OWNER');
        $throws(fn()=>$app->adminUsers()->update(1,['active'=>false],9),'LAST_OWNER');
        $throws(fn()=>$app->adminUsers()->update(1,['role'=>'admin'],1),'VALIDATION_ERROR');
        $same(1,$pdo->rows[1]['active']);$same(3,$pdo->rows[1]['auth_version']);
    });
});

$test('monthly billing CLI actor checks deny legacy, retired and inactive owners',function() use($withOwnerRoles,$throws,$app):void {
    $withOwnerRoles(function(OwnerRoleFixturePdo $pdo) use($throws,$app):void {
        $guard=new ReflectionMethod(Dormitory\Domain\BillingService::class,'assertActiveAdmin');
        $guard->invoke($app->billing(),$pdo,1);
        $throws(fn()=>$guard->invoke($app->billing(),$pdo,2),'ADMIN_INACTIVE');
        $pdo->rows[2]['role']='owner';$pdo->rows[2]['retired_at']='2026-10-02 00:00:00';
        $throws(fn()=>$guard->invoke($app->billing(),$pdo,2),'ADMIN_INACTIVE');
        $pdo->rows[2]['retired_at']=null;$pdo->rows[2]['active']=0;
        $throws(fn()=>$guard->invoke($app->billing(),$pdo,2),'ADMIN_INACTIVE');
        $throws(fn()=>$guard->invoke($app->billing(),$pdo,0),'VALIDATION_ERROR');
    });
});
