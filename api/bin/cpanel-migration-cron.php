<?php

declare(strict_types=1);

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
    $processingPaths = glob($controlDirectory . '/request.processing.*.json') ?: [];
    if ($processingPaths !== []) {
        writeStatus($statusPath, [
            'state' => 'FAILED',
            'request_id' => 'stale-processing',
            'reason' => 'STALE_PROCESSING_REQUEST',
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

        $publishedSha = trim((string) @file_get_contents($deployShaPath));
        if (!preg_match('/^[a-f0-9]{40}$/i', $publishedSha) || !hash_equals($publishedSha, $deployedSha)) {
            throw new RuntimeException('DEPLOY_SHA_MISMATCH');
        }

        writeStatus($statusPath, [
            'state' => 'RUNNING',
            'request_id' => $requestId,
            'deployed_sha' => strtolower($deployedSha),
        ]);

        $phpCli = getenv('MEDISA_PHP_CLI_PATH');
        $phpCli = is_string($phpCli) && $phpCli !== '' ? $phpCli : PHP_BINARY;
        $baseline = getenv('MEDISA_MIGRATION_BASELINE');
        $baseline = is_string($baseline) && $baseline !== '' ? trim($baseline) : null;

        $applyExit = runMigrationCommand($phpCli, $migrationScript, $baseline, false);
        if ($applyExit !== 0) {
            throw new RuntimeException('MIGRATION_APPLY_FAILED');
        }
        $verifyExit = runMigrationCommand($phpCli, $migrationScript, null, true);
        if ($verifyExit !== 0) {
            throw new RuntimeException('SCHEMA_VERIFY_FAILED');
        }

        writeStatus($statusPath, [
            'state' => 'SUCCEEDED',
            'request_id' => $requestId,
            'deployed_sha' => strtolower($deployedSha),
        ]);
        archiveRequest($processingPath, $controlDirectory . '/request.completed.' . safeId($requestId) . '.json');
        exit(0);
    } catch (\Throwable $exception) {
        $reason = preg_match('/^[A-Z0-9_]+$/', $exception->getMessage())
            ? $exception->getMessage()
            : 'REQUEST_FAILED';
        writeStatus($statusPath, [
            'state' => 'FAILED',
            'request_id' => $requestId,
            'reason' => $reason,
        ]);
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

function runMigrationCommand(string $phpCli, string $script, ?string $baseline, bool $verify): int
{
    $command = escapeshellarg($phpCli) . ' ' . escapeshellarg($script);
    if ($baseline !== null) {
        $command .= ' --baseline=' . escapeshellarg($baseline);
    }
    if ($verify) {
        $command .= ' --verify';
    }

    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return 1;
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process);
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
