<?php
declare(strict_types=1);

use Dormitory\Application;
use Dormitory\Config;
use Dormitory\Database;
use Dormitory\Domain\RoomService;
use Dormitory\Http\HttpException;

if (PHP_SAPI !== 'cli') exit(64);

// Load classes directly: this suite never reads .env or opens a database connection.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'Dormitory\\')) return;
    $path = $root.'/src/'.str_replace('\\', '/', substr($class, strlen('Dormitory\\'))).'.php';
    if (is_file($path)) require_once $path;
});
putenv('APP_ENV=testing');
putenv('APP_KEY=room-scope-unit-only-key-2026-32bytes');
putenv('APP_TIMEZONE=Asia/Bangkok');
putenv('BOOKING_HOLD_SECONDS=86400');

final class RoomScopePdo extends PDO
{
    /** @var list<array{sql:string,parameters:array}> */
    public array $calls = [];
    private bool $transactionOpen = false;
    private int $insertId = 98;

    /** @param list<array<string,mixed>> $rooms */
    public function __construct(public array $rooms) {}
    public function inTransaction(): bool { return $this->transactionOpen; }
    public function beginTransaction(): bool { $this->transactionOpen = true; return true; }
    public function commit(): bool { $this->transactionOpen = false; return true; }
    public function rollBack(): bool { $this->transactionOpen = false; return true; }
    public function lastInsertId(?string $name = null): string|false { return (string) $this->insertId; }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== 'SELECT UTC_TIMESTAMP(6)') throw new RuntimeException('Unexpected direct query in room scope test');
        $this->calls[] = ['sql'=>$query, 'parameters'=>[]];
        return new RoomScopeStatement($this, $query, '2026-10-09 18:00:00.000000');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RoomScopeStatement($this, $query);
    }
    /** @return list<array<string,mixed>> */
    public function executeStatement(string $sql, array $parameters): array
    {
        $this->calls[] = ['sql'=>$sql, 'parameters'=>$parameters];
        if (str_starts_with($sql, 'INSERT INTO rooms ')) {
            $row = $this->rooms[0];
            foreach (['room_code','floor','room_type','monthly_rent','description','amenities','image_key','rental_mode','daily_rate','max_guests','daily_deposit'] as $index=>$field) $row[$field] = $parameters[$index];
            $row['id'] = ++$this->insertId; $row['status'] = 'available'; $row['deleted_at'] = null;
            $this->rooms[] = $row;
            return [];
        }
        if (str_starts_with($sql, 'UPDATE rooms SET ')) return [];
        if (!str_starts_with($sql, 'SELECT ')) throw new RuntimeException('Unexpected non-room statement in fixture');
        if (preg_match('/WHERE (?:r\.)?id=\?/', $sql)) {
            return array_values(array_filter($this->rooms, static fn(array $room): bool => $room['id'] === $parameters[0] && $room['deleted_at'] === null));
        }
        // The fixture models only the explicit SQL catalogue predicates, not a PHP-side scope filter.
        if (!str_contains($sql, ' FROM rooms r') || !str_contains($sql, ' WHERE r.deleted_at IS NULL')) throw new RuntimeException('Missing catalogue boundary');
        $scoped = str_contains($sql, ' AND r.rental_mode=?');
        if (($scoped && count($parameters) !== 1) || (!$scoped && $parameters !== [])) throw new RuntimeException('Room scope parameters do not match SQL');
        return array_values(array_filter($this->rooms, static function (array $room) use ($sql,$parameters,$scoped): bool {
            return $room['deleted_at'] === null
                && (!$scoped || $room['rental_mode'] === $parameters[0])
                && (!str_contains($sql, " HAVING status='available'") || $room['status'] === 'available');
        }));
    }
}
final class RoomScopeStatement extends PDOStatement
{
    private array $rows = [];
    private int $position = 0;
    public function __construct(private readonly RoomScopePdo $pdo, private readonly string $sql, private readonly mixed $column = null) {}
    public function execute(?array $params = null): bool { $this->rows = $this->pdo->executeStatement($this->sql, $params ?? []); $this->position = 0; return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->rows[$this->position++] ?? false; }
    public function fetchColumn(int $column = 0): mixed { return $this->column; }
    public function rowCount(): int { return 1; }
}

$configClass = new ReflectionClass(Config::class);
$config = $configClass->newInstanceWithoutConstructor();
$configClass->getConstructor()->invoke($config, [], $root);
$database = new Database($config);
$base = ['id'=>1,'room_code'=>'M01','floor'=>1,'room_type'=>'standard','monthly_rent'=>'4000.00','description'=>null,'amenities'=>'[]','image_key'=>null,
    'rental_mode'=>'monthly','daily_rate'=>null,'max_guests'=>2,'daily_deposit'=>'0.00','housekeeping_status'=>'ready','housekeeping_version'=>1,'deleted_at'=>null,'in_use'=>false,'status'=>'available'];
$pdo = new RoomScopePdo([
    $base,
    array_replace($base, ['id'=>2,'room_code'=>'D01','rental_mode'=>'daily','monthly_rent'=>'0.00','daily_rate'=>'500.00','daily_deposit'=>'100.00']),
    array_replace($base, ['id'=>3,'room_code'=>'M02','status'=>'reserved','in_use'=>true]),
    array_replace($base, ['id'=>4,'room_code'=>'D02','rental_mode'=>'daily','monthly_rent'=>'0.00','daily_rate'=>'500.00','status'=>'occupied','in_use'=>true]),
    array_replace($base, ['id'=>5,'room_code'=>'OLD','deleted_at'=>'2026-10-01 00:00:00']),
]);
(new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $pdo);
$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Application::class, 'config'))->setValue($app, $config);
(new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);
$service = new RoomService($app);
$checks = 0;
$assert = static function (bool $condition, string $why) use (&$checks): void { if (!$condition) throw new RuntimeException($why); $checks++; };
$ids = static fn(array $rows): array => array_column($rows, 'id');
$expectError = static function (callable $work, int $status, string $code) use ($assert): void {
    try { $work(); throw new RuntimeException('Invalid room scope was accepted'); }
    catch (HttpException $error) {
        $assert($error->status === $status && $error->errorCode === $code, 'Room scope must return the intended HTTP error');
        if ($status === 422 && $code === 'VALIDATION_ERROR') $assert(($error->details['field'] ?? null) === 'rental_mode', 'Malformed scope must identify the rental_mode field');
    }
};

$assert($ids($service->all()) === [1,2,3,4], 'Unscoped all() must retain both rental types and exclude retired rooms');
$assert($ids($service->all('monthly')) === [1,3], 'Monthly catalogue must exclude daily rooms before its consumer counts rows');
$assert($ids($service->all('daily')) === [2,4], 'Daily catalogue must exclude monthly rooms');
$assert($ids($service->available()) === [1], 'Legacy public availability must remain monthly-only');
$assert($ids($service->available('daily')) === [2], 'Explicit daily catalogue availability must use both scope and existing status');
$dailyQuery = $pdo->calls[array_key_last($pdo->calls)];
$assert($dailyQuery['parameters'] === ['daily'], 'Rental mode must be bound as a query parameter');
$assert(strpos($dailyQuery['sql'], ' AND r.rental_mode=?') < strpos($dailyQuery['sql'], ' HAVING '), 'Rental scope must be applied by SQL before availability filtering');
$assert($ids($service->available(null)) === [1,2], 'Internal explicit null scope preserves access to both available catalogues');

foreach (['', 'all', 'MONTHLY', ' daily', "monthly' OR 1=1", [], false, 0, new stdClass()] as $invalid) {
    foreach (['all','available'] as $method) {
        $before = count($pdo->calls);
        $expectError(static fn() => $service->$method($invalid), 422, 'VALIDATION_ERROR');
        $assert(count($pdo->calls) === $before, 'Invalid list scopes must fail before the clock query or any database access');
    }
}
foreach (['create','update','delete'] as $method) {
    $before = count($pdo->calls);
    $work = match ($method) { 'create'=>static fn()=>$service->create([], 'all'), 'update'=>static fn()=>$service->update(1, [], 'all'), 'delete'=>static fn()=>$service->delete(1, 'all') };
    $expectError($work, 422, 'VALIDATION_ERROR');
    $assert(count($pdo->calls) === $before, 'Invalid write context must fail before database access');
}
$before = count($pdo->calls);
$expectError(static fn() => $service->create(['rental_mode'=>'monthly'], 'daily'), 422, 'ROOM_RENTAL_MODE');
$expectError(static fn() => $service->update(1, ['rental_mode'=>'daily'], 'monthly'), 422, 'ROOM_RENTAL_MODE');
$assert(count($pdo->calls) === $before, 'A scoped context must not silently force or convert an explicitly different room type');

foreach (['update','delete'] as $method) {
    $before = count($pdo->calls);
    $work = $method === 'update' ? static fn() => $service->update(2, ['room_code'=>'D_NEW'], 'monthly') : static fn() => $service->delete(2, 'monthly');
    $expectError($work, 409, 'ROOM_RENTAL_MODE');
    $calls = array_slice($pdo->calls, $before);
    $assert(count($calls) === 1 && str_contains($calls[0]['sql'], 'FOR UPDATE'), 'Persisted room type must be checked under its lock before expiry, deletion or editing');
}
$before = count($pdo->calls);
$service->update(2, ['room_code'=>'D_NEW'], 'daily');
$calls = array_slice($pdo->calls, $before);
$assert(count(array_filter($calls, static fn(array $call): bool => str_starts_with($call['sql'], 'UPDATE rooms SET '))) === 1, 'A matching scoped room edit must remain available');
$service->update(2, ['room_code'=>'D_LEGACY']);
$checks++;
$created = $service->create(['room_code'=>'D99','floor'=>1,'room_type'=>'standard','daily_rate'=>'500.00'], 'daily');
$assert($created['id'] === 99 && $created['rental_mode'] === 'daily', 'An omitted type on a scoped create must use its explicit daily context');
$assert($created['monthly_rent'] === '0.00', 'Daily context must preserve the existing daily rent validation/default');
$monthly = $service->create(['room_code'=>'M99','floor'=>1,'room_type'=>'standard','monthly_rent'=>'4500.00'], 'monthly');
$assert($monthly['id'] === 100 && $monthly['rental_mode'] === 'monthly' && $monthly['monthly_rent'] === '4500.00', 'A monthly context must keep the requested monthly rent and type');
$legacy = $service->create(['room_code'=>'D100','floor'=>1,'room_type'=>'standard','rental_mode'=>'daily','daily_rate'=>'650.00']);
$assert($legacy['rental_mode'] === 'daily' && $legacy['daily_rate'] === '650.00', 'Unscoped legacy create must continue accepting an explicit daily room');
fwrite(STDOUT, "{$checks} room scope unit checks passed; fake PDO only, no environment-file, database or provider access\n");
