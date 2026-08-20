<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;
use PDOException;

/**
 * Generic offline-mutation idempotency owner.
 *
 * Security:
 * - Identity = authenticated actor_user_id + operation_scope + Idempotency-Key
 * - Cross-actor key reuse never returns another actor's result
 * - Raw request/response bodies are never stored (payload_hash + result locator only)
 *
 * Concurrency: UNIQUE(actor, scope, key) + claim inside the same transaction as the mutation.
 */
final class OfflineMutationIdempotencyService
{
    public const KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';
    public const KEY_MAX_LEN = 128;
    public const CONFLICT_CODE = 'IDEMPOTENCY_KEY_CONFLICT';

    /** @return string|null Normalized key, or null when header absent (non-queue clients). */
    public static function readKey(Request $request)
    {
        $raw = $request->getHeader('idempotency-key', '');
        if (!is_string($raw)) {
            return null;
        }
        $key = trim($raw);
        if ($key === '') {
            return null;
        }
        if (strlen($key) > self::KEY_MAX_LEN || preg_match(self::KEY_PATTERN, $key) !== 1) {
            JsonResponse::badRequest(
                'Idempotency-Key 8-128 guvenli karakter olmalidir.',
                'IDEMPOTENCY_KEY_INVALID',
                'Idempotency-Key'
            );
        }

        return $key;
    }

    /**
     * Deterministic SHA-256 over canonical JSON (sorted keys, no raw secrets expected).
     *
     * @param mixed $canonicalPayload
     */
    public static function hashPayload($canonicalPayload)
    {
        $json = self::canonicalJson($canonicalPayload);
        return hash('sha256', $json);
    }

    public static function schemaReady(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'offline_mutation_idempotency'");
            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Short-circuit COMPLETED replay / conflict before opening a mutation TX when possible.
     *
     * @return array<string, mixed>|null Replay row when COMPLETED+same hash; null to proceed.
     */
    public static function findCompletedReplay(PDO $pdo, $actorUserId, $operationScope, $key, $payloadHash)
    {
        if ($key === null || $key === '' || !self::schemaReady($pdo)) {
            return null;
        }

        $row = self::findRow($pdo, (int) $actorUserId, (string) $operationScope, (string) $key);
        if ($row === null) {
            return null;
        }

        $state = (string) $row['state'];
        $same = hash_equals((string) $row['payload_hash'], (string) $payloadHash);

        if ($state === 'COMPLETED') {
            if (!$same) {
                JsonResponse::error(
                    409,
                    self::CONFLICT_CODE,
                    'Ayni Idempotency-Key farkli payload ile kullanilamaz.'
                );
            }
            return $row;
        }

        if ($state === 'CLAIMED') {
            // Durable CLAIMED should not exist with in-TX claim model.
            JsonResponse::error(
                409,
                'IDEMPOTENCY_IN_FLIGHT',
                'Ayni Idempotency-Key ile islem devam ediyor.'
            );
        }

        // FAILED + same hash → allow reclaim by proceeding
        if ($state === 'FAILED' && !$same) {
            JsonResponse::error(
                409,
                self::CONFLICT_CODE,
                'Ayni Idempotency-Key farkli payload ile kullanilamaz.'
            );
        }

        return null;
    }

    /**
     * Claim CLAIMED inside an open transaction. Caller must complete/fail before commit.
     *
     * @return array<string, mixed>|null Existing COMPLETED row for same hash (replay); null on fresh claim.
     */
    public static function claimInTransaction(PDO $pdo, $actorUserId, $operationScope, $key, $payloadHash)
    {
        if ($key === null || $key === '' || !self::schemaReady($pdo)) {
            return null;
        }
        if (!$pdo->inTransaction()) {
            throw new \RuntimeException('OfflineMutationIdempotencyService::claimInTransaction requires an open transaction.');
        }

        $actor = (int) $actorUserId;
        $scope = (string) $operationScope;
        $idemKey = (string) $key;
        $hash = strtolower((string) $payloadHash);
        $row = null;

        // Two attempts: concurrent loser may wait FOR UPDATE on a row that then rolls back;
        // after unlock the row is gone — retry INSERT once rather than mutating blindly.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $ins = $pdo->prepare('
                    INSERT INTO offline_mutation_idempotency (
                        actor_user_id, operation_scope, idempotency_key, payload_hash,
                        state, http_status, created_at
                    ) VALUES (
                        :actor_user_id, :operation_scope, :idempotency_key, :payload_hash,
                        \'CLAIMED\', 200, NOW(3)
                    )
                ');
                $ins->execute([
                    'actor_user_id' => $actor,
                    'operation_scope' => $scope,
                    'idempotency_key' => $idemKey,
                    'payload_hash' => $hash,
                ]);
                return null;
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }

            $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $sel = $pdo->prepare('
                SELECT * FROM offline_mutation_idempotency
                WHERE actor_user_id = :actor_user_id
                  AND operation_scope = :operation_scope
                  AND idempotency_key = :idempotency_key
                ' . $forUpdate . '
                LIMIT 1
            ');
            $sel->execute([
                'actor_user_id' => $actor,
                'operation_scope' => $scope,
                'idempotency_key' => $idemKey,
            ]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                break;
            }
        }
        if (!is_array($row)) {
            throw new \RuntimeException('Idempotency claim race unresolved.');
        }

        $same = hash_equals((string) $row['payload_hash'], $hash);
        $state = (string) $row['state'];

        if ($state === 'COMPLETED') {
            if (!$same) {
                JsonResponse::error(
                    409,
                    self::CONFLICT_CODE,
                    'Ayni Idempotency-Key farkli payload ile kullanilamaz.'
                );
            }
            return $row;
        }

        if ($state === 'CLAIMED') {
            JsonResponse::error(
                409,
                'IDEMPOTENCY_IN_FLIGHT',
                'Ayni Idempotency-Key ile islem devam ediyor.'
            );
        }

        if ($state === 'FAILED') {
            if (!$same) {
                JsonResponse::error(
                    409,
                    self::CONFLICT_CODE,
                    'Ayni Idempotency-Key farkli payload ile kullanilamaz.'
                );
            }
            $upd = $pdo->prepare('
                UPDATE offline_mutation_idempotency
                SET state = \'CLAIMED\',
                    payload_hash = :payload_hash,
                    error_code = NULL,
                    completed_at = NULL,
                    result_entity_type = NULL,
                    result_entity_id = NULL,
                    result_entity_ref = NULL,
                    http_status = 200
                WHERE id = :id
            ');
            $upd->execute([
                'payload_hash' => $hash,
                'id' => (int) $row['id'],
            ]);
            return null;
        }

        JsonResponse::error(409, 'IDEMPOTENCY_IN_FLIGHT', 'Ayni Idempotency-Key ile islem devam ediyor.');
        return null;
    }

    public static function completeInTransaction(
        PDO $pdo,
        $actorUserId,
        $operationScope,
        $key,
        $httpStatus,
        $entityType = null,
        $entityId = null,
        $entityRef = null
    ) {
        if ($key === null || $key === '' || !self::schemaReady($pdo)) {
            return;
        }

        $stmt = $pdo->prepare('
            UPDATE offline_mutation_idempotency
            SET state = \'COMPLETED\',
                http_status = :http_status,
                result_entity_type = :result_entity_type,
                result_entity_id = :result_entity_id,
                result_entity_ref = :result_entity_ref,
                completed_at = NOW(3),
                error_code = NULL
            WHERE actor_user_id = :actor_user_id
              AND operation_scope = :operation_scope
              AND idempotency_key = :idempotency_key
              AND state = \'CLAIMED\'
        ');
        $stmt->execute([
            'http_status' => (int) $httpStatus,
            'result_entity_type' => $entityType,
            'result_entity_id' => $entityId,
            'result_entity_ref' => $entityRef,
            'actor_user_id' => (int) $actorUserId,
            'operation_scope' => (string) $operationScope,
            'idempotency_key' => (string) $key,
        ]);
    }

    public static function failInTransaction(PDO $pdo, $actorUserId, $operationScope, $key, $errorCode)
    {
        if ($key === null || $key === '' || !self::schemaReady($pdo)) {
            return;
        }
        $stmt = $pdo->prepare('
            UPDATE offline_mutation_idempotency
            SET state = \'FAILED\',
                error_code = :error_code,
                completed_at = NOW(3)
            WHERE actor_user_id = :actor_user_id
              AND operation_scope = :operation_scope
              AND idempotency_key = :idempotency_key
              AND state = \'CLAIMED\'
        ');
        $stmt->execute([
            'error_code' => $errorCode !== null ? (string) $errorCode : null,
            'actor_user_id' => (int) $actorUserId,
            'operation_scope' => (string) $operationScope,
            'idempotency_key' => (string) $key,
        ]);
    }

    /**
     * @param callable(PDO):array{data:mixed,status?:int,entity_type?:?string,entity_id?:?int,entity_ref?:?string,meta?:array} $mutator
     * @param callable(PDO,array):mixed|null $replayLoader
     */
    public static function run(
        PDO $pdo,
        $actorUserId,
        Request $request,
        $operationScope,
        $canonicalPayload,
        callable $mutator,
        $replayLoader = null
    ) {
        $key = self::readKey($request);
        $hash = self::hashPayload($canonicalPayload);

        if ($key !== null) {
            $completed = self::findCompletedReplay($pdo, $actorUserId, $operationScope, $key, $hash);
            if (is_array($completed)) {
                $data = is_callable($replayLoader)
                    ? $replayLoader($pdo, $completed)
                    : null;
                if ($data === null) {
                    JsonResponse::serverError('Idempotent replay sonucu yuklenemedi.');
                }
                JsonResponse::success($data, [], (int) ($completed['http_status'] ?? 200));
            }
        }

        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            if ($key !== null) {
                $replayRow = self::claimInTransaction($pdo, $actorUserId, $operationScope, $key, $hash);
                if (is_array($replayRow)) {
                    if ($ownsTx) {
                        $pdo->commit();
                    }
                    $data = is_callable($replayLoader)
                        ? $replayLoader($pdo, $replayRow)
                        : null;
                    if ($data === null) {
                        JsonResponse::serverError('Idempotent replay sonucu yuklenemedi.');
                    }
                    JsonResponse::success($data, [], (int) ($replayRow['http_status'] ?? 200));
                }
            }

            $result = $mutator($pdo);
            if (!is_array($result) || !array_key_exists('data', $result)) {
                throw new \RuntimeException('Idempotency mutator must return data.');
            }

            $status = isset($result['status']) ? (int) $result['status'] : 200;
            if ($key !== null) {
                self::completeInTransaction(
                    $pdo,
                    $actorUserId,
                    $operationScope,
                    $key,
                    $status,
                    $result['entity_type'] ?? null,
                    isset($result['entity_id']) ? (int) $result['entity_id'] : null,
                    $result['entity_ref'] ?? null
                );
            }

            if ($ownsTx) {
                $pdo->commit();
            }

            JsonResponse::success($result['data'], $result['meta'] ?? [], $status);
        } catch (\Throwable $e) {
            if ($key !== null && $pdo->inTransaction()) {
                try {
                    self::failInTransaction($pdo, $actorUserId, $operationScope, $key, 'MUTATION_FAILED');
                } catch (\Throwable $ignored) {
                    /* best effort */
                }
            }
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private static function findRow(PDO $pdo, $actorUserId, $operationScope, $key)
    {
        $stmt = $pdo->prepare('
            SELECT * FROM offline_mutation_idempotency
            WHERE actor_user_id = :actor_user_id
              AND operation_scope = :operation_scope
              AND idempotency_key = :idempotency_key
            LIMIT 1
        ');
        $stmt->execute([
            'actor_user_id' => (int) $actorUserId,
            'operation_scope' => (string) $operationScope,
            'idempotency_key' => (string) $key,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param mixed $value */
    private static function canonicalJson($value)
    {
        return (string) json_encode(self::sortDeep($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param mixed $value
     * @return mixed
     */
    private static function sortDeep($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::sortDeep($item);
            }
            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::sortDeep($v);
        }
        return $out;
    }
}
