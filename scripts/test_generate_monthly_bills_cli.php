<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @param list<string> $arguments
 *  @return array{exit:int,stdout:string,stderr:string}
 */
function runMonthlyBillingCli(array $arguments): array
{
    $command = [
        PHP_BINARY,
        __DIR__ . '/generate_monthly_bills.php',
        ...$arguments,
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start monthly billing CLI');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [
        'exit' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/** @param callable():void $test */
function monthlyBillingCliTest(string $name, callable $test): void
{
    try {
        $test();
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    } catch (Throwable $error) {
        $GLOBALS['monthlyBillingCliFailures']++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . PHP_EOL);
    }
}

function monthlyBillingCliAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$monthlyBillingCliFailures = 0;

monthlyBillingCliTest('help documents scheduler-safe previous month mode', static function (): void {
    $result = runMonthlyBillingCli(['--help']);
    monthlyBillingCliAssert($result['exit'] === 0, 'help must exit 0');
    monthlyBillingCliAssert(str_contains($result['stdout'], '--previous-month'), 'help omits --previous-month');
    monthlyBillingCliAssert(str_contains($result['stdout'], 'MONTHLY_BILLING_ADMIN_ID'), 'help omits admin environment fallback');
    monthlyBillingCliAssert($result['stderr'] === '', 'help wrote to stderr');
});

monthlyBillingCliTest('period selection is mandatory and unambiguous', static function (): void {
    $missing = runMonthlyBillingCli([]);
    monthlyBillingCliAssert($missing['exit'] === 64, 'missing period must exit 64');
    $both = runMonthlyBillingCli(['--period=2026-06', '--previous-month']);
    monthlyBillingCliAssert($both['exit'] === 64, 'two period modes must exit 64');
});

monthlyBillingCliTest('duplicate options fail before bootstrap', static function (): void {
    $result = runMonthlyBillingCli(['--period=2026-06', '--period=2026-05']);
    monthlyBillingCliAssert($result['exit'] === 64, 'duplicate period must exit 64');
    monthlyBillingCliAssert(str_contains($result['stderr'], 'Duplicate argument'), 'duplicate error is missing');
});

monthlyBillingCliTest('dry run cannot accept an administrative identity', static function (): void {
    $result = runMonthlyBillingCli(['--period=2026-06', '--admin-id=1']);
    monthlyBillingCliAssert($result['exit'] === 64, 'dry-run admin ID must exit 64');
    monthlyBillingCliAssert(str_contains($result['stderr'], 'only with --apply'), 'dry-run admin error is missing');
});

monthlyBillingCliTest('unknown arguments are not reflected into logs', static function (): void {
    $secret = 'DO_NOT_REFLECT_THIS_VALUE';
    $result = runMonthlyBillingCli(['--token=' . $secret]);
    monthlyBillingCliAssert($result['exit'] === 64, 'unknown argument must exit 64');
    monthlyBillingCliAssert(!str_contains($result['stdout'] . $result['stderr'], $secret), 'argument value leaked');
});

monthlyBillingCliTest('apply path contains lock, strict audit, and redacted summaries', static function (): void {
    $source = file_get_contents(__DIR__ . '/generate_monthly_bills.php');
    monthlyBillingCliAssert(is_string($source), 'cannot read monthly billing CLI');
    foreach ([
        'SELECT GET_LOCK(?, 0)',
        'SELECT RELEASE_LOCK(?)',
        'generateClosedPeriod(',
        "'bill.monthly_generate'",
        'monthlyBillingPreviewSummary(',
        'monthlyBillingGenerationSummary(',
        'monthlyBillingSafeErrorDetails(',
    ] as $expected) {
        monthlyBillingCliAssert(str_contains($source, $expected), 'missing guard: ' . $expected);
    }
});

monthlyBillingCliTest('deployment wrapper is role-bound, unprivileged, and time-bounded', static function (): void {
    $source = file_get_contents(__DIR__ . '/run_monthly_billing.sh');
    monthlyBillingCliAssert(is_string($source), 'cannot read monthly billing wrapper');
    foreach ([
        'RUNTIME_ROLE must be job',
        'gosu www-data:www-data sh',
        'MONTHLY_BILLING_TIMEOUT_SECONDS',
        '--kill-after=15s',
        'generate_monthly_bills.php',
    ] as $expected) {
        monthlyBillingCliAssert(str_contains($source, $expected), 'missing wrapper guard: ' . $expected);
    }
});

if ($monthlyBillingCliFailures > 0) {
    fwrite(STDERR, $monthlyBillingCliFailures . ' monthly billing CLI test(s) failed' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, '7 monthly billing CLI tests passed' . PHP_EOL);
