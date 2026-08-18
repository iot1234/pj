<?php
declare(strict_types=1);

use Dormitory\Http\HttpException;
use Dormitory\Http\Request;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class MonthlyBillingRunLocked extends RuntimeException
{
}

/** @param list<array<string,mixed>> $issues
 *  @return array<string,int>
 */
function monthlyBillingIssueCounts(array $issues): array
{
    $counts = [];
    foreach ($issues as $issue) {
        $code = is_array($issue)
            && is_string($issue['code'] ?? null)
            && preg_match('/\A[A-Z0-9_]{1,64}\z/D', $issue['code']) === 1
            ? $issue['code']
            : 'UNKNOWN';
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);
    return $counts;
}

/** @param array<string,mixed> $preview
 *  @return array<string,mixed>
 */
function monthlyBillingPreviewSummary(array $preview): array
{
    $bills = is_array($preview['bills'] ?? null) ? $preview['bills'] : [];
    $issues = is_array($preview['issues'] ?? null) ? $preview['issues'] : [];
    return [
        'period' => (string) ($preview['period'] ?? ''),
        'due_date' => (string) ($preview['due_date'] ?? ''),
        'candidate_bill_count' => count($bills),
        'blocked_issue_count' => count($issues),
        'issue_codes' => monthlyBillingIssueCounts($issues),
        'ready' => $issues === [],
    ];
}

/** @param array<string,mixed> $generation
 *  @return array<string,mixed>
 */
function monthlyBillingGenerationSummary(array $generation): array
{
    $created = is_array($generation['created'] ?? null) ? $generation['created'] : [];
    $skipped = is_array($generation['skipped'] ?? null) ? $generation['skipped'] : [];
    return [
        'period' => (string) ($generation['period'] ?? ''),
        'created_count' => count($created),
        'skipped_count' => count($skipped),
        'no_op' => $created === [],
    ];
}

/** @param array<string,mixed> $details
 *  @return array<string,mixed>
 */
function monthlyBillingSafeErrorDetails(array $details): array
{
    $safe = [];
    if (is_string($details['field'] ?? null)
        && preg_match('/\A[a-z0-9_]{1,64}\z/D', $details['field']) === 1) {
        $safe['field'] = $details['field'];
    }
    foreach (['period', 'maximum'] as $key) {
        if (is_string($details[$key] ?? null)
            && preg_match('/\A\d{4}-\d{2}(?:-\d{2})?\z/D', $details[$key]) === 1) {
            $safe[$key] = $details[$key];
        }
    }
    if (is_int($details['maximum_rooms'] ?? null)) {
        $safe['maximum_rooms'] = $details['maximum_rooms'];
    }
    if (is_bool($details['confirmation_required'] ?? null)) {
        $safe['confirmation_required'] = $details['confirmation_required'];
    }
    if (is_array($details['issues'] ?? null)) {
        $safe['blocked_issue_count'] = count($details['issues']);
        $safe['issue_codes'] = monthlyBillingIssueCounts($details['issues']);
    }
    return $safe;
}

function monthlyBillingRequestId(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable) {
        return substr(hash('sha256', (string) microtime(true) . ':' . (string) getmypid()), 0, 16);
    }
}

$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    fwrite(STDOUT, implode(PHP_EOL, [
        'Usage:',
        '  php scripts/generate_monthly_bills.php --period=YYYY-MM [--due-date=YYYY-MM-DD]',
        '  php scripts/generate_monthly_bills.php --previous-month [--due-date=YYYY-MM-DD]',
        '  php scripts/generate_monthly_bills.php --period=YYYY-MM --admin-id=N --apply [--due-date=YYYY-MM-DD]',
        '  php scripts/generate_monthly_bills.php --previous-month --apply [--admin-id=N] [--due-date=YYYY-MM-DD]',
        '',
        'Dry-run is the default. --apply is accepted only for a completed calendar month.',
        'When --apply omits --admin-id, MONTHLY_BILLING_ADMIN_ID must contain an active admin ID.',
        'Success output contains counts only; bill, resident, room, token, and amount details are not logged.',
        '',
        'Exit codes: 0 success, 64 invalid CLI/config, 65 rejected data, 70 infrastructure failure, 75 run already active.',
    ]) . PHP_EOL);
    exit(0);
}
if (in_array('--help', $arguments, true)) {
    fwrite(STDERR, '--help must be used by itself' . PHP_EOL);
    exit(64);
}

$period = null;
$previousMonth = false;
$dueDate = null;
$adminId = null;
$adminIdSpecified = false;
$apply = false;
$seen = [];
foreach ($arguments as $argument) {
    $option = null;
    $value = null;
    if ($argument === '--apply') {
        $option = 'apply';
    } elseif ($argument === '--previous-month') {
        $option = 'previous-month';
    } elseif (preg_match('/^--period=(\d{4}-\d{2})$/D', $argument, $match) === 1) {
        $option = 'period';
        $value = $match[1];
    } elseif (preg_match('/^--due-date=(\d{4}-\d{2}-\d{2})$/D', $argument, $match) === 1) {
        $option = 'due-date';
        $value = $match[1];
    } elseif (preg_match('/^--admin-id=([1-9]\d{0,17})$/D', $argument, $match) === 1) {
        $option = 'admin-id';
        $value = $match[1];
    } else {
        fwrite(STDERR, 'Unknown or invalid argument' . PHP_EOL);
        exit(64);
    }

    if (isset($seen[$option])) {
        fwrite(STDERR, 'Duplicate argument: --' . $option . PHP_EOL);
        exit(64);
    }
    $seen[$option] = true;
    if ($option === 'apply') {
        $apply = true;
    } elseif ($option === 'previous-month') {
        $previousMonth = true;
    } elseif ($option === 'period') {
        $period = $value;
    } elseif ($option === 'due-date') {
        $dueDate = $value;
    } elseif ($option === 'admin-id') {
        $adminId = (int) $value;
        $adminIdSpecified = true;
    }
}

if (($period === null) === !$previousMonth) {
    fwrite(STDERR, 'Choose exactly one of --period or --previous-month' . PHP_EOL);
    exit(64);
}
if (!$apply && $adminIdSpecified) {
    fwrite(STDERR, '--admin-id is accepted only with --apply' . PHP_EOL);
    exit(64);
}
if ($apply && $adminId === null) {
    $environmentAdminId = getenv('MONTHLY_BILLING_ADMIN_ID');
    if (!is_string($environmentAdminId)
        || preg_match('/\A[1-9]\d{0,17}\z/D', $environmentAdminId) !== 1) {
        fwrite(STDERR, '--admin-id or a valid MONTHLY_BILLING_ADMIN_ID is required with --apply' . PHP_EOL);
        exit(64);
    }
    $adminId = (int) $environmentAdminId;
}

$app = null;
$pdo = null;
$lockAcquired = false;
$lockName = '';
$exitCode = 0;
try {
    /** @var Dormitory\Application $app */
    $app = require dirname(__DIR__) . '/bootstrap.php';
    if ($previousMonth) {
        $timezone = new DateTimeZone((string) $app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $period = (new DateTimeImmutable('first day of this month', $timezone))
            ->modify('-1 month')
            ->format('Y-m');
    }
    if (!is_string($period)) {
        throw new LogicException('Monthly billing period was not resolved');
    }

    if (!$apply) {
        $result = [
            'ok' => true,
            'mode' => 'dry-run',
            'data' => monthlyBillingPreviewSummary(
                $app->billing()->closedPeriodPreview($period, $dueDate),
            ),
        ];
    } else {
        $pdo = $app->database()->pdo();
        $databaseIdentity = implode('|', [
            (string) $app->config->get('DB_HOST', '127.0.0.1'),
            (string) $app->config->get('DB_PORT', '3306'),
            (string) $app->config->get('DB_DATABASE', ''),
        ]);
        $lockName = 'dormitory_monthly_' . substr(hash('sha256', $databaseIdentity), 0, 32);
        $lockStatement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lockStatement->execute([$lockName]);
        $lockResult = $lockStatement->fetchColumn();
        if ($lockResult !== 1 && $lockResult !== '1') {
            throw new MonthlyBillingRunLocked('Another monthly billing run is active');
        }
        $lockAcquired = true;

        $requestId = monthlyBillingRequestId();
        $request = new Request(
            'CLI',
            '/cli/generate-monthly-bills',
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            $requestId,
        );
        $data = $app->billing()->generateClosedPeriod(
            $period,
            (int) $adminId,
            $dueDate,
            function (array $generation) use ($app, $request, $adminId): void {
                $app->audit()->writeStrict(
                    $request,
                    ['type' => 'admin', 'id' => (int) $adminId],
                    'bill.monthly_generate',
                    'bill',
                    null,
                    [
                        'period' => $generation['period'],
                        'created' => count($generation['created']),
                        'skipped' => count($generation['skipped']),
                        'source' => 'cli',
                    ],
                );
            },
        );
        $result = [
            'ok' => true,
            'mode' => 'apply',
            'data' => monthlyBillingGenerationSummary($data),
        ];
    }
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
} catch (MonthlyBillingRunLocked $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'code' => 'MONTHLY_BILLING_ALREADY_RUNNING',
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $exitCode = 75;
} catch (HttpException $error) {
    $details = monthlyBillingSafeErrorDetails($error->details);
    $payload = [
        'ok' => false,
        'code' => $error->errorCode,
        'message' => $error->getMessage(),
    ];
    if ($details !== []) {
        $payload['details'] = $details;
    }
    fwrite(STDERR, json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL);
    $exitCode = $error->status >= 500 ? 70 : 65;
} catch (Throwable $error) {
    $requestId = monthlyBillingRequestId();
    error_log(sprintf(
        '[monthly-billing:%s] infrastructure failure (%s)',
        $requestId,
        $error::class,
    ));
    fwrite(STDERR, json_encode([
        'ok' => false,
        'code' => 'MONTHLY_BILLING_UNAVAILABLE',
        'message' => 'Monthly billing is temporarily unavailable',
        'request_id' => $requestId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    $exitCode = 70;
} finally {
    if ($lockAcquired && $pdo instanceof PDO) {
        try {
            $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStatement->execute([$lockName]);
            $releaseResult = $releaseStatement->fetchColumn();
            if ($releaseResult !== 1 && $releaseResult !== '1') {
                error_log('[monthly-billing] advisory lock was closed by the database before release');
            }
        } catch (Throwable $releaseError) {
            error_log('[monthly-billing] advisory lock release failed (' . $releaseError::class . ')');
        }
    }
}

exit($exitCode);
