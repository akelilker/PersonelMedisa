<?php

declare(strict_types=1);

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\MigrationBackupService;
use Medisa\Api\Database\MigrationExecutionService;
use Medisa\Api\Database\MigrationPreflightReport;
use Medisa\Api\Services\Organizasyon\OrganizationInitialMappingService;
use Medisa\Api\Services\Organizasyon\OrganizationMappingFailure;
use Medisa\Api\Services\Organizasyon\OrganizationMappingInventoryReport;
use Medisa\Api\Services\Organizasyon\OrganizationMappingSpec;

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
$preflightPath = $controlDirectory . '/preflight.json';
$inventoryPath = $controlDirectory . '/organization-inventory.json';
$mappingPreflightPath = $controlDirectory . '/organization-mapping-preflight.json';
$mappingPostcheckPath = $controlDirectory . '/organization-mapping-postcheck.json';
$heartbeatPath = $controlDirectory . '/worker-heartbeat.json';
$lockPath = $controlDirectory . '/worker.lock';
$deployShaPath = getenv('MEDISA_DEPLOY_SHA_PATH');
$deployShaPath = is_string($deployShaPath) && $deployShaPath !== ''
    ? $deployShaPath
    : $apiDirectory . '/.deploy-sha';

if (!is_dir($controlDirectory)) {
    exit(0);
}

// Proves which deploy root the Cron schedule actually executes, even when there
// is no request to process. Best-effort: never blocks or fails migration work.
writeHeartbeat($heartbeatPath, $deployShaPath);

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
    // Declared before the request is parsed so a failure status can always name
    // which mode the worker believed it was running.
    $mode = 'APPLY';
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
        // Requests written before the preflight mode existed carry no mode and must
        // keep meaning "apply", so the control plane stays backward compatible.
        $mode = array_key_exists('mode', $request)
            ? requireString(
                $request,
                'mode',
                '/^(APPLY|READ_ONLY_PREFLIGHT|READ_ONLY_ORGANIZATION_INVENTORY'
                . '|ORGANIZATION_MAPPING_PREFLIGHT|ORGANIZATION_MAPPING_APPLY|FINAL_CLOSE_PREFLIGHT|FINAL_CLOSE_APPLY)$/'
            )
            : 'APPLY';
        // Optional, and only meaningful for APPLY: the single migration version
        // this request is authorized to apply. Requests written before the round
        // model carry none and keep draining the whole pending chain.
        $targetVersion = array_key_exists('target_version', $request)
            ? requireString($request, 'target_version', '/^\d{3}$/')
            : null;

        $stage = 'DEPLOY_SHA_CHECK';
        $publishedSha = trim((string) @file_get_contents($deployShaPath));
        if (!preg_match('/^[a-f0-9]{40}$/i', $publishedSha) || !hash_equals($publishedSha, $deployedSha)) {
            throw new RuntimeException('DEPLOY_SHA_MISMATCH');
        }

        if ($mode === 'FINAL_CLOSE_PREFLIGHT' || $mode === 'FINAL_CLOSE_APPLY') {
            $stage = $mode;
            $report = \Medisa\Api\Services\Operations\FinalCloseService::run($request, $apiDirectory, $publishedSha);
            writeJsonAtomically($controlDirectory . '/final-close-report.json', $report);
            writeStatus($statusPath, [
                'state' => $report['result'] === 'PASS' ? 'SUCCEEDED' : 'FAILED',
                'request_id' => $requestId, 'deployed_sha' => $publishedSha, 'mode' => $mode,
                'final_close_result' => $report['result'],
                'preflight_checksum' => $report['preflight_checksum'],
                'production_mutation_count' => $report['production_mutation_count'],
            ]);
            archiveRequest($processingPath, $controlDirectory . '/request.completed.' . safeId($requestId) . '.json');
            exit($report['result'] === 'PASS' ? 0 : 1);
        }

        $stage = 'STATUS_WRITE';
        writeStatus($statusPath, [
            'state' => 'RUNNING',
            'request_id' => $requestId,
            'deployed_sha' => strtolower($deployedSha),
            'mode' => $mode,
        ]);

        $baseline = getenv('MEDISA_MIGRATION_BASELINE');
        $baseline = is_string($baseline) && $baseline !== '' ? trim($baseline) : null;

        if ($mode === 'READ_ONLY_PREFLIGHT') {
            $stage = 'PREFLIGHT';
            try {
                $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
                $pdo = Connection::get();
                $report = MigrationPreflightReport::collect($pdo, $migrationSource, $deployedSha);
                $report['request_id'] = $requestId;
                writeJsonAtomically($preflightPath, $report);
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'STATUS_WRITE';
            writeStatus($statusPath, [
                'state' => 'SUCCEEDED',
                'request_id' => $requestId,
                'deployed_sha' => strtolower($deployedSha),
                'mode' => $mode,
                'preflight_result' => (string) $report['result'],
                'preflight_generated_at' => (string) $report['generated_at'],
            ]);
            $stage = 'REQUEST_ARCHIVE';
            archiveRequest(
                $processingPath,
                $controlDirectory . '/request.completed.' . safeId($requestId) . '.json'
            );
            exit(0);
        }

        // Row-level organisation inventory. Strictly SELECT-only: it publishes the
        // inventory artifact and leaves before the backup/apply stages exist, so
        // this mode has no path to a mutation even if a later stage is added.
        if ($mode === 'READ_ONLY_ORGANIZATION_INVENTORY') {
            $stage = 'ORGANIZATION_INVENTORY';
            try {
                $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
                $pdo = Connection::get();
                $ledger = MigrationExecutionService::ledgerFacts($pdo, $migrationSource);
                $inventory = OrganizationMappingInventoryReport::collect($pdo, $deployedSha, $ledger['tip']);
                $inventory['request_id'] = $requestId;
                $inventory['pending_migration_count'] = $ledger['pending_count'];
                writeJsonAtomically($inventoryPath, $inventory);
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'STATUS_WRITE';
            writeStatus($statusPath, [
                'state' => 'SUCCEEDED',
                'request_id' => $requestId,
                'deployed_sha' => strtolower($deployedSha),
                'mode' => $mode,
                'inventory_result' => (string) $inventory['result'],
                'inventory_checksum' => (string) $inventory['inventory_checksum'],
                'inventory_generated_at' => (string) $inventory['generated_at'],
            ]);
            $stage = 'REQUEST_ARCHIVE';
            archiveRequest(
                $processingPath,
                $controlDirectory . '/request.completed.' . safeId($requestId) . '.json'
            );
            exit(0);
        }

        // Read-only gate for the initial organisation mapping. Same request shape
        // as the apply below, deliberately reachable on its own so the decision can
        // be reviewed against production before anything is written.
        if ($mode === 'ORGANIZATION_MAPPING_PREFLIGHT') {
            $stage = 'ORGANIZATION_MAPPING_PREFLIGHT';
            try {
                $spec = OrganizationMappingSpec::parse($request['mapping_spec'] ?? null);
                $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
                $pdo = Connection::get();
                $ledger = MigrationExecutionService::ledgerFacts($pdo, $migrationSource);
                $inventory = readPublishedInventory($inventoryPath);
                $report = OrganizationInitialMappingService::preflight(
                    $pdo,
                    $spec,
                    $deployedSha,
                    $ledger['tip'],
                    $ledger['pending_count'],
                    $inventory
                );
                $report['request_id'] = $requestId;
                writeJsonAtomically($mappingPreflightPath, $report);
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'STATUS_WRITE';
            writeStatus($statusPath, [
                'state' => 'SUCCEEDED',
                'request_id' => $requestId,
                'deployed_sha' => strtolower($deployedSha),
                'mode' => $mode,
                'mapping_preflight_result' => (string) $report['result'],
                'mapping_spec_checksum' => (string) $report['spec_checksum'],
                'mapping_operation_id' => (string) $report['operation_id'],
            ]);
            $stage = 'REQUEST_ARCHIVE';
            archiveRequest(
                $processingPath,
                $controlDirectory . '/request.completed.' . safeId($requestId) . '.json'
            );
            exit(0);
        }

        // Mapping apply. Backup is a stage, not a checklist item: the mapping owner
        // refuses to start without verified backup evidence.
        if ($mode === 'ORGANIZATION_MAPPING_APPLY') {
            $stage = 'ORGANIZATION_MAPPING_PREFLIGHT';
            try {
                $spec = OrganizationMappingSpec::parse($request['mapping_spec'] ?? null);
                $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
                $pdo = Connection::get();
                $ledger = MigrationExecutionService::ledgerFacts($pdo, $migrationSource);
                $inventory = readPublishedInventory($inventoryPath);
                $preflight = OrganizationInitialMappingService::preflight(
                    $pdo,
                    $spec,
                    $deployedSha,
                    $ledger['tip'],
                    $ledger['pending_count'],
                    $inventory
                );
                writeJsonAtomically($mappingPreflightPath, $preflight + ['request_id' => $requestId]);
                if ($preflight['result'] !== 'PASS') {
                    throw new OrganizationMappingFailure(
                        'MAPPING_PREFLIGHT_BLOCKED',
                        implode(',', $preflight['blockers'])
                    );
                }
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'ORGANIZATION_MAPPING_BACKUP';
            try {
                $backup = MigrationBackupService::createForOrganizationMapping(
                    $pdo,
                    $apiDirectory,
                    $spec->operationId(),
                    $ledger['tip'],
                    $spec->authorizedDeploySha(),
                    $spec->inventoryChecksum(),
                    $spec->checksum()
                );
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'ORGANIZATION_MAPPING_APPLY';
            try {
                $applied = OrganizationInitialMappingService::apply(
                    $pdo,
                    $spec,
                    $deployedSha,
                    $ledger['tip'],
                    $ledger['pending_count'],
                    $inventory,
                    $backup
                );
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'ORGANIZATION_MAPPING_POSTCHECK';
            try {
                $postcheck = OrganizationInitialMappingService::postcheck($pdo, $spec, $backup);
                $postcheck['request_id'] = $requestId;
                $postcheck['applied'] = $applied['applied'];
                writeJsonAtomically($mappingPostcheckPath, $postcheck);
                if ($postcheck['result'] !== 'PASS') {
                    throw new OrganizationMappingFailure(
                        'MAPPING_POSTCHECK_BLOCKED',
                        implode(',', $postcheck['unexpected_deltas'])
                    );
                }
            } catch (\Throwable $exception) {
                throw MigrationWorkerFailure::fromThrowable($stage, $exception);
            }

            $stage = 'STATUS_WRITE';
            writeStatus($statusPath, [
                'state' => 'SUCCEEDED',
                'request_id' => $requestId,
                'deployed_sha' => strtolower($deployedSha),
                'mode' => $mode,
                'mapping_operation_id' => $spec->operationId(),
                'mapping_spec_checksum' => $spec->checksum(),
                'mapping_postcheck_result' => (string) $postcheck['result'],
                'backup_file' => (string) $backup['file'],
                'backup_sha256' => (string) $backup['sha256'],
                'backup_bytes' => (int) $backup['bytes'],
                'backup_readback' => (string) $backup['readback'],
            ]);
            $stage = 'REQUEST_ARCHIVE';
            archiveRequest(
                $processingPath,
                $controlDirectory . '/request.completed.' . safeId($requestId) . '.json'
            );
            exit(0);
        }

        // Backup is a stage, not a checklist item: apply is unreachable unless a
        // dump for the two closing tables plus the ledger preimage has been
        // written outside the webroot and read back successfully.
        // A targeted request owns exactly one migration. It must be the next
        // pending one: applying anything else would either skip a migration or
        // re-enter one that is already committed.
        $stage = 'TARGET_RESOLVE';
        try {
            $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
            $pdo = Connection::get();
            if ($targetVersion !== null) {
                $ledger = MigrationExecutionService::ledgerFacts($pdo, $migrationSource);
                $nextPending = $ledger['pending_versions'][0] ?? null;
                if ($nextPending === null) {
                    throw new RuntimeException('TARGET_ALREADY_APPLIED');
                }
                if ($nextPending !== $targetVersion) {
                    throw new RuntimeException('TARGET_NOT_NEXT_PENDING');
                }
            }
        } catch (\Throwable $exception) {
            throw MigrationWorkerFailure::fromThrowable($stage, $exception);
        }

        $stage = 'BACKUP';
        try {
            $migrations = $migrationSource->all();
            $migrationTip = $migrations === []
                ? 'unknown'
                : (string) $migrations[count($migrations) - 1]['version'];
            // A targeted request names its dump after the migration it is about to
            // apply, so each migration in a round keeps its own rollback artifact.
            $backupLabel = $targetVersion ?? $migrationTip;
            $backup = MigrationBackupService::create($pdo, $apiDirectory, $requestId, $backupLabel);
        } catch (\Throwable $exception) {
            throw MigrationWorkerFailure::fromThrowable($stage, $exception);
        }

        $stage = 'APPLY';
        try {
            $applyResult = MigrationExecutionService::apply($pdo, $migrationSource, $baseline, $targetVersion);
        } catch (\Throwable $exception) {
            throw MigrationWorkerFailure::fromThrowable($stage, $exception);
        }
        $stage = 'VERIFY';
        try {
            $verifyResult = MigrationExecutionService::verify($pdo, $migrationSource, $targetVersion);
        } catch (\Throwable $exception) {
            throw MigrationWorkerFailure::fromThrowable($stage, $exception);
        }

        $stage = 'STATUS_WRITE';
        writeStatus($statusPath, [
            'state' => 'SUCCEEDED',
            'request_id' => $requestId,
            'deployed_sha' => strtolower($deployedSha),
            'mode' => $mode,
            'target_version' => $targetVersion ?? 'ALL_PENDING',
            'applied_versions' => implode(',', $applyResult['pending']),
            'pending_versions_after' => implode(',', $verifyResult['pending']),
            'backup_file' => (string) $backup['file'],
            'backup_sha256' => (string) $backup['sha256'],
            'backup_bytes' => (int) $backup['bytes'],
            'backup_readback' => (string) $backup['readback'],
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
            'mode' => $mode,
            'reason' => $reason,
            'stage' => $exception instanceof MigrationWorkerFailure ? $exception->stage : $stage,
            'exit_code' => $exception instanceof MigrationWorkerFailure ? $exception->exitCode : 1,
        ];
        if ($exception instanceof MigrationWorkerFailure && $exception->detail !== null) {
            $failureStatus['detail'] = $exception->detail;
        } elseif (preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $exception->getMessage()) === 1) {
            // Bounded single-token code only: never raw PDO/PII message text.
            $failureStatus['detail'] = $exception->getMessage();
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

/**
 * Read back the inventory artifact the read-only inventory mode published.
 *
 * The mapping modes never collect their own inventory: the spec pins a checksum,
 * and that checksum has to be verifiable against the exact artifact a human
 * reviewed, not against a fresh read taken moments before the write.
 *
 * @return array<string, mixed>
 */
function readPublishedInventory(string $inventoryPath): array
{
    $raw = @file_get_contents($inventoryPath);
    if ($raw === false) {
        throw new OrganizationMappingFailure('MAPPING_INVENTORY_ARTIFACT_MISSING');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new OrganizationMappingFailure('MAPPING_INVENTORY_UNREADABLE');
    }

    return $decoded;
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
        // A mapping owner already failed with a precise, publishable reason code;
        // re-classifying it through the generic migration classifier would flatten
        // it into a much less useful UNKNOWN.
        if ($exception instanceof OrganizationMappingFailure) {
            return new self($exception->reason, $stage, 1, $exception->detail);
        }

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
    // Surface bounded single-token owner codes verbatim so a worker failure is
    // attributable. FINAL_CLOSE_*, BACKUP_* and other domain codes (e.g. SGK_*,
    // SUBE_*) are all publishable reasons; without this they would collapse into
    // the generic UNKNOWN_MIGRATION_FAILURE below. Free-text messages (PDO, PII)
    // never match and stay hidden.
    if (preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $message) === 1) {
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
    if ($stage === 'BACKUP') {
        return 'BACKUP_FAILED';
    }
    if ($stage === 'PREFLIGHT') {
        return 'PREFLIGHT_FAILED';
    }
    if ($stage === 'ORGANIZATION_INVENTORY') {
        return 'ORGANIZATION_INVENTORY_FAILED';
    }
    if ($stage === 'ORGANIZATION_MAPPING_PREFLIGHT') {
        return 'ORGANIZATION_MAPPING_PREFLIGHT_FAILED';
    }
    if ($stage === 'ORGANIZATION_MAPPING_BACKUP') {
        return 'ORGANIZATION_MAPPING_BACKUP_FAILED';
    }
    if ($stage === 'ORGANIZATION_MAPPING_APPLY') {
        return 'ORGANIZATION_MAPPING_APPLY_FAILED';
    }
    if ($stage === 'ORGANIZATION_MAPPING_POSTCHECK') {
        return 'ORGANIZATION_MAPPING_POSTCHECK_FAILED';
    }
    return $stage === 'VERIFY' ? 'SCHEMA_VERIFY_FAILED' : 'UNKNOWN_MIGRATION_FAILURE';
}

/**
 * @param array<string, mixed> $status
 */
function writeStatus(string $statusPath, array $status): void
{
    $status['schema_version'] = '1';
    $status['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    writeJsonAtomically($statusPath, $status);
}

/**
 * @param array<string, mixed> $payload
 */
function writeJsonAtomically(string $path, array $payload): void
{
    $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('STATUS_WRITE_FAILED');
    }
    @chmod($temporaryPath, 0600);
    if (PHP_OS_FAMILY === 'Windows') {
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporaryPath);
            throw new RuntimeException('STATUS_WRITE_FAILED');
        }
        @chmod($path, 0600);
        @unlink($temporaryPath);
        return;
    }
    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('STATUS_PUBLISH_FAILED');
    }
}

function writeHeartbeat(string $heartbeatPath, string $deployShaPath): void
{
    $publishedSha = trim((string) @file_get_contents($deployShaPath));
    if (preg_match('/^[a-f0-9]{40}$/i', $publishedSha) !== 1) {
        $publishedSha = 'UNKNOWN';
    }
    $json = json_encode([
        'schema_version' => '1',
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'deployed_sha' => strtolower($publishedSha),
    ], JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $temporaryPath = $heartbeatPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false) {
        return;
    }
    @chmod($temporaryPath, 0600);
    if (PHP_OS_FAMILY === 'Windows') {
        @file_put_contents($heartbeatPath, $json . PHP_EOL, LOCK_EX);
        @chmod($heartbeatPath, 0600);
        @unlink($temporaryPath);
        return;
    }
    if (!@rename($temporaryPath, $heartbeatPath)) {
        @unlink($temporaryPath);
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
