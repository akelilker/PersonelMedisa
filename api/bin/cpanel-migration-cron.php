<?php

declare(strict_types=1);

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\MigrationExecutionService;

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

        $baseline = getenv('MEDISA_MIGRATION_BASELINE');
        $baseline = is_string($baseline) && $baseline !== '' ? trim($baseline) : null;

        $stage = 'APPLY';
        try {
            $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
            $pdo = Connection::get();
            MigrationExecutionService::apply($pdo, $migrationSource, $baseline);
        } catch (\Throwable $exception) {
            throw MigrationWorkerFailure::fromThrowable($stage, $exception);
        }
        $stage = 'VERIFY';
        try {
            MigrationExecutionService::verify($pdo, $migrationSource);
        } catch (\Throwable $exception) {
            throw MigrationWorkerFailure::fromThrowable($stage, $exception);
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

    public static function fromThrowable(string $stage, \Throwable $exception): self
    {
        return new self(
            MigrationExecutionService::classify($exception),
            $stage,
            1,
            MigrationExecutionService::safeDetail($exception),
        );
    }
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
    if (PHP_OS_FAMILY === 'Windows') {
        if (file_put_contents($statusPath, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporaryPath);
            throw new RuntimeException('STATUS_WRITE_FAILED');
        }
        @chmod($statusPath, 0600);
        @unlink($temporaryPath);
        return;
    }
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
