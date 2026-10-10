<?php
declare(strict_types=1);

// No bootstrap, environment file, database or provider is loaded by these unit tests.
if (PHP_SAPI !== 'cli') exit(64);
spl_autoload_register(static function (string $name): void {
    if (str_starts_with($name, 'Dormitory\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($name, 10)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
use Dormitory\Domain\RevenueService;
use Dormitory\Http\HttpException;

$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$expect = static function (callable $fn, string $code) use ($assert): void {
    try { $fn(); } catch (HttpException $error) { $assert($error->errorCode === $code, 'Unexpected refusal'); return; }
    throw new RuntimeException('Expected refusal ' . $code);
};
$bounds = new ReflectionMethod(RevenueService::class, 'periodBounds');
$b = $bounds->invoke(null, '2026-10', 'Asia/Bangkok');
$assert($b['start_sql'] === '2026-09-30 17:00:00' && $b['end_sql'] === '2026-10-31 17:00:00', 'Local month must use UTC half-open bounds');
$assert($b['start_date'] === '2026-10-01' && $b['end_date'] === '2026-11-01', 'Business dates remain local');
$b = $bounds->invoke(null, '2028-02', 'Asia/Bangkok');
$assert($b['end_sql'] === '2028-02-29 17:00:00', 'Leap month boundary is exact');
$b = $bounds->invoke(null, '2026-03', 'America/New_York');
$assert($b['start_sql'] === '2026-03-01 05:00:00' && $b['end_sql'] === '2026-04-01 04:00:00', 'Each DST boundary must be converted independently');
$b = $bounds->invoke(null, '2026-12', 'UTC');
$assert($b['end_sql'] === '2027-01-01 00:00:00', 'Year transition must not wrap the requested month');
foreach (['2026-1','2026-00','2026-13','2026-10-01',' 2026-10','2026-10 ','2026-10\n','0000-01','1000-01','9999-12'] as $invalid) $expect(fn() => $bounds->invoke(null, $invalid, 'Asia/Bangkok'), 'VALIDATION_ERROR');
$expect(fn() => $bounds->invoke(null, '2026-10', 'Invalid/Zone'), 'REVENUE_TIMEZONE_INVALID');
$decimal = new ReflectionMethod(RevenueService::class, 'decimal');
foreach (['0'=>'0.00','1.2'=>'1.20','0001.02'=>'1.02','-0.00'=>'0.00','-150.25'=>'-150.25','9007199254740991.99'=>'9007199254740991.99','123456789012345678901234567890.13'=>'123456789012345678901234567890.13'] as $input => $expected) $assert($decimal->invoke(null, $input) === $expected, 'Money strings must preserve cents without float conversion');
foreach ([null, 1.1, '1e4', '1.001', 'NaN', ' 1.00'] as $invalid) $expect(fn() => $decimal->invoke(null, $invalid), 'REVENUE_DATA_INVALID');
$service = (new ReflectionClass(RevenueService::class))->newInstanceWithoutConstructor();
$events = new ReflectionMethod(RevenueService::class, 'events');
$rows = [];
for ($id = 1; $id <= 201; $id++) $rows[] = ['event_id'=>(string)$id,'event_type'=>$id === 1 ? 'deposit_retention' : 'refund','booking_id'=>'4','occurred_at'=>'2026-10-10 01:02:03.123456','amount'=>'0.13'];
$page = $events->invoke($service, $rows, 400);
$assert(count($page['items']) === 200 && $page['has_more'] && $page['next_offset'] === 600, 'Events paginate without changing aggregate totals');
$assert($page['items'][0]['amount'] === '0.13' && $page['items'][0]['occurred_at'] === '2026-10-10T01:02:03.123456Z', 'Timeline retains cents and UTC microseconds');
$assert($page['items'][0]['cash_flow_direction'] === 'none' && $page['items'][1]['cash_flow_direction'] === 'out', 'Deposit retention does not create new cash');
echo $checks, " revenue unit checks passed; no database, configuration or provider calls\n";
