<?php

declare(strict_types=1);

const MIGRATION_WORKER_OUTPUT_LIMIT = 4096;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$apiDirectory = dirname(__DIR__);
$controlDirectory = getenv('MEDISA_MIGRATION_CONTROL_DIR');
$controlDirectory = is_string($controlDirectory) && $controlDirectory !== ''
    ? $controlDirectory
    : $apiDirectory . '/runtime/migration-control';
$statusPath = $controlDirectory . '/status.json';
$lockPath = $controlDirectory . '/worker.lock';
$migrationScript = getenv('MEDISA_MIGRATION_SCRIPT');
$migrationScript = is_string($migrationScript) && $migrationScript !== ''
    ? $migrationScript
    : $apiDirectory . '/bin/migrate.php';
$deployShaPath = getenv('MEDISA_DEPLOY_SHA_PATH');
$deployShaPath = is_string($deployShaPath) && $deployShaPath !== ''
    ? $deployShaPath
    : $apiDirectory . '/.deploy-sha';

if (!is_dir($controlDirectory)) {
    exit(0);
}

$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    exit(0);
}

try {
    $stage = 'REQUEST_PARSE';
    $processingPaths = glob($controlDirectory . '/request.processing.*.json') ?: [];
    if ($processingPaths !== []) {
        writeStatus($statusPath, [
            'state' => 'FAILED',
            'request_id' => 'stale-processing',
            'reason' => 'STALE_PROCESSING_REQUEST',
            'stage' => 'REQUEST_PARSE',
            'exit_code' => 1,
        ]);
        exit(1);
    }

    $pendingPaths = glob($controlDirectory . '/request.pending.*.json') ?: [];
    sort($pendingPaths, SORT_STRING);
    if ($pendingPaths === []) {
        exit(0);
    }
    $pendingPath = $pendingPaths[0];

    $claimToken = bin2hex(random_bytes(12));
    $processingPath = $controlDirectory . '/request.processing.' . $claimToken . '.json';
    if (!rename($pendingPath, $processingPath)) {
        exit(1);
    }

    $requestId = $claimToken;
    try {
        $stage = 'REQUEST_PARSE';
        $rawRequest = file_get_contents($processingPath);
        if ($rawRequest === false) {
            throw new RuntimeException('REQUEST_UNREADABLE');
        }
        $request = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($request)) {
            throw new RuntimeException('REQUEST_NOT_OBJECT');
        }

        $requestId = requireString($request, 'request_id', '/^[A-Za-z0-9._-]{1,128}$/');
        $deployedSha = requireString($request, 'deployed_sha', '/^[a-f0-9]{40}$/i');
        requireString($request, 'requested_at', '/^\d{4}-\d{2}-\d{2}T.*Z$/');

        $stage = 'DEPLOY_SHA_CHECK';
        $publishedSha = trim((string) @file_get_contents($deployShaPath));
        if (!preg_match('/^[a-f0-9]{40}$/i', $publishedSha) || !hash_equals($publishedSha, $deployedSha)) {
            throw new RuntimeException('DEPLOY_SHA_MISMATCH');
        }

        $stage = 'STATUS_WRITE';
        writeStatus($statusPath, [
            'state' => 'RUNNING',
            'request_id' => $requestId,
            'deployed_sha' => strtolower($deployedSha),
        ]);

        $phpCli = getenv('MEDISA_PHP_CLI_PATH');
        $phpCli = is_string($phpCli) && $phpCli !== '' ? $phpCli : PHP_BINARY;
        $baseline = getenv('MEDISA_MIGRATION_BASELINE');
        $baseline = is_string($baseline) && $baseline !== '' ? trim($baseline) : null;

        $stage = 'APPLY';
        $applyResult = runMigrationCommand($phpCli, $migrationScript, $baseline, false);
        if ($applyResult['exit_code'] !== 0) {
            throw MigrationWorkerFailure::fromCommandResult($stage, $applyResult);
        }
        $stage = 'VERIFY';
        $verifyResult = runMigrationCommand($phpCli, $migrationScript, null, true);
        if ($verifyResult['exit_code'] !== 0) {
            throw MigrationWorkerFailure::fromCommandResult($stage, $verifyResult);
        }

        $stage = 'STATUS_WRITE';
        writeStatus($statusPath, [
            'state' => 'SUCCEEDED',
            'request_id' => $requestId,
            'deployed_sha' => strtolower($deployedSha),
        ]);
        $stage = 'REQUEST_ARCHIVE';
        archiveRequest($processingPath, $controlDirectory . '/request.completed.' . safeId($requestId) . '.json');
        exit(0);
    } catch (\Throwable $exception) {
        $reason = $exception instanceof MigrationWorkerFailure
            ? $exception->reason
            : classifyWorkerFailure($exception, $stage);
        $failureStatus = [
            'state' => 'FAILED',
            'request_id' => $requestId,
            'reason' => $reason,
            'stage' => $exception instanceof MigrationWorkerFailure ? $exception->stage : $stage,
            'exit_code' => $exception instanceof MigrationWorkerFailure ? $exception->exitCode : 1,
        ];
        if ($exception instanceof MigrationWorkerFailure && $exception->detail !== null) {
            $failureStatus['detail'] = $exception->detail;
        }
        writeStatus($statusPath, [
            ...$failureStatus,
        ]);
        $stage = 'REQUEST_ARCHIVE';
        archiveRequest($processingPath, $controlDirectory . '/request.failed.' . safeId($requestId) . '.json');
        exit(1);
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

/**
 * @param array<string, mixed> $request
 */
function requireString(array $request, string $key, string $pattern): string
{
    $value = $request[$key] ?? null;
    if (!is_string($value) || preg_match($pattern, $value) !== 1) {
        throw new RuntimeException('REQUEST_INVALID');
    }
    return $value;
}

final class MigrationWorkerFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $stage,
        public readonly int $exitCode,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($reason);
    }

    /**
     * @param array{exit_code: int, stdout: string, stderr: string} $result
     */
    public static function fromCommandResult(string $stage, array $result): self
    {
        $output = trim($result['stderr'] . "\n" . $result['stdout']);
        $reason = classifyCommandFailure($output, $stage);
        $detail = sanitizeDiagnosticDetail($output);
        return new self($reason, $stage, $result['exit_code'], $detail);
    }
}

/**
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runMigrationCommand(string $phpCli, string $script, ?string $baseline, bool $verify): array
{
    $command = escapeshellarg($phpCli) . ' ' . escapeshellarg($script);
    if ($baseline !== null) {
        $command .= ' --baseline=' . escapeshellarg($baseline);
    }
    if ($verify) {
        $command .= ' --verify';
    }

    $stdoutPath = tempnam(sys_get_temp_dir(), 'medisa-migration-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'medisa-migration-err-');
    if ($stdoutPath === false || $stderrPath === false) {
        if (is_string($stdoutPath)) {
            @unlink($stdoutPath);
        }
        if (is_string($stderrPath)) {
            @unlink($stderrPath);
        }
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
    }

    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
    }

    fclose($pipes[0]);
    $exitCode = proc_close($process);
    $stdout = readBoundedOutput($stdoutPath);
    $stderr = readBoundedOutput($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function readBoundedOutput(string $path): string
{
    $contents = @file_get_contents($path, false, null, 0, MIGRATION_WORKER_OUTPUT_LIMIT + 1);
    if (!is_string($contents)) {
        return '';
    }
    return strlen($contents) > MIGRATION_WORKER_OUTPUT_LIMIT
        ? substr($contents, 0, MIGRATION_WORKER_OUTPUT_LIMIT) . "\n[truncated]"
        : $contents;
}

function classifyWorkerFailure(Throwable $exception, string $stage): string
{
    $message = $exception->getMessage();
    $knownCodes = [
        'DEPLOY_SHA_MISMATCH',
        'REQUEST_INVALID',
        'REQUEST_NOT_OBJECT',
        'REQUEST_UNREADABLE',
    ];
    if (in_array($message, $knownCodes, true)) {
        return $message;
    }
    if ($stage === 'REQUEST_PARSE') {
        return 'REQUEST_INVALID';
    }
    if ($stage === 'DEPLOY_SHA_CHECK') {
        return 'DEPLOY_SHA_CHECK_FAILED';
    }
    if ($stage === 'STATUS_WRITE') {
        return 'STATUS_WRITE_FAILED';
    }
    if ($stage === 'REQUEST_ARCHIVE') {
        return 'REQUEST_ARCHIVE_FAILED';
    }
    return $stage === 'VERIFY' ? 'SCHEMA_VERIFY_FAILED' : 'UNKNOWN_MIGRATION_FAILURE';
}

function classifyCommandFailure(string $output, string $stage): string
{
    if (preg_match('/reason_code=([A-Z0-9_]+)/', $output, $matches) === 1) {
        return $matches[1];
    }
    $normalized = strtolower($output);
    if (
        str_contains($normalized, 'database configuration')
        || str_contains($normalized, 'sqlstate')
        || str_contains($normalized, 'could not find driver')
    ) {
        return 'DB_CONNECTION_FAILED';
    }
    if (
        str_contains($normalized, 'undefined function')
        || str_contains($normalized, 'undefined class')
        || str_contains($normalized, 'extension')
    ) {
        return 'PHP_EXTENSION_MISSING';
    }
    if (str_contains($normalized, 'checksum mismatch')) {
        return 'MIGRATION_CHECKSUM_MISMATCH';
    }
    if (str_contains($normalized, 'ledger has a gap')) {
        return 'MIGRATION_LEDGER_GAP';
    }
    if (str_contains($normalized, 'baseline')) {
        return str_contains($normalized, 'initialized without')
            ? 'MIGRATION_BASELINE_REQUIRED'
            : 'MIGRATION_BASELINE_INVALID';
    }
    if (str_contains($normalized, 'migration source') || str_contains($normalized, 'canonical migration files')) {
        return 'MIGRATION_SOURCE_MISSING';
    }
    if (str_contains($normalized, 'migration lock')) {
        return 'MIGRATION_LOCK_FAILED';
    }
    return 'UNKNOWN_MIGRATION_FAILURE';
}

function sanitizeDiagnosticDetail(string $output): ?string
{
    $lines = preg_split('/\R/', $output) ?: [];
    $safeLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || preg_match('/(password|passwd|secret|token|dsn|authorization|bearer)/i', $line)) {
            continue;
        }
        if (preg_match('/^(?:migration_apply|schema_ready)=/', $line) === 1) {
            $safeLines[] = preg_replace('/\s+/', ' ', $line) ?? $line;
        }
    }
    if ($safeLines === []) {
        return null;
    }
    return substr(implode(' ', $safeLines), 0, MIGRATION_WORKER_OUTPUT_LIMIT);
}

/**
 * @param array<string, string> $status
 */
function writeStatus(string $statusPath, array $status): void
{
    $status['schema_version'] = '1';
    $status['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $temporaryPath = $statusPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $json = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('STATUS_WRITE_FAILED');
    }
    @chmod($temporaryPath, 0600);
    if (!rename($temporaryPath, $statusPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('STATUS_PUBLISH_FAILED');
    }
}

function archiveRequest(string $processingPath, string $archivePath): void
{
    if (!rename($processingPath, $archivePath)) {
        throw new RuntimeException('REQUEST_ARCHIVE_FAILED');
    }
    @chmod($archivePath, 0600);
}

function safeId(string $value): string
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
    return is_string($safe) && $safe !== '' ? $safe : 'unknown';
}
